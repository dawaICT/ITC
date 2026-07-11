<?php
declare(strict_types=1);

putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

echo "=== Students ===\n";
$r = $db->query("SELECT SID, Fname, Lname, program, year, status FROM students ORDER BY SID");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== semester_registration ===\n";
$r = $db->query('SELECT id, student_id, Sid, academic_year, semester, year_of_study, program_code FROM semester_registration ORDER BY id');
$cols = [];
if ($m = $db->query('SHOW COLUMNS FROM semester_registration')) {
    while ($c = $m->fetch_assoc()) {
        $cols[] = $c['Field'];
    }
}
echo 'cols: ' . implode(', ', $cols) . "\n";
$r = $db->query('SELECT * FROM semester_registration ORDER BY id');
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== course_registration count by student ===\n";
$r = $db->query("SELECT Sid, COUNT(*) AS c FROM course_registration GROUP BY Sid ORDER BY c DESC");
while ($row = $r->fetch_assoc()) {
    echo "{$row['Sid']}: {$row['c']}\n";
}

echo "\n=== Distinct registered course codes ===\n";
$r = $db->query('SELECT DISTINCT course_code FROM course_registration ORDER BY course_code');
$registered = [];
while ($row = $r->fetch_assoc()) {
    $registered[] = $row['course_code'];
    echo $row['course_code'] . "\n";
}

echo "\n=== program_courses (TEST-PROG, CSE) ===\n";
foreach (['TEST-PROG', 'CSE', 'BSCS'] as $prog) {
    $stmt = $db->prepare('SELECT program_code, course_code, year, semester FROM program_courses WHERE program_code = ? ORDER BY course_code');
    $stmt->bind_param('s', $prog);
    $stmt->execute();
    $res = $stmt->get_result();
    $codes = [];
    while ($row = $res->fetch_assoc()) {
        $codes[] = $row['course_code'];
    }
    $stmt->close();
    echo "$prog: " . count($codes) . " courses\n";
    if ($codes) {
        echo '  ' . implode(', ', $codes) . "\n";
    }
}

echo "\n=== Registered but NOT in any program_courses ===\n";
if ($registered) {
    $ph = implode(',', array_fill(0, count($registered), '?'));
    $types = str_repeat('s', count($registered));
    $sql = "SELECT DISTINCT cr.course_code FROM course_registration cr
            LEFT JOIN program_courses pc ON pc.course_code = cr.course_code
            WHERE cr.course_code IN ($ph) AND pc.course_code IS NULL";
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$registered);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        echo $row['course_code'] . "\n";
    }
    $stmt->close();
}

echo "\n=== Test-looking courses (EL*, GEN*, COM*) in courses table ===\n";
$r = $db->query("SELECT course_code, course_name FROM courses WHERE course_code LIKE 'EL%' OR course_code LIKE 'GEN%' OR course_code LIKE 'COM%' OR course_code LIKE 'DCSE%' ORDER BY course_code LIMIT 40");
while ($row = $r->fetch_assoc()) {
    echo "{$row['course_code']} | {$row['course_name']}\n";
}
