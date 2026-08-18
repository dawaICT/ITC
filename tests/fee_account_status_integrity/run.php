<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db/connect.php';
require_once $root . '/includes/fees_helpers.php';

$passed = 0;
$failed = 0;
$accountId = 0;
$snapshot = null;

function check(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo '[PASS] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
        return;
    }
    $failed++;
    echo '[FAIL] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
}

function source(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $contents;
}

$page = source($root . '/accounts/fees_student_accounts.php');
$helpers = source($root . '/includes/fees_helpers.php');
$migration = source($root . '/migrations/20260717_fee_account_status_integrity.php');

check(strpos($page, 'hash_equals((string)$csrfToken, $postedToken)') !== false, 'fee-account controller uses constant-time CSRF validation');
check(strpos($page, 'fees_update_student_account_status') !== false, 'fee-account controller delegates state changes to the locked service');
check(strpos($page, "UPDATE student_fee_accounts SET status") === false, 'fee-account controller has no direct status write');
check(strpos($page, "\$where = ['1 = 1']") !== false, 'account listing no longer relies on an impossible deleted enum state');
check(strpos($helpers, "in_array(\$targetStatus, ['active', 'withdrawn', 'cancelled'], true)") !== false, 'status service has a strict allowlist');
check(strpos($helpers, 'LIMIT 1 FOR UPDATE') !== false, 'status service locks the selected account');
check(strpos($migration, 'chk_sfa_status_valid') !== false, 'status migration installs a database invariant');

$invalid = $db->query(
    "SELECT COUNT(*) AS total FROM student_fee_accounts
      WHERE status IS NULL OR status NOT IN ('active','withdrawn','cancelled')"
)->fetch_assoc();
check((int)($invalid['total'] ?? 0) === 0, 'live fee accounts contain no invalid enum state');

$constraint = $db->query(
    "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'student_fee_accounts'
        AND CONSTRAINT_NAME = 'chk_sfa_status_valid'
        AND CONSTRAINT_TYPE = 'CHECK'"
);
check($constraint && $constraint->num_rows === 1, 'live database enforces chk_sfa_status_valid');
if ($constraint) {
    $constraint->free();
}

$result = $db->query("SELECT * FROM student_fee_accounts WHERE status = 'active' ORDER BY id LIMIT 1");
$snapshot = $result ? ($result->fetch_assoc() ?: null) : null;
if ($result) {
    $result->free();
}
if (!$snapshot) {
    throw new RuntimeException('No active fee account is available for status-transition testing.');
}
$accountId = (int)$snapshot['id'];

try {
    foreach (['', 'deleted', 'approved'] as $forgedStatus) {
        $forged = fees_update_student_account_status($db, $accountId, $forgedStatus, 'ITC900');
        check(empty($forged['success']), 'forged status is rejected: ' . ($forgedStatus === '' ? '(empty)' : $forgedStatus));
    }

    $normalized = fees_update_student_account_status($db, $accountId, ' ACTIVE ', 'ITC900');
    check(!empty($normalized['success']) && ($normalized['status'] ?? '') === 'active', 'valid status is normalized safely');

    $withdrawn = fees_update_student_account_status($db, $accountId, 'withdrawn', 'ITC900');
    check(!empty($withdrawn['success']), 'valid active-to-withdrawn transition succeeds', json_encode($withdrawn));
    $state = $db->query('SELECT status FROM student_fee_accounts WHERE id = ' . $accountId)->fetch_assoc();
    check(($state['status'] ?? '') === 'withdrawn', 'withdrawn state is persisted');

    $repeated = fees_update_student_account_status($db, $accountId, 'withdrawn', 'ITC900');
    check(!empty($repeated['success']), 'idempotent repeated status request is safe');

    $reactivated = fees_update_student_account_status($db, $accountId, 'active', 'ITC900');
    check(!empty($reactivated['success']), 'withdrawn account can be deliberately reactivated');

    $constraintRejected = false;
    $db->begin_transaction();
    try {
        $db->query("UPDATE student_fee_accounts SET status = '' WHERE id = {$accountId}");
    } catch (Throwable $e) {
        $constraintRejected = true;
    } finally {
        $db->rollback();
    }
    check($constraintRejected, 'database constraint rejects an invalid status even outside the service');

    $invalidIdentity = fees_generate_student_account($db, 'NOT-A-STUDENT', 999999, 999999, '2026', 'January');
    check($invalidIdentity === null, 'fee-account generator rejects forged student/course/mode identities');
    $invalidYear = fees_generate_student_account(
        $db,
        (string)$snapshot['student_id'],
        (int)$snapshot['course_id'],
        (int)$snapshot['training_mode_id'],
        'not-a-year',
        'January'
    );
    check($invalidYear === null, 'fee-account generator rejects malformed academic years');
} finally {
    if ($accountId > 0 && is_array($snapshot)) {
        $stmt = $db->prepare('UPDATE student_fee_accounts SET status = ?, updated_at = ? WHERE id = ?');
        $originalStatus = (string)$snapshot['status'];
        $originalUpdatedAt = (string)$snapshot['updated_at'];
        $stmt->bind_param('ssi', $originalStatus, $originalUpdatedAt, $accountId);
        $stmt->execute();
        $stmt->close();
    }
}

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
