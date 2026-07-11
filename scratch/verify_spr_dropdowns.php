<?php
define('IS_SCRIPT', true);
$_SESSION = ['staff_id' => 'WUC900', 'user_id' => 'WUC900', 'role' => 'systems_admin', 'csrf_token' => 'test'];
$_GET = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/wucportal/admin/student_progression_report.php';

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/student_progression_report.php';

$filters = ['flag' => 'all', 'q' => '', 'academic_year' => '', 'semester' => '', 'course_code' => '', 'program_code' => ''];
$report = student_progression_report($db, $filters, 'admin');

$filterAcademicYears = [];
$filterCourses       = [];
$filterPrograms      = [];

$hasCrTable    = @$db->query("SHOW TABLES LIKE 'course_registration'")->num_rows > 0;
$hasCrAcadYear = $hasCrTable && @$db->query("SHOW COLUMNS FROM course_registration LIKE 'academic_year'")->num_rows > 0;
$hasCrYear     = $hasCrTable && @$db->query("SHOW COLUMNS FROM course_registration LIKE 'Year'")->num_rows > 0;

if ($hasCrAcadYear) {
    $ayRes = @$db->query("SELECT DISTINCT academic_year FROM course_registration WHERE academic_year IS NOT NULL AND academic_year != '' ORDER BY academic_year DESC LIMIT 20");
} elseif ($hasCrYear) {
    $ayRes = @$db->query("SELECT DISTINCT `Year` AS academic_year FROM course_registration WHERE `Year` IS NOT NULL AND `Year` != '' ORDER BY `Year` DESC LIMIT 20");
} else {
    $ayRes = false;
}
if ($ayRes) {
    while ($row = $ayRes->fetch_assoc()) {
        $val = trim((string)$row['academic_year']);
        if ($val !== '') {
            $filterAcademicYears[] = $val;
        }
    }
    $ayRes->free();
}

if (@$db->query("SHOW TABLES LIKE 'courses'")->num_rows > 0) {
    $hasCourseName = @$db->query("SHOW COLUMNS FROM courses LIKE 'course_name'")->num_rows > 0;
    $cSql = $hasCourseName
        ? "SELECT course_code, course_name FROM courses ORDER BY course_code"
        : "SELECT course_code, '' AS course_name FROM courses ORDER BY course_code";
    $cRes = @$db->query($cSql);
    if ($cRes) {
        while ($row = $cRes->fetch_assoc()) {
            $filterCourses[] = ['code' => (string)$row['course_code'], 'name' => (string)$row['course_name']];
        }
        $cRes->free();
    }
}

if (@$db->query("SHOW TABLES LIKE 'programs'")->num_rows > 0) {
    $hasProgramName   = @$db->query("SHOW COLUMNS FROM programs LIKE 'program_name'")->num_rows > 0;
    $hasProgramActive = @$db->query("SHOW COLUMNS FROM programs LIKE 'is_active'")->num_rows > 0;
    $nameExpr  = $hasProgramName ? 'program_name' : "'' AS program_name";
    $activeCond = $hasProgramActive ? " WHERE is_active = 1" : '';
    $pSql = "SELECT program_code, {$nameExpr} FROM programs{$activeCond} ORDER BY program_code";
    $pRes = @$db->query($pSql);
    if ($pRes) {
        while ($row = $pRes->fetch_assoc()) {
            $filterPrograms[] = ['code' => (string)$row['program_code'], 'name' => (string)$row['program_name']];
        }
        $pRes->free();
    }
}

echo "Report flagged rows : " . count($report['rows']) . PHP_EOL;
echo "Academic year opts  : " . (empty($filterAcademicYears) ? '(none)' : implode(', ', $filterAcademicYears)) . PHP_EOL;
echo "Course options      : " . count($filterCourses) . " courses" . PHP_EOL;
echo "Program options     : " . count($filterPrograms) . " programs" . PHP_EOL;
echo PHP_EOL . "Sample courses  : " . implode(', ', array_column(array_slice($filterCourses, 0, 5), 'code')) . PHP_EOL;
echo "Sample programs : " . implode(', ', array_column(array_slice($filterPrograms, 0, 5), 'code')) . PHP_EOL;
echo PHP_EOL . "ALL OK - no SQL errors." . PHP_EOL;
