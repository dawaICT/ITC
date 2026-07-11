<?php
require_once __DIR__ . '/../db/connect.php';
$sid = 'CSE26456789';
$r = $db->query('DESCRIBE semester_assessment');
while ($x = $r->fetch_assoc()) {
    echo $x['Field'] . "\n";
}
echo "--- sample ---\n";
$s = $db->prepare("SELECT Course_Code, A1, A2, T1, T2, Total_CA, Year, semester, status FROM semester_assessment WHERE Sid = ? LIMIT 5");
$s->bind_param('s', $sid);
$s->execute();
$res = $s->get_result();
while ($row = $res->fetch_assoc()) {
    print_r($row);
}
echo 'count courses reg: ';
$c = $db->prepare('SELECT COUNT(DISTINCT course_code) n FROM course_registration WHERE Sid=?');
$c->bind_param('s', $sid);
$c->execute();
echo $c->get_result()->fetch_assoc()['n'] . "\n";
