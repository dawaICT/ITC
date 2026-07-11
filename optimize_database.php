<?php
/**
 * Apply Database Optimizations
 * Adds missing indexes and applies performance improvements
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once 'db/connect.php';

echo "=== APPLYING DATABASE OPTIMIZATIONS ===\n\n";

$optimizations_applied = [];
$optimizations_failed = [];

// 1. Add indexes to exams table
echo "1. Optimizing exams table...\n";

$exams_indexes = [
    'idx_course_code' => 'Course_Code',
    'idx_semester' => 'semester',
    'idx_year' => 'Year',
    'idx_composite' => '(Sid, Course_Code, semester, Year)'
];

foreach ($exams_indexes as $index_name => $columns) {
    // Check if index exists
    $check = $db->query("SHOW INDEX FROM exams WHERE Key_name = '$index_name'");
    if ($check && $check->num_rows > 0) {
        echo "   ℹ️  Index $index_name already exists\n";
        continue;
    }
    
    try {
        $sql = "ALTER TABLE exams ADD INDEX $index_name $columns";
        if ($db->query($sql)) {
            echo "   ✅ Added index: $index_name on $columns\n";
            $optimizations_applied[] = "Added index $index_name to exams";
        }
    } catch (Exception $e) {
        echo "   ❌ Failed to add index $index_name: " . $e->getMessage() . "\n";
        $optimizations_failed[] = "Failed to add index $index_name to exams";
    }
}

// 2. Add indexes to semester_assessment table
echo "\n2. Optimizing semester_assessment table...\n";

$assessment_indexes = [
    'idx_composite' => '(Sid, Course_Code, semester, Year)'
];

foreach ($assessment_indexes as $index_name => $columns) {
    // Check if index exists
    $check = $db->query("SHOW INDEX FROM semester_assessment WHERE Key_name = '$index_name'");
    if ($check && $check->num_rows > 0) {
        echo "   ℹ️  Index $index_name already exists\n";
        continue;
    }
    
    try {
        $sql = "ALTER TABLE semester_assessment ADD INDEX $index_name $columns";
        if ($db->query($sql)) {
            echo "   ✅ Added composite index: $index_name on $columns\n";
            $optimizations_applied[] = "Added index $index_name to semester_assessment";
        }
    } catch (Exception $e) {
        echo "   ❌ Failed to add index $index_name: " . $e->getMessage() . "\n";
        $optimizations_failed[] = "Failed to add index $index_name to semester_assessment";
    }
}

// 3. Add indexes to course_registration table
echo "\n3. Optimizing course_registration table...\n";

$course_reg_indexes = [
    'idx_student_id' => 'student_id'
];

foreach ($course_reg_indexes as $index_name => $column) {
    // Check if index exists
    $check = $db->query("SHOW INDEX FROM course_registration WHERE Key_name = '$index_name'");
    if ($check && $check->num_rows > 0) {
        echo "   ℹ️  Index $index_name already exists\n";
        continue;
    }
    
    try {
        $sql = "ALTER TABLE course_registration ADD INDEX $index_name ($column)";
        if ($db->query($sql)) {
            echo "   ✅ Added index: $index_name on $column\n";
            $optimizations_applied[] = "Added index $index_name to course_registration";
        }
    } catch (Exception $e) {
        echo "   ❌ Failed to add index $index_name: " . $e->getMessage() . "\n";
        $optimizations_failed[] = "Failed to add index $index_name to course_registration";
    }
}

// 4. Clean up old orphaned record (the one with invalid Sid)
echo "\n4. Cleaning up orphaned exam record...\n";
try {
    $result = $db->query("DELETE e FROM exams e LEFT JOIN students s ON e.Sid = s.SID WHERE s.SID IS NULL");
    if ($result) {
        $affected = $db->affected_rows;
        echo "   ✅ Cleaned up $affected orphaned exam record(s)\n";
        $optimizations_applied[] = "Removed $affected orphaned exam records";
    }
} catch (Exception $e) {
    echo "   ⚠️  Could not clean orphaned records: " . $e->getMessage() . "\n";
}

// 5. Analyze tables for query optimization
echo "\n5. Analyzing tables for query optimizer...\n";
$tables_to_analyze = ['students', 'exams', 'semester_assessment', 'courses', 'student_program'];
foreach ($tables_to_analyze as $table) {
    try {
        $db->query("ANALYZE TABLE $table");
        echo "   ✅ Analyzed table: $table\n";
    } catch (Exception $e) {
        echo "   ⚠️  Could not analyze $table: " . $e->getMessage() . "\n";
    }
}

// 6. Test query performance after optimization
echo "\n6. Testing query performance after optimization...\n";
$start = microtime(true);
$test_query = "SELECT s.SID, s.Fname, s.Lname, e.Course_Code, c.course_name, 
               e.Total_marks, sa.Total_CA, e.semester, e.Year, p.program_name
               FROM students s
               INNER JOIN exams e ON s.SID = e.Sid
               INNER JOIN courses c ON e.Course_Code = c.course_code
               INNER JOIN semester_assessment sa ON c.course_code = sa.Course_Code 
                   AND s.SID = sa.Sid 
                   AND e.semester = sa.semester 
                   AND e.Year = sa.Year
               INNER JOIN student_program sp ON s.SID = sp.Sid
               INNER JOIN programs p ON sp.program_code = p.program_code
               WHERE e.Sid = '2023001'
               AND e.semester = 1
               AND e.Year = 2026
               LIMIT 10";

$result = $db->query($test_query);
$duration = (microtime(true) - $start) * 1000;

if ($result !== false) {
    echo "   ✅ Query executed in " . number_format($duration, 2) . " ms\n";
    echo "   ✅ Found {$result->num_rows} records\n";
} else {
    echo "   ❌ Query failed: " . $db->error . "\n";
}

// 7. Display EXPLAIN for the query
echo "\n7. Query execution plan (EXPLAIN)...\n";
$explain = $db->query("EXPLAIN $test_query");
if ($explain) {
    echo "   " . str_repeat("-", 70) . "\n";
    printf("   %-15s %-12s %-15s %-10s\n", "Table", "Type", "Key", "Rows");
    echo "   " . str_repeat("-", 70) . "\n";
    while ($row = $explain->fetch_assoc()) {
        printf("   %-15s %-12s %-15s %-10s\n", 
            $row['table'], 
            $row['type'], 
            $row['key'] ?? 'NULL', 
            $row['rows']
        );
    }
    echo "   " . str_repeat("-", 70) . "\n";
}

// Summary
echo "\n=== OPTIMIZATION SUMMARY ===\n";
echo "Optimizations Applied: " . count($optimizations_applied) . "\n";
if (count($optimizations_applied) > 0) {
    foreach ($optimizations_applied as $i => $opt) {
        echo "  " . ($i + 1) . ". ✅ $opt\n";
    }
}

if (count($optimizations_failed) > 0) {
    echo "\nOptimizations Failed: " . count($optimizations_failed) . "\n";
    foreach ($optimizations_failed as $i => $fail) {
        echo "  " . ($i + 1) . ". ❌ $fail\n";
    }
}

echo "\n=== OPTIMIZATION COMPLETE ===\n";
echo "Run database_health_check.php again to verify improvements.\n";
