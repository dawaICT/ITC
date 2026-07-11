<?php
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
require dirname(__DIR__) . '/includes/course_recommendation_engine.php';

$svc = new RegistrationDataService($db);
$sid = $argv[1] ?? 'CSE26456789';

$best = $svc->getBestStudentProgramCode($sid);
$recs = wuc_course_recommendations($db, $sid);
echo "getBestStudentProgramCode: {$best}\n";
echo "wuc_course_recommendations program: {$recs['program_code']}\n";

$tc = $svc->resolveRegistrationTermContext($sid);
echo "term context program: " . ($tc['program_code'] ?? '') . "\n";

// Find students where best != rec program
echo "\nStudents where programs differ:\n";
if ($r = $db->query("SELECT DISTINCT student_id FROM semester_registration WHERE student_id IS NOT NULL LIMIT 100")) {
    while ($row = $r->fetch_assoc()) {
        $s = trim($row['student_id']);
        if ($s === '') continue;
        $best = $svc->getBestStudentProgramCode($s);
        $rec = wuc_course_recommendations($db, $s);
        $tc = $svc->resolveRegistrationTermContext($s);
        $termProg = $tc['program_code'] ?? '';
        if ($best !== $rec['program_code'] || $best !== $termProg) {
            echo "  {$s}: best={$best} rec={$rec['program_code']} term={$termProg}\n";
        }
    }
}
