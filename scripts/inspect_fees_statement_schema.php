<?php
require_once __DIR__ . '/../db/connect.php';

$tables = ['student_fee_accounts', 'student_payments', 'payments', 'courses', 'training_modes'];
foreach ($tables as $t) {
    $r = $db->query("SHOW TABLES LIKE '{$t}'");
    echo $t . ': ' . (($r && $r->num_rows > 0) ? 'EXISTS' : 'MISSING') . "\n";
    if ($r && $r->num_rows > 0) {
        $d = $db->query("DESCRIBE `{$t}`");
        while ($row = $d->fetch_assoc()) {
            echo '  ' . $row['Field'] . ' ' . $row['Type'] . "\n";
        }
    }
}

$sid = $argv[1] ?? 'CSE26456789';
echo "\n--- student_fee_accounts for {$sid} ---\n";
$stmt = $db->prepare("SELECT * FROM student_fee_accounts WHERE student_id = ? LIMIT 3");
$stmt->bind_param('s', $sid);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    print_r($row);
}
$stmt->close();

echo "\n--- payments for {$sid} ---\n";
$stmt = $db->prepare("SELECT * FROM payments WHERE student_id = ? LIMIT 5");
if ($stmt) {
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
    $stmt->close();
}
