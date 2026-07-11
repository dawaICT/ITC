<?php
/**
 * HTTP end-to-end smoke test for login + password reset pages.
 */
require_once __DIR__ . '/../includes/auth_helpers.php';
require_once __DIR__ . '/../db/connect.php';

$base = 'http://localhost/wucportal/';
$staffId = 'ITC900';
$staffNrc = '900000/00/1';
$staffOrig = 'Test@12345';
$staffNew = 'ResetStaff1!';

$studentId = 'CSE26456789';
$studentNrc = '123456/78/9';
$studentOrig = 'Student@12345';
$studentNew = 'ResetStudent1!';

$ok = true;
function pass(string $m): void { echo "PASS: {$m}\n"; }
function fail(string $m): void { global $ok; $ok = false; echo "FAIL: {$m}\n"; }

function http_request(string $url, array $opts = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $opts['cookie'] ?? '',
        CURLOPT_COOKIEFILE => $opts['cookie'] ?? '',
        CURLOPT_TIMEOUT => 30,
    ] + ($opts['curl'] ?? []));
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['code' => 0, 'body' => '', 'headers' => '', 'error' => $err];
    }
    $headerSize = strpos($raw, "\r\n\r\n");
    $headers = $headerSize !== false ? substr($raw, 0, $headerSize) : '';
    $body = $headerSize !== false ? substr($raw, $headerSize + 4) : $raw;
    return ['code' => $code, 'body' => $body, 'headers' => $headers, 'error' => ''];
}

function extract_csrf(string $html): ?string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

function post_form(string $url, array $fields, string $cookieFile): array {
    return http_request($url, [
        'cookie' => $cookieFile,
        'curl' => [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ],
    ]);
}

function login_staff(string $id, string $pass, string $cookieFile): bool {
    $get = http_request($GLOBALS['base'] . 'staff_login.php', ['cookie' => $cookieFile]);
    $csrf = extract_csrf($get['body']);
    if (!$csrf) {
        fail('staff login page missing csrf');
        return false;
    }
    $post = post_form($GLOBALS['base'] . 'staff_login.php', [
        'csrf_token' => $csrf,
        'user_id' => $id,
        'password' => $pass,
    ], $cookieFile);
    if (!in_array($post['code'], [303, 302], true)) {
        fail("staff login POST returned HTTP {$post['code']}");
        return false;
    }
    if (!preg_match('/^Location:\s*(.+)$/mi', $post['headers'], $loc)) {
        fail('staff login missing redirect');
        return false;
    }
    $dest = trim($loc[1]);
    if (stripos($dest, 'staff_login.php') !== false) {
        fail("staff login failed for password attempt");
        return false;
    }
    return true;
}

function reset_staff(string $id, string $nrc, string $new, string $cookieFile): bool {
    $get = http_request($GLOBALS['base'] . 'staff_forgot_password.php', ['cookie' => $cookieFile]);
    $csrf = extract_csrf($get['body']);
    if (!$csrf) {
        fail('staff reset page missing csrf');
        return false;
    }
    $post = post_form($GLOBALS['base'] . 'staff_forgot_password.php', [
        'csrf_token' => $csrf,
        'user_id' => $id,
        'nrc_pass' => $nrc,
        'new_password' => $new,
        'confirm_password' => $new,
    ], $cookieFile);
    if (!in_array($post['code'], [303, 302], true)) {
        fail("staff reset POST returned HTTP {$post['code']}");
        return false;
    }
    return true;
}

function login_student(string $id, string $pass, string $cookieFile): bool {
    $get = http_request($GLOBALS['base'] . 'student_login.php', ['cookie' => $cookieFile]);
    $csrf = extract_csrf($get['body']);
    if (!$csrf) {
        fail('student login page missing csrf');
        return false;
    }
    $post = post_form($GLOBALS['base'] . 'student_login.php', [
        'csrf_token' => $csrf,
        'login' => '1',
        'Sid' => $id,
        'Password' => $pass,
    ], $cookieFile);
    if (!in_array($post['code'], [303, 302], true)) {
        fail("student login POST returned HTTP {$post['code']}");
        return false;
    }
    if (!preg_match('/^Location:\s*(.+)$/mi', $post['headers'], $loc)) {
        fail('student login missing redirect');
        return false;
    }
    $dest = trim($loc[1]);
    if (stripos($dest, 'student_login.php') !== false) {
        fail('student login failed for password attempt');
        return false;
    }
    return true;
}

function reset_student(string $id, string $nrc, string $new, string $cookieFile): bool {
    $get = http_request($GLOBALS['base'] . 'studentPasswordReset.php', ['cookie' => $cookieFile]);
    $csrf = extract_csrf($get['body']);
    if (!$csrf) {
        fail('student reset page missing csrf');
        return false;
    }
    $post = post_form($GLOBALS['base'] . 'studentPasswordReset.php', [
        'csrf_token' => $csrf,
        'Sid' => $id,
        'nrc_pass' => $nrc,
        'new_password' => $new,
        'confirm_password' => $new,
    ], $cookieFile);
    if (!in_array($post['code'], [303, 302], true)) {
        fail("student reset POST returned HTTP {$post['code']}");
        return false;
    }
    return true;
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wuc_auth_test_' . getmypid() . '.cookie';

// Staff flow
if (login_staff($staffId, $staffOrig, $tmp)) {
    pass('staff login with original password');
} 
@unlink($tmp);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wuc_auth_test_' . getmypid() . '_2.cookie';

if (reset_staff($staffId, $staffNrc, $staffNew, $tmp)) {
    pass('staff password reset POST accepted');
}
if (login_staff($staffId, $staffNew, $tmp)) {
    pass('staff login works after password reset');
} else {
    fail('staff login after reset');
}
@unlink($tmp);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wuc_auth_test_' . getmypid() . '_3.cookie';
if (reset_staff($staffId, $staffNrc, $staffOrig, $tmp)) {
    pass('staff password restored to original');
}

// Student flow
@unlink($tmp);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wuc_auth_test_' . getmypid() . '_4.cookie';
if (login_student($studentId, $studentOrig, $tmp)) {
    pass('student login with original password');
}
@unlink($tmp);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wuc_auth_test_' . getmypid() . '_5.cookie';
if (reset_student($studentId, $studentNrc, $studentNew, $tmp)) {
    pass('student password reset POST accepted');
}
if (login_student($studentId, $studentNew, $tmp)) {
    pass('student login works after password reset');
} else {
    fail('student login after reset');
}
@unlink($tmp);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wuc_auth_test_' . getmypid() . '_6.cookie';
if (reset_student($studentId, $studentNrc, $studentOrig, $tmp)) {
    pass('student password restored to original');
}
@unlink($tmp);

if (!$ok) {
    exit(1);
}
echo "\nAll HTTP auth flow checks passed.\n";
