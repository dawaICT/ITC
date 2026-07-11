<?php
/**
 * Verify course_levels table fixes
 * Tests that the column name corrections and indexes are working properly
 */

require 'db/connect.php';

echo "=== COURSE_LEVELS TABLE VERIFICATION ===\n\n";

$all_passed = true;

// Test 1: Column structure
echo "Test 1: Verifying table structure...\n";
$result = $db->query("SHOW COLUMNS FROM course_levels");
$columns = [];
while($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}

if(in_array('year', $columns)) {
    echo "   ✓ Column 'year' exists\n";
} else {
    echo "   ✗ Column 'year' NOT FOUND\n";
    $all_passed = false;
}

if(!in_array('year_level', $columns)) {
    echo "   ✓ No 'year_level' column (correct)\n";
} else {
    echo "   ✗ Column 'year_level' exists (should not)\n";
    $all_passed = false;
}

// Test 2: Indexes
echo "\nTest 2: Verifying indexes...\n";
$result = $db->query("SHOW INDEX FROM course_levels");
$index_names = [];
while($row = $result->fetch_assoc()) {
    $index_names[] = $row['Key_name'];
}

if(in_array('idx_program_semester_year', $index_names)) {
    echo "   ✓ Index 'idx_program_semester_year' exists\n";
} else {
    echo "   ✗ Index 'idx_program_semester_year' NOT FOUND\n";
    $all_passed = false;
}

if(in_array('idx_course_code', $index_names)) {
    echo "   ✓ Index 'idx_course_code' exists\n";
} else {
    echo "   ✗ Index 'idx_course_code' NOT FOUND\n";
    $all_passed = false;
}

// Test 3: Query similar to courseReg.php
echo "\nTest 3: Testing courseReg.php style query...\n";
$test_query = "SELECT c.* FROM courses c 
    INNER JOIN course_levels cl ON c.course_code = cl.course_code
    WHERE cl.semester = '1'
    AND cl.year = '1'
    AND cl.program_code = 'BSCS'
    AND c.status = 'active'";

$result = $db->query($test_query);
if($result) {
    echo "   ✓ Query executed successfully\n";
    echo "   ✓ Found {$result->num_rows} courses for BSCS Year 1 Semester 1\n";
} else {
    echo "   ✗ Query failed: " . $db->error . "\n";
    $all_passed = false;
}

// Test 4: Query similar to semesterReg.php (using prepared statement)
echo "\nTest 4: Testing semesterReg.php style query...\n";
$courses_query = "SELECT c.course_code, c.course_name 
    FROM courses c
    INNER JOIN course_levels cl ON c.course_code = cl.course_code
    WHERE cl.program_code = ? 
    AND cl.semester = ? 
    AND cl.year = ?
    AND c.status = 'active'";

$stmt = $db->prepare($courses_query);
if($stmt) {
    $program = 'BSCS';
    $semester = 1;
    $year = 1;
    $stmt->bind_param("sii", $program, $semester, $year);
    if($stmt->execute()) {
        $result = $stmt->get_result();
        echo "   ✓ Prepared statement executed successfully\n";
        echo "   ✓ Found {$result->num_rows} courses\n";
        
        if($result->num_rows > 0) {
            echo "   Sample courses:\n";
            $count = 0;
            while($count < 3 && ($row = $result->fetch_assoc())) {
                echo "      - {$row['course_code']}: {$row['course_name']}\n";
                $count++;
            }
        }
    } else {
        echo "   ✗ Statement execution failed: " . $stmt->error . "\n";
        $all_passed = false;
    }
    $stmt->close();
} else {
    echo "   ✗ Prepared statement failed: " . $db->error . "\n";
    $all_passed = false;
}

// Test 5: Data integrity
echo "\nTest 5: Data integrity checks...\n";
$result = $db->query("SELECT COUNT(*) as total FROM course_levels");
$row = $result->fetch_assoc();
echo "   ✓ Total records: {$row['total']}\n";

$result = $db->query("SELECT COUNT(DISTINCT program_code) as programs FROM course_levels");
$row = $result->fetch_assoc();
echo "   ✓ Distinct programs: {$row['programs']}\n";

$result = $db->query("SELECT 
    SUM(CASE WHEN course_code IS NULL OR course_code = '' THEN 1 ELSE 0 END) as empty_course,
    SUM(CASE WHEN program_code IS NULL OR program_code = '' THEN 1 ELSE 0 END) as empty_program
FROM course_levels");
$row = $result->fetch_assoc();

if($row['empty_course'] == 0 && $row['empty_program'] == 0) {
    echo "   ✓ No NULL/empty values in critical columns\n";
} else {
    echo "   ✗ Found NULL/empty values: course={$row['empty_course']}, program={$row['empty_program']}\n";
    $all_passed = false;
}

// Test 6: Check for orphaned records
echo "\nTest 6: Checking for orphaned records...\n";
$result = $db->query("SELECT COUNT(*) as orphaned 
    FROM course_levels cl 
    LEFT JOIN courses c ON cl.course_code = c.course_code 
    WHERE c.course_code IS NULL");
$row = $result->fetch_assoc();

if($row['orphaned'] == 0) {
    echo "   ✓ No orphaned records (all course_levels reference valid courses)\n";
} else {
    echo "   ⚠ Found {$row['orphaned']} orphaned records (course_levels referencing non-existent courses)\n";
    // Not failing the test, just a warning
}

// Summary
echo "\n" . str_repeat("=", 60) . "\n";
if($all_passed) {
    echo "✓ ALL TESTS PASSED\n";
    echo "\nThe course_levels table is properly configured and working.\n";
    echo "Both courseReg.php and semesterReg.php should now work correctly.\n";
} else {
    echo "✗ SOME TESTS FAILED\n";
    echo "\nPlease review the errors above.\n";
}
echo str_repeat("=", 60) . "\n";

?>
