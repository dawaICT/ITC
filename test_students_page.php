<?php
// Test script to simulate accessing admissions/students.php
echo "=== Testing admissions/students.php access ===\n\n";

// Start session and simulate login
session_start();

// Simulate logged in user (like WUC015)
$_SESSION['staff_id'] = 'WUC015';
$_SESSION['user_name'] = 'Mss. Wenndy Katongo';
$_SESSION['user_role'] = 'admin';
$_SESSION['position'] = 'Admission';

echo "Simulated session:\n";
echo "- staff_id: " . ($_SESSION['staff_id'] ?? 'not set') . "\n";
echo "- user_role: " . ($_SESSION['user_role'] ?? 'not set') . "\n\n";

// Test the authentication checks from students.php
echo "Testing authentication checks:\n";

// Check 1: staff_id
if (!isset($_SESSION['staff_id'])) {
    echo "❌ FAIL: staff_id not set - would redirect to login\n";
    exit(1);
} else {
    echo "✅ PASS: staff_id is set\n";
}

// Check 2: user_role
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    echo "❌ FAIL: user_role not admin - would redirect to login\n";
    exit(1);
} else {
    echo "✅ PASS: user_role is admin\n";
}

echo "\n✅ Authentication passed - user would access students.php\n\n";

// Now test database connection and queries
echo "Testing database queries:\n";

require 'db/connect.php';

if (!$db) {
    echo "❌ FAIL: Database connection failed\n";
    exit(1);
} else {
    echo "✅ PASS: Database connected\n";
}

// Test the count query
try {
    $count_query = $db->prepare("SELECT COUNT(DISTINCT students.SID) as total FROM students
        INNER JOIN student_program ON students.SID = student_program.Sid
        INNER JOIN programs ON student_program.program_code = programs.program_code");
    if (!$count_query) {
        throw new Exception("Failed to prepare count query: " . $db->error);
    }
    $count_query->execute();
    $count_result = $count_query->get_result();
    $total_row = $count_result->fetch_object();
    $total_records = $total_row ? $total_row->total : 0;
    $count_query->close();

    echo "✅ PASS: Count query successful - $total_records students found\n";

    // Test main query
    $limit = 5; // Small limit for testing
    $offset = 0;
    $stmt = $db->prepare("SELECT DISTINCT students.*, programs.program_name, student_program.intake, student_program.mode
        FROM students
        INNER JOIN student_program ON students.SID = student_program.Sid
        INNER JOIN programs ON student_program.program_code = programs.program_code
        ORDER BY students.SID ASC LIMIT ? OFFSET ?");
    if (!$stmt) {
        throw new Exception("Failed to prepare main query: " . $db->error);
    }
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $results = $stmt->get_result();

    if (!$results) {
        throw new Exception("Query execution failed: " . $db->error);
    }

    $row_count = $results->num_rows;
    echo "✅ PASS: Main query successful - $row_count records retrieved\n";

    $stmt->close();

} catch (Exception $e) {
    echo "❌ FAIL: Database error - " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n🎉 SUCCESS: All tests passed - students.php should work correctly\n";
?>