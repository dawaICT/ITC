<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db/connect.php';

$passed = 0;
$failed = 0;
$suffix = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
$fixtureSid = 'ATOMIC-' . $suffix;
$fixtureCourse = 'ATM-' . substr($suffix, 0, 8);
$fixtureYear = '2098';
$sessionId = 'codexatomic' . strtolower(bin2hex(random_bytes(8)));

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_id($sessionId);
session_start();
$_SESSION['Sid'] = 'CSE26456789';
$_SESSION['user_role'] = 'student';
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
session_write_close();

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

function httpGet(string $url, ?string $sessionId = null): array
{
    $headers = [];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    if ($sessionId !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sessionId);
    }
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$headers): int {
        $length = strlen($line);
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $length;
    });
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => $error];
}

$workflow = source($root . '/students/includes/StudentAcademicWorkflowService.php');
$registrationData = source($root . '/students/includes/RegistrationDataService.php');
$legacyController = source($root . '/students/process_registration.php');
$legacyGetController = source($root . '/students/process.php');
$canonicalPage = source($root . '/students/registration.php');
$migration = source($root . '/migrations/20260717_registration_atomicity.php');

$beginPos = strpos($workflow, '$this->db->begin_transaction();', strpos($workflow, 'function registerStudentForPeriod'));
$createPos = strpos($workflow, '$this->regData->createRegistration', $beginPos ?: 0);
$enrolPos = strpos($workflow, '$this->autoEnrolCourses', $createPos ?: 0);
$commitPos = strpos($workflow, '$this->db->commit();', $enrolPos ?: 0);
check($beginPos !== false && $createPos > $beginPos && $enrolPos > $createPos && $commitPos > $enrolPos, 'canonical registration and course enrolment share one transaction');
check(strpos($workflow, '$this->db->rollback();', $commitPos ?: 0) !== false, 'canonical registration rolls back on downstream failure');
check(strpos($workflow, "implode(' AND ', \$match)") !== false, 'course reuse matches year of study and academic year together');
check(strpos($workflow, 'ON DUPLICATE KEY UPDATE') !== false, 'course enrolment insert is race-safe and idempotent');
check(strpos($registrationData, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)') !== false, 'period registration insert is race-safe and idempotent');
check(strpos($legacyController, 'http_response_code(410)') !== false, 'parallel legacy registration controller is retired');
check(stripos($legacyController, 'INSERT INTO') === false && stripos($legacyController, 'UPDATE ') === false, 'retired controller has no database mutation');
check(strpos($legacyGetController, "header('Location: /wucportal/students/registration.php', true, 303)") !== false, 'legacy GET registration route redirects to the canonical page');
check(stripos($legacyGetController, 'processRegistration(') === false, 'legacy GET registration route cannot invoke the obsolete engine');
check(strpos($canonicalPage, 'process_registration.php') === false, 'canonical registration page does not call the retired controller');
check(strpos($canonicalPage, 'StudentRegistrationSystem.php') === false, 'canonical registration page no longer imports the obsolete mutation engine');
check(strpos($migration, 'ALTER TABLE semester_registration ENGINE=InnoDB') !== false, 'migration makes the period registration table transactional');
check(strpos($migration, 'uniq_course_registration_student_year') !== false, 'migration installs course-enrolment natural-key uniqueness');

$engineStmt = $db->prepare(
    "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('semester_registration', 'course_registration')"
);
$engineStmt->execute();
$engines = [];
foreach ($engineStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $engines[(string)$row['TABLE_NAME']] = strtoupper((string)$row['ENGINE']);
}
$engineStmt->close();
check(($engines['semester_registration'] ?? '') === 'INNODB', 'live semester_registration writes can roll back');
check(($engines['course_registration'] ?? '') === 'INNODB', 'live course_registration writes can roll back');

$indexRows = $db->query(
    "SHOW INDEX FROM course_registration WHERE Key_name = 'uniq_course_registration_student_year'"
)->fetch_all(MYSQLI_ASSOC);
$indexColumns = array_map(static fn(array $row): string => (string)$row['Column_name'], $indexRows);
check($indexColumns === ['Sid', 'course_code', 'Year', 'academic_year'], 'live course-enrolment unique key has the intended natural columns', implode(',', $indexColumns));

$duplicateGroups = (int)($db->query(
    "SELECT COUNT(*) AS total FROM (
        SELECT Sid, course_code, Year, academic_year
          FROM course_registration
         GROUP BY Sid, course_code, Year, academic_year
        HAVING COUNT(*) > 1
    ) duplicates"
)->fetch_assoc()['total'] ?? 0);
check($duplicateGroups === 0, 'live course registrations contain no duplicate natural keys');

try {
    $db->begin_transaction();
    $semesterInsert = $db->prepare(
        "INSERT INTO semester_registration
            (student_id, SID, program_code, semester, period_type, year_of_study, Year, academic_year,
             registration_status, fee_status)
         VALUES (?, ?, 'ICT-001', '4', 'term', 9, 9, ?, 'registered', 'eligible')"
    );
    $semesterInsert->bind_param('sss', $fixtureSid, $fixtureSid, $fixtureYear);
    $semesterInsert->execute();
    $semesterRegistrationId = (int)$semesterInsert->insert_id;
    $semesterInsert->close();

    $courseInsert = $db->prepare(
        "INSERT INTO course_registration
            (Sid, course_code, semester, Year, academic_year, semester_registration_id, status, is_active)
         VALUES (?, ?, 4, 9, ?, ?, 'registered', 1)"
    );
    $courseInsert->bind_param('sssi', $fixtureSid, $fixtureCourse, $fixtureYear, $semesterRegistrationId);
    $courseInsert->execute();
    $courseInsert->close();
    $db->rollback();

    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM semester_registration WHERE student_id = ? OR SID = ?');
    $stmt->bind_param('ss', $fixtureSid, $fixtureSid);
    $stmt->execute();
    $semesterResidue = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM course_registration WHERE Sid = ?');
    $stmt->bind_param('s', $fixtureSid);
    $stmt->execute();
    $courseResidue = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    check($semesterResidue === 0 && $courseResidue === 0, 'one rollback removes both period and course registration writes');
} catch (Throwable $e) {
    if ($db->errno || $db->thread_id) {
        try { $db->rollback(); } catch (Throwable $ignored) {}
    }
    check(false, 'cross-table rollback fixture completes', $e->getMessage());
}

$duplicateBlocked = false;
try {
    $db->begin_transaction();
    $stmt = $db->prepare(
        "INSERT INTO course_registration (Sid, course_code, semester, Year, academic_year, status, is_active)
         VALUES (?, ?, 1, 9, ?, 'registered', 1)"
    );
    $stmt->bind_param('sss', $fixtureSid, $fixtureCourse, $fixtureYear);
    $stmt->execute();
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $duplicateBlocked = (int)$e->getCode() === 1062;
    }
    $stmt->close();
    $db->rollback();
} catch (Throwable $e) {
    try { $db->rollback(); } catch (Throwable $ignored) {}
}
check($duplicateBlocked, 'database blocks a racing duplicate course enrolment');

$baseUrl = rtrim((string)(getenv('WUC_TEST_BASE_URL') ?: 'http://localhost/wucportal'), '/');
$retired = httpGet($baseUrl . '/students/process_registration.php', $sessionId);
check($retired['status'] === 410, 'authenticated legacy registration request returns Gone', 'status=' . $retired['status']);
check(stripos((string)$retired['body'], 'retired') !== false, 'retired endpoint returns a safe migration message');
$unauthenticated = httpGet($baseUrl . '/students/process_registration.php');
check($unauthenticated['status'] === 302, 'retired endpoint still requires student authentication');
check(($unauthenticated['headers']['location'] ?? '') === '/wucportal/student_login.php', 'unauthenticated retired request returns to student login');
$legacyGet = httpGet($baseUrl . '/students/process.php?id=FORGED&academicYear=2099&term=4', $sessionId);
check($legacyGet['status'] === 303, 'authenticated legacy GET cannot register from query parameters');
check(($legacyGet['headers']['location'] ?? '') === '/wucportal/students/registration.php', 'legacy GET returns to the canonical registration page');
$canonicalResponse = httpGet($baseUrl . '/students/registration.php', $sessionId);
check($canonicalResponse['status'] === 200, 'canonical registration page renders after legacy retirement');
check(
    stripos((string)$canonicalResponse['body'], 'Fatal error') === false
        && stripos((string)$canonicalResponse['body'], 'Unknown column') === false,
    'canonical registration page has no schema fatal'
);

session_id($sessionId);
session_start();
session_destroy();

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
