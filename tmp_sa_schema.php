<?php
require_once __DIR__ . '/db/connect.php';
echo "DESCRIBE semester_assessment:\n";
$r = $db->query('DESCRIBE semester_assessment');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' ' . $row['Type'] . ' ' . ($row['Default'] ?? '') . "\n";
}

echo "\nstudent_assessment_marks:\n";
$r = $db->query('DESCRIBE student_assessment_marks');
while ($row = $r->fetch_assoc()) echo $row['Field'] . "\n";
$r = $db->query('SELECT COUNT(*) c FROM student_assessment_marks');
echo 'count: ' . $r->fetch_assoc()['c'] . "\n";
