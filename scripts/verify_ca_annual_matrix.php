<?php
declare(strict_types=1);
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../students/includes/continuous_assessment_helpers.php';
require_once __DIR__ . '/../students/includes/RegistrationDataService.php';
require_once __DIR__ . '/../students/includes/period_mode_helper.php';

$sid = $argv[1] ?? 'CSE26456789';
$year = $argv[2] ?? '2026';

$reg = new RegistrationDataService($db);
$student = student_ca_fetch_student_profile($db, $sid);
$yos = student_ca_resolve_year_of_study($db, $sid, $year);
$code = trim((string)($student['program_code'] ?? 'CSE'));
$config = student_ca_period_columns($db, $code, 'term');
$courses = $reg->getRegisteredCourses($sid, (int)$yos, 0, null, $year, 'year');
$map = student_ca_fetch_period_totals_map($db, $sid, $year, $yos, $config['periods']);
$records = student_ca_build_annual_records($courses, $map, $config['periods']);

echo "Program: {$code}\n";
echo "Structure: {$config['period_mode']}\n";
echo "Columns: " . implode(', ', $config['headers']) . ", Final CA\n";
echo "Courses: " . count($records) . "\n";
foreach (array_slice($records, 0, 3) as $r) {
    echo $r->course_code . ' | periods=' . json_encode($r->periods) . ' | final=' . ($r->final_ca ?? 'null') . "\n";
}
