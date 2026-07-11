<?php
/**
 * Add performance indexes to course_levels table
 * This will speed up queries that join courses with course_levels
 */

require 'db/connect.php';

echo "=== ADDING INDEXES TO COURSE_LEVELS TABLE ===\n\n";

// Index 1: Composite index for program_code, semester, year
echo "1. Adding index: idx_program_semester_year...\n";
$sql1 = "ALTER TABLE course_levels ADD INDEX idx_program_semester_year (program_code, semester, year)";
if($db->query($sql1)) {
    echo "   ✓ Successfully added idx_program_semester_year\n";
} else {
    if(strpos($db->error, 'Duplicate key name') !== false) {
        echo "   ⚠ Index already exists\n";
    } else {
        echo "   ✗ Error: " . $db->error . "\n";
    }
}

// Index 2: Index for course_code (for joins with courses table)
echo "\n2. Adding index: idx_course_code...\n";
$sql2 = "ALTER TABLE course_levels ADD INDEX idx_course_code (course_code)";
if($db->query($sql2)) {
    echo "   ✓ Successfully added idx_course_code\n";
} else {
    if(strpos($db->error, 'Duplicate key name') !== false) {
        echo "   ⚠ Index already exists\n";
    } else {
        echo "   ✗ Error: " . $db->error . "\n";
    }
}

// Verify indexes
echo "\n3. Verifying indexes...\n";
$result = $db->query("SHOW INDEX FROM course_levels");
$indexes = [];
while($row = $result->fetch_assoc()) {
    if(!isset($indexes[$row['Key_name']])) {
        $indexes[$row['Key_name']] = [];
    }
    $indexes[$row['Key_name']][] = $row['Column_name'];
}

echo "   Current indexes on course_levels:\n";
foreach($indexes as $name => $columns) {
    echo "      - $name: " . implode(', ', $columns) . "\n";
}

echo "\n✓ Index optimization complete!\n";
?>
