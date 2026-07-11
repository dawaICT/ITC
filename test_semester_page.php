<?php
// Test script to check if semester.php components work correctly
require 'db/connect.php';

echo "=== TESTING SEMESTER PAGE COMPONENTS ===\n\n";

// Test 1: Check if display_semester_courses function exists and works
echo "1. Testing display_semester_courses() function...\n";

// Include the file to make the function available
require 'admin/semester_courses.php';

if (function_exists('display_semester_courses')) {
    echo "✓ Function exists\n";

    // Capture function output
    ob_start();
    display_semester_courses();
    $output = ob_get_clean();

    if (!empty($output)) {
        echo "✓ Function produces output (" . strlen($output) . " characters)\n";

        // Check if output contains expected content
        if (strpos($output, 'program_courses') !== false || strpos($output, 'No Semester Courses') !== false || strpos($output, 'card') !== false) {
            echo "✓ Output appears to be valid HTML\n";
        } else {
            echo "⚠ Output may not be displaying data correctly\n";
            echo "First 200 chars: " . substr($output, 0, 200) . "\n";
        }
    } else {
        echo "⚠ Function produced no output\n";
    }
} else {
    echo "✗ Function display_semester_courses() not found after including file\n";
}

echo "\n2. Testing AJAX endpoints...\n";

// Test get_all_programs.php
echo "Testing get_all_programs.php... ";
$programs_response = file_get_contents('http://localhost/wucportal/admin/ajax/get_all_programs.php');
if ($programs_response) {
    $programs_data = json_decode($programs_response, true);
    if ($programs_data && isset($programs_data['success'])) {
        echo "✓ Working (found " . count($programs_data['programs']) . " programs)\n";
    } else {
        echo "⚠ Response received but format may be incorrect\n";
    }
} else {
    echo "✗ Could not access endpoint\n";
}

// Test get_courses.php
echo "Testing get_courses.php... ";
$courses_response = file_get_contents('http://localhost/wucportal/admin/ajax/get_courses.php');
if ($courses_response) {
    $courses_data = json_decode($courses_response, true);
    if ($courses_data && isset($courses_data['success'])) {
        echo "✓ Working (found " . count($courses_data['courses']) . " courses)\n";
    } else {
        echo "⚠ Response received but format may be incorrect\n";
    }
} else {
    echo "✗ Could not access endpoint\n";
}

// Test get_semester_statistics.php
echo "Testing get_semester_statistics.php... ";
$stats_response = file_get_contents('http://localhost/wucportal/admin/ajax/get_semester_statistics.php');
if ($stats_response) {
    $stats_data = json_decode($stats_response, true);
    if ($stats_data && isset($stats_data['success'])) {
        echo "✓ Working (stats: " . json_encode($stats_data['statistics']) . ")\n";
    } else {
        echo "⚠ Response received but format may be incorrect\n";
    }
} else {
    echo "✗ Could not access endpoint\n";
}

echo "\n3. Testing database queries...\n";

// Test program_courses query
$result = $db->query("SELECT COUNT(*) as count FROM program_courses");
if ($result) {
    $row = $result->fetch_assoc();
    echo "✓ program_courses table accessible (" . $row['count'] . " records)\n";
} else {
    echo "✗ program_courses query failed: " . $db->error . "\n";
}

// Test JOIN query
$result = $db->query("SELECT pc.id, pc.program_code, pc.course_code, pc.semester, pc.credits,
                             p.program_name, c.course_name
                      FROM program_courses pc
                      LEFT JOIN programs p ON pc.program_code = p.program_code
                      LEFT JOIN courses c ON pc.course_code = c.course_code
                      LIMIT 1");
if ($result && $result->num_rows > 0) {
    echo "✓ JOIN query working\n";
} else {
    echo "⚠ JOIN query issue: " . $db->error . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
?>
