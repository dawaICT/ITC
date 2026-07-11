<?php
require_once __DIR__ . '/../db/connect.php';

echo "=== SHOW TABLES ===\n";
$r = $db->query("SHOW TABLES LIKE 'ai_%'");
while ($row = $r->fetch_row()) {
    echo $row[0] . "\n";
}

echo "\n=== DESCRIBE ai_portal_logs ===\n";
try {
    $r = $db->query("DESCRIBE ai_portal_logs");
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== DESCRIBE ai_skill_taxonomy ===\n";
try {
    $r = $db->query("DESCRIBE ai_skill_taxonomy");
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== COUNT ai_skill_taxonomy ===\n";
try {
    $r = $db->query("SELECT COUNT(*) FROM ai_skill_taxonomy");
    $row = $r->fetch_row();
    echo "Total: " . $row[0] . "\n";
    
    $r = $db->query("SELECT COUNT(*) FROM ai_skill_taxonomy WHERE embedding IS NOT NULL");
    $row = $r->fetch_row();
    echo "With embeddings: " . $row[0] . "\n";
    
    $r = $db->query("SELECT DISTINCT embed_model FROM ai_skill_taxonomy");
    echo "Embed models: ";
    $models = [];
    while ($row = $r->fetch_row()) {
        $models[] = $row[0];
    }
    echo implode(", ", $models) . "\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
