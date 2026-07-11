<?php
/**
 * Test: Course Retrieval Consistency
 * 
 * Verifies that courses are retrieved consistently across:
 * - registration.php (getSemesterRegisteredCourses)
 * - courseReg.php (course_levels + course_registration queries)
 * - myCourses.php (course_registration queries)
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "=== Course Retrieval Consistency Test ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

require_once __DIR__ . '/includes/DatabaseConnection.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';
require_once __DIR__ . '/includes/Database.php';

$dbConn = DatabaseConnection::getInstance();
$mysqli = $dbConn->getMysqli();
$pdo = $dbConn->getPdo();

if (!$mysqli || !$pdo) {
    echo "✗ Database connection FAILED\n";
    exit(1);
}

echo "✓ Database connection OK\n\n";

// Find a student with course registrations
echo "1. Finding student with registered courses...\n";
$testSid = null;
$testYear = null;
$testSem = null;
$testProgram = null;

$res = $mysqli->query("
    SELECT cr.Sid, cr.Year, cr.semester, sr.program_code, COUNT(*) as course_count
    FROM course_registration cr
    LEFT JOIN semester_registration sr ON cr.semester_registration_id = sr.id
    GROUP BY cr.Sid, cr.Year, cr.semester
    HAVING course_count > 0
    ORDER BY course_count DESC
    LIMIT 1
");

if ($res && $row = $res->fetch_assoc()) {
    $testSid = $row['Sid'];
    $testYear = $row['Year'];
    $testSem = $row['semester'];
    $testProgram = $row['program_code'];
    echo "   ✓ Found: SID=$testSid, Year=$testYear, Sem=$testSem, Program=$testProgram\n";
    echo "   Expected courses: {$row['course_count']}\n\n";
} else {
    echo "   ✗ No students with course registrations found\n";
    exit(1);
}

// ============================================
// Method 1: registration.php style query
// (Uses getSemesterRegisteredCourses logic)
// ============================================
echo "2. Testing registration.php query pattern...\n";

$regService = new RegistrationDataService($mysqli);

// Get semester registration ID
$semReg = $regService->getLatestSemesterRegistration($testSid);
$semRegId = $semReg['id'] ?? null;

// Discover columns (same as registration.php)
$courseRegCols = [];
$meta = $pdo->query("SHOW COLUMNS FROM course_registration");
while ($col = $meta->fetch(PDO::FETCH_ASSOC)) {
    $courseRegCols[strtolower($col['Field'])] = $col['Field'];
}

$crSidCol = $courseRegCols['sid'] ?? ($courseRegCols['student_id'] ?? 'Sid');
$crSemCol = $courseRegCols['semester'] ?? ($courseRegCols['semester_term'] ?? 'semester');
$crYearCol = $courseRegCols['year'] ?? ($courseRegCols['year_of_study'] ?? 'Year');
$crSemRegIdCol = $courseRegCols['semester_registration_id'] ?? null;

$registrationCourses = [];

// Try semester_registration_id link first (like registration.php)
if ($semRegId && $crSemRegIdCol) {
    $stmt = $pdo->prepare("
        SELECT cr.course_code, c.course_name, COALESCE(c.credit_hours, c.credits, 3) as credits
        FROM course_registration cr
        JOIN courses c ON c.course_code = cr.course_code
        WHERE cr.`{$crSemRegIdCol}` = ?
        ORDER BY cr.course_code
    ");
    $stmt->execute([$semRegId]);
    $registrationCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fallback to Year/Semester
if (empty($registrationCourses)) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT cr.course_code, c.course_name, COALESCE(c.credit_hours, c.credits, 3) as credits
        FROM course_registration cr
        JOIN courses c ON c.course_code = cr.course_code
        WHERE cr.`{$crSidCol}` = ? AND cr.`{$crYearCol}` = ? AND cr.`{$crSemCol}` = ?
        ORDER BY cr.course_code
    ");
    $stmt->execute([$testSid, $testYear, $testSem]);
    $registrationCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo "   Found " . count($registrationCourses) . " courses via registration.php pattern:\n";
foreach ($registrationCourses as $c) {
    echo "   - {$c['course_code']}: {$c['course_name']} ({$c['credits']} credits)\n";
}

// ============================================
// Method 2: myCourses.php style query
// ============================================
echo "\n3. Testing myCourses.php query pattern...\n";

$myCoursesCourses = [];

$sql = "SELECT cr.course_code, c.course_name, COALESCE(c.credit_hours, c.credits, 3) as credits
        FROM course_registration cr
        JOIN courses c ON c.course_code = cr.course_code
        WHERE cr.`{$crSidCol}` = ?
          AND cr.`{$crSemCol}` = ?
          AND cr.`{$crYearCol}` = ?
        ORDER BY cr.course_code";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('sss', $testSid, $testSem, $testYear);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $myCoursesCourses[] = $row;
}
$stmt->close();

echo "   Found " . count($myCoursesCourses) . " courses via myCourses.php pattern:\n";
foreach ($myCoursesCourses as $c) {
    echo "   - {$c['course_code']}: {$c['course_name']} ({$c['credits']} credits)\n";
}

// ============================================
// Method 3: courseReg.php style query (available courses from course_levels)
// ============================================
echo "\n4. Testing courseReg.php available courses query...\n";

$availableCourses = [];

// Discover course_levels columns
$clCols = [];
$clMeta = $mysqli->query("SHOW COLUMNS FROM course_levels");
if ($clMeta) {
    while ($c = $clMeta->fetch_assoc()) {
        $clCols[strtolower($c['Field'])] = $c['Field'];
    }
}

$clYearCol = $clCols['year'] ?? ($clCols['year_level'] ?? 'Year');
$clSemCol = $clCols['semester'] ?? 'semester';
$clProgCol = $clCols['program_code'] ?? 'program_code';

if ($testProgram) {
    $sql = "SELECT cl.course_code, c.course_name, COALESCE(c.credit_hours, c.credits, 3) as credits
            FROM course_levels cl
            JOIN courses c ON c.course_code = cl.course_code
            WHERE cl.`{$clProgCol}` = ?
              AND cl.`{$clYearCol}` = ?
              AND cl.`{$clSemCol}` = ?
            ORDER BY cl.course_code";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('sss', $testProgram, $testYear, $testSem);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $availableCourses[] = $row;
    }
    $stmt->close();
}

echo "   Found " . count($availableCourses) . " available courses in course_levels for program=$testProgram:\n";
foreach ($availableCourses as $c) {
    echo "   - {$c['course_code']}: {$c['course_name']} ({$c['credits']} credits)\n";
}

// ============================================
// Method 4: RegistrationDataService method
// ============================================
echo "\n5. Testing RegistrationDataService.getRegisteredCourses()...\n";

$serviceCourses = $regService->getRegisteredCourses($testSid, (int)$testYear, (int)$testSem);

echo "   Found " . count($serviceCourses) . " courses via RegistrationDataService:\n";
foreach ($serviceCourses as $c) {
    echo "   - {$c['course_code']}: " . ($c['course_name'] ?? 'N/A') . " (" . ($c['credit_hours'] ?? 3) . " credits)\n";
}

// ============================================
// COMPARISON
// ============================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "COMPARISON RESULTS\n";
echo str_repeat('=', 60) . "\n\n";

// Extract course codes for comparison
$regCodes = array_column($registrationCourses, 'course_code');
$myCodes = array_column($myCoursesCourses, 'course_code');
$svcCodes = array_column($serviceCourses, 'course_code');

sort($regCodes);
sort($myCodes);
sort($svcCodes);

echo "Course codes by source:\n";
echo "  registration.php: " . implode(', ', $regCodes) . "\n";
echo "  myCourses.php:    " . implode(', ', $myCodes) . "\n";
echo "  DataService:      " . implode(', ', $svcCodes) . "\n\n";

// Check consistency
$allMatch = ($regCodes === $myCodes) && ($myCodes === $svcCodes);

if ($allMatch) {
    echo "✓ All methods return IDENTICAL courses!\n";
} else {
    echo "⚠ MISMATCH detected between methods:\n";
    
    // Find differences
    $regOnly = array_diff($regCodes, $myCodes);
    $myOnly = array_diff($myCodes, $regCodes);
    $svcDiff = array_diff($svcCodes, $regCodes);
    
    if (!empty($regOnly)) echo "  Only in registration.php: " . implode(', ', $regOnly) . "\n";
    if (!empty($myOnly)) echo "  Only in myCourses.php: " . implode(', ', $myOnly) . "\n";
    if (!empty($svcDiff)) echo "  Different in DataService: " . implode(', ', $svcDiff) . "\n";
}

// Check credit consistency
echo "\nCredit hour consistency:\n";
$creditIssues = [];

foreach ($registrationCourses as $c) {
    $code = $c['course_code'];
    $credits = (int)$c['credits'];
    
    // Find matching in myCourses
    foreach ($myCoursesCourses as $mc) {
        if ($mc['course_code'] === $code && (int)$mc['credits'] !== $credits) {
            $creditIssues[] = "$code: reg={$credits}, myCourses={$mc['credits']}";
        }
    }
}

if (empty($creditIssues)) {
    echo "  ✓ Credit hours match across all methods\n";
} else {
    echo "  ⚠ Credit mismatches:\n";
    foreach ($creditIssues as $issue) {
        echo "    - $issue\n";
    }
}

// Check course_levels vs registered (courseReg completeness)
echo "\nCourse registration completeness:\n";
$availCodes = array_column($availableCourses, 'course_code');
$notRegistered = array_diff($availCodes, $regCodes);
$extraRegistered = array_diff($regCodes, $availCodes);

if (empty($notRegistered) && empty($extraRegistered)) {
    echo "  ✓ All available courses are registered\n";
} else {
    if (!empty($notRegistered)) {
        echo "  Available but NOT registered: " . implode(', ', $notRegistered) . "\n";
    }
    if (!empty($extraRegistered)) {
        echo "  Registered but NOT in course_levels: " . implode(', ', $extraRegistered) . "\n";
        echo "    (This may be OK - courses could be from other programs or electives)\n";
    }
}

// Summary
echo "\n" . str_repeat('=', 60) . "\n";
echo "SUMMARY\n";
echo str_repeat('=', 60) . "\n";

$issues = [];
if (!$allMatch) $issues[] = "Course lists don't match between pages";
if (!empty($creditIssues)) $issues[] = "Credit hour inconsistencies";

if (empty($issues)) {
    echo "✓ All checks passed! Courses display consistently across all pages.\n";
} else {
    echo "⚠ Issues found:\n";
    foreach ($issues as $i) echo "  - $i\n";
}

echo "\n=== Test Complete ===\n";
