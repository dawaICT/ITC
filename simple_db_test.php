<?php
// Simple DB test without guard
require_once __DIR__ . '/db/connect.php';

echo "Database Connection Test\n";
echo "======================\n";
echo "Host: " . $db->host_info . "\n";
echo "Server: " . $db->server_info . "\n";

$result = $db->query('SELECT DATABASE() as dbname');
if ($result) {
    $row = $result->fetch_assoc();
    echo "Database: " . $row['dbname'] . "\n";
}

$result = $db->query("SELECT COUNT(*) as cnt FROM students");
if ($result) {
    $row = $result->fetch_assoc();
    echo "Students count: " . $row['cnt'] . "\n";
    echo "\nConnection: OK\n";
} else {
    echo "Error: " . $db->error . "\n";
}
