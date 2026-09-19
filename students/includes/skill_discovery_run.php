<?php
declare(strict_types=1);

/**
 * Shared Skill Discovery controller (student academic or enterprise portal shell).
 * Expects: mysqli $db, string $studentId, string $skillDiscoveryFormAction
 */

require_once dirname(__DIR__, 2) . '/includes/ai_portal.php';
require_once dirname(__DIR__, 2) . '/ai/match.php';
require_once __DIR__ . '/StudentSkillDiscoveryService.php';

if (!defined('SKILL_MAX_EXPERIENCES')) {
    define('SKILL_MAX_EXPERIENCES', 5);
}
if (!defined('SKILL_MAX_ENTRY_LENGTH')) {
    define('SKILL_MAX_ENTRY_LENGTH', 1200);
}

$studentId = trim((string)($studentId ?? $_SESSION['Sid'] ?? $_SESSION['student_id'] ?? ''));
if (!isset($db) || !($db instanceof mysqli)) {
    require_once dirname(__DIR__, 2) . '/db/connect.php';
}
$skillService = new StudentSkillDiscoveryService($db);
$studentProfile = $skillService->buildStudentProfile($studentId);
$academicSkills = $studentProfile['valid'] ? $skillService->discoverAcademicSkills($studentId) : [];
$experienceResults = null;

$experienceEntries = [];
$seenExperienceKeys = [];
if (isset($_POST['experiences']) && is_array($_POST['experiences'])) {
    foreach ($_POST['experiences'] as $experienceInput) {
        $experienceInput = trim((string)$experienceInput);
        if ($experienceInput === '') {
            continue;
        }
        $experienceKey = function_exists('mb_strtolower')
            ? mb_strtolower($experienceInput, 'UTF-8')
            : strtolower($experienceInput);
        if (isset($seenExperienceKeys[$experienceKey])) {
            continue;
        }
        $seenExperienceKeys[$experienceKey] = true;
        $experienceEntries[] = $experienceInput;
    }
} elseif (isset($_POST['experience'])) {
    $legacyExperience = trim((string)$_POST['experience']);
    if ($legacyExperience !== '') {
        $experienceEntries[] = $legacyExperience;
    }
}

$query = implode("\n\n", $experienceEntries);
$formExperiences = $experienceEntries;
while (count($formExperiences) < 2) {
    $formExperiences[] = '';
}
$error = null;
$matchModeUsed = null;
$skillCount = 0;
$embeddedCount = 0;
$currentModelEmbeddedCount = 0;
$otherModelEmbeddedCount = 0;
$missingEmbeddingCount = 0;

if (!function_exists('student_skill_text_length')) {
    function student_skill_text_length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

$taxonomyTableExists = false;
if ($tableCheck = $db->query("SHOW TABLES LIKE 'ai_skill_taxonomy'")) {
    $taxonomyTableExists = $tableCheck->num_rows > 0;
    $tableCheck->free();
}

if ($taxonomyTableExists) {
    $indexSql = "
        SELECT
            COUNT(*) AS total,
            SUM(embedding IS NOT NULL) AS embedded,
            SUM(embedding IS NOT NULL AND (embed_model = ? OR embed_model = ?)) AS current_model_embedded,
            SUM(embedding IS NOT NULL AND embed_model IS NOT NULL AND embed_model NOT IN (?, ?)) AS other_model_embedded,
            SUM(embedding IS NULL) AS missing_embedding
        FROM ai_skill_taxonomy
    ";
    if ($countStmt = $db->prepare($indexSql)) {
        $indexModel = AI_EMBED_MODEL;
        $latestModel = strpos(AI_EMBED_MODEL, ':') === false ? AI_EMBED_MODEL . ':latest' : AI_EMBED_MODEL;
        $countStmt->bind_param('ssss', $indexModel, $latestModel, $indexModel, $latestModel);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $countRow = $countResult->fetch_assoc();
        $skillCount = (int)($countRow['total'] ?? 0);
        $embeddedCount = (int)($countRow['embedded'] ?? 0);
        $currentModelEmbeddedCount = (int)($countRow['current_model_embedded'] ?? 0);
        $otherModelEmbeddedCount = (int)($countRow['other_model_embedded'] ?? 0);
        $missingEmbeddingCount = (int)($countRow['missing_embedding'] ?? 0);
        $countResult->free();
        $countStmt->close();
    }
}

$ollamaUp = ollama_available();
$embeddingModelReady = $ollamaUp && ollama_model_available(AI_EMBED_MODEL);
$semanticMatchReady = $embeddingModelReady && $currentModelEmbeddedCount > 0;
$lexicalMatchReady = $taxonomyTableExists && $skillCount > 0;
$canDiscover = $lexicalMatchReady;
$aiReady = $semanticMatchReady;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $shortExperienceFound = false;
    $longExperienceFound = false;
    foreach ($experienceEntries as $experienceEntry) {
        $entryLength = student_skill_text_length($experienceEntry);
        if ($entryLength < 12) {
            $shortExperienceFound = true;
        }
        if ($entryLength > SKILL_MAX_ENTRY_LENGTH) {
            $longExperienceFound = true;
        }
    }

    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
        $error = 'Your session has changed since this page was opened. Please try again.';
    } elseif (count($experienceEntries) < 2) {
        $error = 'Enter at least two separate experiences before discovering skills.';
    } elseif (count($experienceEntries) > SKILL_MAX_EXPERIENCES) {
        $error = 'You can submit up to ' . SKILL_MAX_EXPERIENCES . ' experience entries at a time.';
    } elseif ($shortExperienceFound) {
        $error = 'Each experience needs a little more detail so the matcher has enough context.';
    } elseif ($longExperienceFound) {
        $error = 'Keep each experience under ' . SKILL_MAX_ENTRY_LENGTH . ' characters.';
    } elseif (student_skill_text_length($query) > 4000) {
        $error = 'Keep the combined experience descriptions under 4000 characters.';
    } elseif (!$taxonomyTableExists || $skillCount === 0) {
        $error = 'The skill taxonomy has not been set up yet. Please contact ICT.';
    } elseif (!$canDiscover) {
        $error = 'Skill matching is not available yet. Please contact ICT.';
    } else {
        $rate = function_exists('wuc_ai_rate_limit')
            ? wuc_ai_rate_limit('student_skill_discovery', 12, 3600)
            : ['ok' => true];
        if (empty($rate['ok'])) {
            $error = 'Too many skill discovery requests. Please try again in '
                . max(1, (int)ceil(((int)($rate['retry_after'] ?? 3600)) / 60))
                . ' minutes.';
        } else {
            try {
                set_time_limit(300);
                $rawResults = ai_match_skills_from_experiences($experienceEntries, 8, 2);
                $matchModeUsed = ai_match_last_mode();
                $experienceResults = $skillService->enrichExperienceMatches($rawResults, $experienceEntries, $studentId);
            } catch (Throwable $e) {
                error_log('skill_discovery: ' . $e->getMessage());
                if ($lexicalMatchReady) {
                    try {
                        $rawResults = ai_match_skills_from_experiences($experienceEntries, 8, 2, true);
                        $matchModeUsed = 'lexical';
                        $experienceResults = $skillService->enrichExperienceMatches($rawResults, $experienceEntries, $studentId);
                    } catch (Throwable $fallbackError) {
                        error_log('skill_discovery lexical fallback: ' . $fallbackError->getMessage());
                        $error = 'Skill matching is temporarily unavailable. Please try again later.';
                    }
                } else {
                    $error = 'Skill matching is temporarily unavailable. Please try again later.';
                }
            }
        }
    }
}

$examples = [
    'I sell vegetables at the market, serve customers, handle mobile money payments, and keep records of daily sales.',
    'I help organise community events, speak to visitors, schedule volunteers, and solve problems on the day.',
    'I repair phones and computers, explain technical issues to customers, and keep track of spare parts.',
];

$skillDiscoveryEyebrow = $skillDiscoveryEyebrow ?? 'Student Services';
$skillDiscoveryFormAction = $skillDiscoveryFormAction ?? 'skill_discovery.php';
