<?php
declare(strict_types=1);

/**
 * Reusable conversational chat responder (multi-turn, session memory).
 *
 * Thin role endpoints set up auth + a system prompt + optional context JSON, then
 * call wuc_ai_chat_respond(). It handles CSRF, history, generation and the JSON
 * response. Mirrors the student chat pattern so all roles behave consistently.
 */

require_once __DIR__ . '/ai_portal.php';
require_once __DIR__ . '/chatbot_db.php';

if (!function_exists('wuc_ai_chat_respond')) {
    function wuc_ai_chat_respond(mysqli $db, array $opts): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
            return;
        }

        $raw = file_get_contents('php://input');
        $payload = ($raw !== '' && ($d = json_decode($raw, true)) && is_array($d)) ? $d : $_POST;

        $token = (string)($payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Invalid security token. Refresh and try again.']);
            return;
        }

        $role    = (string)($opts['role'] ?? 'staff');
        $userId  = (string)($opts['user_id'] ?? 'unknown');
        $feature = (string)($opts['feature'] ?? ('ai_chat_' . $role));
        $historyKey = 'ai_chat_history_' . md5($role . '|' . $userId);
        $convoSessionKey = 'chatbot_convo_' . md5($role . '|' . $userId);

        // Parse role into DB fields: 'staff:lecturer' → userRole='staff', chatbotType='lecturer'
        $dbUserRole    = strstr($role, ':', true) ?: $role;
        $dbChatbotType = strstr($role, ':') !== false ? ltrim((string)strstr($role, ':'), ':') : 'general';

        // Ensure chatbot DB tables exist (once per session to avoid repeat DDL checks)
        if (empty($_SESSION['chatbot_schema_checked'])) {
            wuc_chatbot_ensure_schema($db);
            $_SESSION['chatbot_schema_checked'] = true;
        }

        if (!empty($payload['reset'])) {
            unset($_SESSION[$historyKey]);
            // Archive current conversation and start a fresh one
            $newConvoId = wuc_chatbot_new_convo($db, $userId, $dbUserRole, $dbChatbotType);
            if ($newConvoId !== null) {
                $_SESSION[$convoSessionKey] = $newConvoId;
            } else {
                unset($_SESSION[$convoSessionKey]);
            }
            echo json_encode(['ok' => true, 'reset' => true]);
            return;
        }

        $message = trim((string)($payload['message'] ?? ''));
        if ($message === '') {
            echo json_encode(['ok' => false, 'message' => 'Please type a message.']);
            return;
        }
        if (mb_strlen($message) > 800) {
            $message = mb_substr($message, 0, 800);
        }

        $rate = wuc_ai_rate_limit((string)($opts['rate_key'] ?? $feature), (int)($opts['rate_limit'] ?? 40), 3600);
        if (!$rate['ok']) {
            echo json_encode(['ok' => false, 'message' => 'Too many messages. Please wait about ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.']);
            return;
        }

        // Resolve DB conversation ID (cached in session to avoid a DB round-trip each turn)
        $dbConvoId = isset($_SESSION[$convoSessionKey]) ? (int)$_SESSION[$convoSessionKey] : null;
        if ($dbConvoId === null) {
            $dbConvoId = wuc_chatbot_get_or_create_convo($db, $userId, $dbUserRole, $dbChatbotType);
            if ($dbConvoId !== null) {
                $_SESSION[$convoSessionKey] = $dbConvoId;
            }
        }

        // Load history: session cache first; fall back to DB when session is empty
        $history = $_SESSION[$historyKey] ?? [];
        if (!is_array($history)) {
            $history = [];
        }
        if (empty($history) && $dbConvoId !== null) {
            $dbHistory = wuc_chatbot_load_history($db, $dbConvoId, 16);
            if (!empty($dbHistory)) {
                $history = $dbHistory;
                $_SESSION[$historyKey] = $history;
            }
        }

        // FAQ-first (Sprint 9): a confident knowledge-base match answers with
        // zero AI cost; anything else falls through to the LLM chain below.
        require_once __DIR__ . '/faq_chat_engine.php';
        $faqReply = wuc_faq_response($db, $message, $dbChatbotType !== 'general' ? $dbChatbotType : $dbUserRole, $userId);
        if ($faqReply !== null) {
            $history[] = ['role' => 'user', 'content' => $message];
            $history[] = ['role' => 'assistant', 'content' => $faqReply];
            $_SESSION[$historyKey] = array_slice($history, -16);
            if ($dbConvoId !== null) {
                wuc_chatbot_save_turn($db, $dbConvoId, $message, $faqReply);
            }
            echo json_encode([
                'ok'         => true,
                'reply_html' => '<div class="ai-output">' . wuc_ai_render_markdown($faqReply) . '</div>',
                'used_ai'    => false,
                'model'      => 'faq-knowledge-base',
            ]);
            return;
        }

        $messages = [[
            'role' => 'system',
            'content' => (string)($opts['system_prompt'] ?? 'You are a helpful assistant for the ITC portal.'),
        ]];
        $contextJson = (string)($opts['context_json'] ?? '');
        if ($contextJson !== '') {
            $messages[] = ['role' => 'system', 'content' => "Portal context (JSON, refreshed this turn):\n" . $contextJson];
        }
        foreach (array_slice($history, -16) as $t) {
            $messages[] = ['role' => (($t['role'] ?? '') === 'assistant' ? 'assistant' : 'user'), 'content' => (string)($t['content'] ?? '')];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        $fallback = $opts['fallback'] ?? static function (): string {
            return "I cannot reach the AI service at this moment. Please try again shortly.";
        };

        $result = wuc_ai_generate($db, [
            'feature'       => $feature,
            'user_role'     => $role,
            'user_id'       => $userId,
            'input_summary' => mb_substr($message, 0, 200),
            'context_hash'  => hash('sha256', $contextJson . '|' . $message),
            'messages'      => $messages,
            'fallback'      => $fallback,
        ]);

        $reply = (string)$result['text'];
        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = ['role' => 'assistant', 'content' => $reply];
        $_SESSION[$historyKey] = array_slice($history, -16);

        // Persist to DB for cross-session memory
        if ($dbConvoId !== null) {
            wuc_chatbot_save_turn($db, $dbConvoId, $message, $reply);
        }

        echo json_encode([
            'ok'         => true,
            'reply_html' => '<div class="ai-output">' . wuc_ai_render_markdown($reply) . '</div>',
            'used_ai'    => (bool)$result['used_ai'],
            'model'      => (string)$result['model'],
        ]);
    }
}
