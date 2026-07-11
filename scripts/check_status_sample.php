<?php
require __DIR__ . '/../db/connect.php';
$res = $db->query('SELECT status FROM processed_applicants LIMIT 1');
if ($res) {
    $row = $res->fetch_assoc();
    echo 'Status sample: ' . ($row['status'] ?? 'N/A') . "\n";
} else {
    echo 'Query failed: ' . $db->error . "\n";
}
?>