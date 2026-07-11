<?php
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
require dirname(__DIR__) . '/includes/course_recommendation_engine.php';

$svc = new RegistrationDataService($db);
$sid = $argv[1] ?? 'CSE26456789';

// Show term context
$tc = $svc->resolveRegistrationTermContext($sid);
echo "Term context: " . json_encode($tc) . "\n";

$sel = $svc->getSelectableCoursesForTerm($sid, $tc);
echo "Already registered: " . ($sel['already_registered'] ? 'yes' : 'no') . "\n";
echo "Selectable courses:\n";
foreach ($sel['courses'] as $c) {
    echo "  {$c['course_code']} => '{$c['course_name']}'\n";
}

// Direct getRegisteredCourses
if ($tc) {
    $reg = $svc->getRegisteredCourses($sid, (int)$tc['year_of_study'], (int)$tc['semester'], $tc['id'] ?? null);
    echo "\ngetRegisteredCourses:\n";
    foreach ($reg as $c) {
        echo "  {$c['course_code']} => '{$c['course_name']}'\n";
    }
    
    $avail = $svc->getAvailableCourses($tc['program_code'], (int)$tc['year_of_study'], (int)$tc['semester']);
    echo "\ngetAvailableCourses ({$tc['program_code']} y{$tc['year_of_study']} s{$tc['semester']}):\n";
    foreach ($avail as $c) {
        echo "  {$c['course_code']} => '{$c['course_name']}'\n";
    }
}

$recs = wuc_course_recommendations($db, $sid);
echo "\nRecommendations (filtered to selectable):\n";
$codes = array_flip(array_column($sel['courses'], 'course_code'));
foreach (['missing','retake'] as $t) {
    foreach ($recs[$t] ?? [] as $item) {
        if (!isset($codes[$item['course_code']])) continue;
        echo "  [{$t}] {$item['course_code']} => '{$item['course_name']}'\n";
    }
}

// Check course_registration rows
echo "\ncourse_registration rows for student:\n";
if ($stmt = $db->prepare("SELECT course_code, semester, Year, status, is_active FROM course_registration WHERE Sid = ?")) {
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        echo "  " . json_encode($row) . "\n";
    }
    $stmt->close();
}

// Compare exact vs trim join for selectable course codes
echo "\nJoin comparison for selectable codes:\n";
foreach ($sel['courses'] as $c) {
    $code = $c['course_code'];
    $stmt = $db->prepare("SELECT 
        (SELECT course_name FROM courses WHERE course_code = ? LIMIT 1) AS exact,
        (SELECT course_name FROM courses WHERE TRIM(UPPER(course_code)) = TRIM(UPPER(?)) LIMIT 1) AS trim");
    $stmt->bind_param('ss', $code, $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (($row['exact'] ?? '') !== ($row['trim'] ?? '')) {
        echo "  {$code}: exact='{$row['exact']}' trim='{$row['trim']}' selectable='{$c['course_name']}'\n";
    }
}
