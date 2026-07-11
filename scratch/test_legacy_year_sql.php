<?php
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
$sid = 'CSE26456789';
$s = $db->prepare('SELECT course_code, Year, academic_year FROM course_registration WHERE Sid=? AND (Year=? OR Year=? OR CAST(academic_year AS CHAR)=?)');
$s->bind_param('ssss', $sid, $y1, $y2, $y3);
$y1 = '1'; $y2 = '2026'; $y3 = '2026';
$s->execute();
$r = $s->get_result();
while ($x = $r->fetch_assoc()) {
    echo "{$x['course_code']} Year={$x['Year']} ay={$x['academic_year']}\n";
}
