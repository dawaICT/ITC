<?php
/**
 * Live E2E test of the SHORT-COURSE enrollment→login pipeline.
 *
 *  Stage 1: create + enroll a brand-new short-course student through the REAL
 *           shared handler (sc_run_enrollment_action 'create_enroll').
 *  Stage 2: enroll an EXISTING credential-less student ('enroll_student') and
 *           confirm a login row is created for them too.
 *  Stage 3 (HTTP): sign in as the new student with SID + NRC, complete the
 *           forced password change, reach the dashboard and the short-course page.
 *  Stage 4: clean up everything.
 *
 * Run:  E:\xampp\php\php.exe scripts\e2e_short_course_login_test.php
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/short_course_actions.php';

$BASE = 'http://localhost/wucportal';
$pass = 0; $fail = 0;
function ok(bool $cond, string $label) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [OK]   $label\n"; }
    else { $fail++; echo "  [FAIL] $label\n"; }
}
function runAction(mysqli $db, string $action, array $post): array {
    $_POST = $post; $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    sc_run_enrollment_action($db, $action, 'E2E-TEST');
    $out = ob_get_clean();
    return json_decode($out, true) ?: ['success' => false, 'message' => 'non-JSON: ' . $out];
}

$nrc = '112233/44/1';
$nrc2 = '223344/55/1';
$email = 'sc.e2e@example.test';

// A live active short course to enroll into
$course = $db->query("SELECT id FROM short_courses WHERE status = 'active' ORDER BY id LIMIT 1")->fetch_assoc();
if (!$course) { echo "No active short course available — aborting.\n"; exit(1); }
$courseId = (int)$course['id'];

// Pre-clean leftovers
foreach ([$nrc, $nrc2] as $n) {
    $db->query("DELETE FROM short_course_enrollments WHERE student_id IN (SELECT SID FROM students WHERE nrc_pass = '$n')");
    $db->query("DELETE FROM student_login WHERE Sid IN (SELECT SID FROM students WHERE nrc_pass = '$n')");
    $db->query("DELETE FROM students WHERE nrc_pass = '$n'");
}

// ── Stage 1: create + enroll a new short-course student ─────────────────────
$res = runAction($db, 'create_enroll', [
    'course_id' => $courseId, 'fname' => 'Shortcourse', 'lname' => 'Tester',
    'sex' => 'F', 'nrc_pass' => $nrc, 'mobile' => '+260966001122',
    'email' => $email, 'dob' => '2000-01-10', 'notes' => 'E2E test',
]);
ok(!empty($res['success']), 'create_enroll succeeded: ' . ($res['message'] ?? ''));
$sid = $res['student_id'] ?? '';
ok($sid !== '' && preg_match('/^ITC\d{2}T[1-3]\d{4}(\d{2})?$/', $sid), "generated SID is valid ITC format: $sid");
ok(($res['default_password'] ?? '') === $nrc, 'response reports NRC as the initial password');

$q = fn(string $sql) => $db->query($sql)->fetch_assoc();
ok((bool)$q("SELECT 1 ok FROM students WHERE SID = '$sid' AND status = 'active'"), 'students row exists, status=active');
ok((bool)$q("SELECT 1 ok FROM short_course_enrollments WHERE student_id = '$sid' AND short_course_id = $courseId"), 'short_course_enrollments row exists');
$loginRow = $q("SELECT Password, must_change_password FROM student_login WHERE Sid = '$sid'");
ok((bool)$loginRow, 'student_login row exists');
ok($loginRow && password_verify($nrc, $loginRow['Password']), 'initial password = NRC verifies');
ok($loginRow && (int)$loginRow['must_change_password'] === 1, 'must_change_password flag set');

// ── Stage 2: enroll an EXISTING credential-less student ─────────────────────
$db->query("INSERT INTO students (SID, Fname, Lname, sex, nrc_pass, email, status, dte_adm)
            VALUES ('ITC26T13344', 'Legacy', 'NoLogin', 'M', '$nrc2', 'sc.e2e2@example.test', 'active', NOW())");
$res2 = runAction($db, 'enroll_student', [
    'course_id' => $courseId, 'student_id' => 'ITC26T13344', 'notes' => 'E2E legacy',
]);
ok(!empty($res2['success']), 'enroll_student (existing student) succeeded: ' . ($res2['message'] ?? ''));
$login2 = $q("SELECT Password, must_change_password FROM student_login WHERE Sid = 'ITC26T13344'");
ok((bool)$login2, 'login row was backfilled for the credential-less existing student');
ok($login2 && password_verify($nrc2, $login2['Password']), 'backfilled password = NRC verifies');

// ── Stage 3: HTTP login as the new short-course student ─────────────────────
$cookieJar = tempnam(sys_get_temp_dir(), 'sc_e2e_');
function httpReq(string $url, ?array $post, string $cookieJar) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr((string)$raw, 0, $hs);
    $loc = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';
    return ['code' => $code, 'location' => $loc, 'body' => substr((string)$raw, $hs)];
}
$csrfOf = fn(string $html) => preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';

$page = httpReq("$BASE/student_login.php", null, $cookieJar);
$login = httpReq("$BASE/studentLogin.php", ['csrf_token' => $csrfOf($page['body']), 'login' => '1', 'Sid' => $sid, 'Password' => $nrc], $cookieJar);
ok(strpos($login['location'], 'students/change_password.php') !== false, 'first login routed to forced password change');

$cpPage = httpReq("$BASE/students/change_password.php", null, $cookieJar);
$newPw = 'ScStrongPass!77';
$cp = httpReq("$BASE/students/change_password.php", [
    'csrf_token' => $csrfOf($cpPage['body']), 'current_password' => $nrc,
    'new_password' => $newPw, 'confirm_password' => $newPw,
], $cookieJar);
ok(in_array($cp['code'], [302, 303], true) && strpos($cp['location'], 'index.php') !== false, 'password change accepted');

$dash = httpReq("$BASE/students/index.php", null, $cookieJar);
ok($dash['code'] === 200, 'dashboard loads (200) for short-course-only student');
ok(stripos($dash['body'], 'Shortcourse') !== false || stripos($dash['body'], $sid) !== false, 'dashboard shows the student');
$scPage = httpReq("$BASE/students/short_courses.php", null, $cookieJar);
ok($scPage['code'] === 200, 'students/short_courses.php loads (200)');
ok(stripos($scPage['body'], 'Fatal error') === false && stripos($scPage['body'], 'Warning:') === false,
   'short-course page renders without PHP errors');

// ── Stage 4: cleanup ─────────────────────────────────────────────────────────
foreach ([$sid, 'ITC26T13344'] as $s) {
    $db->query("DELETE FROM short_course_enrollments WHERE student_id = '$s'");
    $db->query("DELETE FROM student_login WHERE Sid = '$s'");
    $db->query("DELETE FROM invoices WHERE student_id = '$s'");
    $db->query("DELETE FROM login_activity WHERE user_id = '$s'");
    $db->query("DELETE FROM students WHERE SID = '$s'");
}
$left = (int)$db->query("SELECT COUNT(*) c FROM students WHERE nrc_pass IN ('$nrc','$nrc2')")->fetch_assoc()['c'];
ok($left === 0, 'test data cleaned up');
@unlink($cookieJar);

echo "\nResult: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
