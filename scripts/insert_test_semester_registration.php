<?php
require_once __DIR__ . '/../db/connect.php';

// Usage: php insert_test_semester_registration.php SID PROGRAM SEMESTER YEAR
$Sid = $argv[1] ?? 'S12345';
$program = $argv[2] ?? 'BSCS';
$semester = $argv[3] ?? '1';
$Year = $argv[4] ?? '1';

echo "Inserting semester_registration for Sid={$Sid}, program={$program}, semester={$semester}, Year={$Year}\n";

$cols = [];
if ($res = $db->query("SHOW COLUMNS FROM semester_registration")) {
    while ($c = $res->fetch_assoc()) { $cols[strtolower($c['Field'])] = $c['Field']; }
    $res->free();
} else {
    echo "Failed to read semester_registration columns: " . $db->error . "\n";
    exit(1);
}

$sidCol = $cols['sid'] ?? ($cols['student_id'] ?? ($cols['student'] ?? ($cols['s_id'] ?? 'Sid')));
$semCol = $cols['semester'] ?? ($cols['sem'] ?? 'semester');
$yearCol = $cols['year'] ?? ($cols['year_of_study'] ?? ($cols['academic_year'] ?? 'Year'));
$progCol = $cols['program_code'] ?? ($cols['program'] ?? 'program_code');

echo "Detected columns: sid={$sidCol}, program={$progCol}, semester={$semCol}, year={$yearCol}\n";

// Check for duplicate
$checkSql = "SELECT id FROM semester_registration WHERE `".$db->real_escape_string($sidCol)."`='".$db->real_escape_string($Sid)."' AND `".$db->real_escape_string($progCol)."`='".$db->real_escape_string($program)."' AND `".$db->real_escape_string($semCol)."`='".$db->real_escape_string($semester)."' AND `".$db->real_escape_string($yearCol)."`='".$db->real_escape_string($Year)."' LIMIT 1";
$chk = $db->query($checkSql);
if ($chk && $chk->num_rows > 0) {
    echo "Row already exists, skipping insert.\n";
    exit(0);
}

$insertSql = "INSERT INTO semester_registration (`".$db->real_escape_string($progCol)."`, `".$db->real_escape_string($sidCol)."`, `".$db->real_escape_string($semCol)."`, `".$db->real_escape_string($yearCol)."`) VALUES ('".$db->real_escape_string($program)."', '".$db->real_escape_string($Sid)."', '".$db->real_escape_string($semester)."', '".$db->real_escape_string($Year)."')";
if ($db->query($insertSql)) {
    echo "Inserted successfully, id=".$db->insert_id."\n";
} else {
    echo "Insert failed: " . $db->error . "\n";
}

?>