<?php
declare(strict_types=1);

/**
 * Database helpers for persistent chatbot conversation history.
 *
 * Uses wuc_ensure_tables() which silently logs DDL failures when the runtime DB
 * user lacks CREATE privileges. If the tables are unavailable the chatbot falls
 * back to session-only memory — no page error is thrown.
 *
 * Tables:
 *   chatbot_conversations — one row per conversation thread.
 *   chatbot_messages      — one row per message turn.
 *   chatbot_memory        — key/value memory per user+role+type.
 */

require_once __DIR__ . '/schema_guard.php';

if (!function_exists('wuc_chatbot_ensure_schema')) {
    function wuc_chatbot_ensure_schema(mysqli $db): bool
    {
        wuc_ensure_tables($db, [
            "CREATE TABLE IF NOT EXISTS chatbot_conversations (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id      VARCHAR(64)  NOT NULL,
                user_role    VARCHAR(64)  NOT NULL DEFAULT '',
                chatbot_type VARCHAR(64)  NOT NULL DEFAULT 'general',
                title        VARCHAR(255) NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user (user_id, user_role, chatbot_type, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS chatbot_messages (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id INT UNSIGNED NOT NULL,
                sender_type     ENUM('user','assistant') NOT NULL,
                message         TEXT         NOT NULL,
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_convo (conversation_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS chatbot_memory (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id      VARCHAR(64)  NOT NULL,
                user_role    VARCHAR(64)  NOT NULL DEFAULT '',
                chatbot_type VARCHAR(64)  NOT NULL DEFAULT 'general',
                memory_key   VARCHAR(128) NOT NULL,
                memory_value TEXT         NOT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_mem (user_id, user_role, chatbot_type, memory_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);

        return wuc_table_exists($db, 'chatbot_conversations')
            && wuc_table_exists($db, 'chatbot_messages');
    }
}

if (!function_exists('wuc_chatbot_get_or_create_convo')) {
    /**
     * Return the most recent conversation_id for user+role+type, creating one
     * if none exists. Returns null when the DB layer is unavailable.
     */
    function wuc_chatbot_get_or_create_convo(
        mysqli $db,
        string $userId,
        string $userRole,
        string $chatbotType
    ): ?int {
        if (!wuc_table_exists($db, 'chatbot_conversations')) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT id FROM chatbot_conversations
                 WHERE user_id = ? AND user_role = ? AND chatbot_type = ?
                 ORDER BY updated_at DESC LIMIT 1'
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('sss', $userId, $userRole, $chatbotType);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) {
                return (int)$row['id'];
            }
            return wuc_chatbot_new_convo($db, $userId, $userRole, $chatbotType);
        } catch (Throwable $e) {
            error_log('wuc_chatbot_get_or_create_convo: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wuc_chatbot_new_convo')) {
    /**
     * Create a new conversation row and return its ID.
     * Old conversations are kept (not deleted) so history is preserved.
     */
    function wuc_chatbot_new_convo(
        mysqli $db,
        string $userId,
        string $userRole,
        string $chatbotType
    ): ?int {
        if (!wuc_table_exists($db, 'chatbot_conversations')) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'INSERT INTO chatbot_conversations (user_id, user_role, chatbot_type) VALUES (?, ?, ?)'
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('sss', $userId, $userRole, $chatbotType);
            $stmt->execute();
            $id = (int)$db->insert_id;
            $stmt->close();
            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            error_log('wuc_chatbot_new_convo: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wuc_chatbot_load_history')) {
    /**
     * Return the last $limit message turns for $convoId as an array of
     * ['role' => 'user'|'assistant', 'content' => string].
     */
    function wuc_chatbot_load_history(mysqli $db, int $convoId, int $limit = 16): array
    {
        if (!wuc_table_exists($db, 'chatbot_messages')) {
            return [];
        }
        try {
            $stmt = $db->prepare(
                'SELECT sender_type, message FROM (
                    SELECT sender_type, message, created_at
                    FROM chatbot_messages
                    WHERE conversation_id = ?
                    ORDER BY created_at DESC LIMIT ?
                 ) sub ORDER BY created_at ASC'
            );
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('ii', $convoId, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            $history = [];
            while ($row = $res->fetch_assoc()) {
                $history[] = [
                    'role'    => $row['sender_type'] === 'assistant' ? 'assistant' : 'user',
                    'content' => (string)$row['message'],
                ];
            }
            $stmt->close();
            return $history;
        } catch (Throwable $e) {
            error_log('wuc_chatbot_load_history: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('wuc_chatbot_save_turn')) {
    /**
     * Persist one user message and one assistant reply to the database.
     */
    function wuc_chatbot_save_turn(
        mysqli $db,
        int    $convoId,
        string $userMsg,
        string $assistantMsg
    ): void {
        if (!wuc_table_exists($db, 'chatbot_messages')) {
            return;
        }
        try {
            $stmt = $db->prepare(
                'INSERT INTO chatbot_messages (conversation_id, sender_type, message) VALUES (?, ?, ?)'
            );
            if (!$stmt) {
                return;
            }
            $senderUser = 'user';
            $stmt->bind_param('iss', $convoId, $senderUser, $userMsg);
            $stmt->execute();
            $senderAi = 'assistant';
            $stmt->bind_param('iss', $convoId, $senderAi, $assistantMsg);
            $stmt->execute();
            $stmt->close();
            // Touch updated_at so get_or_create_convo returns this conversation next time
            $upd = $db->prepare(
                'UPDATE chatbot_conversations SET updated_at = NOW() WHERE id = ?'
            );
            if ($upd) {
                $upd->bind_param('i', $convoId);
                $upd->execute();
                $upd->close();
            }
        } catch (Throwable $e) {
            error_log('wuc_chatbot_save_turn: ' . $e->getMessage());
        }
    }
}
