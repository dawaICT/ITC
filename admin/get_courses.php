<?php
require_once 'includes/admin.php';
require_once dirname(__DIR__) . '/includes/helpers/course_availability_helpers.php';

header('Content-Type: application/json');

if (!isset($_POST['program']) || !isset($_POST['semester'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Program and semester are required']);
    exit();
}

$program = $db->real_escape_string($_POST['program']);
$semester = $db->real_escape_string($_POST['semester']);

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

// Get courses for the selected program. Semester only narrows explicit exceptions.
$query = "SELECT c.* 
          FROM courses c 
          INNER JOIN program_courses pc ON c.course_code = pc.course_code 
          WHERE " . implode(' AND ', $where) . "
          ORDER BY c.course_name ASC";

try {
    $stmt = $db->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $courses = [];
    $hasFee = false;
    // detect column existence for course_fee on first run (fallback will be handled if not present)
    if ($result && $result->num_rows > 0) {
        $sample = $result->fetch_object();
        $hasFee = property_exists($sample, 'course_fee');
        // rewind by re-executing query to iterate from start
        $stmt->execute();
        $result = $stmt->get_result();
    }

    while ($row = $result->fetch_object()) {
        $fee = 0.0;
        if ($hasFee && isset($row->course_fee)) {
            $fee = (float)$row->course_fee;
        } else {
            // Fallback: if fee_structure table exists, derive fee per course if available
            $fee = 0.0;
            $fs = $db->prepare("SELECT amount FROM fee_structure WHERE program_code = ? AND semester = ? AND (status = 'active' OR status IS NULL) LIMIT 1");
            if ($fs) {
                $fs->bind_param('ss', $program, $semester);
                if ($fs->execute()) {
                    $rs = $fs->get_result();
                    if ($rs && ($r = $rs->fetch_object())) {
                        $fee = (float)$r->amount;
                    }
                }
            }
        }
        $courses[] = [
            'course_code' => $row->course_code,
            'course_name' => $row->course_name,
            'credits' => isset($row->credits) ? (int)$row->credits : null,
            'course_fee' => $fee
        ];
    }

    echo json_encode($courses);

} catch (Exception $e) {
    error_log('get_courses error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch courses. Please try again.']);
}
?> 
