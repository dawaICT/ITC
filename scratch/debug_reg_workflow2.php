<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require dirname(__DIR__) . '/db/connect.php';

echo "=== courses columns ===\n";
$r = $db->query('DESCRIBE courses');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' (' . $row['Type'] . ")\n";
}

echo "\n=== course_registration rows ===\n";
$r = $db->query('SELECT * FROM course_registration LIMIT 5');
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== Test JOIN without credits fallback ===\n";
$sid = 'CSE26456789';
$sql = "SELECT cr.course_code, c.course_name, c.credit_hours as credits
        FROM course_registration cr
        JOIN courses c ON c.course_code = cr.course_code
        WHERE cr.Sid = ?";
try {
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    echo "Rows: " . $res->num_rows . "\n";
    while ($row = $res->fetch_assoc()) {
        echo json_encode($row) . "\n";
    }
    $stmt->close();
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Test COALESCE with credits (broken?) ===\n";
$sql2 = "SELECT cr.course_code, c.course_name, COALESCE(c.credit_hours, c.credits, 3) as credits
        FROM course_registration cr
        JOIN courses c ON c.course_code = cr.course_code
        WHERE cr.Sid = ?";
try {
    $stmt = $db->prepare($sql2);
    echo "Prepare OK\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== buildRegistrationState for CSE26456789 ===\n";
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
$svc = new RegistrationDataService($db);
$regs = $svc->getRegisteredCourses('CSE26456789', 1, 1, 5);
echo "Registered courses (sem reg 5): " . count($regs) . "\n";
foreach ($regs as $c) {
    echo json_encode($c) . "\n";
}

echo "\n=== getBestStudentProgramCode ===\n";
echo $svc->getBestStudentProgramCode('CSE26456789') . "\n";

echo "\n=== student_program rows ===\n";
$r = $db->query("SELECT * FROM student_program WHERE Sid = 'CSE26456789'");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\nDONE\n";
