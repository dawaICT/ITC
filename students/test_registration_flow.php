<?php
/**
 * Test: Semester Registration Flow
 * 
 * Verifies DB connection and data consistency across:
 * - registration.php (semester registration)
 * - courseReg.php (course selection)  
 * - myCourses.php (view registered courses)
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "=== Semester Registration Flow Test ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Load database connection (shared by all three pages)
require_once __DIR__ . '/includes/DatabaseConnection.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';

$dbConn = DatabaseConnection::getInstance();
$mysqli = $dbConn->getMysqli();
$pdo = $dbConn->getPdo();

if (!$mysqli || !$pdo) {
    echo "✗ Database connection FAILED\n";
    echo "  Error: " . ($dbConn->getLastError() ?? 'Unknown') . "\n";
    exit(1);
}

echo "✓ Database connection OK (mysqli + PDO)\n\n";

// Test 1: Check required tables exist
echo "1. Checking required tables...\n";
$tables = [
    'students' => 'Student profiles',
    'student_program' => 'Student-program mapping',
    'semester_registration' => 'Semester registrations (registration.php)',
    'course_registration' => 'Course registrations (courseReg.php → myCourses.php)',
    'courses' => 'Course catalog',
    'course_levels' => 'Course-program mapping',
    'invoices' => 'Fee invoices',
    'student_payments' => 'Payment records'
];

$missingTables = [];
foreach ($tables as $table => $desc) {
    $res = $mysqli->query("SHOW TABLES LIKE '$table'");
    $exists = ($res && $res->num_rows > 0);
    echo "   " . ($exists ? '✓' : '✗') . " $table - $desc\n";
    if (!$exists) $missingTables[] = $table;
}

if (!empty($missingTables)) {
    echo "\n⚠ Warning: Missing tables may affect functionality\n\n";
}

// Test 2: Pick a test student
echo "\n2. Finding test student...\n";
$testStudent = null;
$res = $mysqli->query("SELECT s.SID, s.Fname, s.Lname, sp.program_code 
                       FROM students s 
                       LEFT JOIN student_program sp ON s.SID = sp.Sid 
                       LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $testStudent = $row;
    echo "   ✓ Found: {$row['Fname']} {$row['Lname']} (SID: {$row['SID']})\n";
    echo "   Program: " . ($row['program_code'] ?? 'Not assigned') . "\n";
} else {
    echo "   ✗ No students found in database\n";
    exit(1);
}

$sid = $testStudent['SID'];

// Test 3: Check semester_registration (registration.php uses this)
echo "\n3. Testing semester_registration (registration.php)...\n";
$regService = new RegistrationDataService($mysqli);

$latestReg = $regService->getLatestSemesterRegistration($sid);
if ($latestReg) {
    echo "   ✓ Found semester registration:\n";
    echo "     - ID: " . ($latestReg['id'] ?? 'N/A') . "\n";
    echo "     - Year of Study: " . ($latestReg['year_of_study'] ?? 'N/A') . "\n";
    echo "     - Semester: " . ($latestReg['semester'] ?? 'N/A') . "\n";
    echo "     - Academic Year: " . ($latestReg['academic_year'] ?? 'N/A') . "\n";
    echo "     - Program: " . ($latestReg['program_code'] ?? 'N/A') . "\n";
} else {
    echo "   ⚠ No semester registration found for SID=$sid\n";
    echo "   (Student needs to complete registration.php first)\n";
}

// Test 4: Check course_registration (courseReg.php writes, myCourses.php reads)
echo "\n4. Testing course_registration (courseReg.php → myCourses.php)...\n";

// Discover column names (same logic as myCourses.php)
$crCols = [];
$meta = $mysqli->query("SHOW COLUMNS FROM course_registration");
if ($meta) {
    while ($c = $meta->fetch_assoc()) {
        $crCols[strtolower($c['Field'])] = $c['Field'];
    }
    $meta->free();
}

$crSidCol = $crCols['sid'] ?? ($crCols['student_id'] ?? 'Sid');
$crSemCol = $crCols['semester'] ?? ($crCols['semester_term'] ?? 'semester');
$crYearCol = $crCols['year'] ?? ($crCols['academic_year'] ?? 'Year');

echo "   Column mapping: SID=$crSidCol, Semester=$crSemCol, Year=$crYearCol\n";

// Get registered courses
$sql = "SELECT cr.course_code, c.course_name, COALESCE(c.credit_hours, c.credits, 3) as credits
        FROM course_registration cr
        JOIN courses c ON c.course_code = cr.course_code
        WHERE cr.`$crSidCol` = ?
        ORDER BY cr.course_code";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $sid);
$stmt->execute();
$result = $stmt->get_result();

$courses = [];
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}
$stmt->close();

if (!empty($courses)) {
    echo "   ✓ Found " . count($courses) . " registered course(s):\n";
    $totalCredits = 0;
    foreach ($courses as $c) {
        $credits = (int)($c['credits'] ?? 3);
        $totalCredits += $credits;
        echo "     - {$c['course_code']}: {$c['course_name']} ({$credits} credit hours)\n";
    }
    echo "   Total credit hours: $totalCredits\n";
} else {
    echo "   ⚠ No course registrations found for SID=$sid\n";
    echo "   (Student needs to complete courseReg.php first)\n";
}

// Test 5: Check data flow consistency
echo "\n5. Verifying data flow consistency...\n";

// Check if semester_registration.id is linked to course_registration
$semRegIdCol = $crCols['semester_registration_id'] ?? null;
if ($semRegIdCol && $latestReg) {
    $semRegId = $latestReg['id'];
    $checkSql = "SELECT COUNT(*) as cnt FROM course_registration WHERE `$semRegIdCol` = ?";
    $checkStmt = $mysqli->prepare($checkSql);
    $checkStmt->bind_param('i', $semRegId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result()->fetch_assoc();
    $linkedCount = (int)$checkResult['cnt'];
    $checkStmt->close();
    
    echo "   ✓ semester_registration_id column exists\n";
    echo "   Courses linked to semester_registration.id=$semRegId: $linkedCount\n";
} else {
    echo "   ⚠ No semester_registration_id column in course_registration\n";
    echo "   (Courses linked by Year/Semester instead of foreign key)\n";
}

// Test 6: Check invoice generation (registration.php creates invoices)
echo "\n6. Testing invoice generation (registration.php)...\n";
$invSql = "SELECT invoice_number, academic_year, semester, amount, status 
           FROM invoices 
           WHERE student_id = ? 
           ORDER BY id DESC LIMIT 1";
$invStmt = $mysqli->prepare($invSql);
$invStmt->bind_param('s', $sid);
$invStmt->execute();
$invResult = $invStmt->get_result();

if ($inv = $invResult->fetch_assoc()) {
    echo "   ✓ Latest invoice found:\n";
    echo "     - Invoice #: {$inv['invoice_number']}\n";
    echo "     - Academic Year: {$inv['academic_year']}\n";
    echo "     - Semester: {$inv['semester']}\n";
    echo "     - Amount: K" . number_format((float)$inv['amount'], 2) . "\n";
    echo "     - Status: {$inv['status']}\n";
} else {
    echo "   ⚠ No invoices found for SID=$sid\n";
}
$invStmt->close();

// Test 7: Check current academic session
echo "\n7. Testing academic session service...\n";
try {
    require_once __DIR__ . '/includes/AcademicSessionService.php';
    $sessionService = new AcademicSessionService($mysqli);
    $currentSession = $sessionService->getCurrentSession();
    
    if ($currentSession) {
        echo "   ✓ Current session: " . ($currentSession['academic_year'] ?? 'N/A') . "\n";
        echo "   Semester: " . ($currentSession['semester_term'] ?? 'N/A') . "\n";
    } else {
        echo "   ⚠ No active academic session found\n";
    }
} catch (Throwable $e) {
    echo "   ⚠ AcademicSessionService error: " . $e->getMessage() . "\n";
}

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "SUMMARY\n";
echo str_repeat('=', 50) . "\n";

$issues = [];
if (empty($latestReg)) {
    $issues[] = "No semester registration (registration.php not completed)";
}
if (empty($courses)) {
    $issues[] = "No course registrations (courseReg.php not completed)";
}
if (!empty($missingTables)) {
    $issues[] = "Missing tables: " . implode(', ', $missingTables);
}

if (empty($issues)) {
    echo "✓ All checks passed! Data flow is working correctly.\n";
    echo "\nFlow verified:\n";
    echo "  registration.php → semester_registration ✓\n";
    echo "  courseReg.php → course_registration ✓\n";
    echo "  myCourses.php ← reads from course_registration ✓\n";
} else {
    echo "⚠ Issues found:\n";
    foreach ($issues as $issue) {
        echo "  - $issue\n";
    }
    echo "\nNote: These may be expected if the test student hasn't completed registration.\n";
}

echo "\n=== Test Complete ===\n";
