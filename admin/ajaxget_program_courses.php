<?php
require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/helpers/course_availability_helpers.php';

header('Content-Type: application/json');

$programCode = trim((string)($_GET['program_code'] ?? $_GET['program_id'] ?? ''));
$year = (int)($_GET['year'] ?? $_GET['year_of_study'] ?? 0);
$semester = (int)($_GET['semester'] ?? 0);

if ($programCode === '' || $year < 1 || $semester < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'program_code, year and semester are required']);
    exit;
}

$pcCols = wuc_course_availability_columns($db, 'program_courses');
$where = [
    'pc.program_code = ?',
    'pc.year = ?',
    "LOWER(COALESCE(c.status, 'active')) = 'active'",
];
$types = 'si';
$params = [$programCode, $year];
$periodFilter = wuc_course_availability_period_filter($pcCols, 'pc', $pcCols['semester'] ?? null, $semester);
if ($periodFilter['sql'] !== '1=1') {
    $where[] = $periodFilter['sql'];
    $types .= $periodFilter['types'];
    $params = array_merge($params, $periodFilter['params']);
}

$sql = "SELECT
            c.id AS course_id,
            c.course_code,
            c.course_name,
            COUNT(DISTINCT cr.Sid) AS registered_students
        FROM program_courses pc
        INNER JOIN courses c ON TRIM(UPPER(c.course_code)) = TRIM(UPPER(pc.course_code))
        LEFT JOIN course_registration cr
               ON TRIM(UPPER(cr.course_code)) = TRIM(UPPER(pc.course_code))
              AND cr.Year = pc.year
              AND COALESCE(cr.is_active, 1) = 1
        WHERE " . implode(' AND ', $where) . "
        GROUP BY c.id, c.course_code, c.course_name
        ORDER BY c.course_code";

$stmt = $db->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load program courses']);
    exit;
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$courses = [];
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}
$stmt->close();

echo json_encode($courses);
