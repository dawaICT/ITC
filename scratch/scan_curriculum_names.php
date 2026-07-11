<?php
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
require dirname(__DIR__) . '/includes/course_recommendation_engine.php';
require dirname(__DIR__) . '/students/includes/AcademicSessionService.php';
require dirname(__DIR__) . '/students/includes/period_mode_helper.php';

$svc = new RegistrationDataService($db);

// All program codes with curriculum
$programs = [];
if ($r = $db->query('SELECT DISTINCT program_code FROM program_courses')) {
    while ($row = $r->fetch_assoc()) {
        $programs[] = $row['program_code'];
    }
}

echo "Programs: " . implode(', ', $programs) . "\n\n";

foreach ($programs as $prog) {
    for ($year = 1; $year <= 3; $year++) {
        for ($sem = 1; $sem <= 3; $sem++) {
            $avail = $svc->getAvailableCourses($prog, $year, $sem);
            if (empty($avail)) continue;

            // Simulate rec names via exact join (recommendation engine style)
            foreach ($avail as $c) {
                $code = $c['course_code'];
                $stmt = $db->prepare('SELECT COALESCE(c.course_name,"") AS n FROM program_courses pc LEFT JOIN courses c ON c.course_code = pc.course_code WHERE pc.program_code = ? AND pc.course_code = ? LIMIT 1');
                $stmt->bind_param('ss', $prog, $code);
                $stmt->execute();
                $recName = ($stmt->get_result()->fetch_assoc()['n'] ?? '');
                $stmt->close();

                if ($c['course_name'] !== $recName) {
                    echo "MISMATCH {$prog} y{$year}s{$sem} {$code}:\n";
                    echo "  selectable/getAvailable: '{$c['course_name']}'\n";
                    echo "  rec-engine join:           '{$recName}'\n";
                }
            }
        }
    }
}

echo "Done scanning curriculum.\n";

// Check courses with empty course_name
echo "\nCourses with empty course_name in catalog:\n";
if ($r = $db->query("SELECT course_code, course_name, category FROM courses WHERE TRIM(COALESCE(course_name,'')) = '' LIMIT 20")) {
    while ($row = $r->fetch_assoc()) {
        echo "  {$row['course_code']} category='{$row['category']}'\n";
    }
}

// Check if category used anywhere as fallback
echo "\nCourses where category looks like a title (non-empty name + category differ significantly):\n";
if ($r = $db->query("SELECT course_code, course_name, category FROM courses WHERE TRIM(COALESCE(category,'')) <> '' AND TRIM(category) <> TRIM(course_name) LIMIT 10")) {
    while ($row = $r->fetch_assoc()) {
        echo "  {$row['course_code']}: name='{$row['course_name']}' category='{$row['category']}'\n";
    }
}
