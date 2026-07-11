<?php
require 'db/connect.php';

echo "Database connection: " . ($db ? "OK" : "FAILED") . PHP_EOL;

if ($db) {
    echo "Tables check:" . PHP_EOL;

    $tables = ['students', 'student_program', 'programs', 'staff_positions', 'positions'];
    foreach ($tables as $table) {
        $result = $db->query("SHOW TABLES LIKE '$table'");
        $exists = $result && $result->num_rows > 0;
        echo "  - $table: " . ($exists ? "EXISTS" : "MISSING") . PHP_EOL;
    }

    // Check if staff_positions has data
    $result = $db->query("SELECT COUNT(*) as count FROM staff_positions");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "Staff positions count: " . $row['count'] . PHP_EOL;
    }
}
?>