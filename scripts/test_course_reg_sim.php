<?php
// CLI test: simulate courseReg selection logic for a given student
require_once __DIR__ . '/../db/connect.php';

// Configure test values here
$sid = $argv[1] ?? 'S12345';
$semester = $argv[2] ?? '1';
$Year = $argv[3] ?? '1';

echo "Simulating for Sid={$sid}, semester={$semester}, Year={$Year}\n";

// Find latest semester_registration for this Sid
$cols = [];
if ($meta = $db->query("SHOW COLUMNS FROM semester_registration")) {
    while ($c = $meta->fetch_assoc()) { $cols[strtolower((string)$c['Field'])] = (string)$c['Field']; }
    $meta->free();
}
$srSidCol  = $cols['student_id'] ?? ($cols['sid'] ?? ($cols['student'] ?? 'student_id'));
$srSemCol  = $cols['semester'] ?? ($cols['semester_term'] ?? ($cols['term'] ?? 'semester'));
$srYearCol = $cols['year_of_study'] ?? ($cols['year'] ?? ($cols['academic_year'] ?? 'year_of_study'));

$sqlSr = "SELECT `{$srSemCol}` AS semester, `{$srYearCol}` AS Year, program_code FROM semester_registration WHERE `{$srSidCol}`='".$db->real_escape_string($sid)."' ORDER BY id DESC LIMIT 1";
$program = null;
if ($res = $db->query($sqlSr)) {
    if ($row = $res->fetch_assoc()) {
        $program = $row['program_code'];
        $semester = $row['semester'] ?? $semester;
        $Year = $row['Year'] ?? $Year;
    }
    $res->free();
}

if (!$program) {
    echo "No semester_registration found for Sid={$sid}\n";
    exit(1);
}

echo "Detected program={$program}, semester={$semester}, Year={$Year}\n";

// Detect course_levels columns
$clCols = [];
if ($m3 = $db->query("SHOW COLUMNS FROM course_levels")) { while ($c3 = $m3->fetch_assoc()) { $clCols[strtolower((string)$c3['Field'])] = (string)$c3['Field']; } $m3->free(); }
$clYearCol   = $clCols['year'] ?? ($clCols['year_level'] ?? ($clCols['yr'] ?? 'Year'));
$clSemCol    = $clCols['semester'] ?? ($clCols['sem'] ?? 'semester');
$clProgCol   = $clCols['program_code'] ?? ($clCols['program'] ?? 'program_code');
$clCourseCol = $clCols['course_code'] ?? ($clCols['course'] ?? 'course_code');
$clStatusCol = $clCols['status'] ?? null;

$progCol = $db->real_escape_string($clProgCol);
$semCol  = $db->real_escape_string($clSemCol);
$yearCol = $db->real_escape_string($clYearCol);
$courseCol = $db->real_escape_string($clCourseCol);
$where = "{$progCol}='".$db->real_escape_string($program)."' AND {$semCol}='".$db->real_escape_string($semester)."' AND {$yearCol}='".$db->real_escape_string($Year)."'";
if ($clStatusCol) { $where .= " AND `" . $db->real_escape_string($clStatusCol) . "`='active'"; }
$sqlCurr = "SELECT DISTINCT cl.{$courseCol} AS course_code, COALESCE(c.course_name,'') AS course_name, COALESCE(c.credit_hours,0) AS credit_hours FROM course_levels cl LEFT JOIN courses c ON c.course_code = cl.{$courseCol} WHERE {$where} ORDER BY cl.{$courseCol}";

$recordsSet = [];
if ($qrc = $db->query($sqlCurr)) {
    while ($rc = $qrc->fetch_assoc()) {
        $recordsSet[] = [
            'course_code' => $rc['course_code'],
            'course_name' => $rc['course_name'] ?? '',
            'credit_hours' => $rc['credit_hours'] ?? 0
        ];
    }
    $qrc->free();
}

echo "Courses for program/Year/semester:\n";
if (empty($recordsSet)) {
    echo "  (none)\n";
} else {
    foreach ($recordsSet as $c) {
        $name = $c['course_name'] ?? '';
        $credits = $c['credit_hours'] ?? 0;
        echo "  - " . $c['course_code'] . " | " . $name . " (" . $credits . " credits)\n";
    }
}

// Also show preselected (already registered) courses
$crCols = [];
if ($m2 = $db->query("SHOW COLUMNS FROM course_registration")) { while ($c2 = $m2->fetch_assoc()) { $crCols[strtolower((string)$c2['Field'])] = (string)$c2['Field']; } $m2->free(); }
$crYearCol = $crCols['year'] ?? ($crCols['academic_year'] ?? 'Year');
$preselected = [];
if ($qr = $db->query("SELECT DISTINCT course_code FROM course_registration WHERE Sid='".$db->real_escape_string($sid)."' AND semester='".$db->real_escape_string($semester)."' AND `{$crYearCol}`='".$db->real_escape_string($Year)."'")) {
    while ($rw = $qr->fetch_assoc()) { $preselected[] = $rw['course_code']; }
    $qr->free();
}

echo "Preselected (already registered) courses:\n";
if (empty($preselected)) { echo "  (none)\n"; } else { foreach ($preselected as $p) echo "  - $p\n"; }

?>