<?php
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/helpers/academic_period_helpers.php';

$prog = 'CSE';
$y = 1;
$all = getCoursesForProgramYearOfStudy($db, $prog, $y);
$t1 = getCoursesForProgramYear($db, $prog, $y, 1);
$t2 = getCoursesForProgramYear($db, $prog, $y, 2);
$t3 = getCoursesForProgramYear($db, $prog, $y, 3);

echo "Year-wide: " . count($all) . "\n";
echo "Term 1 filter: " . count($t1) . "\n";
echo "Term 2 filter: " . count($t2) . "\n";
echo "Term 3 filter: " . count($t3) . "\n";

$c1 = array_column($all, 'course_code');
sort($c1);
echo "Codes: " . implode(', ', $c1) . "\n";
