<?php
/**
 * Automated Verification Script - Phase 12 Embedded AI
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/ai_portal.php';
require_once __DIR__ . '/../includes/chatbot_db.php';

echo "=== Phase 12 Embedded AI Verification Checks ===\n\n";

function verify_assert(bool $condition, string $description) {
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        exit(1);
    }
}

// 1. Check schema for chatbot memory persistence
wuc_chatbot_ensure_schema($db);
$tables = [];
if ($res = $db->query("SHOW TABLES")) {
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
}
verify_assert(in_array('chatbot_conversations', $tables, true), "Table 'chatbot_conversations' exists for persistent memory.");
verify_assert(in_array('chatbot_messages', $tables, true), "Table 'chatbot_messages' exists for persistent memory.");

// 2. Validate System Prompt Rules in ai_portal.php code
$ai_portal_code = file_get_contents('includes/ai_portal.php');
verify_assert(stripos($ai_portal_code, "never call it a university, college") !== false, "Safety constraint: AI must never call ITC a university or college.");
verify_assert(stripos($ai_portal_code, "Never use emojis or decorative symbols") !== false, "Formatting constraint: Emojis are prohibited.");
verify_assert(stripos($ai_portal_code, "Never expose raw JSON field names") !== false, "Privacy constraint: Raw JSON paths/keys are hidden.");

// 3. Test multi-turn DB conversation state & persistence
$testSid = 'STUD-AI-12';
$convoId = wuc_chatbot_get_or_create_convo($db, $testSid, 'student', 'student');
verify_assert($convoId > 0, "Created or retrieved persistent conversation ID: $convoId");

// Clean previous entries
$db->query("DELETE FROM chatbot_messages WHERE conversation_id = $convoId");

// Save message turn
$userMsg = "Hello AI, this is a test message.";
$aiReply = "Hello student. I am here to assist you.";
wuc_chatbot_save_turn($db, $convoId, $userMsg, $aiReply);

// Load conversation history
$history = wuc_chatbot_load_history($db, $convoId, 5);
echo "[INFO] History array length: " . count($history) . "\n";

$foundUser = false;
$foundAssistant = false;
foreach ($history as $h) {
    if ($h['content'] === $userMsg && $h['role'] === 'user') {
        $foundUser = true;
    }
    if ($h['content'] === $aiReply && $h['role'] === 'assistant') {
        $foundAssistant = true;
    }
}

verify_assert(count($history) >= 2, "Chat history is persistently saved in database.");
verify_assert($foundUser, "Chat history contains last user message correctly.");
verify_assert($foundAssistant, "Chat history contains last assistant reply correctly.");

// Clean up test data
$db->query("DELETE FROM chatbot_messages WHERE conversation_id = $convoId");
$db->query("DELETE FROM chatbot_conversations WHERE id = $convoId");

echo "\nVerification Results:\n";
echo "=== [ALL PASS] Phase 12 Embedded AI Verification completed successfully! ===\n";
?>
