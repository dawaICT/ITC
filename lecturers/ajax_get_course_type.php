<?php
/**
 * AJAX endpoint to detect course academic structure and default registration period/year.
 */
header('Content-Type: application/json');
try {
    require_once __DIR__ . '/includes/guard.php';
    require_once dirname(__DIR__) . '/includes/ca_helpers.php';
    require_once dirname(__DIR__) . '/includes/elearning_access.php';
} catch (Throwable $e) {
    error_log('ajax_get_course_type bootstrap failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'System error. Please try again or contact support.', 'type' => 'semester']);
    exit;
}
if (!isset($_SESSION['staff_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized', 'type' => 'semester']);
    exit;
}

$courseCode = trim($_GET['course_code'] ?? '');

if ($courseCode === '') {
    echo json_encode(['success' => false, 'error' => 'Course code is required', 'type' => 'semester']);
    exit;
}

if (!(isset($_SESSION['role']) && $_SESSION['role'] === 'systems_admin')) {
    if (!isLecturerAssignedToCourse($db, (string)$_SESSION['staff_id'], $courseCode)) {
        echo json_encode(['success' => false, 'error' => 'You are not assigned to this course.', 'type' => 'semester']);
        exit;
    }
}

$mode = ca_course_period_mode($db, $courseCode);
$programType = $mode['period_mode'];
$examinationType = $mode['examination_type'];

$periods = [];
$diagnostics = ca_registration_diagnostics($db, $courseCode);
foreach ($diagnostics as $entry) {
    if ($entry['semester'] !== '' && !in_array($entry['semester'], $periods, true)) {
        $periods[] = $entry['semester'];
    }
}

$period = $periods[0] ?? '';
$year = '';

if ($diagnostics !== []) {
    usort($diagnostics, static function (array $a, array $b): int {
        $yearCmp = strcmp($b['year'], $a['year']);
        return $yearCmp !== 0 ? $yearCmp : strcmp($b['semester'], $a['semester']);
    });
    $period = (string)$diagnostics[0]['semester'];
    $year = (string)$diagnostics[0]['year'];
}

echo json_encode([
    'success' => true,
    'type' => $programType,
    'examination_type' => $examinationType,
    'period' => $period,
    'periods' => $periods,
    'year' => $year,
    'course_code' => $courseCode,
    'is_short_course' => !empty($mode['is_short_course']),
    'ca_upload_blocked' => ca_is_external_short_course($db, $courseCode),
    'short_course_upload_url' => ca_short_course_upload_path(),
]);
