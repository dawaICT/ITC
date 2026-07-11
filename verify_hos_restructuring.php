<?php
require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/includes/hos_section_helpers.php';
require_once __DIR__ . '/hod/includes/hod_schema_helpers.php';

echo "HOS Restructuring Verification\n";
echo "Database: " . getenv('WUC_DB_NAME') . "\n\n";

$pass = true;

// 1. Verify ITC904 (Academic HOS)
echo "Testing ITC904 (Academic Head of Section):\n";
$deptContext = hod_resolve_department($db, 'ITC904');
if ($deptContext['section_id'] !== 'ENGICT') {
    echo "[FAIL] Section ID expected ENGICT, got: " . $deptContext['section_id'] . "\n";
    $pass = false;
} else {
    echo "[PASS] Section ID resolves to ENGICT\n";
}
if ($deptContext['section_type'] !== 'academic') {
    echo "[FAIL] Section Type expected academic, got: " . $deptContext['section_type'] . "\n";
    $pass = false;
} else {
    echo "[PASS] Section Type resolves to academic\n";
}
$expectedAcademic = [1, 2, 3, 7, 9, 10, 11, 12, 14, 25, 26];
$diff = array_diff($expectedAcademic, $deptContext['candidates']);
$diffRev = array_diff($deptContext['candidates'], $expectedAcademic);
if (!empty($diff) || !empty($diffRev)) {
    echo "[FAIL] Candidate departments mismatch. Got: " . implode(',', $deptContext['candidates']) . "\n";
    $pass = false;
} else {
    echo "[PASS] Candidate departments match expected academic list (CS, BA, ENG, AE, EEE, ME, ICT, AG, MMI, PSM, TEL)\n";
}
echo "\n";

// 2. Verify ITC910 (Transport HOS)
echo "Testing ITC910 (Transport Head of Section):\n";
$deptContext = hod_resolve_department($db, 'ITC910');
if ($deptContext['section_id'] !== 'TRANSPORT') {
    echo "[FAIL] Section ID expected TRANSPORT, got: " . $deptContext['section_id'] . "\n";
    $pass = false;
} else {
    echo "[PASS] Section ID resolves to TRANSPORT\n";
}
if ($deptContext['section_type'] !== 'transport') {
    echo "[FAIL] Section Type expected transport, got: " . $deptContext['section_type'] . "\n";
    $pass = false;
} else {
    echo "[PASS] Section Type resolves to transport\n";
}
$expectedTransport = [8, 13, 27, 28, 29];
$diff = array_diff($expectedTransport, $deptContext['candidates']);
$diffRev = array_diff($deptContext['candidates'], $expectedTransport);
if (!empty($diff) || !empty($diffRev)) {
    echo "[FAIL] Candidate departments mismatch. Got: " . implode(',', $deptContext['candidates']) . "\n";
    $pass = false;
} else {
    echo "[PASS] Candidate departments match expected transport list (TR, SF, TLOG, DRV, FLEET)\n";
}
echo "\n";

// 3. Verify ITC900 (Systems Admin)
echo "Testing ITC900 (Systems Admin HOS):\n";
$deptContext = hod_resolve_department($db, 'ITC900');
echo "Resolved Section ID: " . $deptContext['section_id'] . "\n";
echo "Resolved Section Type: " . $deptContext['section_type'] . "\n";
echo "Resolved Candidates Count: " . count($deptContext['candidates']) . "\n";
if (empty($deptContext['candidates'])) {
    echo "[FAIL] Expected candidates to be hydrated, got empty list\n";
    $pass = false;
} else {
    echo "[PASS] Systems Admin resolves section and hydrates candidate list successfully\n";
}
echo "\n";

// 4. Verify no overlapping candidate departments between academic and transport
$overlap = array_intersect($expectedAcademic, $expectedTransport);
if (!empty($overlap)) {
    echo "[FAIL] Academic and Transport departments overlap: " . implode(',', $overlap) . "\n";
    $pass = false;
} else {
    echo "[PASS] Data isolation verified: zero overlap between academic and transport departments\n";
}

if ($pass) {
    echo "\nAll HOS restructuring verification checks passed.\n";
    exit(0);
} else {
    echo "\nSome HOS verification checks failed.\n";
    exit(1);
}
