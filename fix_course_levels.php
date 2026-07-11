<?php
/**
 * Fix course_levels table column name issues
 * Problem: courseReg.php and semesterReg.php reference 'year_level' but the column is named 'year'
 * Solution: Fix the SQL queries in both files to use the correct column name 'year'
 */

echo "=== COURSE_LEVELS TABLE DEBUG AND FIX ===\n\n";

require 'db/connect.php';

// Verify the issue
echo "1. Checking course_levels table structure...\n";
$result = $db->query("SHOW COLUMNS FROM course_levels");
$columns = [];
while($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
    echo "   - {$row['Field']}\n";
}

if(in_array('year', $columns) && !in_array('year_level', $columns)) {
    echo "\n✓ Confirmed: Table has 'year' column, NOT 'year_level'\n";
} else {
    echo "\n⚠ Unexpected column structure!\n";
    exit(1);
}

// Check files that need fixing
echo "\n2. Files with incorrect column references:\n";
echo "   - admin/courseReg.php (line 102)\n";
echo "   - admin/semesterReg.php (line 307)\n";

echo "\n3. Verifying data integrity...\n";
$result = $db->query("SELECT COUNT(*) as total FROM course_levels");
$row = $result->fetch_assoc();
echo "   Total records: {$row['total']}\n";

$result = $db->query("SELECT program_code, COUNT(*) as count FROM course_levels GROUP BY program_code");
echo "   Records by program:\n";
while($row = $result->fetch_assoc()) {
    echo "      {$row['program_code']}: {$row['count']}\n";
}

echo "\n4. Testing a sample query that courseReg.php would use...\n";
$test_query = "SELECT c.* FROM courses c 
    INNER JOIN course_levels cl ON c.course_code = cl.course_code
    WHERE cl.semester = '1'
    AND cl.year = '1'
    AND cl.program_code = 'BSCS'
    AND c.status = 'active'";

$result = $db->query($test_query);
if($result) {
    echo "   ✓ Query successful! Found {$result->num_rows} courses\n";
    if($result->num_rows > 0) {
        echo "   Sample courses:\n";
        $count = 0;
        while($count < 3 && ($row = $result->fetch_assoc())) {
            echo "      - {$row['course_code']}: {$row['course_name']}\n";
            $count++;
        }
    }
} else {
    echo "   ✗ Query failed: " . $db->error . "\n";
}

echo "\n5. Checking for missing indexes (performance optimization)...\n";
$result = $db->query("SHOW INDEX FROM course_levels");
$indexes = [];
while($row = $result->fetch_assoc()) {
    if(!isset($indexes[$row['Key_name']])) {
        $indexes[$row['Key_name']] = [];
    }
    $indexes[$row['Key_name']][] = $row['Column_name'];
}

echo "   Existing indexes:\n";
foreach($indexes as $name => $columns) {
    echo "      $name: " . implode(', ', $columns) . "\n";
}

// Recommend indexes for better performance
echo "\n6. Recommended indexes for performance:\n";
$recommended = [
    'idx_program_semester_year' => ['program_code', 'semester', 'year'],
    'idx_course_code' => ['course_code']
];

foreach($recommended as $index_name => $cols) {
    $col_str = implode(', ', $cols);
    if(!isset($indexes[$index_name])) {
        echo "   ⚠ MISSING: $index_name ($col_str)\n";
        echo "      SQL: ALTER TABLE course_levels ADD INDEX $index_name ($col_str);\n";
    } else {
        echo "   ✓ EXISTS: $index_name\n";
    }
}

echo "\n=== SUMMARY ===\n";
echo "Issues Found:\n";
echo "1. courseReg.php uses 'cl.year_level' instead of 'cl.year'\n";
echo "2. semesterReg.php uses 'cl.year_level' instead of 'cl.year'\n";
echo "\nNext Steps:\n";
echo "- Apply fixes to both PHP files\n";
echo "- Consider adding indexes for better query performance\n";
echo "- Test course registration after fix\n";

echo "\n✓ Debug complete!\n";
?>
