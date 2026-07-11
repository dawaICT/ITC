<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/result_entry_helpers.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';

$staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$courseCode = trim((string)($_GET['course_code'] ?? ''));
$semester = trim((string)($_GET['semester'] ?? ''));
$year = trim((string)($_GET['year'] ?? ''));

if ($staffId === '') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You must be logged in.']);
    exit;
}
// Exam result entry is ADMIN-ONLY, or an account the admin has granted an exam
// permission to. Plain lecturers (and other roles, on this lecturer endpoint)
// cannot load exam students.
if (!function_exists('canEnterLecturerExamResults') || !canEnterLecturerExamResults()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to enter exam results.']);
    exit;
}
// Academic year is the calendar year (4 digits), consistent with
// result_validate_entry() and course_registration.academic_year.
if ($courseCode === '' || !preg_match('/^[1-3]$/', $semester) || !preg_match('/^\d{4}$/', $year)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Course, period, and academic year are required.']);
    exit;
}

$students = result_students_for_course_period_status($db, $courseCode, $semester, $year);
$payload = [];
$eligibleCount = 0;
$blockedCount = 0;
foreach ($students as $row) {
    $sid = (string)($row['SID'] ?? $row['Sid'] ?? '');
    if ($sid === '') {
        continue;
    }
    $eligible = !empty($row['eligible']);
    $eligible ? $eligibleCount++ : $blockedCount++;
    $payload[] = [
        'Sid' => $sid,
        'name' => trim((string)($row['Fname'] ?? '') . ' ' . (string)($row['Lname'] ?? '')),
        'eligible' => $eligible,
        'reason' => (string)($row['reason'] ?? ''),
        'payment_percent' => $row['payment_percent'] ?? null,
    ];
}

echo json_encode([
    'success' => true,
    'students' => $payload,
    'count' => count($payload),
    'eligible_count' => $eligibleCount,
    'blocked_count' => $blockedCount,
    'message' => count($payload) > 0
        ? sprintf('%d eligible student(s), %d blocked by payment or registration validation.', $eligibleCount, $blockedCount)
        : 'Results cannot be entered because no students are registered for this term.',
]);
