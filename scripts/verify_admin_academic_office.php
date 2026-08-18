<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/teaching_planner/init.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

foreach (['student_risk_summary', 'assessment_periods', 'test_timetable'] as $t) {
    $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'");
    echo $t . ': ' . (($r && $r->num_rows > 0) ? 'OK' : 'MISSING') . PHP_EOL;
    if ($r) {
        $r->free();
    }
}

echo 'canManageAcademicOfficeOps exists: ' . (function_exists('canManageAcademicOfficeOps') ? 'yes' : 'no') . PHP_EOL;
echo 'canAccessRegistrar exists: ' . (function_exists('canAccessRegistrar') ? 'yes' : 'no') . PHP_EOL;
echo 'TeachingPlannerService: ' . (class_exists('TeachingPlannerService') ? 'OK' : 'MISSING') . PHP_EOL;
echo 'tp_schema_ready: ' . (function_exists('tp_schema_ready') && tp_schema_ready($db) ? 'yes' : 'no') . PHP_EOL;

$adminPages = [
    'admin/risk_watchlist.php',
    'admin/teaching_planner_monitor.php',
    'admin/test_timetable.php',
    'admin/upload_ca.php',
    'admin/upload_exam_results.php',
    'admin/itc_academic_reports.php',
    'admin/students_by_admin.php',
];
foreach ($adminPages as $p) {
    $full = dirname(__DIR__) . '/' . $p;
    echo $p . ': ' . (is_file($full) ? 'exists' : 'MISSING') . PHP_EOL;
}

$nav = file_get_contents(dirname(__DIR__) . '/admin/includes/nav.php');
if ($nav === false) {
    echo "nav read failed\n";
    exit(1);
}
echo 'nav has /registrar/ links: ' . (str_contains($nav, '/registrar/') ? 'YES (BAD)' : 'no (good)') . PHP_EOL;
echo 'nav has Academic Office: ' . (str_contains($nav, 'Academic Office') ? 'yes' : 'no') . PHP_EOL;
echo "DONE\n";
