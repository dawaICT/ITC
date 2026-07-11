<?php
/**
 * Audit and backfill incomplete staff/student account provisioning.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/audit_account_creation.php
 *   C:\xampp\php\php.exe scripts/audit_account_creation.php --apply
 *   C:\xampp\php\php.exe scripts/audit_account_creation.php --apply --type=staff
 *   C:\xampp\php\php.exe scripts/audit_account_creation.php --apply --type=student
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/helpers/staff_provisioning.php';
require_once __DIR__ . '/../includes/helpers/student_provisioning.php';
require_once __DIR__ . '/../includes/staff_role_helpers.php';

$apply = in_array('--apply', $argv ?? [], true);
$typeFilter = null;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--type=') === 0) {
        $typeFilter = substr($arg, 7);
    }
}

echo ($apply ? 'APPLY' : 'DRY-RUN') . ": account creation audit\n\n";

$staffGaps = 0;
$studentGaps = 0;

if ($typeFilter === null || $typeFilter === 'staff') {
    echo "=== STAFF ===\n";
    $res = $db->query("SELECT staff_id, role FROM staff WHERE status = 'active' ORDER BY staff_id");
    while ($row = $res->fetch_assoc()) {
        $sid = (string)$row['staff_id'];
        $role = wuc_normalize_staff_role((string)($row['role'] ?? 'staff'));

        $userId = 0;
        $stmt = $db->prepare('SELECT user_id FROM users WHERE staff_id = ? OR username = ? LIMIT 1');
        $stmt->bind_param('ss', $sid, $sid);
        $stmt->execute();
        $stmt->bind_result($userId);
        $stmt->fetch();
        $stmt->close();

        $hasUserRole = false;
        if ($userId > 0) {
            $stmt = $db->prepare('SELECT 1 FROM user_roles WHERE user_id = ? AND status = "active" LIMIT 1');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $hasUserRole = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        }

        $resolved = wuc_resolve_staff_roles($db, $sid);
        $ok = $userId > 0 && $hasUserRole && !empty($resolved['all_roles']);

        if (!$ok) {
            $staffGaps++;
            echo "GAP {$sid} ({$role}): user=" . ($userId > 0 ? 'Y' : 'N')
                . ' user_role=' . ($hasUserRole ? 'Y' : 'N')
                . ' resolved=' . json_encode($resolved['all_roles'] ?? []) . "\n";
            if ($apply) {
                $p = wuc_provision_staff_account($db, $sid, $role, null, 'audit_backfill');
                echo '  -> ' . ($p['ok'] ? 'FIXED' : 'FAILED') . ': ' . implode(', ', $p['messages']) . "\n";
            }
        }
    }
    echo "Staff gaps: {$staffGaps}\n\n";
}

if ($typeFilter === null || $typeFilter === 'student') {
    echo "=== STUDENTS ===\n";
    $res = $db->query("SELECT SID FROM students WHERE COALESCE(status, 'active') = 'active' ORDER BY SID LIMIT 500");
    while ($row = $res->fetch_assoc()) {
        $sid = (string)$row['SID'];

        $userId = 0;
        $stmt = $db->prepare('SELECT user_id FROM users WHERE student_id = ? OR username = ? LIMIT 1');
        $stmt->bind_param('ss', $sid, $sid);
        $stmt->execute();
        $stmt->bind_result($userId);
        $stmt->fetch();
        $stmt->close();

        $hasLogin = false;
        if ($stmt = $db->prepare('SELECT 1 FROM student_login WHERE Sid = ? LIMIT 1')) {
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $hasLogin = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        }

        $hasUserRole = false;
        if ($userId > 0) {
            $stmt = $db->prepare(
                'SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.role_id = ur.role_id
                 WHERE ur.user_id = ? AND r.role_name = "student" AND ur.status = "active" LIMIT 1'
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $hasUserRole = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        }

        $hasProgram = false;
        if ($stmt = $db->prepare('SELECT 1 FROM student_program WHERE Sid = ? LIMIT 1')) {
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $hasProgram = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        }

        $ok = $userId > 0 && $hasUserRole && $hasLogin;
        if (!$ok) {
            $studentGaps++;
            echo "GAP {$sid}: users=" . ($userId > 0 ? 'Y' : 'N')
                . ' login=' . ($hasLogin ? 'Y' : 'N')
                . ' user_role=' . ($hasUserRole ? 'Y' : 'N')
                . ' program=' . ($hasProgram ? 'Y' : 'N') . "\n";
            if ($apply) {
                $p = wuc_provision_student_account($db, $sid, [
                    'only_create_login' => true,
                    'assigned_by' => 'audit_backfill',
                ]);
                echo '  -> ' . ($p['ok'] ? 'FIXED' : 'FAILED') . ': ' . implode(', ', $p['messages']) . "\n";
            }
        }
    }
    echo "Student gaps: {$studentGaps}\n";
}

wuc_ensure_lecturer_role_permissions($db);
wuc_ensure_student_role_permissions($db);
echo "\nRole permission templates verified.\n";
