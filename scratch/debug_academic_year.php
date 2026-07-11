<?php
define('IS_SCRIPT', true);
$_SESSION = ['staff_id' => 'WUC900', 'user_id' => 'WUC900', 'role' => 'systems_admin', 'csrf_token' => 'test'];
$_GET = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/student_progression_report.php';

$filters = ['flag' => 'all', 'q' => '', 'academic_year' => '', 'semester' => '', 'course_code' => '', 'program_code' => ''];
$report = student_progression_report($db, $filters, 'admin');

echo "Total rows: " . count($report['rows']) . PHP_EOL . PHP_EOL;

// Show unique academic_year + year_of_study combos
$combos = [];
foreach ($report['rows'] as $row) {
    $k = '[academic_year=' . var_export($row['academic_year'], true)
       . '] [year_of_study=' . var_export($row['year_of_study'], true)
       . '] [semester=' . var_export($row['semester'], true) . ']';
    $combos[$k] = ($combos[$k] ?? 0) + 1;
}
echo "Unique academic_year / year_of_study / semester combos in rows:" . PHP_EOL;
foreach ($combos as $k => $cnt) {
    echo "  {$cnt}x  {$k}" . PHP_EOL;
}

// Also check raw DB values
echo PHP_EOL . "--- Raw DB values in course_registration ---" . PHP_EOL;
$cr_res = $db->query("SELECT DISTINCT academic_year, Year FROM course_registration WHERE academic_year IS NOT NULL OR Year IS NOT NULL LIMIT 20");
if ($cr_res) {
    while ($r = $cr_res->fetch_assoc()) {
        echo "  academic_year=" . var_export($r['academic_year'] ?? null, true)
           . "  Year=" . var_export($r['Year'] ?? null, true) . PHP_EOL;
    }
} else {
    echo "  (query failed: " . $db->error . ")" . PHP_EOL;
}

echo PHP_EOL . "--- Raw DB values in student_program ---" . PHP_EOL;
$sp_res = $db->query("SELECT DISTINCT academic_year, startYear FROM student_program LIMIT 20");
if ($sp_res) {
    while ($r = $sp_res->fetch_assoc()) {
        echo "  academic_year=" . var_export($r['academic_year'] ?? null, true)
           . "  startYear=" . var_export($r['startYear'] ?? null, true) . PHP_EOL;
    }
} else {
    echo "  (query failed or column missing: " . $db->error . ")" . PHP_EOL;
}
