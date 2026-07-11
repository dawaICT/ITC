<?php
// Test database connection for courseReg.php
require_once __DIR__ . '/students/includes/guard.php';

echo "=== Database Connection Test ===\n";
if (!isset($db) || !($db instanceof mysqli)) {
    echo "ERROR: Database connection not available\n";
    exit(1);
}

echo "SUCCESS: Database connected\n";
echo "Server: " . $db->server_info . "\n";
echo "Host: " . $db->host_info . "\n";
$result = $db->query('SELECT DATABASE()');
if ($result) {
    $row = $result->fetch_row();
    echo "Database: " . $row[0] . "\n";
    $result->free();
}
echo "Ping: " . ($db->ping() ? 'OK' : 'FAIL') . "\n";
