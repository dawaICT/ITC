<?php
/**
 * Live E2E test of the registration→login pipeline.
 *
 *  Stage 1 (backend): register a brand-new student through the REAL admissions
 *           handler (handleNewStudentRegistration) — committed, then cleaned up.
 *  Stage 2 (HTTP): sign in on the real login endpoint with SID + NRC.
 *  Stage 3 (HTTP): verify the forced password change, set a new password.
 *  Stage 4 (HTTP): confirm dashboard access and re-login with the new password.
 *  Stage 5: clean up every row the test created.
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../admissions/includes/registration_handlers.php';

$BASE = 'http://localhost/wucportal';
$pass = 0; $fail = 0;
function ok(bool $cond, string $label) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [OK]   $label\n"; }
    else { $fail++; echo "  [FAIL] $label\n"; }
}

$nrc = '987654/88/1';
$email = 'e2e.regtest@example.test';
$phone = '+260971112233';

// Pre-clean any leftovers from a previous run
$db->query("DELETE FROM student_login WHERE Sid IN (SELECT SID FROM students WHERE email = '$email')");
$db->query("DELETE FROM student_program WHERE Sid IN (SELECT SID FROM students WHERE email = '$email')");
$db->query("DELETE FROM invoices WHERE student_id IN (SELECT SID FROM students WHERE email = '$email')");
$db->query("DELETE FROM students WHERE email = '$email'");

// ── Stage 1: real registration handler ─────────────────────────────────────
$input = [
    'fname' => 'Endtoend', 'lname' => 'Tester', 'gender' => 'M', 'dob' => '2002-03-15',
    'program' => 'TEST-PROG', 'email' => $email, 'phone' => $phone, 'nrc' => $nrc,
    'semester' => '1', 'entry_year' => date('Y'), 'mode' => 'Full-time', 'sponsor' => 'Self',
    'nok_fname' => 'Next', 'nok_lname' => 'Kin', 'nok_relationship' => 'Parent', 'nok_phone' => '+260977654321',
];
$result = handleNewStudentRegistration($db, $input, []);
ok(!empty($result['success']), 'registration handler succeeded: ' . ($result['message'] ?? ''));
$sid = $result['student_id'] ?? '';
ok($sid !== '' && preg_match('/^ITC\d{2}T[1-3]\d{4}(\d{2})?$/', $sid), "generated SID is valid format: $sid");

// Verify all supporting rows
$q = fn(string $sql) => $db->query($sql)->fetch_assoc();
ok((bool)$q("SELECT 1 ok FROM students WHERE SID = '$sid' AND status = 'active'"), 'students row exists, status=active');
ok((bool)$q("SELECT 1 ok FROM student_program WHERE Sid = '$sid' AND status = 'active'"), 'student_program row exists');
$loginRow = $q("SELECT Password, must_change_password FROM student_login WHERE Sid = '$sid'");
ok((bool)$loginRow, 'student_login row exists');
ok($loginRow && password_verify($nrc, $loginRow['Password']), 'initial password = NRC verifies');
ok($loginRow && (int)$loginRow['must_change_password'] === 1, 'must_change_password flag set');

// ── HTTP helpers ────────────────────────────────────────────────────────────
$cookieJar = tempnam(sys_get_temp_dir(), 'e2e_cookies_');
function httpReq(string $url, ?array $post = null, string $cookieJar = '') {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr((string)$raw, 0, $headerSize);
    $body = substr((string)$raw, $headerSize);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) { $location = trim($m[1]); }
    return ['code' => $code, 'location' => $location, 'body' => $body];
}
function extractCsrf(string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

// ── Stage 2: HTTP login with SID + NRC ──────────────────────────────────────
$page = httpReq("$BASE/student_login.php", null, $cookieJar);
ok($page['code'] === 200, 'GET student_login.php → 200');
$csrf = extractCsrf($page['body']);
ok($csrf !== '', 'login page exposes CSRF token');

$login = httpReq("$BASE/studentLogin.php", [
    'csrf_token' => $csrf, 'login' => '1', 'Sid' => $sid, 'Password' => $nrc,
], $cookieJar);
ok(in_array($login['code'], [302, 303], true), 'POST studentLogin.php redirects (' . $login['code'] . ')');
ok(strpos($login['location'], 'students/change_password.php') !== false,
   'first login is routed to forced password change (got: ' . $login['location'] . ')');

// Guard really blocks other student pages while flag set
$dash = httpReq("$BASE/students/index.php", null, $cookieJar);
ok(in_array($dash['code'], [302, 303], true) && strpos($dash['location'], 'change_password.php') !== false,
   'dashboard is blocked until password is changed');

// ── Stage 3: forced password change ─────────────────────────────────────────
$cpPage = httpReq("$BASE/students/change_password.php", null, $cookieJar);
ok($cpPage['code'] === 200, 'GET change_password.php → 200');
$cpCsrf = extractCsrf($cpPage['body']);
ok($cpCsrf !== '', 'change-password page exposes CSRF token');

$newPw = 'E2eStrongPass!42';
$cp = httpReq("$BASE/students/change_password.php", [
    'csrf_token' => $cpCsrf, 'current_password' => $nrc,
    'new_password' => $newPw, 'confirm_password' => $newPw,
], $cookieJar);
ok(in_array($cp['code'], [302, 303], true) && strpos($cp['location'], 'index.php') !== false,
   'password change accepted, redirected to dashboard');

// ── Stage 4: dashboard access + re-login with the new password ──────────────
$dash2 = httpReq("$BASE/students/index.php", null, $cookieJar);
ok($dash2['code'] === 200, 'GET students/index.php → 200 after change');
ok(stripos($dash2['body'], 'Endtoend') !== false || stripos($dash2['body'], $sid) !== false,
   'dashboard shows the logged-in student');

// Fresh session: old NRC password must fail, new one must go straight in
$jar2 = tempnam(sys_get_temp_dir(), 'e2e_cookies2_');
$page2 = httpReq("$BASE/student_login.php", null, $jar2);
$csrf2 = extractCsrf($page2['body']);
$loginOld = httpReq("$BASE/studentLogin.php", ['csrf_token' => $csrf2, 'login' => '1', 'Sid' => $sid, 'Password' => $nrc], $jar2);
ok(strpos($loginOld['location'], 'student_login.php') !== false, 'old NRC password is rejected after change');

$page3 = httpReq("$BASE/student_login.php", null, $jar2);
$csrf3 = extractCsrf($page3['body']);
$loginNew = httpReq("$BASE/studentLogin.php", ['csrf_token' => $csrf3, 'login' => '1', 'Sid' => $sid, 'Password' => $newPw], $jar2);
ok(strpos($loginNew['location'], 'students/index.php') !== false,
   'new password signs straight in to the dashboard (got: ' . $loginNew['location'] . ')');

// ── Stage 5: cleanup ────────────────────────────────────────────────────────
$db->query("DELETE FROM student_login WHERE Sid = '$sid'");
$db->query("DELETE FROM student_program WHERE Sid = '$sid'");
$db->query("DELETE FROM invoices WHERE student_id = '$sid'");
$db->query("DELETE FROM login_activity WHERE user_id = '$sid'");
$db->query("DELETE FROM login_attempts WHERE user_id LIKE '%$sid%'");
$db->query("DELETE FROM students WHERE SID = '$sid'");
$left = $db->query("SELECT COUNT(*) c FROM students WHERE SID = '$sid'")->fetch_assoc()['c'];
ok((int)$left === 0, 'test data cleaned up');
@unlink($cookieJar); @unlink($jar2);

echo "\nResult: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
