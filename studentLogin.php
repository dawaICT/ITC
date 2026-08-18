<?php

require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/includes/login_activity_logger.php';
require_once __DIR__ . '/includes/portal_access.php';

const WUC_STUDENT_MAX_LOGIN_ATTEMPTS = 5;
const WUC_STUDENT_LOCKOUT_SECONDS = 900;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    // Use the helper's default 303 (See Other) like every other redirect in this file.
    wuc_redirect('student_login.php');
}

$sid = trim((string) ($_POST['Sid'] ?? ($_POST['student_id'] ?? '')));
$password = (string) ($_POST['Password'] ?? ($_POST['password'] ?? ''));

// Optional post-login destination (e.g. the parallel eLearning login entry point).
// Whitelisted to internal student paths only to avoid open-redirect abuse.
function wuc_student_login_safe_next(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return 'students/index.php';
    }
    // Strip any leading slash so it is always treated as an in-app relative path.
    $raw = ltrim($raw, '/');
    // Reject traversal, schemes, protocol-relative URLs and query/anchor injection.
    if (strpos($raw, '..') !== false || strpos($raw, '://') !== false || strpos($raw, "\\") !== false) {
        return 'students/index.php';
    }
    // Only allow plain internal .php paths under students/.
    if (!preg_match('#^students/[A-Za-z0-9_/-]+\.php$#', $raw)) {
        return 'students/index.php';
    }
    return $raw;
}
$rawNextDest = (string) ($_POST['next'] ?? '');
$hasExplicitNext = trim($rawNextDest) !== '';
$nextDest = wuc_student_login_safe_next($rawNextDest);

// When the attempt originated from the parallel eLearning entry point, error
// redirects must return there (not to the standard portal login) so the user
// keeps the eLearning branding/context and actually sees the flash message.
$fromElearning = $hasExplicitNext && (
    strpos($nextDest, 'students/elearning/') === 0
    || strpos(ltrim(trim($rawNextDest), '/'), 'students/elearning/') === 0
);
$loginReturnPage = $fromElearning ? 'elearning_login.php' : 'student_login.php';

if (wuc_is_login_locked($db, 'student', $sid, WUC_STUDENT_MAX_LOGIN_ATTEMPTS, WUC_STUDENT_LOCKOUT_SECONDS)) {
    $_SESSION['errorMssg'] = 'Too many failed login attempts. Please try again in 15 minutes.';
    wuc_redirect($loginReturnPage);
}

if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
    wuc_record_failed_login($db, 'student', $sid);
    $_SESSION['errorMssg'] = 'Security token mismatch. Please refresh the page and try again.';
    wuc_redirect($loginReturnPage);
}

if ($sid === '' || $password === '') {
    wuc_record_failed_login($db, 'student', $sid);
    $_SESSION['errorMssg'] = 'Please provide both Student ID and Password.';
    wuc_redirect($loginReturnPage);
}

if (!preg_match('/^[A-Za-z0-9_.@-]{3,50}$/', $sid)) {
    wuc_record_failed_login($db, 'student', $sid);
    $_SESSION['errorMssg'] = 'Invalid Student ID or Password.';
    wuc_redirect($loginReturnPage);
}

$stmt = $db->prepare(
    'SELECT u.user_id, u.username, u.password AS user_pass, u.student_id, s.Fname, s.Lname, s.status,
            sl.Password AS login_pass
     FROM users u
     JOIN student_login sl ON sl.Sid = u.student_id
     LEFT JOIN students s ON s.SID = u.student_id
     WHERE u.username = ? AND u.student_id IS NOT NULL
     LIMIT 1'
);

if (!$stmt) {
    error_log('Student login prepare failed: ' . $db->error);
    $_SESSION['errorMssg'] = 'Login service temporarily unavailable. Please try again shortly.';
    wuc_redirect($loginReturnPage);
}

$stmt->bind_param('s', $sid);
$stmt->execute();
$result = $stmt->get_result();
$user = $result ? $result->fetch_assoc() : null;
$stmt->close();

$storedHash = $user['user_pass'] ?? password_hash('dummy-password', PASSWORD_DEFAULT);
[$passwordOk, $needsRehash] = wuc_password_verify_legacy($password, (string) $storedHash);

// Self-heal when student_login.Password was updated but users.password was not.
if (!$passwordOk && !empty($user['login_pass'])) {
    [$legacyOk, $legacyRehash] = wuc_password_verify_legacy($password, (string) $user['login_pass']);
    if ($legacyOk) {
        $passwordOk = true;
        $needsRehash = $legacyRehash;
        $foundSidForSync = (string) ($user['student_id'] ?? $sid);
        $syncHash = $needsRehash ? password_hash($password, PASSWORD_DEFAULT) : (string) $user['login_pass'];
        wuc_sync_student_password($db, $foundSidForSync, $syncHash);
    }
}

// Legacy-only accounts (student_login row exists but users row is missing/out of sync).
if (!$user || !$passwordOk) {
    $existingUserId = $user['user_id'] ?? null;
    $legacyStmt = $db->prepare(
        'SELECT sl.Sid AS student_id, sl.Password AS login_pass, s.Fname, s.Lname, s.status
         FROM student_login sl
         LEFT JOIN students s ON s.SID = sl.Sid
         WHERE sl.Sid = ?
         LIMIT 1'
    );
    if ($legacyStmt) {
        $legacyStmt->bind_param('s', $sid);
        $legacyStmt->execute();
        $legacy = $legacyStmt->get_result()->fetch_assoc();
        $legacyStmt->close();

        if ($legacy) {
            [$legacyOk, $legacyRehash] = wuc_password_verify_legacy($password, (string) ($legacy['login_pass'] ?? ''));
            if ($legacyOk) {
                $user = [
                    'user_id' => $existingUserId,
                    'username' => (string) $legacy['student_id'],
                    'student_id' => (string) $legacy['student_id'],
                    'Fname' => $legacy['Fname'] ?? '',
                    'Lname' => $legacy['Lname'] ?? '',
                    'status' => $legacy['status'] ?? 'active',
                ];
                $passwordOk = true;
                $needsRehash = $legacyRehash;

                $syncHash = $needsRehash ? password_hash($password, PASSWORD_DEFAULT) : (string) $legacy['login_pass'];
                wuc_sync_student_password($db, (string) $legacy['student_id'], $syncHash);

                $uidStmt = $db->prepare('SELECT user_id FROM users WHERE student_id = ? OR username = ? LIMIT 1');
                if ($uidStmt) {
                    $legacySid = (string) $legacy['student_id'];
                    $uidStmt->bind_param('ss', $legacySid, $legacySid);
                    $uidStmt->execute();
                    $uidStmt->bind_result($resolvedUserId);
                    if ($uidStmt->fetch()) {
                        $user['user_id'] = (int) $resolvedUserId;
                    }
                    $uidStmt->close();
                }
            }
        }
    }
}

if (!$user || !$passwordOk) {
    wuc_record_failed_login($db, 'student', $sid);
    $_SESSION['errorMssg'] = 'Invalid Student ID or Password.';
    wuc_redirect($loginReturnPage);
}

if (empty($user['user_id'])) {
    error_log('Student login missing users.user_id for Sid ' . ($user['student_id'] ?? $sid));
    $_SESSION['errorMssg'] = 'Login service temporarily unavailable. Please try again shortly.';
    wuc_redirect($loginReturnPage);
}

$status = strtolower(trim((string) ($user['status'] ?? 'active')));
if (in_array($status, ['inactive', 'suspended', 'blocked', 'disabled', 'withdrawn', 'deleted'], true)) {
    $_SESSION['errorMssg'] = 'Your student account is not active. Please contact the administrator.';
    wuc_redirect($loginReturnPage);
}

session_regenerate_id(true);
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$foundSid = (string) $user['student_id'];
$studentName = trim((string) ($user['Fname'] ?? '') . ' ' . (string) ($user['Lname'] ?? ''));

if ($needsRehash) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $update = $db->prepare('UPDATE users SET password = ? WHERE user_id = ?');
    if ($update) {
        $userIdDb = (int) $user['user_id'];
        $update->bind_param('si', $newHash, $userIdDb);
        $update->execute();
        $update->close();
    }
}

wuc_clear_login_attempts($db, 'student', $foundSid);
wuc_log_login($db, $foundSid, 'student', $studentName);

$_SESSION['Sid'] = $foundSid;
$_SESSION['student_id'] = $foundSid;
$_SESSION['user_id'] = $foundSid;
$_SESSION['user_id_db'] = (int) $user['user_id'];
$_SESSION['user_name'] = $studentName !== '' ? $studentName : $foundSid;
$_SESSION['user_role'] = 'student';
$_SESSION['role'] = 'student';
$_SESSION['logged_in'] = true;
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/includes/helpers/student_provisioning.php';
wuc_repair_student_account_on_login($db, $foundSid, (int)$user['user_id']);

// Force a password change on first login for accounts flagged for it (e.g.
// short-course students issued a default password). Column-existence guarded so
// login still works on databases that have not run the migration.
$_SESSION['must_change_password'] = false;
if ($colRes = @$db->query("SHOW COLUMNS FROM student_login LIKE 'must_change_password'")) {
    $hasMustChange = $colRes->num_rows > 0;
    $colRes->free();
    if ($hasMustChange && ($mcStmt = $db->prepare('SELECT must_change_password FROM student_login WHERE Sid = ? LIMIT 1'))) {
        $mcStmt->bind_param('s', $foundSid);
        $mcStmt->execute();
        $mcStmt->bind_result($mustChangeFlag);
        if ($mcStmt->fetch()) {
            $_SESSION['must_change_password'] = ((int) $mustChangeFlag === 1);
        }
        $mcStmt->close();
    }
}

if (!empty($_SESSION['must_change_password'])) {
    // Preserve the intended destination across the forced password change.
    $_SESSION['post_password_change_next'] = $nextDest;
    wuc_redirect('students/change_password.php');
}

if ($hasExplicitNext) {
    $requestedPortal = strpos($nextDest, 'students/elearning/') === 0 ? 'elearning' : 'academic';

    // eLearning entry login: ensure an active student can reach the Learning Hub
    // immediately. Many accounts already have academic portal rows, which disables
    // inference for eLearning and previously bounced them back to the login form.
    if ($requestedPortal === 'elearning' && $fromElearning) {
        try {
            wuc_grant_user_portal_access($db, (int)$user['user_id'], ['elearning'], 'elearning_login');
        } catch (Throwable $e) {
            error_log('eLearning portal grant failed for user ' . (int)$user['user_id'] . ': ' . $e->getMessage());
        }
    }

    if (!wuc_user_has_portal_access($db, (int)$user['user_id'], $requestedPortal)) {
        $_SESSION['errorMssg'] = $requestedPortal === 'elearning'
            ? 'Your account is active, but eLearning access has not been assigned. Please contact the Registrar or eLearning Administrator.'
            : 'You do not have permission to access the Academic Portal.';
        wuc_redirect($requestedPortal === 'elearning' ? 'elearning_login.php' : 'student_login.php');
    }
    $_SESSION['current_portal'] = $requestedPortal;
    wuc_redirect($nextDest);
}

$afterLoginUrl = wuc_after_login_portal_url($db, (int)$user['user_id'], 'student');
if ($afterLoginUrl === '/wucportal/students/index.php') {
    require_once __DIR__ . '/includes/student_program_portal.php';
    $afterLoginUrl = wuc_student_program_portal_url($db, $foundSid);
}
wuc_redirect($afterLoginUrl);
