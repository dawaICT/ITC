<?php
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/helpers/course_availability_helpers.php';
$sessionStudentId = wuc_api_require_student();

// Validate parameters
$sid = isset($_GET['sid']) ? trim((string) $_GET['sid']) : $sessionStudentId;
$semester = isset($_GET['semester']) ? $_GET['semester'] : '';
$year = isset($_GET['year']) ? $_GET['year'] : '';

if (empty($sid) || empty($semester) || empty($year)) {
    wuc_json_error('Missing required parameters.', 422);
}

// Validate student ID matches session
if ($sid !== $sessionStudentId) {
    wuc_json_error('Student ID mismatch.', 403);
}

try {
    // Get program code for this student
    $program = '';
    $stmt = $db->prepare(
        "SELECT sp.program_code
         FROM student_program sp
         JOIN programs p ON p.program_code = sp.program_code
         WHERE sp.Sid = ? AND COALESCE(p.is_active, 1) = 1
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $program = $row['program_code'];
        }
        $stmt->close();
    }
    
    if (empty($program)) {
        wuc_json_error('Program not found for student.', 404);
    }
    
    // Get available courses for this program, semester, and year
    $courses = [];
    $pcCols = wuc_course_availability_columns($db, 'program_courses');
    $where = [
        'pc.program_code = ?',
        'pc.year = ?',
        'COALESCE(p.is_active, 1) = 1',
        "LOWER(COALESCE(c.status, 'active')) = 'active'",
    ];
    $types = 'ss';
    $params = [$program, $year];
    $periodFilter = wuc_course_availability_period_filter($pcCols, 'pc', $pcCols['semester'] ?? null, $semester);
    if ($periodFilter['sql'] !== '1=1') {
        $where[] = $periodFilter['sql'];
        $types .= $periodFilter['types'];
        $params = array_merge($params, $periodFilter['params']);
    }

    $sql = "SELECT DISTINCT pc.course_code, c.course_name, c.credits
            FROM program_courses pc 
            JOIN programs p ON p.program_code = pc.program_code
            JOIN courses c ON pc.course_code = c.course_code
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pc.course_code";
    
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $courses[] = [
                'course_code' => $row['course_code'],
                'course_name' => $row['course_name'] ?? '',
                'credit_hours' => $row['credits'] ?? 3
            ];
        }
        $stmt->close();
    }
    
    // Get already registered courses
    $registered = [];
    $sql = "SELECT course_code FROM course_registration 
            WHERE Sid = ? 
            AND semester = ? 
            AND Year = ?";
    
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('sss', $sid, $semester, $year);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $registered[] = $row['course_code'];
        }
        $stmt->close();
    }
    
    // Return success response
    wuc_json_response([
        'success' => true,
        'courses' => $courses,
        'registered' => $registered,
        'program_code' => $program,
        'timestamp' => time()
    ]);
    
} catch (Throwable $e) {
    // Log error
    error_log("Error in api_get_courses.php: " . $e->getMessage());
    
    // Return error response
    wuc_json_error('Unable to load courses.', 500);
}
