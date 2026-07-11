<?php
require_once __DIR__ . '/../db/connect.php';
$sid = $argv[1] ?? 'CSE26456789';
$stmt = $db->prepare("SELECT Course_Code, Total_CA, Year, semester, status FROM semester_assessment WHERE Sid = ? ORDER BY Course_Code, semester");
$stmt->bind_param('s', $sid);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    echo implode("\t", $row) . "\n";
}
$stmt->close();
$sr = $db->prepare("SELECT academic_year, year_of_study, semester, period_type, program_code FROM semester_registration WHERE student_id = ? OR sid = ? ORDER BY id DESC LIMIT 5");
$sr->bind_param('ss', $sid, $sid);
$sr->execute();
echo "--- registrations ---\n";
$r = $sr->get_result();
while ($row = $r->fetch_assoc()) {
    print_r($row);
}
$sr->close();
