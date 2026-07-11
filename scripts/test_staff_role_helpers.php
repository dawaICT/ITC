<?php
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/staff_role_helpers.php';

function role_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$aliases = [
    'System Admin' => 'systems_admin',
    'Finance Officer' => 'accountant',
    'Bursar' => 'accountant',
    'Admissions Officer' => 'admission_officer',
    'HOD' => 'head_of_department',
    'Driving Instructor' => 'transport_officer',
];
foreach ($aliases as $raw => $expected) {
    role_check(wuc_normalize_staff_role($raw) === $expected, "{$raw} normalizes to {$expected}");
}

role_check(wuc_normalize_staff_role('Custom Role') === 'custom role', 'login normalization preserves unknown roles');
role_check(wuc_normalize_staff_role('Custom Role', false) === 'staff', 'authorization normalization safely falls back');
role_check(
    wuc_primary_staff_role(['lecturer', 'registrar', 'accountant']) === 'registrar',
    'shared role priority is deterministic'
);

$result = $db->query(
    'SELECT sp.staff_id, p.PosName
     FROM staff_positions sp
     INNER JOIN positions p ON p.PosID = sp.PosID
     ORDER BY sp.id
     LIMIT 1'
);
$row = $result ? $result->fetch_assoc() : null;
if ($row) {
    $staffId = (string) $row['staff_id'];
    $expectedRole = wuc_normalize_staff_role((string) $row['PosName']);
    $resolved = wuc_resolve_staff_roles($db, $staffId);
    role_check(in_array($expectedRole, $resolved['all_roles'] ?? [], true), 'live position is resolved through the canonical map');
    role_check(wuc_staff_has_role($db, $staffId, $expectedRole), 'role membership uses the shared resolver');

    $_SESSION = [];
    role_check(wuc_hydrate_staff_roles($db, $staffId), 'live roles hydrate into the session');
    role_check(isset($_SESSION['role'], $_SESSION['all_roles']), 'hydration sets the canonical session contract');
}

echo "Staff role helper regression checks passed.\n";
