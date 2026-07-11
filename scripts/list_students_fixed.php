<?php
require_once __DIR__ . '/../db/connect.php';

$cols = [];
$r = $db->query("SHOW COLUMNS FROM students");
if (!$r) { echo "Failed to read students columns: " . $db->error . "\n"; exit(1); }
while ($c = $r->fetch_assoc()) { $cols[] = $c['Field']; }
if (empty($cols)) { echo "No columns found for students\n"; exit(1); }

// Determine SID-like column
$sidCol = null;
foreach (['SID','student_id','studentid','Sid','studentID','id'] as $try) {
    foreach ($cols as $c) {
        if (strcasecmp($c, $try) === 0) { $sidCol = $c; break 2; }
    }
}
if (!$sidCol) { $sidCol = $cols[0]; }

// Pick name columns if available
$fname = null; $lname = null;
foreach ($cols as $c) { if (strcasecmp($c,'Fname')===0||strcasecmp($c,'first_name')===0||strcasecmp($c,'firstname')===0) $fname = $c; if (strcasecmp($c,'Lname')===0||strcasecmp($c,'last_name')===0||strcasecmp($c,'lastname')===0) $lname = $c; }

$sql = "SELECT `".$db->real_escape_string($sidCol)."` AS SID";
if ($fname) $sql .= ", `".$db->real_escape_string($fname)."` AS FNAME";
if ($lname) $sql .= ", `".$db->real_escape_string($lname)."` AS LNAME";
$sql .= " FROM students LIMIT 20";

$res = $db->query($sql);
if (!$res) { echo "Query failed: " . $db->error . "\n"; exit(1); }
while ($r = $res->fetch_assoc()) {
    echo ($r['SID'] ?? '') . " | " . ($r['FNAME'] ?? '') . " " . ($r['LNAME'] ?? '') . "\n";
}
?>