<?php
// Suppress any PHP output before JSON response
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require_once "../includes/admin.php";
require_once dirname(__DIR__, 2) . "/includes/helpers/course_availability_helpers.php";

// Discard any output from admin.php
ob_end_clean();

header('Content-Type: application/json');

try {
    // Check if program and semester are provided for filtered results
    $program = isset($_POST['program']) ? $db->real_escape_string($_POST['program']) : null;
    $semester = isset($_POST['semester']) ? $db->real_escape_string($_POST['semester']) : null;

    // Detect available columns
    $hasCourseFeeinCourses = false;
    $res = $db->query("SHOW COLUMNS FROM courses LIKE 'course_fee'");
    if ($res && $res->num_rows > 0) $hasCourseFeeinCourses = true;

    $hasCredits = false;
    $res = $db->query("SHOW COLUMNS FROM courses LIKE 'credits'");
    if ($res && $res->num_rows > 0) $hasCredits = true;

    $hasCreditHours = false;
    $res = $db->query("SHOW COLUMNS FROM courses LIKE 'credit_hours'");
    if ($res && $res->num_rows > 0) $hasCreditHours = true;

    // Build credits expression
    if ($hasCredits && $hasCreditHours) {
        $creditsExpr = "COALESCE(c.credits, c.credit_hours, 3)";
    } elseif ($hasCredits) {
        $creditsExpr = "COALESCE(c.credits, 3)";
    } elseif ($hasCreditHours) {
        $creditsExpr = "COALESCE(c.credit_hours, 3)";
    } else {
        $creditsExpr = "3";
    }

    $feeExpr = $hasCourseFeeinCourses ? "c.course_fee" : "0.00 AS course_fee";

    // Detect status column in courses table
    $hasStatus = false;
    $res = $db->query("SHOW COLUMNS FROM courses LIKE 'status'");
    if ($res && $res->num_rows > 0) $hasStatus = true;

    if ($program && $semester) {
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
        // Get courses for specific program and semester
        $query = "SELECT c.course_code, c.course_name, 
                         $feeExpr,
                         $creditsExpr AS credits
                  FROM courses c 
                  INNER JOIN program_courses pc ON c.course_code = pc.course_code 
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY c.course_name ASC";
        
        $stmt = $db->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        // Get all courses (optionally active only)
        $statusFilter = $hasStatus ? "WHERE c.status = 'active'" : "";
        $query = "SELECT c.course_code, c.course_name, 
                         $feeExpr,
                         $creditsExpr AS credits
                  FROM courses c
                  $statusFilter
                  ORDER BY c.course_name";
        
        $result = $db->query($query);
    }

    if (!$result) {
        throw new Exception($db->error);
    }

    $courses = [];
    while ($row = $result->fetch_assoc()) {
        $courses[] = [
            'course_code' => $row['course_code'],
            'course_name' => $row['course_name'],
            'credits' => $row['credits'] ?: 3,
            'course_fee' => (float)($row['course_fee'] ?? 0.0)
        ];
    }

    // Return in the { success: true, courses: [...] } format expected by loadCourses()
    echo json_encode([
        'success' => true,
        'courses' => $courses
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
