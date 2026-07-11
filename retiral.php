<?php
// Student Retirement/Withdrawal handler.
// Retiring a student is a privileged, destructive status change, so this
// endpoint requires a staff session, a valid CSRF token, and a POST request.
require_once __DIR__ . '/includes/session_guard.php';
require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/config/auth_check.php';
require_once __DIR__ . '/includes/role_helpers.php';

wuc_enforce_session_guard([
    'context'         => 'admin',
    'session_keys'    => ['staff_id', 'user_id'],
    'activity_keys'   => ['last_activity', 'last_active_time'],
    'timeout'         => 1800,
    'post_grace'      => 30,
    'login_path'      => '/wucportal/staff_login.php',
    'flash_key'       => 'errorMessage',
    'timeout_message' => 'Your session has expired. Please log in again.',
    'login_message'   => 'Please log in to continue.',
]);

$staffId = $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? null;
if ($staffId !== null) {
    hydrateStaffRolesFromDatabase((string) $staffId);
}

// Only registrar / systems admin may retire a student.
$allRoles = (array)($_SESSION['all_roles'] ?? []);
$mayRetire = in_array(ROLE_SYSTEMS_ADMIN, $allRoles, true)
    || in_array(ROLE_REGISTRAR, $allRoles, true);
if (!$mayRetire) {
    http_response_code(403);
    exit('Access denied.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['retire_student'])) {
    http_response_code(405);
    exit('This action must be submitted via POST.');
}

$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    exit('Request verification failed. Please refresh and try again.');
}

$student_id = trim((string)($_POST['student_id'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));

if ($student_id === '') {
    http_response_code(422);
    exit('Student ID is required.');
}

// Confirm the student exists.
$stmt = $db->prepare("SELECT SID FROM students WHERE SID = ? LIMIT 1");
$stmt->bind_param("s", $student_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    $stmt->close();
    http_response_code(404);
    exit('Student not found.');
}
$stmt->close();

// Update status (parameterised — the old direct-interpolation was injectable).
$upd = $db->prepare("UPDATE students SET status = 'retired' WHERE SID = ?");
$upd->bind_param("s", $student_id);
$upd->execute();
$upd->close();

// Best-effort audit trail. student_retirement_log is provisioned by migrations;
// if it is absent, log to the error log rather than fatalling the request.
try {
    $log = $db->prepare("INSERT INTO student_retirement_log (student_id, retirement_date, reason, retired_by) VALUES (?, NOW(), ?, ?)");
    if ($log) {
        $actor = (string)$staffId;
        $log->bind_param("sss", $student_id, $reason, $actor);
        $log->execute();
        $log->close();
    }
} catch (Throwable $e) {
    error_log('[retiral] could not write student_retirement_log: ' . $e->getMessage());
}

echo 'Student ' . htmlspecialchars($student_id, ENT_QUOTES, 'UTF-8') . ' has been retired successfully.';
