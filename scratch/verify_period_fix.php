<?php
declare(strict_types=1);
putenv('WUC_CONFIG_FILE=C:\xampp\wucportal-var\config\environment.php');
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_period_helpers.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$tests = [
    ['CSE', 'term', 3],
    ['DTL', 'semester', 2],
    ['TRANS-014', 'semester', 2],
    ['AUTO-006', 'term', 3], // trade test uses level but period_mode is term
];

$passed = 0;
foreach ($tests as [$code, $expectedMode, $expectedMax]) {
    $meta = getProgramAcademicStructure($db, $code);
    $coursesY1P1 = getCoursesForProgramYear($db, $code, 1, 1);
    $ok = ($meta['period_mode'] === $expectedMode && $meta['max_periods'] === $expectedMax);
    echo ($ok ? 'PASS' : 'FAIL') . " {$code}: mode={$meta['period_mode']} max={$meta['max_periods']} courses_y1p1=" . count($coursesY1P1) . "\n";
    if ($ok) {
        $passed++;
    }
    $bad = validateCoursePeriodAssignment($db, $code, 1, $expectedMax + 1);
    echo '  invalid period check: ' . ($bad['ok'] ? 'FAIL (should reject)' : 'PASS (rejected)') . "\n";
}

echo "\n{$passed}/" . count($tests) . " structure tests passed\n";
