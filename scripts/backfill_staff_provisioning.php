<?php
/**
 * Audit and backfill lecturer/staff provisioning gaps (users, user_roles, positions, portals).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/backfill_staff_provisioning.php
 *   C:\xampp\php\php.exe scripts/backfill_staff_provisioning.php --apply
 *   C:\xampp\php\php.exe scripts/backfill_staff_provisioning.php --apply --role=lecturer
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/helpers/staff_provisioning.php';
require_once __DIR__ . '/../includes/staff_role_helpers.php';

$apply = in_array('--apply', $argv ?? [], true);
$roleFilter = null;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--role=') === 0) {
        $roleFilter = substr($arg, 7);
    }
}

echo ($apply ? 'APPLY' : 'DRY-RUN') . ": staff provisioning audit/backfill\n\n";

$referenceId = 'ITC900';
$ref = wuc_resolve_staff_roles($db, $referenceId);
echo "Reference {$referenceId}: roles=" . json_encode($ref['all_roles'] ?? []) . "\n\n";

$sql = 'SELECT staff_id, role, status FROM staff WHERE status = "active"';
if ($roleFilter !== null) {
    $sql .= ' AND role = "' . $db->real_escape_string(wuc_normalize_staff_role($roleFilter)) . '"';
}
$sql .= ' ORDER BY staff_id';
$res = $db->query($sql);

$gaps = [];
while ($row = $res->fetch_assoc()) {
    $sid = (string)$row['staff_id'];
    $role = wuc_normalize_staff_role((string)($row['role'] ?? 'staff'));

    $hasUser = false;
    $userId = 0;
    if ($stmt = $db->prepare('SELECT user_id FROM users WHERE staff_id = ? OR username = ? LIMIT 1')) {
        $stmt->bind_param('ss', $sid, $sid);
        $stmt->execute();
        $stmt->bind_result($userId);
        $hasUser = $stmt->fetch();
        $stmt->close();
    }

    $hasUserRole = false;
    if ($hasUser && $userId > 0) {
        $stmt = $db->prepare(
            'SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = ? AND ur.status = "active" LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $hasUserRole = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    }

    $hasPosition = false;
    if ($stmt = $db->prepare('SELECT 1 FROM staff_positions WHERE staff_id = ? LIMIT 1')) {
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $hasPosition = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    }

    $resolved = wuc_resolve_staff_roles($db, $sid);
    $resolvedRoles = $resolved['all_roles'] ?? [];

    if (!$hasUser || !$hasUserRole || !$hasPosition || $resolvedRoles === []) {
        $gaps[] = [
            'staff_id' => $sid,
            'role' => $role,
            'has_user' => $hasUser,
            'has_user_role' => $hasUserRole,
            'has_position' => $hasPosition,
            'resolved_roles' => $resolvedRoles,
        ];
        echo "GAP {$sid} ({$role}): user=" . ($hasUser ? 'Y' : 'N')
            . ' user_role=' . ($hasUserRole ? 'Y' : 'N')
            . ' position=' . ($hasPosition ? 'Y' : 'N')
            . ' resolved=' . json_encode($resolvedRoles) . "\n";
    }
}

echo "\nTotal gaps: " . count($gaps) . "\n";

if (!$apply || $gaps === []) {
    exit(0);
}

foreach ($gaps as $gap) {
    $provision = wuc_provision_staff_account($db, $gap['staff_id'], $gap['role'], null, 'backfill');
    echo ($provision['ok'] ? 'FIXED' : 'FAILED') . " {$gap['staff_id']}: " . implode(', ', $provision['messages']) . "\n";
}

wuc_ensure_lecturer_role_permissions($db);
echo "\nLecturer role permissions verified.\n";
