<?php
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/fees_helpers.php';

$sid = $argv[1] ?? 'CSE26456789';

$stmt = $db->prepare("SELECT sfa.*, c.course_code, c.course_name FROM student_fee_accounts sfa INNER JOIN courses c ON c.id = sfa.course_id WHERE sfa.student_id = ? AND sfa.status = 'active' ORDER BY sfa.id DESC LIMIT 1");
$stmt->bind_param('s', $sid);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$account) {
    echo "No active account\n";
    exit(1);
}

echo "=== FEE ACCOUNT ===\n";
print_r($account);

$breakdown = fees_calculate_payable($db, (int)$account['course_id'], (int)$account['training_mode_id'], (string)$account['academic_year']);
echo "\n=== fees_calculate_payable (course_fees module) ===\n";
print_r($breakdown);

// Program-based fee_structure (registration workflow)
$prog = $db->prepare("SELECT program FROM students WHERE SID = ? LIMIT 1");
$prog->bind_param('s', $sid);
$prog->execute();
$programCode = (string)($prog->get_result()->fetch_assoc()['program'] ?? '');
$prog->close();
echo "\nStudent program: {$programCode}\n";

if ($programCode !== '') {
    $fs = $db->prepare("SELECT fee_description, amount, year_of_study, semester, status FROM fee_structure WHERE program_code = ? AND status = 'active' ORDER BY year_of_study, semester");
    $fs->bind_param('s', $programCode);
    $fs->execute();
    $res = $fs->get_result();
    echo "\n=== fee_structure (program) ===\n";
    $sum = 0;
    while ($row = $res->fetch_assoc()) {
        echo json_encode($row) . "\n";
        $sum += (float)$row['amount'];
    }
    echo "Total fee_structure rows sum: {$sum}\n";
    $fs->close();
}

// course_fees for this course
$cf = $db->prepare("SELECT * FROM course_fees WHERE course_id = ? AND training_mode_id = ? AND academic_year = ?");
$cf->bind_param('iis', $account['course_id'], $account['training_mode_id'], $account['academic_year']);
$cf->execute();
echo "\n=== course_fees row ===\n";
print_r($cf->get_result()->fetch_assoc());
$cf->close();

// course_fee_breakdown
$cfb = $db->prepare("SELECT fi.name, fi.amount, fi.mandatory_status, fi.collection_type FROM course_fee_breakdown cfb INNER JOIN fee_items fi ON fi.id = cfb.fee_item_id WHERE cfb.course_id = ? AND fi.academic_year = ? AND fi.status = 'active'");
$cfb->bind_param('is', $account['course_id'], $account['academic_year']);
$cfb->execute();
echo "\n=== course_fee_breakdown items ===\n";
$res = $cfb->get_result();
while ($row = $res->fetch_assoc()) {
    print_r($row);
}
