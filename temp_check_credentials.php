<?php
require_once('db/connect.php');

echo "=== USER_CREDENTIALS TABLE STRUCTURE ===\n";
$result = $db->query('DESCRIBE user_credentials');
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