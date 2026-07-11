<?php
// Insert test fee rows for a program/year/semester
require_once __DIR__ . '/connect.php';

$program = 'BSCS';
$academic_year = '2025/2026';
$semester = 1;
$year_of_study = 2;
$items = [
    ['Tuition', 50000.00],
    ['Registration', 2000.00],
    ['Lab Fee', 3000.00]
];

$db->begin_transaction();
try {
    $stmt = $db->prepare("INSERT INTO fee_structures (program_code, academic_year, semester, year_of_study, fee_description, amount, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())");
    if (!$stmt) { throw new Exception('Prepare failed: ' . $db->error); }

    foreach ($items as $it) {
        [$desc, $amt] = $it;
        if (!$stmt->bind_param('ssissd', $program, $academic_year, $semester, $year_of_study, $desc, $amt)) {
            // fallback manual insert
            $sql = sprintf(
                "INSERT INTO fee_structures (program_code, academic_year, semester, year_of_study, fee_description, amount, status, created_at) VALUES ('%s','%s',%d,%d,'%s',%F,'active',NOW())",
                $db->real_escape_string($program), $db->real_escape_string($academic_year), $semester, $year_of_study, $db->real_escape_string($desc), $amt
            );
            if (!$db->query($sql)) { throw new Exception('Insert fallback failed: ' . $db->error); }
        } else {
            if (!$stmt->execute()) { throw new Exception('Execute failed: ' . $stmt->error); }
        }
    }

    $db->commit();
    echo "Inserted test fees for $program $academic_year semester $semester year $year_of_study\n";
} catch (Exception $e) {
    $db->rollback();
    echo "Error inserting test fees: " . $e->getMessage() . "\n";
    exit(1);
}

// Quick verification
$res = $db->query("SELECT fee_description, amount FROM fee_structures WHERE program_code='".$db->real_escape_string($program)."' AND academic_year='".$db->real_escape_string($academic_year)."' AND semester=$semester AND year_of_study=$year_of_study");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo $r['fee_description'] . ' => ' . $r['amount'] . "\n";
    }
}
