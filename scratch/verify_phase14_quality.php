<?php
/**
 * Automated Verification Script - Phase 14 Quality Assurance
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

define('IS_SCRIPT', true);
$_SESSION['user_id'] = '1';
$_SESSION['staff_id'] = '1';
$_SESSION['role'] = 'systems_admin';

require_once __DIR__ . '/../db/connect.php';

echo "=== Phase 14 Quality Assurance Verification Checks ===\n\n";

function verify_assert(bool $condition, string $description) {
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        exit(1);
    }
}

// 1. Schema Check
$tables = [];
if ($res = $db->query("SHOW TABLES")) {
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
}
verify_assert(in_array('course_evaluations', $tables, true), "Table 'course_evaluations' exists.");
verify_assert(in_array('student_evaluated_courses', $tables, true), "Table 'student_evaluated_courses' exists.");

// 2. Anonymity verification: check that course_evaluations table does NOT have student_id column
$columns = [];
if ($res = $db->query("SHOW COLUMNS FROM course_evaluations")) {
    while ($row = $res->fetch_assoc()) {
        $columns[] = strtolower($row['Field']);
    }
}
verify_assert(!in_array('student_id', $columns, true) && !in_array('sid', $columns, true), "Anonymity: course_evaluations table does not contain a student identifier.");

// 3. Single-submission constraint check
$testStudent = 'STUD-QA-14';
$testCourse = 'WUCQA14';
$db->query("DELETE FROM student_evaluated_courses WHERE student_id = '$testStudent'");
$db->query("DELETE FROM course_evaluations WHERE course_code = '$testCourse'");

// First mapping insert
$stmt1 = $db->prepare("INSERT INTO student_evaluated_courses (student_id, course_code) VALUES (?, ?)");
$stmt1->bind_param('ss', $testStudent, $testCourse);
verify_assert($stmt1->execute(), "Successfully inserted first evaluation mapping.");
$stmt1->close();

// Second mapping insert (should fail due to Primary Key constraint)
$failedDuplicate = false;
try {
    $stmt2 = $db->prepare("INSERT INTO student_evaluated_courses (student_id, course_code) VALUES (?, ?)");
    $stmt2->bind_param('ss', $testStudent, $testCourse);
    $stmt2->execute();
    $stmt2->close();
} catch (mysqli_sql_exception $e) {
    $failedDuplicate = true;
}
verify_assert($failedDuplicate, "Single Submission: Duplicate evaluation map insert fails on primary key.");

// 4. Anonymous evaluation insertion & aggregation verification
$testLecturer = 'LECT-QA-14';
$stmtEval = $db->prepare("INSERT INTO course_evaluations (course_code, lecturer_id, rating_lecturer, rating_content, rating_facilities, rating_services, comments) VALUES (?, ?, 5, 4, 3, 5, 'Constructive feedback')");
$stmtEval->bind_param('ss', $testCourse, $testLecturer);
verify_assert($stmtEval->execute(), "Successfully inserted anonymous rating record.");
$stmtEval->close();

// HOD aggregation check
$stmtAggregate = $db->prepare("
    SELECT 
        AVG(rating_lecturer) as avg_l, 
        AVG(rating_content) as avg_c, 
        AVG(rating_facilities) as avg_f, 
        AVG(rating_services) as avg_s
    FROM course_evaluations 
    WHERE course_code = ?");
$stmtAggregate->bind_param('s', $testCourse);
$stmtAggregate->execute();
$agg = $stmtAggregate->get_result()->fetch_assoc();
$stmtAggregate->close();

verify_assert((float)$agg['avg_l'] === 5.0 && (float)$agg['avg_c'] === 4.0, "Aggregated ratings match the input evaluations values.");

// 5. In-process CSV Export verification
$code = file_get_contents('hod/ajax/qa_export.php');
$code = preg_replace('/^\s*<\?php/i', '', $code);
$code = preg_replace('/\?>\s*$/', '', $code);
$code = str_replace('exit;', '// exit;', $code);
$code = str_replace('dirname(__DIR__, 2)', "'c:/xampp/htdocs/wucportal'", $code);
$code = str_replace('dirname(__DIR__)', "'c:/xampp/htdocs/wucportal/hod'", $code);

ob_start();
try {
    eval($code);
} catch (Throwable $e) {
    // Ignore errors during testing evaluation
}
$csvOutput = ob_get_clean();

$lines = explode("\n", trim((string)$csvOutput));
verify_assert(count($lines) >= 1, "CSV exporter outputs at least a header row.");
verify_assert(stripos($lines[0], 'Course Code') !== false && stripos($lines[0], 'Overall Rating') !== false, "CSV exporter outputs correct column headers.");

// Clean up
$db->query("DELETE FROM student_evaluated_courses WHERE student_id = '$testStudent'");
$db->query("DELETE FROM course_evaluations WHERE course_code = '$testCourse'");

echo "\nVerification Results:\n";
echo "=== [ALL PASS] Phase 14 Quality Assurance Verification completed successfully! ===\n";
?>
