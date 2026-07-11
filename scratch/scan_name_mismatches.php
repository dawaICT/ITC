<?php
require dirname(__DIR__) . '/db/connect.php';

echo "Tables like course_levels:\n";
if ($r = $db->query("SHOW TABLES LIKE 'course_levels'")) {
    echo "count: {$r->num_rows}\n";
}

echo "\ngetAvailableCourses test:\n";
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
$svc = new RegistrationDataService($db);

// find a student with registration
$sid = null;
$prog = null;
$year = 1;
$sem = 1;
if ($r = $db->query("SELECT student_id, program_code, year_of_study, semester FROM semester_registration ORDER BY id DESC LIMIT 1")) {
    if ($row = $r->fetch_assoc()) {
        $sid = $row['student_id'];
        $prog = $row['program_code'];
        $year = (int)$row['year_of_study'];
        $sem = (int)$row['semester'];
        echo "Using {$sid} prog={$prog} y={$year} s={$sem}\n";
    }
}

if ($prog) {
    $courses = $svc->getAvailableCourses($prog, $year, $sem);
    foreach ($courses as $c) {
        echo "  {$c['course_code']} => {$c['course_name']}\n";
    }
    echo "count: " . count($courses) . "\n";
}

require dirname(__DIR__) . '/includes/course_recommendation_engine.php';
if ($sid) {
    $recs = wuc_course_recommendations($db, $sid);
    $termContext = $svc->resolveRegistrationTermContext($sid);
    $selectable = $svc->getSelectableCoursesForTerm($sid, $termContext);
    
    echo "\nSelectable vs Rec names:\n";
    $recMap = [];
    foreach (['missing','retake'] as $t) {
        foreach ($recs[$t] ?? [] as $item) {
            $recMap[$item['course_code']] = $item['course_name'];
        }
    }
    foreach ($selectable['courses'] ?? [] as $c) {
        $code = $c['course_code'];
        $rn = $recMap[$code] ?? '(no rec)';
        $match = ($c['course_name'] === $rn) ? 'OK' : 'DIFF';
        if ($match === 'DIFF') {
            echo "  DIFF {$code}: sel='{$c['course_name']}' rec='{$rn}'\n";
        }
    }
}

// Scan all students with registrations for mismatches
echo "\nScanning all registered students for name mismatches...\n";
require dirname(__DIR__) . '/students/includes/AcademicSessionService.php';
require dirname(__DIR__) . '/students/includes/period_mode_helper.php';

$mismatchCount = 0;
$checked = 0;
if ($r = $db->query("SELECT DISTINCT student_id FROM semester_registration WHERE student_id IS NOT NULL AND student_id <> '' LIMIT 50")) {
    while ($row = $r->fetch_assoc()) {
        $s = trim($row['student_id']);
        if ($s === '') continue;
        $checked++;
        $pm = getStudentProgramPeriodMode($db, $s);
        $sess = (new AcademicSessionService($db))->getCurrentSession($pm);
        $tc = $svc->resolveRegistrationTermContext($s, null, (string)($sess['academic_year']??''), (int)($sess['semester_term']??1), $pm, false);
        if (!$tc) continue;
        $sel = $svc->getSelectableCoursesForTerm($s, $tc);
        if (empty($sel['courses'])) continue;
        $recs = wuc_course_recommendations($db, $s);
        $codes = array_flip(array_column($sel['courses'], 'course_code'));
        $recMap = [];
        foreach (['missing','retake'] as $t) {
            foreach ($recs[$t] ?? [] as $item) {
                if (isset($codes[$item['course_code']])) {
                    $recMap[$item['course_code']] = $item['course_name'];
                }
            }
        }
        foreach ($sel['courses'] as $c) {
            $code = $c['course_code'];
            if (!isset($recMap[$code])) continue;
            if ($c['course_name'] !== $recMap[$code]) {
                echo "  MISMATCH sid={$s} {$code}: sel='{$c['course_name']}' rec='{$recMap[$code]}'\n";
                $mismatchCount++;
            }
        }
    }
}
echo "Checked {$checked} students, mismatches: {$mismatchCount}\n";
