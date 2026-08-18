<?php
/**
 * AJAX endpoint: enrolled students for a short course (for CA entry).
 * Returns JSON { success, students:[{Sid,name}], count }.
 */
header('Content-Type: application/json');
ini_set('display_errors', '0');

try {
    require_once __DIR__ . '/includes/guard.php';
    require_once dirname(__DIR__) . '/includes/short_course_ca.php';
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'System error.', 'students' => []]);
    exit;
}

if (!isset($_SESSION['staff_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized', 'students' => []]);
    exit;
}
if (!isset($db) || !($db instanceof mysqli)) {
    echo json_encode(['success' => false, 'error' => 'Database connection not available', 'students' => []]);
    exit;
}

$shortCourseId = (int)($_GET['short_course_id'] ?? 0);
if ($shortCourseId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Short course is required', 'students' => []]);
    exit;
}

$staffId = (string)$_SESSION['staff_id'];
// Systems admins can load any course's student list.
// All others must be assigned via course_lecturer (sc_ca_staff_owns checks this).
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'systems_admin';
if (!$isAdmin && !sc_ca_staff_owns($db, $staffId, $shortCourseId)) {
    echo json_encode(['success' => false, 'error' => 'You are not assigned to this short course.', 'students' => []]);
    exit;
}

// Fetch the short course name for feedback messages.
$shortCourseName = '';
$shortCourseCode = '';
if ($stmt = $db->prepare("SELECT course_code, course_name FROM short_courses WHERE id = ? LIMIT 1")) {
    $stmt->bind_param('i', $shortCourseId);
    if ($stmt->execute()) {
        $scRow = $stmt->get_result()->fetch_assoc();
        $shortCourseCode = (string)($scRow['course_code'] ?? '');
        $shortCourseName = (string)($scRow['course_name'] ?? '');
    }
    $stmt->close();
}
$displayName = $shortCourseName !== ''
    ? $shortCourseCode . ' – ' . $shortCourseName
    : ($shortCourseCode !== '' ? $shortCourseCode : "Short Course #{$shortCourseId}");

try {
    $students = sc_ca_enrolled_students($db, $shortCourseId);
} catch (Throwable $e) {
    error_log('ajax_get_short_course_students failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error while loading students.', 'students' => []]);
    exit;
}

$count = count($students);
$response = [
    'success' => true,
    'students' => $students,
    'count' => $count,
    'short_course_id' => $shortCourseId,
    'short_course_name' => $displayName,
];
if ($count === 0) {
    $response['info'] = 'No students enrolled in ' . $displayName . '.';
}
echo json_encode($response);
