<?php
require_once 'students/includes/Database.php';
$db = new Database();
$conn = $db->getConnection();

// Check for orphaned course_registration records (no semester_registration)
echo 'Checking for orphaned course_registration records...' . PHP_EOL;
$stmt = $conn->query('
    SELECT COUNT(*) as count
    FROM course_registration cr
    LEFT JOIN semester_registration sr ON cr.semester_registration_id = sr.id
    WHERE sr.id IS NULL
');
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo 'Orphaned course_registration records: ' . $row['count'] . PHP_EOL;

// Check for student_courses records without corresponding semester_registration
echo 'Checking for student_courses without semester_registration...' . PHP_EOL;
$stmt = $conn->query('
    SELECT COUNT(*) as count
    FROM student_courses sc
    LEFT JOIN semester_registration sr ON sc.student_id = sr.student_id
        AND sc.academic_year = sr.academic_year
        AND sc.semester = sr.semester
    WHERE sr.id IS NULL
');
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo 'Orphaned student_courses records: ' . $row['count'] . PHP_EOL;

// Check total records
$stmt = $conn->query('SELECT COUNT(*) as count FROM course_registration');
echo 'Total course_registration records: ' . $stmt->fetch(PDO::FETCH_ASSOC)['count'] . PHP_EOL;

$stmt = $conn->query('SELECT COUNT(*) as count FROM student_courses');
echo 'Total student_courses records: ' . $stmt->fetch(PDO::FETCH_ASSOC)['count'] . PHP_EOL;

$stmt = $conn->query('SELECT COUNT(*) as count FROM semester_registration');
echo 'Total semester_registration records: ' . $stmt->fetch(PDO::FETCH_ASSOC)['count'] . PHP_EOL;
?>