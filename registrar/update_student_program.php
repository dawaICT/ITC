<?php
/**
 * update_student_program.php
 * Registrar: Update a student's program of study.
 *
 * POST fields:
 *   csrf_token      - anti-CSRF token stored in the session
 *   student_id      - the student's SID (e.g. CSE26456789)
 *   new_program_id  - the program_code from the programs table
 *
 * On success redirects to students_by_admin.php with a flash message.
 * On failure redirects back with an error flash message.
 */

/* ── Bootstrap ─────────────────────────────────────────────────────────── */
require_once __DIR__ . '/../db/connect.php';    // provides $db (mysqli)
require_once __DIR__ . '/../includes/audit.php'; // audit_log() helper
require_once __DIR__ . '/../includes/helpers/academic_structure_helpers.php';
require_once __DIR__ . '/../includes/cse_progression.php';

require_once __DIR__ . '/../includes/role_helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ── Role guard: systems administrators only ────────────────────────────── */
if (empty($_SESSION['user_name']) && empty($_SESSION['staff_id'])) {
    $_SESSION['errorMssg'] = 'You must be logged in to perform this action.';
    header('Location: ../staff_login.php');
    exit;
}
wuc_require_systems_admin('/wucportal/registrar/search_student.php');

/* ── Only handle POST ───────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: students_by_admin.php');
    exit;
}

/* ── CSRF validation ────────────────────────────────────────────────────── */
$submitted_token = trim($_POST['csrf_token'] ?? '');
$session_token   = $_SESSION['csrf_token'] ?? '';

if (empty($submitted_token) || empty($session_token) || !hash_equals($session_token, $submitted_token)) {
    $_SESSION['errorMssg'] = 'Invalid security token. Please try again.';
    header('Location: students_by_admin.php');
    exit;
}

/* ── Field presence validation ──────────────────────────────────────────── */
$student_id     = trim($_POST['student_id'] ?? '');
$new_program_id = trim($_POST['new_program_id'] ?? '');

if ($student_id === '' || $new_program_id === '') {
    $_SESSION['errorMssg'] = 'Both Student ID and Program are required.';
    header('Location: students_by_admin.php');
    exit;
}

/* ── Length guard ───────────────────────────────────────────────────────── */
if (strlen($student_id) > 64 || strlen($new_program_id) > 64) {
    $_SESSION['errorMssg'] = 'Invalid Student ID or Program ID length.';
    header('Location: students_by_admin.php');
    exit;
}

/* ── Verify student exists in students table ────────────────────────────── */
$stmt = $db->prepare("SELECT SID, Fname, Lname FROM students WHERE SID = ? LIMIT 1");
if (!$stmt) {
    $_SESSION['errorMssg'] = 'Database error while verifying student.';
    header('Location: students_by_admin.php');
    exit;
}
$stmt->bind_param('s', $student_id);
$stmt->execute();
$student_result = $stmt->get_result();
if ($student_result->num_rows === 0) {
    $stmt->close();
    $_SESSION['errorMssg'] = 'Student ID "' . htmlspecialchars($student_id, ENT_QUOTES, 'UTF-8') . '" does not exist.';
    header('Location: students_by_admin.php');
    exit;
}
$student = $student_result->fetch_assoc();
$stmt->close();

/* ── Verify the new program exists in programs table ────────────────────── */
$stmt = $db->prepare("SELECT program_code, program_name FROM programs WHERE program_code = ? LIMIT 1");
if (!$stmt) {
    $_SESSION['errorMssg'] = 'Database error while verifying program.';
    header('Location: students_by_admin.php');
    exit;
}
$stmt->bind_param('s', $new_program_id);
$stmt->execute();
$program_result = $stmt->get_result();
if ($program_result->num_rows === 0) {
    $stmt->close();
    $_SESSION['errorMssg'] = 'Program "' . htmlspecialchars($new_program_id, ENT_QUOTES, 'UTF-8') . '" does not exist.';
    header('Location: students_by_admin.php');
    exit;
}
$program = $program_result->fetch_assoc();
$stmt->close();

if ($stageError = wuc_cse_direct_assignment_error($new_program_id)) {
    $_SESSION['errorMssg'] = $stageError;
    header('Location: students_by_admin.php');
    exit;
}

/* ── Fetch old program code for audit trail ─────────────────────────────── */
$old_program_code = null;
$old_program_row = [];
$stmt_old = $db->prepare("SELECT program_code, term, semester, current_term_number, current_semester_number, current_level_number, intake FROM student_program WHERE Sid = ? ORDER BY id DESC LIMIT 1");
if ($stmt_old) {
    $stmt_old->bind_param('s', $student_id);
    $stmt_old->execute();
    $old_result = $stmt_old->get_result();
    if ($old_row = $old_result->fetch_assoc()) {
        $old_program_code = $old_row['program_code'];
        $old_program_row = $old_row;
    }
    $stmt_old->close();
}

$period_source = array_merge($old_program_row, $_POST);
$period_payload = wuc_student_program_period_payload($db, $new_program_id, wuc_student_program_period_input($db, $new_program_id, $period_source, 1));
if (!$period_payload['ok']) {
    $_SESSION['errorMssg'] = $period_payload['reason'];
    header('Location: students_by_admin.php');
    exit;
}
$period_fields = $period_payload['fields'];

/* ── Update (or insert) student_program row ─────────────────────────────── */
if ($old_program_code !== null) {
    // Row exists — update the program code
    $stmt_write = $db->prepare(
        "UPDATE student_program
            SET program_code = ?,
                term = ?,
                semester = ?,
                current_term_number = ?,
                current_semester_number = ?,
                current_level_number = ?
          WHERE Sid = ?"
    );
    if (!$stmt_write) {
        $_SESSION['errorMssg'] = 'Database error: could not prepare update statement.';
        header('Location: students_by_admin.php');
        exit;
    }
    $stmt_write->bind_param('sssssss', $new_program_id, $period_fields['term'], $period_fields['semester'], $period_fields['current_term_number'], $period_fields['current_semester_number'], $period_fields['current_level_number'], $student_id);
} else {
    // No existing row — insert a new one
    $stmt_write = $db->prepare(
        "INSERT INTO student_program
            (Sid, program_code, term, semester, current_term_number, current_semester_number, current_level_number, status, registration_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'active', CURDATE())"
    );
    if (!$stmt_write) {
        $_SESSION['errorMssg'] = 'Database error: could not prepare insert statement.';
        header('Location: students_by_admin.php');
        exit;
    }
    $stmt_write->bind_param('sssssss', $student_id, $new_program_id, $period_fields['term'], $period_fields['semester'], $period_fields['current_term_number'], $period_fields['current_semester_number'], $period_fields['current_level_number']);
}

$success = $stmt_write->execute();
$stmt_write->close();

/* ── Flash message and audit log ────────────────────────────────────────── */
if ($success) {
    $actor_id = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'unknown';

    audit_log($db, (string)$actor_id, 'student_program.update', [
        'student_id'       => $student_id,
        'student_name'     => trim($student['Fname'] . ' ' . $student['Lname']),
        'old_program_code' => $old_program_code,
        'new_program_code' => $new_program_id,
        'new_program_name' => $program['program_name'] ?? '',
    ]);

    $student_name  = htmlspecialchars(trim($student['Fname'] . ' ' . $student['Lname']), ENT_QUOTES, 'UTF-8');
    $program_label = htmlspecialchars($program['program_name'] ?? $new_program_id, ENT_QUOTES, 'UTF-8');

    $_SESSION['successMsg'] = "Program for {$student_name} successfully updated to {$program_label}.";
} else {
    $_SESSION['errorMssg'] = 'Failed to update student program. Please try again.';
}

header('Location: students_by_admin.php');
exit;
