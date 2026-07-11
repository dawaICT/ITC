<?php
require 'db/connect.php';

echo "=== Quick Data Structure Check ===\n\n";

// Get all tables
$result = $db->query("SHOW TABLES");
$tables = [];
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

echo "Available tables: " . count($tables) . "\n";

// Check critical tables
$critical_tables = ['students', 'staff', 'courses', 'departments', 'user_credentials', 'student_login', 'access_right'];

echo "\nCritical Tables Status:\n";
foreach ($critical_tables as $table) {
    $exists = in_array($table, $tables);
    echo "- $table: " . ($exists ? "EXISTS" : "MISSING") . "\n";

    if ($exists) {
        // Get record count
        $count_result = $db->query("SELECT COUNT(*) as count FROM $table");
        $count = $count_result->fetch_assoc()['count'];
        echo "  Records: $count\n";

        // Show table structure
        $struct_result = $db->query("DESCRIBE $table");
        echo "  Columns: ";
        $columns = [];
        while ($col = $struct_result->fetch_assoc()) {
            $columns[] = $col['Field'];
        }
        echo implode(', ', $columns) . "\n";
    }
    echo "\n";
}

// Check for orphaned records
echo "Orphaned Records Check:\n";

// Check user_credentials without matching staff
$result = $db->query("
    SELECT COUNT(*) as count
    FROM user_credentials uc
    LEFT JOIN staff s ON uc.staff_id = s.staff_id
    WHERE s.staff_id IS NULL
");
$count = $result->fetch_assoc()['count'];
echo "- Orphaned user_credentials: $count\n";

// Check access_right without matching staff
$result = $db->query("
    SELECT COUNT(*) as count
    FROM access_right ar
    LEFT JOIN staff s ON ar.staff_id = s.staff_id
    WHERE s.staff_id IS NULL
");
$count = $result->fetch_assoc()['count'];
echo "- Orphaned access_right: $count\n";

// Check student_login without matching students
$result = $db->query("
    SELECT COUNT(*) as count
    FROM student_login sl
    LEFT JOIN students s ON sl.Sid = s.SID
    WHERE s.SID IS NULL
");
$count = $result->fetch_assoc()['count'];
echo "- Orphaned student_login: $count\n";

echo "\n=== Check Complete ===\n";
?>




