<?php
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
require dirname(__DIR__) . '/students/includes/StudentDataService.php';
require dirname(__DIR__) . '/students/includes/AcademicSessionService.php';
require dirname(__DIR__) . '/students/includes/period_mode_helper.php';
// Inline the key checks (do not include registration.php — it emits HTML):

function sem_cols(mysqli $db): array {
    $cols = [];
    if ($meta = $db->query('SHOW COLUMNS FROM semester_registration')) {
        while ($c = $meta->fetch_assoc()) { $cols[strtolower($c['Field'])] = $c['Field']; }
        $meta->free();
    }
    return $cols;
}

function check_sem_reg(mysqli $db, string $sid, string $ay, string $sem, string $periodType): ?array {
    $cols = sem_cols($db);
    $sidCols = array_values(array_filter(['student_id','sid'], fn($c) => isset($cols[$c])));
    $semCol = $cols['semester'] ?? 'semester';
    $periodCol = $cols['period_type'] ?? null;
    $sidSql = implode(' OR ', array_map(fn($c) => "`$c` = ?", $sidCols));
    $sql = "SELECT * FROM semester_registration WHERE ($sidSql) AND `$semCol` = ?";
    $types = str_repeat('s', count($sidCols)) . 's';
    $params = array_merge(array_fill(0, count($sidCols), $sid), [$sem]);
    if ($periodCol) { $sql .= " AND `$periodCol` = ?"; $types .= 's'; $params[] = $periodType; }
    if (isset($cols['academic_year'])) { $sql .= " AND (`{$cols['academic_year']}` = ? OR ? LIKE CONCAT(`{$cols['academic_year']}`, '%'))"; $types .= 'ss'; $params[] = $ay; $params[] = $ay; }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

$sid = 'CSE26456789';
$svc = new RegistrationDataService($db);
$sess = new AcademicSessionService($db);
$periodMode = getStudentProgramPeriodMode($db, $sid);
$session = $sess->getCurrentSession($periodMode);
$ay = (string)($session['academic_year'] ?? '2026');
$sem = (string)($session['semester_term'] ?? '1');

echo "=== Workflow state for $sid ===\n";
echo "Current period: $ay Term/Sem $sem (mode=$periodMode)\n";
echo "Best program: " . $svc->getBestStudentProgramCode($sid) . "\n";

$currentReg = check_sem_reg($db, $sid, $ay, $sem, $periodMode);
echo "Registration for CURRENT period: " . ($currentReg ? json_encode(['id'=>$currentReg['id'],'program'=>$currentReg['program_code']??'','sem'=>$currentReg['semester']??'']) : 'NONE') . "\n";

$latest = $svc->getLatestSemesterRegistration($sid);
echo "getLatestSemesterRegistration: " . json_encode($latest) . "\n";

if ($currentReg) {
    $yos = (int)($currentReg['year_of_study'] ?? 1);
    $courses = $svc->getRegisteredCourses($sid, $yos, (int)$sem, (int)$currentReg['id']);
    echo "Courses for current period reg: " . count($courses) . "\n";
} else {
    echo "Student needs term registration for current period\n";
    // What courseReg.php would show without ?id=
    if ($latest) {
        echo "BUG: courseReg.php fallback would use stale reg id={$latest['id']} sem={$latest['semester']} prog={$latest['program_code']}\n";
    }
}

echo "\n=== processCourseReg guard simulation (current term) ===\n";
require dirname(__DIR__) . '/includes/helpers/academic_structure_helpers.php';
$program = $svc->getBestStudentProgramCode($sid);
$yos = 1;
$semInt = (int)$sem;
$available = $svc->getAvailableCourses($program, $yos, $semInt);
echo "Available for $program Y$yos S$semInt: " . count($available) . "\n";
if ($available) {
    $codes = array_column($available, 'course_code');
    $guard = wuc_legacy_course_registration_guard($db, $sid, $program, $yos, $semInt, $codes);
    echo "Guard result: " . json_encode($guard) . "\n";
}

echo "\n=== FeeGuard for current term ===\n";
require dirname(__DIR__) . '/students/includes/FeeGuard.php';
$fg = fg_check_fee_threshold($db, $sid, $yos, $semInt, 50.0, $ay);
echo json_encode($fg) . "\n";

echo "\nDONE\n";
