<?php
require_once(__DIR__ . '/../db/connect.php');

// Check if table exists
$tableExists = false;
$result = $db->query("SHOW TABLES LIKE 'semester_registration'");
if ($result && $result->num_rows > 0) {
    $tableExists = true;
}

if ($tableExists) {
    // Get table structure
    $result = $db->query("DESCRIBE semester_registration");
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row;
    }
}

// Output results
header('Content-Type: application/json');
echo json_encode([
    'success' => $tableExists,
    'message' => $tableExists ? 'Table exists' : 'Table does not exist',
    'columns' => $tableExists ? $columns : []
], JSON_PRETTY_PRINT);
?> 