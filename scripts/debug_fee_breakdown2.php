<?php
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../students/includes/FeeGuard.php';

$sid = $argv[1] ?? 'CSE26456789';
$prog = fg_student_program($db, $sid);
echo "fg_student_program: " . ($prog ?? 'null') . "\n";

$stmt = $db->prepare("SELECT program, year FROM students WHERE SID = ?");
$stmt->bind_param('s', $sid);
$stmt->execute();
print_r($stmt->get_result()->fetch_assoc());
$stmt->close();

$stmt = $db->prepare("SELECT * FROM semester_registration WHERE student_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('s', $sid);
$stmt->execute();
print_r($stmt->get_result()->fetch_assoc());
$stmt->close();

if ($prog) {
    echo "fg_required_fee Y1 S2: " . fg_required_fee($db, $prog, 1, 2) . "\n";
}
$stmt = $db->prepare("SELECT program FROM students WHERE SID = ?");
$stmt->bind_param('s', $sid);
$stmt->execute();
$p = (string)($stmt->get_result()->fetch_assoc()['program'] ?? '');
$stmt->close();
if ($p) echo "fg_required_fee from students.program Y1 S2: " . fg_required_fee($db, $p, 1, 2) . "\n";
