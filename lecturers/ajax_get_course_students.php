<?php
/**
 * AJAX endpoint to fetch students registered for a specific course, period, and academic year.
 */
header('Content-Type: application/json');
ini_set('display_errors', '0');

try {
    require_once __DIR__ . '/includes/guard.php';
    require_once dirname(__DIR__) . '/includes/ca_helpers.php';
    require_once dirname(__DIR__) . '/includes/elearning_access.php';
} catch (Exception $e) {
    error_log('ajax_get_course_students bootstrap failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'System error. Please try again or contact support.', 'students' => []]);
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

$courseCode = trim($_GET['course_code'] ?? '');
$semester = trim($_GET['semester'] ?? '');
$year = trim($_GET['year'] ?? '');

if ($courseCode === '') {
    echo json_encode(['success' => false, 'error' => 'Course code is required', 'students' => []]);
    exit;
}

if ($year === '') {
    echo json_encode(['success' => false, 'error' => 'Academic year is required before loading students.', 'students' => []]);
    exit;
}

if ($semester === '') {
    echo json_encode(['success' => false, 'error' => 'Term, semester, or intake batch is required before loading students.', 'students' => []]);
    exit;
}

if (!(isset($_SESSION['role']) && $_SESSION['role'] === 'systems_admin')) {
    if (!isLecturerAssignedToCourse($db, (string)$_SESSION['staff_id'], $courseCode)) {
        echo json_encode(['success' => false, 'error' => 'You are not assigned to this course.', 'students' => []]);
        exit;
    }
}

try {
    ca_ensure_schema($db);

    $mode = ca_course_period_mode($db, $courseCode);
    $periodMode = $mode['period_mode'];
    $periodLabel = ca_period_label($periodMode, $semester);

    $courseName = $courseCode;
    if ($stmt = $db->prepare('SELECT course_name FROM courses WHERE course_code = ? LIMIT 1')) {
        $stmt->bind_param('s', $courseCode);
        if ($stmt->execute()) {
            $nameRow = $stmt->get_result()->fetch_assoc();
            if ($nameRow && !empty($nameRow['course_name'])) {
                $courseName = $courseCode . ' – ' . $nameRow['course_name'];
            }
        }
        $stmt->close();
    }

    $programLabel = '';
    if (ca_table_exists($db, 'program_courses') && ca_table_exists($db, 'programs')) {
        if ($stmt = $db->prepare('SELECT p.program_name FROM program_courses pc JOIN programs p ON p.program_code = pc.program_code WHERE pc.course_code = ? LIMIT 1')) {
            $stmt->bind_param('s', $courseCode);
            if ($stmt->execute()) {
                $progRow = $stmt->get_result()->fetch_assoc();
                if ($progRow && !empty($progRow['program_name'])) {
                    $programLabel = (string)$progRow['program_name'];
                }
            }
            $stmt->close();
        }
    }

    $lecturerId = (string)($_SESSION['staff_id'] ?? '');
    $result = ca_fetch_course_students($db, $courseCode, $semester, $year);
    $students = $result['students'];
    $count = (int)$result['count'];

    $response = [
        'success' => true,
        'students' => $students,
        'count' => $count,
        'course_code' => $courseCode,
        'course_name' => $courseName,
        'program_name' => $programLabel,
        'semester' => $semester,
        'year' => $year,
        'period_label' => $periodLabel,
        'period_mode' => $periodMode,
        'lecturer_id' => $lecturerId,
        'can_upload' => $count > 0,
        'filter_summary' => [
            'course' => $courseName,
            'program' => $programLabel !== '' ? $programLabel : 'Not linked',
            'academic_year' => $year,
            'period' => $periodLabel,
            'lecturer' => $lecturerId,
        ],
    ];

    if ($count === 0) {
        $diagnostics = ca_registration_diagnostics($db, $courseCode);
        $response['info'] = ca_build_no_students_message($courseName, $periodLabel, $year, $periodMode, $diagnostics);
        $response['diagnostics'] = $diagnostics;
    }

    echo json_encode($response);
} catch (Throwable $e) {
    error_log('ajax_get_course_students failed: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Unable to load students. Please try again or contact support.',
        'students' => [],
    ]);
}
