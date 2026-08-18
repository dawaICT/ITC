<?php
declare(strict_types=1);

/**
 * Legacy semester registration/invoice endpoint.
 * Hardened: student session required, SID forced from session, CSRF required.
 */
require_once __DIR__ . '/../includes/production_guards.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/payment_helpers.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

wuc_require_post();
$Sid = wuc_force_session_student_id($_POST['Sid'] ?? null, false);
wuc_require_csrf();

if (
    !isset($_POST['program_code'], $_POST['invoice'], $_POST['balance'], $_POST['narration'], $_POST['semester'], $_POST['Year'])
) {
    echo "<script>alert('Invalid data provided!')</script>";
    echo "<script>window.open('registration.php','_self')</script>";
    exit;
}

$program_code = trim((string)$_POST['program_code']);
$invoice = trim((string)$_POST['invoice']);
$narration = trim((string)$_POST['narration']);
$semester = trim((string)$_POST['semester']);
$Year = trim((string)$_POST['Year']);

if ($Sid === '' || $program_code === '' || $semester === '' || $Year === '') {
    echo "<script>alert('Invalid data provided!')</script>";
    echo "<script>window.open('registration.php','_self')</script>";
    exit;
}

$checkStmt = $db->prepare(
    'SELECT id FROM semester_registration WHERE SID = ? AND program_code = ? AND semester = ? AND academic_year = ? LIMIT 1'
);
if ($checkStmt) {
    $checkStmt->bind_param('ssss', $Sid, $program_code, $semester, $Year);
    $checkStmt->execute();
    $res = $checkStmt->get_result();
    if ($res && $res->num_rows > 0) {
        $checkStmt->close();
        echo "<script>alert('You are already registered for Semester {$semester} Year {$Year}.')</script>";
        echo "<script>window.open('registration.php','_self')</script>";
        exit;
    }
    $checkStmt->close();
}

if ((float)$invoice > 0) {
    $created = payment_create_student_invoice(
        $db,
        $Sid,
        (float)$invoice,
        $Year,
        $semester,
        $narration !== '' ? $narration : 'Semester registration invoice'
    );
    if (empty($created['success']) && empty($created['duplicate'])) {
        echo "<script>alert('Semester registration failed! Could not create invoice.')</script>";
        echo "<script>window.open('registration.php','_self')</script>";
        exit;
    }
}

$insertReg = $db->prepare(
    'INSERT INTO semester_registration (program_code, SID, semester, academic_year, registration_date, created_at) VALUES (?,?,?,?,NOW(),NOW())'
);
if (!$insertReg) {
    error_log('process_invoice_1 prepare failed: ' . $db->error);
    echo "<script>alert('Failed to register for semester. Please try again.')</script>";
    echo "<script>window.open('registration.php','_self')</script>";
    exit;
}

$insertReg->bind_param('ssss', $program_code, $Sid, $semester, $Year);
if ($insertReg->execute()) {
    echo "<script>alert('Semester registration successful!')</script>";
    echo "<script>window.open('registration.php','_self')</script>";
} else {
    error_log('process_invoice_1 execute failed: ' . $insertReg->error);
    echo "<script>alert('Failed to register for semester. Please try again.')</script>";
    echo "<script>window.open('registration.php','_self')</script>";
}
$insertReg->close();
