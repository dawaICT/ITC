<?php
require_once __DIR__ . '/../db/connect.php';

echo "=== Staff with WUC prefix ===\n";
$r = $db->query("SELECT staff_id, Fname, Lname, role FROM staff WHERE staff_id LIKE 'WUC%' ORDER BY staff_id");
$count = 0;
while ($row = $r->fetch_assoc()) {
    echo implode(' | ', $row) . "\n";
    $count++;
}
echo "Count: {$count}\n\n";

echo "=== Students with WUC in SID ===\n";
$r2 = $db->query("SELECT SID, Fname, Lname, program FROM students WHERE SID LIKE '%WUC%' ORDER BY SID");
$count2 = 0;
while ($row = $r2->fetch_assoc()) {
    echo implode(' | ', $row) . "\n";
    $count2++;
}
echo "Count: {$count2}\n\n";

echo "=== Staff ID pattern summary ===\n";
$r3 = $db->query("SELECT staff_id FROM staff ORDER BY staff_id");
$patterns = [];
while ($row = $r3->fetch_assoc()) {
    $id = $row['staff_id'];
    if (preg_match('/^(WUC|ITC|LEC|ADM|HOD|REG|DEN|VC|DVC|ACC|LIB)/', $id, $m)) {
        $patterns[$m[1]] = ($patterns[$m[1]] ?? 0) + 1;
    } else {
        $patterns['other'] = ($patterns['other'] ?? 0) + 1;
    }
}
print_r($patterns);

echo "\n=== Recent student SIDs (last 15) ===\n";
$r4 = $db->query("SELECT SID, program, nrc_pass FROM students ORDER BY dte_adm DESC LIMIT 15");
while ($row = $r4->fetch_assoc()) {
    echo implode(' | ', $row) . "\n";
}

echo "\n=== Invalid student numbers (not matching CSE26456789 style) ===\n";
$r5 = $db->query("SELECT SID, program FROM students WHERE SID NOT REGEXP '^[A-Z0-9]{3}[0-9]{8}$' ORDER BY SID LIMIT 30");
$invalid = 0;
while ($row = $r5->fetch_assoc()) {
    echo implode(' | ', $row) . "\n";
    $invalid++;
}
echo "Invalid count (showing max 30): {$invalid}\n";

echo "\n=== Next ITC staff ID would be ===\n";
require_once __DIR__ . '/../includes/id_helpers.php';
echo generateNextStaffId($db) . "\n";
