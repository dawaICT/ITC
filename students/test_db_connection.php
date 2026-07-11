<?php
/**
 * Database Connection Test
 * 
 * Tests the unified DatabaseConnection class and StudentDataService
 * Run via: php test_db_connection.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "=== ITC Portal Database Connection Test ===\n\n";

// Test 1: Include DatabaseConnection
echo "1. Loading DatabaseConnection class...\n";
try {
    require_once __DIR__ . '/includes/DatabaseConnection.php';
    echo "   ✓ DatabaseConnection loaded successfully\n\n";
} catch (Throwable $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 2: Get singleton instance
echo "2. Getting DatabaseConnection instance...\n";
try {
    $dbConn = DatabaseConnection::getInstance();
    echo "   ✓ Instance obtained\n\n";
} catch (Throwable $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 3: Get mysqli connection
echo "3. Testing mysqli connection...\n";
try {
    $mysqli = $dbConn->getMysqli();
    if ($mysqli && !$mysqli->connect_errno) {
        echo "   ✓ mysqli connected successfully\n";
        echo "   Server: " . $mysqli->server_info . "\n\n";
    } else {
        echo "   ✗ mysqli connection failed: " . ($dbConn->getLastError() ?? 'Unknown error') . "\n\n";
    }
} catch (Throwable $e) {
    echo "   ✗ Exception: " . $e->getMessage() . "\n\n";
}

// Test 4: Get PDO connection
echo "4. Testing PDO connection...\n";
try {
    $pdo = $dbConn->getPdo();
    if ($pdo) {
        $version = $pdo->query("SELECT VERSION()")->fetchColumn();
        echo "   ✓ PDO connected successfully\n";
        echo "   MySQL Version: $version\n\n";
    } else {
        echo "   ✗ PDO connection failed: " . ($dbConn->getLastError() ?? 'Unknown error') . "\n\n";
    }
} catch (Throwable $e) {
    echo "   ✗ Exception: " . $e->getMessage() . "\n\n";
}

// Test 5: Test connection method
echo "5. Running testConnection()...\n";
if ($dbConn->testConnection()) {
    echo "   ✓ Connection test passed\n\n";
} else {
    echo "   ✗ Connection test failed: " . ($dbConn->getLastError() ?? 'Unknown error') . "\n\n";
}

// Test 6: Load StudentDataService
echo "6. Loading StudentDataService...\n";
try {
    require_once __DIR__ . '/includes/StudentDataService.php';
    $studentService = new StudentDataService($mysqli, $pdo);
    echo "   ✓ StudentDataService loaded successfully\n\n";
} catch (Throwable $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 7: Get current academic session
echo "7. Testing getCurrentAcademicSession()...\n";
try {
    $session = $studentService->getCurrentAcademicSession();
    echo "   ✓ Academic session retrieved:\n";
    echo "     - Academic Year: " . $session['academic_year'] . "\n";
    echo "     - Semester: " . $session['semester'] . "\n";
    echo "     - Active: " . ($session['is_active'] ? 'Yes' : 'No') . "\n\n";
} catch (Throwable $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n\n";
}

// Test 8: Check tables exist
echo "8. Checking required tables...\n";
$requiredTables = [
    'students',
    'student_program', 
    'programs',
    'semester_registration',
    'course_registration',
    'student_payments',
    'invoices'
];

foreach ($requiredTables as $table) {
    $result = $mysqli->query("SHOW TABLES LIKE '$table'");
    $exists = ($result && $result->num_rows > 0);
    echo "   " . ($exists ? '✓' : '✗') . " $table\n";
}
echo "\n";

// Test 9: Count students
echo "9. Counting records...\n";
try {
    $studentCount = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    echo "   - Students: $studentCount\n";
    
    $programCount = $pdo->query("SELECT COUNT(*) FROM programs")->fetchColumn();
    echo "   - Programs: $programCount\n";
    
    $courseCount = $pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
    echo "   - Courses: $courseCount\n\n";
} catch (Throwable $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n\n";
}

echo "=== Test Complete ===\n";
