<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require dirname(__DIR__) . '/db/connect.php';

echo "=== TABLE CHECK ===\n";
foreach (['course_levels','program_courses','curriculum_courses','course_registration','semester_registration','students','programs'] as $t) {
    $r = $db->query("SHOW TABLES LIKE '$t'");
    echo "$t: " . ($r->num_rows ? 'YES' : 'NO') . "\n";
}

echo "\n=== course_registration columns ===\n";
$r = $db->query('DESCRIBE course_registration');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' (' . $row['Type'] . ")\n";
}

echo "\n=== semester_registration sample ===\n";
$r = $db->query('SELECT * FROM semester_registration ORDER BY id DESC LIMIT 3');
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== Students with program but no semester reg ===\n";
$r = $db->query("SELECT s.SID, sp.program_code, sp.status AS prog_status
    FROM students s
    INNER JOIN student_program sp ON s.SID = sp.Sid
    LEFT JOIN semester_registration sr ON sr.student_id = s.SID
    WHERE sr.id IS NULL AND s.status = 'active'
    LIMIT 5");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== Students with semester reg but no course reg ===\n";
$r = $db->query("SELECT sr.student_id, sr.id AS sem_reg_id, sr.program_code, sr.semester, sr.year_of_study,
    (SELECT COUNT(*) FROM course_registration cr WHERE cr.Sid = sr.student_id) AS cr_count
    FROM semester_registration sr
    LEFT JOIN course_registration cr ON cr.semester_registration_id = sr.id
    GROUP BY sr.id
    HAVING cr_count = 0
    LIMIT 5");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== program_courses for CSE ===\n";
$r = $db->query("SHOW TABLES LIKE 'program_courses'");
if ($r->num_rows) {
    $cnt = $db->query("SELECT COUNT(*) c FROM program_courses WHERE program_code = 'CSE'")->fetch_assoc()['c'];
    echo "CSE courses in program_courses: $cnt\n";
    $r2 = $db->query("SELECT program_code, course_code, semester FROM program_courses LIMIT 5");
    while ($row = $r2->fetch_assoc()) {
        echo json_encode($row) . "\n";
    }
}

echo "\n=== Test getAvailableCourses ===\n";
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
$svc = new RegistrationDataService($db);
$courses = $svc->getAvailableCourses('CSE', 1, 1);
echo "CSE Y1 S1 courses: " . count($courses) . "\n";
foreach (array_slice($courses, 0, 5) as $c) {
    echo json_encode($c) . "\n";
}

echo "\n=== Test course_registration JOIN courses ===\n";
$sid = 'CSE26456789';
$sql = "SELECT cr.course_code, c.course_name, COALESCE(c.credit_hours, c.credits, 3) as credits
        FROM course_registration cr
        JOIN courses c ON c.course_code = cr.course_code
        WHERE cr.Sid = ?";
$stmt = $db->prepare($sql);
if (!$stmt) {
    echo "PREPARE FAILED: " . $db->error . "\n";
    // try without join
    $sql2 = "SELECT * FROM course_registration WHERE Sid = ? LIMIT 3";
    $stmt2 = $db->prepare($sql2);
    echo "Simple prepare: " . ($stmt2 ? 'OK' : $db->error) . "\n";
} else {
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    echo "Rows: " . $res->num_rows . "\n";
    while ($row = $res->fetch_assoc()) {
        echo json_encode($row) . "\n";
    }
    $stmt->close();
}

echo "\n=== courses table credit columns ===\n";
$r = $db->query('DESCRIBE courses');
while ($row = $r->fetch_assoc()) {
    if (preg_match('/credit|name/i', $row['Field'])) {
        echo $row['Field'] . ' (' . $row['Type'] . ")\n";
    }
}

echo "\nDONE\n";
