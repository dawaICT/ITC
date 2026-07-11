<?php
include "includes/lecturer.php";
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['deptId'])) {
    $deptId = trim($_POST['deptId']);

    $stmt = $db->prepare("
        SELECT d.department_id, d.department_name, d.faculty, d.status,
               (SELECT COUNT(DISTINCT sp.Sid) FROM student_program sp
                JOIN programs p ON sp.program_code = p.program_code
                WHERE p.department_id = d.department_id) AS total_students,
               (SELECT COUNT(DISTINCT ssa.staff_id) FROM staff_section_assignments ssa
                JOIN sections sec ON ssa.section_id = sec.section_id
                WHERE sec.department_id = d.department_id AND ssa.status = 'active') AS total_faculty
        FROM departments d
        WHERE d.department_id = ?
    ");
    $stmt->bind_param("s", $deptId);
    $stmt->execute();
    $department = $stmt->get_result()->fetch_assoc();

    if ($department) {
        echo json_encode($department);
    } else {
        echo json_encode(['error' => 'Department not found.']);
    }
} else {
    echo json_encode(['error' => 'Invalid request.']);
}
?>