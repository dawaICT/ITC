<?php
/**
 * Automated runner for tools/DEBUG_PROMPT_HARD_account_creation.md gates.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/run_hard_debug_gates.php
 *   C:\xampp\php\php.exe scripts/run_hard_debug_gates.php --apply
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

putenv('APP_ENV=development');
$root = dirname(__DIR__);
require_once $root . '/db/connect.php';
require_once $root . '/includes/staff_role_helpers.php';

$apply = in_array('--apply', $argv ?? [], true);
$failures = 0;
$passes = 0;

function gate(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        echo "[PASS] {$name}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    } else {
        $failures++;
        echo "[FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    }
}

echo "=== HARD DEBUG GATES ===\n";
echo ($apply ? "Mode: APPLY repairs where supported\n\n" : "Mode: VERIFY only\n\n");

// Gate 0 — baseline accounts
$itc907 = wuc_resolve_staff_roles($db, 'ITC907');
$itc900 = wuc_resolve_staff_roles($db, 'ITC900');
gate(
    'Gate 0a ITC907 pure lecturer',
    in_array('lecturer', $itc907['all_roles'] ?? [], true)
        && !in_array('systems_admin', $itc907['all_roles'] ?? [], true),
    'roles=' . json_encode($itc907['all_roles'] ?? [])
);
gate(
    'Gate 0b ITC900 systems_admin present',
    in_array('systems_admin', $itc900['all_roles'] ?? [], true),
    'roles=' . json_encode($itc900['all_roles'] ?? [])
);

// Gate 1 — schema tables exist
$requiredTables = [
    'staff', 'users', 'user_credentials', 'user_roles', 'roles',
    'staff_positions', 'positions', 'access_right', 'role_permissions',
    'user_portal_access', 'students', 'student_login', 'student_program',
];
$missingTables = [];
foreach ($requiredTables as $table) {
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    if (!$r || $r->num_rows === 0) {
        $missingTables[] = $table;
    }
}
gate('Gate 1 schema tables', $missingTables === [], $missingTables ? 'missing: ' . implode(', ', $missingTables) : 'all present');

// Gate 3 — entry points call provision (static file scan)
$entryChecks = [
    'admin/add_staff.php' => 'wuc_provision_staff_account',
    'admin/createAccount.php' => 'wuc_provision_staff_account',
    'admin/add_lecturer.php' => 'wuc_provision_staff_account',
    'admin/manage_lectures.php' => 'wuc_provision_staff_account',
    'admin/employer_accounts.php' => 'wuc_provision_staff_account',
    'registrar/add_staff.php' => 'wuc_provision_staff_account',
    'registrar/createAccount.php' => 'wuc_provision_staff_account',
    'vc/add_staff.php' => 'wuc_provision_staff_account',
    'vc/createAccount.php' => 'wuc_provision_staff_account',
    'admissions/includes/registration_handlers.php' => 'wuc_provision_student_account',
    'studentLogin.php' => 'wuc_repair_student_account_on_login',
    'staffLogin.php' => 'wuc_provision_staff_account',
];
foreach ($entryChecks as $relPath => $needle) {
    $full = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    $content = is_file($full) ? (string)file_get_contents($full) : '';
    gate("Gate 3 {$relPath}", strpos($content, $needle) !== false, "expects {$needle}");
}

// grant_access.php must stay disabled
$grant = (string)file_get_contents($root . '/grant_access.php');
gate(
    'Gate 3 grant_access.php disabled',
    strpos($grant, 'http_response_code(403)') !== false && strpos($grant, 'disabled for security') !== false,
    'legacy utility blocked at top'
);

// Gate 4 — no hardcoded ITC900 in lecturer paths
$lecturerFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/lecturers', FilesystemIterator::SKIP_DOTS)
);
$hardcodedHits = [];
foreach ($lecturerFiles as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $c = (string)file_get_contents($file->getPathname());
    if (preg_match('/ITC900|WUC900/', $c)) {
        $hardcodedHits[] = str_replace($root . '/', '', $file->getPathname());
    }
}
gate('Gate 4 no ITC900 in lecturers/', $hardcodedHits === [], $hardcodedHits ? implode(', ', $hardcodedHits) : 'clean');

// Gate 5 — audit gaps
ob_start();
$argv = ['audit_account_creation.php'];
if ($apply) {
    $argv[] = '--apply';
}
require $root . '/scripts/audit_account_creation.php';
$auditOut = ob_get_clean();
echo $auditOut;
preg_match('/Staff gaps: (\d+)/', $auditOut, $staffM);
preg_match('/Student gaps: (\d+)/', $auditOut, $stuM);
$staffGaps = (int)($staffM[1] ?? -1);
$studentGaps = (int)($stuM[1] ?? -1);
gate('Gate 5 staff gaps = 0', $staffGaps === 0, "count={$staffGaps}");
gate('Gate 5 student gaps = 0', $studentGaps === 0, "count={$studentGaps}");

// Gate 6 — DB parity sample for ITC907
$parityOk = true;
$parityDetail = [];
$uid = 0;
if ($stmt = $db->prepare('SELECT user_id FROM users WHERE staff_id = ? OR username = ? LIMIT 1')) {
    $sid = 'ITC907';
    $stmt->bind_param('ss', $sid, $sid);
    $stmt->execute();
    $stmt->bind_result($uid);
    $stmt->fetch();
    $stmt->close();
}
if ($uid <= 0) {
    $parityOk = false;
    $parityDetail[] = 'users missing';
} else {
    $checks = [
        'user_roles' => 'SELECT 1 FROM user_roles WHERE user_id = ? AND status = "active" LIMIT 1',
        'staff_positions' => 'SELECT 1 FROM staff_positions WHERE staff_id = "ITC907" LIMIT 1',
    ];
    foreach ($checks as $label => $sql) {
        if ($label === 'user_roles') {
            $st = $db->prepare($sql);
            $st->bind_param('i', $uid);
            $st->execute();
            $has = $st->get_result()->num_rows > 0;
            $st->close();
        } else {
            $has = $db->query($sql)->num_rows > 0;
        }
        if (!$has) {
            $parityOk = false;
            $parityDetail[] = $label . ' missing';
        }
    }
}
gate('Gate 6 ITC907 DB parity', $parityOk, $parityDetail ? implode(', ', $parityDetail) : 'users+roles+positions ok');

// Student baseline
$studentRef = null;
$res = $db->query("SELECT SID FROM students WHERE COALESCE(status,'active')='active' ORDER BY SID LIMIT 1");
if ($res && ($row = $res->fetch_assoc())) {
    $studentRef = (string)$row['SID'];
}
$studentParity = false;
if ($studentRef) {
    $uid = 0;
    $st = $db->prepare('SELECT user_id FROM users WHERE student_id = ? OR username = ? LIMIT 1');
    $st->bind_param('ss', $studentRef, $studentRef);
    $st->execute();
    $st->bind_result($uid);
    $st->fetch();
    $st->close();
    $hasLogin = false;
    if ($st = $db->prepare('SELECT 1 FROM student_login WHERE Sid = ? LIMIT 1')) {
        $st->bind_param('s', $studentRef);
        $st->execute();
        $hasLogin = $st->get_result()->num_rows > 0;
        $st->close();
    }
    $hasRole = false;
    if ($uid > 0) {
        $st = $db->prepare(
            'SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = ? AND r.role_name = "student" AND ur.status = "active" LIMIT 1'
        );
        $st->bind_param('i', $uid);
        $st->execute();
        $hasRole = $st->get_result()->num_rows > 0;
        $st->close();
    }
    $studentParity = $uid > 0 && $hasLogin && $hasRole;
}
gate('Gate 6 student baseline parity', $studentParity, $studentRef ? "SID={$studentRef}" : 'no active student found');

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passes}\n";
echo "Failed: {$failures}\n";
if ($failures === 0) {
    echo "ALL HARD GATES PASSED\n";
    exit(0);
}
echo "HARD GATES INCOMPLETE — fix failures and re-run\n";
if (!$apply) {
    echo "Tip: re-run with --apply to backfill provisioning gaps\n";
}
exit(1);
