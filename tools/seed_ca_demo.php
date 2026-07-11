<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db/connect.php';

$sid = 'CSE26456789';
$year = '2026';
$period = '2';
$programType = 'term';

$courses = [
    ['DCSE-101', 12.0, 10.0, 18.0],
    ['DCSE-102', 14.0, 11.0, 20.0],
    ['DCSE-103', 13.0, 12.0, 17.0],
];

foreach ($courses as [$code, $a1, $a2, $t1]) {
    $total = round($a1 + $a2 + $t1, 1);
    $check = $db->prepare('SELECT id FROM semester_assessment WHERE Sid=? AND Course_Code=? AND semester=? AND Year=? LIMIT 1');
    $check->bind_param('ssss', $sid, $code, $period, $year);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    if ($existing) {
        $id = (int)$existing['id'];
        $upd = $db->prepare("UPDATE semester_assessment SET A1=?, A2=?, T1=?, Total_CA=?, status='Published', published_by='seed', published_at=NOW(), program_type=? WHERE id=?");
        $upd->bind_param('ddddsi', $a1, $a2, $t1, $total, $programType, $id);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $db->prepare("INSERT INTO semester_assessment (Sid, Course_Code, A1, A2, T1, Total_CA, semester, Year, program_type, status, published_by, published_at) VALUES (?,?,?,?,?,?,?,?,?, 'Published', 'seed', NOW())");
        $ins->bind_param('ssddddsss', $sid, $code, $a1, $a2, $t1, $total, $period, $year, $programType);
        $ins->execute();
        $ins->close();
    }
    echo "Seeded {$code} total={$total}\n";
}

require_once dirname(__DIR__) . '/students/includes/continuous_assessment_helpers.php';
require_once dirname(__DIR__) . '/students/includes/RegistrationDataService.php';

$reg = new RegistrationDataService($db);
$yos = student_ca_resolve_year_of_study($db, $sid, $year);
$courses = $reg->getRegisteredCourses($sid, (int)$yos, 0, null, $year, 'year');
$periods = [1, 2, 3];
$map = student_ca_fetch_period_components_map($db, $sid, $year, $yos, $periods);
$records = student_ca_build_annual_records($courses, $map, $periods, $yos);
$withMarks = 0;
foreach ($records as $r) {
    foreach ($periods as $p) {
        if (student_ca_period_has_mark($r->periods[$p] ?? null)) {
            $withMarks++;
            break;
        }
    }
}
echo "Courses: " . count($records) . ", with published marks: {$withMarks}\n";
