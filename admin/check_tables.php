<?php
require_once(__DIR__ . '/../db/connect.php');

// Get all tables
$all_tables = [];
$result = $db->query("SHOW TABLES");
while ($row = $result->fetch_array()) {
    $all_tables[] = $row[0];
}

// Check if required tables exist
$tables = ['students', 'programs'];
$missing_tables = [];

foreach ($tables as $table) {
    if (!in_array($table, $all_tables)) {
        $missing_tables[] = $table;
    }
}

// Output results
header('Content-Type: application/json');
echo json_encode([
    'success' => empty($missing_tables),
    'missing_tables' => $missing_tables,
    'all_tables' => $all_tables
], JSON_PRETTY_PRINT);
?> 