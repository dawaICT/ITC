<?php
require_once "../includes/admin.php";
header('Content-Type: application/json');

if (!isset($_GET['academic_year']) || !isset($_GET['semester'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Academic year and semester are required']);
    exit;
}

$academic_year = $_GET['academic_year'];
$semester = $_GET['semester'];

// Get programs that have students enrolled in courses for the selected academic
// year and semester. Enrollment truth lives in course_registration (student_courses
// never existed); programs are keyed by program_code.
$query = "SELECT DISTINCT p.program_code, p.program_name
          FROM programs p
          INNER JOIN student_program sp ON sp.program_code = p.program_code
          INNER JOIN course_registration cr ON cr.Sid = sp.Sid
          WHERE cr.academic_year = ?
          AND cr.semester = ?
          ORDER BY p.program_name";

$stmt = $db->prepare($query);
$stmt->bind_param("ss", $academic_year, $semester);
$stmt->execute();
$result = $stmt->get_result();

$programs = [];
while ($row = $result->fetch_assoc()) {
    $programs[] = [
        'program_id' => $row['program_code'],
        'program_name' => $row['program_name']
    ];
}

echo json_encode($programs);
?> 