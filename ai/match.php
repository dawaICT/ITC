<?php
/**
 * Skill matching engine — the heart of the offline module.
 *
 * Given free text describing a person's experience, it:
 *   1. embeds the text (local Ollama),
 *   2. compares it (cosine similarity) against every taxonomy embedding in MySQL,
 *   3. returns the top-N closest skills.
 *
 * No LLM is involved in matching — only embeddings — so it's fast on a CPU.
 *
 * Library use:
 *   require_once 'ai/match.php';
 *   $hits = ai_match_skills("I sell vegetables and keep a record of sales", 5);
 *
 * CLI use:
 *   php ai\match.php "I sell vegetables and keep a record of sales"
 */

if (!isset($db) || !($db instanceof mysqli)) {
    require_once __DIR__ . '/../includes/db_connect.php';
}
require_once __DIR__ . '/ollama.php';

/**
 * Cosine similarity between two equal-length float vectors. Range ~[-1, 1].
 */
function ai_cosine(array $a, array $b): float
{
    if (count($a) !== count($b) || count($a) === 0) {
        return 0.0;
    }
    $dot = 0.0; $na = 0.0; $nb = 0.0;
    $n = count($a);
    for ($i = 0; $i < $n; $i++) {
        if (!is_numeric($a[$i]) || !is_numeric($b[$i])) {
            return 0.0;
        }
        $x = (float)$a[$i]; $y = (float)$b[$i];
        $dot += $x * $y;
        $na  += $x * $x;
        $nb  += $y * $y;
    }
    if ($na == 0.0 || $nb == 0.0) { return 0.0; }
    return $dot / (sqrt($na) * sqrt($nb));
}

/**
 * Break a free-text experience into multiple skill signals.
 *
 * The first signal is always the full text. Additional signals come from
 * sentences and common connectors, so one paragraph can surface sales,
 * customer service, bookkeeping, farming, and digital skills separately.
 */
function ai_skill_signal_texts(string $text): array
{
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, 4000, 'UTF-8');
    } else {
        $text = substr($text, 0, 4000);
    }
    $clean = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($clean === '') {
        return [];
    }

    $signals = [$clean];
    $sentences = preg_split('/[.!?\r\n]+/', $clean) ?: [];

    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if ($sentence === '') {
            continue;
        }
        if (strcasecmp($sentence, rtrim($clean, ".!? \t\n\r\0\x0B")) !== 0) {
            $signals[] = $sentence;
        }

        $parts = preg_split('/[,;:]|\s+(?:and|also|then|plus|while|where|with)\s+/i', $sentence) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $signals[] = $part;
            }
        }
    }

    $out = [];
    $seen = [];
    foreach ($signals as $signal) {
        $signal = trim(preg_replace('/\s+/', ' ', $signal) ?? $signal);
        if (strlen($signal) < 10 || str_word_count($signal) < 2) {
            continue;
        }

        $key = strtolower($signal);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $signal;

        if (count($out) >= 10) {
            break;
        }
    }

    return $out;
}

/**
 * Small keyword boost from taxonomy labels/synonyms.
 *
 * Embeddings catch meaning; this catches direct evidence words such as repair,
 * payments, records, sales, cooking, driving, and farming.
 */
function ai_lexical_skill_boost(string $signal, string $label, ?string $altLabels): float
{
    $stopWords = [
        'people' => true,
        'person' => true,
        'work' => true,
        'working' => true,
        'keep' => true,
        'keeping' => true,
        'service' => true,
        'services' => true,
        'daily' => true,
    ];
    $normaliseWord = static function (string $word): string {
        $word = strtolower($word);
        if (strlen($word) > 4 && substr($word, -3) === 'ies') {
            return substr($word, 0, -3) . 'y';
        }
        foreach (['ing', 'ed', 'es', 's'] as $suffix) {
            if (strlen($word) > strlen($suffix) + 3 && substr($word, -strlen($suffix)) === $suffix) {
                $word = substr($word, 0, -strlen($suffix));
                if ($suffix === 'ing' && strlen($word) > 2 && substr($word, -1) === substr($word, -2, 1)) {
                    $word = substr($word, 0, -1);
                }
                break;
            }
        }
        return $word;
    };

    $signalWords = preg_split('/[^a-z0-9]+/i', strtolower($signal)) ?: [];
    $signalSet = [];
    foreach ($signalWords as $word) {
        $word = $normaliseWord($word);
        if (strlen($word) >= 4 && !isset($stopWords[$word])) {
            $signalSet[$word] = true;
        }
    }

    if (!$signalSet) {
        return 0.0;
    }

    $skillText = strtolower($label . ' ' . (string)$altLabels);
    $skillWords = preg_split('/[^a-z0-9]+/i', $skillText) ?: [];
    $matches = 0;
    foreach ($skillWords as $word) {
        $word = $normaliseWord($word);
        if (strlen($word) >= 4 && !isset($stopWords[$word]) && isset($signalSet[$word])) {
            $matches++;
        }
    }

    return min(0.30, $matches * 0.10);
}

/**
 * Convert raw matcher signals into a user-facing rating.
 *
 * This is not a probability. It is a calibrated confidence index for display:
 * semantic similarity carries most of the weight, keyword evidence adds a
 * small lift, and repeated support across signals adds only a modest bonus.
 */
function ai_skill_display_rating(float $semanticScore, float $lexicalBoost, int $signalCount = 1): float
{
    $semanticScore = max(0.0, min(1.0, $semanticScore));
    $lexicalBoost = max(0.0, min(0.30, $lexicalBoost));

    $rating = 0.0;
    if ($semanticScore >= 0.35) {
        $rating = (($semanticScore - 0.35) / 0.40) * 70.0;
    }

    // Direct label/synonym evidence must remain useful even when the local
    // embedding service is unavailable or a development mock is active.
    $rating += min(60.0, $lexicalBoost * 450.0);
    $rating += min(8.0, max(0, $signalCount - 1) * 2.0);

    return max(0.0, min(92.0, $rating));
}

function ai_skill_rating_label(float $rating): string
{
    if ($rating >= 75.0) {
        return 'Strong';
    }
    if ($rating >= 55.0) {
        return 'Good';
    }
    if ($rating >= 35.0) {
        return 'Possible';
    }
    return 'Low';
}

/** Whether the local Ollama embedding model can run for this request. */
function ai_skill_embedding_runtime_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    return $ready = ollama_available() && ollama_model_available(AI_EMBED_MODEL);
}

/** Last matcher mode used by ai_match_skills(): semantic or lexical. */
function ai_match_last_mode(): string
{
    return (string)($GLOBALS['_ai_skill_match_mode'] ?? 'lexical');
}

/**
 * Load taxonomy rows for matching. When $requireEmbeddings is true, only rows
 * indexed for the current embed model are returned (semantic matching). When
 * false, all taxonomy labels are returned for keyword-only fallback.
 */
function ai_load_taxonomy_skills(mysqli $db, bool $requireEmbeddings = true): array
{
    static $cache = [];
    $cacheKey = $requireEmbeddings ? 'embed' : 'lexical';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if ($requireEmbeddings) {
        $stmt = $db->prepare("
            SELECT skill_code, preferred_label, alt_labels, skill_group, skill_type, embedding
            FROM ai_skill_taxonomy
            WHERE embedding IS NOT NULL
              AND (embed_model = ? OR embed_model = ?)
        ");
        $model = AI_EMBED_MODEL;
        $latestModel = strpos($model, ':') === false ? $model . ':latest' : $model;
        $stmt->bind_param('ss', $model, $latestModel);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query("
            SELECT skill_code, preferred_label, alt_labels, skill_group, skill_type, embedding
            FROM ai_skill_taxonomy
        ");
    }

    if (!$res || $res->num_rows === 0) {
        if ($requireEmbeddings) {
            throw new RuntimeException(
                "No embedded skills found for " . AI_EMBED_MODEL . ". Run:  php ai\\load_skills.php --reembed"
            );
        }
        throw new RuntimeException('No skills found in the taxonomy.');
    }

    $skills = [];
    while ($row = $res->fetch_assoc()) {
        $vec = [];
        if ($requireEmbeddings) {
            $decoded = json_decode((string)($row['embedding'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $vec = $decoded;
        }
        $skills[] = [
            'code'  => $row['skill_code'],
            'label' => $row['preferred_label'],
            'alt'   => $row['alt_labels'],
            'group' => $row['skill_group'],
            'type'  => $row['skill_type'],
            'vec'   => $vec,
        ];
    }

    if (!$skills) {
        if ($requireEmbeddings) {
            throw new RuntimeException(
                "Embedded skills are present but could not be read. Re-run:  php ai\\load_skills.php --reembed"
            );
        }
        throw new RuntimeException('No skills found in the taxonomy.');
    }

    return $cache[$cacheKey] = $skills;
}

/**
 * Load embedded taxonomy rows once for matching.
 */
function ai_load_embedded_skills(mysqli $db): array
{
    return ai_load_taxonomy_skills($db, true);
}

/** Development mock embeddings are deterministic test vectors, not semantic. */
function ai_embedding_runtime_is_mock(): bool
{
    static $isMock = null;
    if ($isMock !== null) {
        return $isMock;
    }
    $isMock = false;
    if (!ollama_available()) {
        return $isMock;
    }
    try {
        foreach (ollama_installed_models() as $model) {
            $name = (string)($model['model'] ?? $model['name'] ?? '');
            $digest = strtolower((string)($model['digest'] ?? ''));
            if (strpos($name, AI_EMBED_MODEL) === 0 && strpos($digest, 'mock') !== false) {
                $isMock = true;
                break;
            }
        }
    } catch (Throwable $e) {
        // The normal availability/error path remains authoritative.
    }
    return $isMock;
}

/**
 * Match several separate experience entries and aggregate their skill evidence.
 *
 * This avoids awarding a final skill from one short description. The caller can
 * require a minimum number of experience entries before this function is used.
 */
function ai_match_skills_from_experiences(array $experiences, int $topN = 5, int $minExperiences = 2, bool $forceLexical = false): array
{
    $topN = max(1, min(50, $topN));
    $minExperiences = max(1, min(20, $minExperiences));
    $cleanExperiences = [];
    foreach ($experiences as $experience) {
        $experience = trim(preg_replace('/\s+/', ' ', (string)$experience) ?? (string)$experience);
        if ($experience !== '') {
            $cleanExperiences[] = $experience;
        }
    }

    if (count($cleanExperiences) < $minExperiences) {
        throw new InvalidArgumentException('Enter at least ' . $minExperiences . ' separate experiences before matching skills.');
    }

    $aggregate = [];
    $perExperienceLimit = max($topN, 8);

    foreach ($cleanExperiences as $experienceIndex => $experience) {
        foreach (ai_match_skills($experience, $perExperienceLimit, $forceLexical) as $hit) {
            $code = (string)$hit['code'];
            $rating = (float)($hit['rating'] ?? (((float)($hit['score'] ?? 0)) * 100));
            $rankScore = (float)($hit['rank_score'] ?? ($hit['score'] ?? 0));

            if (!isset($aggregate[$code])) {
                $hit['rating'] = $rating;
                $hit['score'] = $rating / 100.0;
                $hit['rank_score'] = $rankScore;
                $hit['experience_indexes'] = [$experienceIndex];
                $hit['experience_count'] = 1;
                $aggregate[$code] = $hit;
                continue;
            }

            if ($rating > (float)($aggregate[$code]['rating'] ?? 0)) {
                $aggregate[$code]['rating'] = $rating;
                $aggregate[$code]['score'] = $rating / 100.0;
                $aggregate[$code]['rating_label'] = $hit['rating_label'] ?? $aggregate[$code]['rating_label'];
                $aggregate[$code]['semantic_score'] = $hit['semantic_score'] ?? $aggregate[$code]['semantic_score'];
                $aggregate[$code]['lexical_boost'] = $hit['lexical_boost'] ?? $aggregate[$code]['lexical_boost'];
            }

            $aggregate[$code]['rank_score'] = max((float)($aggregate[$code]['rank_score'] ?? 0), $rankScore);
            if (!in_array($experienceIndex, $aggregate[$code]['experience_indexes'], true)) {
                $aggregate[$code]['experience_indexes'][] = $experienceIndex;
            }

            foreach (($hit['evidence'] ?? []) as $evidence) {
                if (count($aggregate[$code]['evidence']) >= 4) {
                    break;
                }
                if (!in_array($evidence, $aggregate[$code]['evidence'], true)) {
                    $aggregate[$code]['evidence'][] = $evidence;
                }
            }
        }
    }

    $scored = array_values($aggregate);
    foreach ($scored as &$hit) {
        $experienceCount = count(array_unique($hit['experience_indexes'] ?? []));
        $hit['experience_count'] = $experienceCount;
        $supportBoost = min(8.0, max(0, $experienceCount - 1) * 4.0);
        $hit['rating'] = max(0.0, min(96.0, ((float)($hit['rating'] ?? 0)) + $supportBoost));
        $hit['score'] = $hit['rating'] / 100.0;
        $hit['rank_score'] = ((float)($hit['rank_score'] ?? 0)) + ($supportBoost / 100.0);
        $hit['rating_label'] = ai_skill_rating_label($hit['rating']);
    }
    unset($hit);

    usort($scored, function ($a, $b) {
        $ratingCompare = ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0);
        if ($ratingCompare !== 0) {
            return $ratingCompare;
        }
        return ($b['rank_score'] ?? 0) <=> ($a['rank_score'] ?? 0);
    });

    return array_slice($scored, 0, $topN);
}

/**
 * Match free text against the taxonomy. Returns an array of:
 *   ['code','label','group','type','score']  sorted by score desc.
 *
 * @throws RuntimeException if Ollama is down or no embeddings are loaded.
 */
function ai_match_skills(string $text, int $topN = 5, bool $forceLexical = false): array
{
    global $db;

    $topN = max(1, min(50, $topN));

    if (!isset($db) || !($db instanceof mysqli)) {
        throw new RuntimeException('Database connection is not available for AI skill matching.');
    }

    $signals = ai_skill_signal_texts($text);
    if (!$signals) {
        throw new InvalidArgumentException('Cannot match empty experience text.');
    }

    $useEmbeddings = !$forceLexical
        && ai_skill_embedding_runtime_ready()
        && !ai_embedding_runtime_is_mock();
    $GLOBALS['_ai_skill_match_mode'] = $useEmbeddings ? 'semantic' : 'lexical';
    $skills = ai_load_taxonomy_skills($db, $useEmbeddings);
    $aggregate = [];
    $hasMultipleSignals = count($signals) > 1;
    $semanticReliable = $useEmbeddings;

    foreach ($signals as $signalIndex => $signal) {
        $queryVec = [];
        if ($useEmbeddings) {
            try {
                $queryVec = ollama_embed($signal);
            } catch (Throwable $e) {
                if (!$forceLexical) {
                    return ai_match_skills($text, $topN, true);
                }
                throw $e;
            }
        }
        $segmentScores = [];

        foreach ($skills as $skill) {
            $semanticScore = ($useEmbeddings && $semanticReliable && $skill['vec'])
                ? ai_cosine($queryVec, $skill['vec'])
                : 0.0;
            $lexicalBoost = ai_lexical_skill_boost($signal, $skill['label'], $skill['alt']);
            $segmentScores[] = [
                'code'  => $skill['code'],
                'label' => $skill['label'],
                'group' => $skill['group'],
                'type'  => $skill['type'],
                'score' => min(1.0, $semanticScore + $lexicalBoost),
                'semantic_score' => $semanticScore,
                'lexical_boost' => $lexicalBoost,
                'signal' => $signal,
            ];
        }

        usort($segmentScores, fn($a, $b) => $b['score'] <=> $a['score']);
        $limit = $signalIndex === 0 ? max($topN, 8) : 6;

        foreach (array_slice($segmentScores, 0, $limit) as $hit) {
            $code = $hit['code'];
            $fullTextWeight = $hasMultipleSignals ? 0.25 : 1.0;
            $weightedScore = $hit['score'] * ($signalIndex === 0 ? $fullTextWeight : 1.0);

            if (!isset($aggregate[$code])) {
                $aggregate[$code] = [
                    'code' => $hit['code'],
                    'label' => $hit['label'],
                    'group' => $hit['group'],
                    'type' => $hit['type'],
                    'score' => $weightedScore,
                    'rank_score' => $weightedScore,
                    'semantic_score' => $hit['semantic_score'],
                    'lexical_boost' => $hit['lexical_boost'],
                    'signal_count' => 1,
                    'evidence' => [$hit['signal']],
                ];
                continue;
            }

            if ($weightedScore > $aggregate[$code]['score']) {
                $aggregate[$code]['score'] = $weightedScore;
                $aggregate[$code]['rank_score'] = $weightedScore;
                $aggregate[$code]['semantic_score'] = $hit['semantic_score'];
                $aggregate[$code]['lexical_boost'] = $hit['lexical_boost'];
                array_unshift($aggregate[$code]['evidence'], $hit['signal']);
                $aggregate[$code]['evidence'] = array_values(array_unique($aggregate[$code]['evidence']));
            }
            $aggregate[$code]['signal_count']++;
            if (count($aggregate[$code]['evidence']) < 3 && !in_array($hit['signal'], $aggregate[$code]['evidence'], true)) {
                $aggregate[$code]['evidence'][] = $hit['signal'];
            }
        }
    }

    $scored = array_values($aggregate);
    foreach ($scored as &$hit) {
        $supportBonus = min(0.04, max(0, $hit['signal_count'] - 1) * 0.01);
        $hit['rank_score'] = $hit['score'] + $supportBonus;
        $hit['rating'] = ai_skill_display_rating(
            (float)($hit['semantic_score'] ?? 0.0),
            (float)($hit['lexical_boost'] ?? 0.0),
            (int)($hit['signal_count'] ?? 1)
        );
        $hit['rating_label'] = ai_skill_rating_label($hit['rating']);
        $hit['score'] = $hit['rating'] / 100.0;
    }
    unset($hit);

    usort($scored, function ($a, $b) {
        $ratingCompare = ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0);
        if ($ratingCompare !== 0) {
            return $ratingCompare;
        }
        return ($b['rank_score'] ?? 0) <=> ($a['rank_score'] ?? 0);
    });
    return array_slice($scored, 0, $topN);
}

// ---- CLI entry point ----
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $text = $argv[1];
    echo "Query: $text\n\n";
    try {
        foreach (ai_match_skills($text, 8) as $i => $h) {
            printf("%2d. %-34s  %5.1f%% %-8s [%s / %s]\n",
                $i + 1, $h['label'], $h['rating'], $h['rating_label'], $h['group'], $h['type']);
            if (!empty($h['evidence'][0])) {
                printf("    evidence: %s\n", $h['evidence'][0]);
            }
            printf("    debug: semantic %.3f, keyword %.3f, rank %.3f\n",
                $h['semantic_score'] ?? 0, $h['lexical_boost'] ?? 0, $h['rank_score'] ?? 0);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
}
