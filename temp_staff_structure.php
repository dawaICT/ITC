<?php
require_once('db/connect.php');

echo "=== STAFF TABLE STRUCTURE ===\n";
$result = $db->query('DESCRIBE staff');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "- {$row['Field']} ({$row['Type']})\n";
    }
    $result->free();
} else {
    echo "Error: " . $db->error . "\n";
}

$db->close();
?>