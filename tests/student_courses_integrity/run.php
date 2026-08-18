<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db/connect.php';

$passed = 0;
$failed = 0;
$suffix = strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
$fixtureSid = 'SCM-' . $suffix;
$fixtureCourse = 'SC-' . substr($suffix, 0, 8);

function check(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo '[PASS] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
        return;
    }
    $failed++;
    echo '[FAIL] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
}

function source(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $contents;
}

$admissions = source($root . '/admissions/includes/registration_handlers.php');
$paymentHelpers = source($root . '/includes/payment_helpers.php');
$legacyCourseRegistration = source($root . '/students/processCourseReg.php');
$migration = source($root . '/migrations/20260717_student_courses_integrity.php');

check(strpos($admissions, "'status' => 'registered'") !== false, 'admissions writes a valid legacy course status');
check(strpos($admissions, "'status' => 'active'") === false, 'admissions no longer produces blank enum statuses');
check(strpos($paymentHelpers, 'INSERT IGNORE INTO student_courses') !== false, 'payment activation remains idempotent under mirror uniqueness');
check(strpos($legacyCourseRegistration, 'INSERT IGNORE INTO student_courses') !== false, 'legacy course synchronization remains idempotent under mirror uniqueness');
check(strpos($migration, 'student_courses_duplicate_archive_20260717') !== false, 'migration preserves removed duplicate rows in an audit archive');
check(strpos($migration, 'uniq_student_courses_period') !== false, 'migration enforces the mirror natural key');
check(strpos($migration, 'chk_student_courses_status_valid') !== false, 'migration rejects future invalid statuses');

$duplicateGroups = (int)($db->query(
    "SELECT COUNT(*) AS total FROM (
        SELECT student_id, course_code, academic_year, semester
          FROM student_courses
         GROUP BY student_id, course_code, academic_year, semester
        HAVING COUNT(*) > 1
    ) duplicates"
)->fetch_assoc()['total'] ?? 0);
check($duplicateGroups === 0, 'live student_courses has no duplicate natural keys');

$invalidStatuses = (int)($db->query(
    "SELECT COUNT(*) AS total FROM student_courses WHERE status IS NULL OR status = ''"
)->fetch_assoc()['total'] ?? 0);
check($invalidStatuses === 0, 'live student_courses has no blank/null enum status');

$indexRows = $db->query(
    "SHOW INDEX FROM student_courses WHERE Key_name = 'uniq_student_courses_period'"
)->fetch_all(MYSQLI_ASSOC);
$indexColumns = array_map(static fn(array $row): string => (string)$row['Column_name'], $indexRows);
check(
    $indexColumns === ['student_id', 'course_code', 'academic_year', 'semester'],
    'live mirror unique key has the intended columns',
    implode(',', $indexColumns)
);

$constraintStmt = $db->prepare(
    "SELECT COUNT(*) AS total FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'student_courses'
        AND CONSTRAINT_NAME = 'chk_student_courses_status_valid'
        AND CONSTRAINT_TYPE = 'CHECK'"
);
$constraintStmt->execute();
$hasStatusCheck = (int)($constraintStmt->get_result()->fetch_assoc()['total'] ?? 0) === 1;
$constraintStmt->close();
check($hasStatusCheck, 'live mirror status check is installed');

$archiveTable = $db->query("SHOW TABLES LIKE 'student_courses_duplicate_archive_20260717'");
$archiveExists = $archiveTable && $archiveTable->num_rows === 1;
if ($archiveTable) {
    $archiveTable->free();
}
check($archiveExists, 'duplicate archive table exists');
$archiveRows = $archiveExists
    ? (int)($db->query('SELECT COUNT(*) AS total FROM student_courses_duplicate_archive_20260717')->fetch_assoc()['total'] ?? 0)
    : 0;
check($archiveRows >= 17, 'all historical duplicate copies remain auditable', 'rows=' . $archiveRows);

$duplicateBlocked = false;
$invalidStatusBlocked = false;
try {
    $db->begin_transaction();
    $stmt = $db->prepare(
        "INSERT INTO student_courses (student_id, course_code, academic_year, semester, status)
         VALUES (?, ?, '2098', 4, 'registered')"
    );
    $stmt->bind_param('ss', $fixtureSid, $fixtureCourse);
    $stmt->execute();
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $duplicateBlocked = (int)$e->getCode() === 1062;
    }
    $stmt->close();

    try {
        $invalid = $db->prepare(
            "INSERT INTO student_courses (student_id, course_code, academic_year, semester, status)
             VALUES (?, 'INVALID-STATUS', '2098', 4, 'active')"
        );
        $invalid->bind_param('s', $fixtureSid);
        $invalid->execute();
        $invalid->close();
    } catch (mysqli_sql_exception $e) {
        $invalidStatusBlocked = true;
    }
    $db->rollback();
} catch (Throwable $e) {
    try { $db->rollback(); } catch (Throwable $ignored) {}
}
check($duplicateBlocked, 'database blocks a racing duplicate legacy mirror row');
check($invalidStatusBlocked, 'database blocks an invalid legacy mirror status');

$stmt = $db->prepare('SELECT COUNT(*) AS total FROM student_courses WHERE student_id = ?');
$stmt->bind_param('s', $fixtureSid);
$stmt->execute();
$residue = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
check($residue === 0, 'legacy mirror integrity fixtures leave no residue');

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
