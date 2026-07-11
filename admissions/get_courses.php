<?php
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';

// Check if user is logged in
if (!isset($_SESSION['staff_id'])) {
    error_log("get_courses.php: staff_id not set in session");
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Optional role check for admin access — adjust role name as needed
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'staff'])) {
    error_log("get_courses.php: user_role not set or not admin/staff. Current role: " . ($_SESSION['user_role'] ?? 'not set'));
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

require_once '../db/connect.php';
require_once dirname(__DIR__) . '/includes/helpers/course_availability_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit();
}

if (!isset($_POST['program']) || !isset($_POST['semester'])) {
    echo json_encode(['error' => 'Program and semester are required']);
    exit();
}

$program = $db->real_escape_string($_POST['program']);
$semester = $db->real_escape_string($_POST['semester']);

try {
    // Programme courses run for the whole academic year by default; the
    // semester value only narrows rows explicitly flagged period-specific.
    $pcCols = wuc_course_availability_columns($db, 'program_courses');
    $where = ['pc.program_code = ?'];
    $types = 's';
    $params = [$program];
    $periodFilter = wuc_course_availability_period_filter($pcCols, 'pc', $pcCols['semester'] ?? null, $semester);
    if ($periodFilter['sql'] !== '1=1') {
        $where[] = $periodFilter['sql'];
        $types .= $periodFilter['types'];
        $params = array_merge($params, $periodFilter['params']);
    }

    $query = "SELECT DISTINCT c.course_code, c.course_name, c.credits, c.course_fee
              FROM courses c
              INNER JOIN program_courses pc ON c.course_code = pc.course_code
              WHERE " . implode(' AND ', $where) . "
              ORDER BY c.course_name ASC";

    $stmt = $db->prepare($query);

    if (!$stmt) {
        throw new Exception('Database prepare error: ' . $db->error);
    }

    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        throw new Exception('Database execute error: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $courses = [];
    
    while ($row = $result->fetch_assoc()) {
        $courses[] = [
            'course_code' => $row['course_code'],
            'course_name' => $row['course_name'],
            'credits' => $row['credits'] ?? 0,
            'course_fee' => floatval($row['course_fee'] ?? 0)
        ];
    }
    
    echo json_encode($courses);
    
} catch (Exception $e) {
    error_log("Error in get_courses.php: " . $e->getMessage());
    echo json_encode(['error' => 'An error occurred while fetching courses']);
}
?>