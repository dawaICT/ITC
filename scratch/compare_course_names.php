<?php
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
require dirname(__DIR__) . '/students/includes/AcademicSessionService.php';
require dirname(__DIR__) . '/students/includes/period_mode_helper.php';
require dirname(__DIR__) . '/includes/course_recommendation_engine.php';

$svc = new RegistrationDataService($db);
$sessSvc = new AcademicSessionService($db);
$sid = $argv[1] ?? 'CSE26456789';

$periodMode = getStudentProgramPeriodMode($db, $sid);
$session = $sessSvc->getCurrentSession($periodMode);
$ay = (string)($session['academic_year'] ?? '');
$sem = (int)($session['semester_term'] ?? 1);

$termContext = $svc->resolveRegistrationTermContext($sid, null, $ay, $sem, $periodMode, false);
$selectable = $svc->getSelectableCoursesForTerm($sid, $termContext);
$recs = wuc_course_recommendations($db, $sid);

$selectableByCode = [];
foreach ($selectable['courses'] ?? [] as $c) {
    $selectableByCode[(string)($c['course_code'] ?? '')] = (string)($c['course_name'] ?? '');
}

$selectableCodes = array_flip(array_keys($selectableByCode));
$recItems = [];
foreach (['missing', 'retake'] as $type) {
    foreach ($recs[$type] ?? [] as $item) {
        $code = (string)($item['course_code'] ?? '');
        if (isset($selectableCodes[$code])) {
            $recItems[$code] = (string)($item['course_name'] ?? '');
        }
    }
}

echo "SID={$sid}\n";
echo str_pad('CODE', 12) . str_pad('SELECTABLE', 40) . "RECOMMENDATION\n";
echo str_repeat('-', 100) . "\n";
$mismatches = 0;
foreach ($selectableByCode as $code => $selName) {
    $recName = $recItems[$code] ?? '(not in recs)';
    $match = ($selName === $recName) ? 'OK' : 'MISMATCH';
    if ($match === 'MISMATCH') {
        $mismatches++;
    }
    echo str_pad($code, 12) . str_pad($selName, 40) . $recName . " [{$match}]\n";
}
echo "\nMismatches: {$mismatches}\n";

// Also show raw courses table names
echo "\n--- courses table ---\n";
$codes = array_keys($selectableByCode);
if ($codes) {
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    $stmt = $db->prepare("SELECT course_code, course_name FROM courses WHERE course_code IN ($ph)");
    $stmt->bind_param($types, ...$codes);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        echo $row['course_code'] . ' => ' . $row['course_name'] . "\n";
    }
    $stmt->close();
}

// program_courses join (recommendation engine style)
echo "\n--- program_courses LEFT JOIN courses (rec engine) ---\n";
$prog = (string)($termContext['program_code'] ?? '');
if ($prog && $codes) {
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $types = 's' . str_repeat('s', count($codes));
    $params = array_merge([$prog], $codes);
    $stmt = $db->prepare("SELECT pc.course_code, COALESCE(c.course_name,'') AS course_name
        FROM program_courses pc LEFT JOIN courses c ON c.course_code = pc.course_code
        WHERE pc.program_code = ? AND pc.course_code IN ($ph)");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        echo $row['course_code'] . ' => ' . $row['course_name'] . "\n";
    }
    $stmt->close();
}

// course_levels join (getAvailableCourses style)
echo "\n--- course_levels JOIN courses TRIM/UPPER (selectable) ---\n";
$year = (int)($termContext['year_of_study'] ?? 1);
$semester = (string)($termContext['semester'] ?? '1');
if ($prog) {
    $stmt = $db->prepare("SELECT cl.course_code, COALESCE(c.course_name,'') AS course_name
        FROM course_levels cl
        JOIN courses c ON TRIM(UPPER(c.course_code)) = TRIM(UPPER(cl.course_code))
        WHERE cl.program_code = ? AND cl.semester = ? AND cl.year = ?");
    $yr = (string)$year;
    $stmt->bind_param('sss', $prog, $semester, $yr);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        echo $row['course_code'] . ' => ' . $row['course_name'] . "\n";
    }
    $stmt->close();
}
