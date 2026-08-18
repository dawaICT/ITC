<?php
declare(strict_types=1);

/**
 * Finance-only invoice creation controller.
 *
 * The shared navigation bootstrap is buffered so it can enforce login, portal,
 * and finance-role access without emitting a page before this POST controller
 * returns its 303 redirect.
 */
$page_title = 'Create Student Invoice';
$outputLevel = ob_get_level();
ob_start();
require __DIR__ . '/includes/nav.php';
while (ob_get_level() > $outputLevel) {
    ob_end_clean();
}

require_once __DIR__ . '/../includes/invoice_helpers.php';

$redirect = static function (string $type, string $message): never {
    $_SESSION['flash_' . $type] = $message;
    wuc_safe_redirect('/wucportal/accounts/invoice_student.php', 303);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $redirect('error', 'Invoice creation requires a submitted form.');
}

$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
$postedToken = (string)($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    $redirect('error', 'Invalid request token. Please refresh and try again.');
}

$studentId = trim((string)($_POST['student_id'] ?? ''));
$amountRaw = trim((string)($_POST['invoice_amount'] ?? ''));
$academicYear = trim((string)($_POST['academic_year'] ?? ''));
$semester = trim((string)($_POST['semester'] ?? ''));
$yearOfStudy = (int)($_POST['year_of_study'] ?? 0);
$narrationChoice = trim((string)($_POST['narration'] ?? ''));
$otherNarration = trim((string)($_POST['other_narration'] ?? ''));

$allowedNarrations = [
    'Tuition Fee',
    'Registration Fee',
    'Library Fee',
    'Accommodation Fee',
    'Examination Fee',
    'Other',
];

if (
    $studentId === ''
    || $amountRaw === ''
    || !is_numeric($amountRaw)
    || !in_array($narrationChoice, $allowedNarrations, true)
    || !preg_match('/^\d{4}$/', $academicYear)
    || !in_array($semester, ['1', '2', '3', '4'], true)
    || $yearOfStudy < 1
    || $yearOfStudy > 10
) {
    $redirect('error', 'Please provide valid student, amount, academic period, and narration details.');
}

$narration = $narrationChoice === 'Other' ? $otherNarration : $narrationChoice;
if ($narration === '' || strlen($narration) > 200) {
    $redirect('error', 'Narration is required and cannot exceed 200 characters.');
}

$staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'accounts');
$result = invoice_create_for_student(
    $db,
    $studentId,
    (float)$amountRaw,
    $academicYear,
    $semester,
    'Invoice: ' . $narration,
    null,
    $yearOfStudy,
    $staffId
);

if (!empty($result['success'])) {
    $redirect(
        'success',
        'Invoice ' . (string)$result['invoice_number'] . ' created successfully for ' . $studentId . '.'
    );
}

$redirect('error', (string)($result['message'] ?? 'The invoice could not be created safely.'));
