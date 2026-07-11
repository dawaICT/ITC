<?php
/**
 * Final validation test for programs functionality
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once "db/connect.php";

echo "<h1>Programs Functionality Validation Test</h1>";
echo "<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    h1, h2, h3 { color: #333; }
    .test-case { background: white; padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #007bff; }
    .pass { border-left-color: #28a745; }
    .fail { border-left-color: #dc3545; }
    .success { color: #28a745; font-weight: bold; }
    .error { color: #dc3545; font-weight: bold; }
    .info { color: #17a2b8; }
    pre { background: #f4f4f4; padding: 10px; border-radius: 4px; overflow-x: auto; }
</style>";

$tests_passed = 0;
$tests_failed = 0;

// Test 1: Can we fetch all programs?
echo "<div class='test-case'>";
echo "<h3>Test 1: Fetch All Programs</h3>";
try {
    $result = $db->query("SELECT p.*, d.department_name 
                          FROM programs p 
                          LEFT JOIN departments d ON p.department_id = d.id 
                          ORDER BY p.program_name");
    if ($result) {
        $count = $result->num_rows;
        echo "<p class='success'>✓ PASS - Successfully fetched $count programs</p>";
        
        echo "<h4>Sample Programs:</h4>";
        echo "<pre>";
        $sample = 0;
        while ($row = $result->fetch_assoc() && $sample < 3) {
            echo "Code: {$row['program_code']}\n";
            echo "Name: {$row['program_name']}\n";
            echo "Type: {$row['program_type']}\n";
            echo "Mode: {$row['study_mode']}\n";
            echo "Duration: " . ($row['program_duration'] ?? 'N/A') . " months\n";
            echo "Department: " . ($row['department_name'] ?? 'N/A') . "\n";
            echo "Active: " . ($row['is_active'] ? 'Yes' : 'No') . "\n";
            echo "---\n";
            $sample++;
        }
        echo "</pre>";
        $tests_passed++;
    } else {
        throw new Exception($db->error);
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ FAIL - " . $e->getMessage() . "</p>";
    $tests_failed++;
}
echo "</div>";

// Test 2: Can we fetch departments?
echo "<div class='test-case'>";
echo "<h3>Test 2: Fetch Departments</h3>";
try {
    $result = $db->query("SELECT id, department_name FROM departments ORDER BY department_name");
    if ($result) {
        $count = $result->num_rows;
        echo "<p class='success'>✓ PASS - Successfully fetched $count departments</p>";
        
        echo "<h4>Departments List:</h4>";
        echo "<ul>";
        while ($row = $result->fetch_assoc()) {
            echo "<li>ID {$row['id']}: {$row['department_name']}</li>";
        }
        echo "</ul>";
        $tests_passed++;
    } else {
        throw new Exception($db->error);
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ FAIL - " . $e->getMessage() . "</p>";
    $tests_failed++;
}
echo "</div>";

// Test 3: Check enrollment data
echo "<div class='test-case'>";
echo "<h3>Test 3: Fetch Program Enrollments</h3>";
try {
    $result = $db->query("SELECT p.program_name, COUNT(*) as student_count
                          FROM student_program sp
                          JOIN programs p ON sp.program_code = p.program_code
                          GROUP BY sp.program_code, p.program_name
                          ORDER BY student_count DESC");
    if ($result) {
        $count = $result->num_rows;
        echo "<p class='success'>✓ PASS - Successfully fetched enrollment data for $count programs</p>";
        
        if ($count > 0) {
            echo "<h4>Enrollment Summary:</h4>";
            echo "<ul>";
            while ($row = $result->fetch_assoc()) {
                echo "<li>{$row['program_name']}: {$row['student_count']} students</li>";
            }
            echo "</ul>";
        } else {
            echo "<p class='info'>→ No enrollments found (this is OK for a fresh setup)</p>";
        }
        $tests_passed++;
    } else {
        throw new Exception($db->error);
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ FAIL - " . $e->getMessage() . "</p>";
    $tests_failed++;
}
echo "</div>";

// Test 4: Validate data integrity
echo "<div class='test-case'>";
echo "<h3>Test 4: Data Integrity Checks</h3>";
$integrity_ok = true;

// Check for programs with invalid department references
$check = $db->query("SELECT COUNT(*) as count FROM programs p 
                     LEFT JOIN departments d ON p.department_id = d.id 
                     WHERE p.department_id IS NOT NULL AND d.id IS NULL");
if ($check) {
    $invalid = $check->fetch_assoc()['count'];
    if ($invalid == 0) {
        echo "<p class='success'>✓ All department references are valid</p>";
    } else {
        echo "<p class='error'>✗ Found $invalid programs with invalid department_id</p>";
        $integrity_ok = false;
    }
}

// Check for NULL required fields
$check = $db->query("SELECT COUNT(*) as count FROM programs 
                     WHERE program_code IS NULL OR program_code = '' 
                        OR program_name IS NULL OR program_name = ''");
if ($check) {
    $invalid = $check->fetch_assoc()['count'];
    if ($invalid == 0) {
        echo "<p class='success'>✓ All programs have required fields</p>";
    } else {
        echo "<p class='error'>✗ Found $invalid programs with missing required fields</p>";
        $integrity_ok = false;
    }
}

// Check for duplicate program codes
$check = $db->query("SELECT program_code, COUNT(*) as count FROM programs 
                     GROUP BY program_code HAVING count > 1");
if ($check) {
    $duplicates = $check->num_rows;
    if ($duplicates == 0) {
        echo "<p class='success'>✓ No duplicate program codes</p>";
    } else {
        echo "<p class='error'>✗ Found $duplicates duplicate program codes</p>";
        $integrity_ok = false;
    }
}

if ($integrity_ok) {
    echo "<p class='success'>✓ PASS - Data integrity is intact</p>";
    $tests_passed++;
} else {
    echo "<p class='error'>✗ FAIL - Data integrity issues found</p>";
    $tests_failed++;
}
echo "</div>";

// Test 5: Simulate programs.php query
echo "<div class='test-case'>";
echo "<h3>Test 5: Simulate Main Programs Page Query</h3>";
try {
    // This is the actual query used in programs.php
    $query = "SELECT p.*, d.department_name 
              FROM programs p 
              LEFT JOIN departments d ON p.department_id = d.id 
              ORDER BY p.program_name";
    
    $result = $db->query($query);
    if ($result) {
        $count = $result->num_rows;
        echo "<p class='success'>✓ PASS - Main query works correctly ($count programs)</p>";
        
        // Check if all expected columns are present
        $sample = $result->fetch_assoc();
        if ($sample) {
            $expected_cols = ['program_code', 'program_name', 'program_type', 'study_mode', 
                            'program_duration', 'department_id', 'is_active', 'department_name'];
            $missing = [];
            foreach ($expected_cols as $col) {
                if (!array_key_exists($col, $sample)) {
                    $missing[] = $col;
                }
            }
            
            if (empty($missing)) {
                echo "<p class='success'>✓ All expected columns present</p>";
            } else {
                echo "<p class='error'>✗ Missing columns: " . implode(', ', $missing) . "</p>";
                throw new Exception("Missing columns in result set");
            }
        }
        $tests_passed++;
    } else {
        throw new Exception($db->error);
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ FAIL - " . $e->getMessage() . "</p>";
    $tests_failed++;
}
echo "</div>";

// Summary
echo "<div class='test-case " . ($tests_failed == 0 ? 'pass' : 'fail') . "'>";
echo "<h2>Test Summary</h2>";
echo "<p><strong>Total Tests:</strong> " . ($tests_passed + $tests_failed) . "</p>";
echo "<p class='success'><strong>Passed:</strong> $tests_passed</p>";
echo "<p class='error'><strong>Failed:</strong> $tests_failed</p>";

if ($tests_failed == 0) {
    echo "<h3 class='success'>✓ ALL TESTS PASSED!</h3>";
    echo "<p>The programs.php page should work correctly.</p>";
    echo "<p class='info'><strong>Next Step:</strong> Access <a href='admin/programs.php'>programs.php</a> in your browser to verify the UI.</p>";
} else {
    echo "<h3 class='error'>✗ SOME TESTS FAILED</h3>";
    echo "<p>Please review the failures above and run the fix script again.</p>";
}
echo "</div>";

$db->close();
?>
