<?php
declare(strict_types=1);

/**
 * FAQ / knowledge-base chat layer (zero-cost AI, Sprint 9).
 *
 * Keyword-intent matcher over the faq_knowledge_base table. Chat endpoints
 * call wuc_faq_response() BEFORE any LLM: a confident match answers instantly
 * from the curated template (no AI required); no match falls through to the
 * existing Ollama/cloud/static-fallback chain.
 *
 * Matching is purely lexical, so instruction-like input ("ignore previous
 * instructions…") cannot steer it — it either contains the curated keywords
 * or it does not.
 */

require_once __DIR__ . '/schema_guard.php';
require_once __DIR__ . '/portal_alerts.php'; // wuc_ai_decision_log()

if (!function_exists('wuc_faq_normalize')) {
    function wuc_faq_normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string)preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}

if (!function_exists('wuc_faq_load')) {
    /** Active FAQs visible to $role ('all' or the role listed in roles_allowed). */
    function wuc_faq_load(mysqli $db, string $role): array
    {
        static $cache = [];
        $role = strtolower(trim($role)) ?: 'all';
        if (isset($cache[$role])) {
            return $cache[$role];
        }
        if (!wuc_table_exists($db, 'faq_knowledge_base')) {
            return $cache[$role] = [];
        }

        $faqs = [];
        try {
            $sql = "SELECT id, category, intent_key, keywords, question, answer_template, priority
                    FROM faq_knowledge_base
                    WHERE status = 'active'
                      AND (roles_allowed = 'all' OR FIND_IN_SET(?, roles_allowed) > 0)
                    ORDER BY priority DESC, id";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $role);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $faqs[] = $row;
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('wuc_faq_load failed: ' . $e->getMessage());
        }
        return $cache[$role] = $faqs;
    }
}

if (!function_exists('wuc_faq_match')) {
    /**
     * Best FAQ for a message, or null when no confident match.
     *
     * Scoring: +2 per matched multi-word keyword phrase, +1 per matched single
     * word (word-boundary). A match needs score >= 2 so a lone generic word
     * ("fees") in a long unrelated sentence doesn't hijack the reply — but a
     * curated phrase ("payment plan") is decisive on its own.
     */
    function wuc_faq_match(mysqli $db, string $message, string $role): ?array
    {
        $normalized = wuc_faq_normalize($message);
        if ($normalized === '' || mb_strlen($normalized) > 400) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach (wuc_faq_load($db, $role) as $faq) {
            $score = 0;
            $keywords = array_filter(array_map('trim', explode(',', (string)$faq['keywords'])));
            foreach ($keywords as $keyword) {
                $kw = wuc_faq_normalize($keyword);
                if ($kw === '') {
                    continue;
                }
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $normalized)) {
                    $score += (strpos($kw, ' ') !== false) ? 2 : 1;
                }
            }
            if ($score >= 2 && ($score > $bestScore || ($score === $bestScore && $best !== null && (int)$faq['priority'] > (int)$best['priority']))) {
                $best = $faq;
                $bestScore = $score;
            }
        }

        if ($best === null) {
            return null;
        }
        return [
            'id' => (int)$best['id'],
            'intent_key' => (string)$best['intent_key'],
            'category' => (string)$best['category'],
            'question' => (string)$best['question'],
            'answer' => (string)$best['answer_template'],
            'score' => $bestScore,
        ];
    }
}

if (!function_exists('wuc_faq_response')) {
    /**
     * FAQ-first responder: the answer template (Markdown) on a confident
     * match, otherwise null so the caller falls through to the LLM chain.
     */
    function wuc_faq_response(mysqli $db, string $message, string $role, string $userId = ''): ?string
    {
        try {
            $match = wuc_faq_match($db, $message, $role);
        } catch (Throwable $e) {
            error_log('wuc_faq_response failed: ' . $e->getMessage());
            return null;
        }
        if ($match === null) {
            return null;
        }

        if (function_exists('wuc_ai_decision_log')) {
            wuc_ai_decision_log($db, [
                'feature' => 'faq_chat_engine',
                'decision_type' => 'faq_answered',
                'entity_type' => 'faq',
                'entity_id' => (string)$match['id'],
                'input_summary' => mb_substr($message, 0, 200),
                'outcome' => 'Matched intent ' . $match['intent_key'] . ' (score ' . $match['score'] . ')',
                'user_id' => $userId !== '' ? $userId : null,
            ]);
        }

        return $match['answer']
            . "\n\n---\n*Answered from the ITC knowledge base. If this does not fully answer your question, "
            . "ask again with more detail or contact the relevant office.*";
    }
}
