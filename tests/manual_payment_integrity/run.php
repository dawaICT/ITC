<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db/connect.php';
require_once $root . '/includes/manual_payment_helpers.php';

$passed = 0;
$failed = 0;
$createdPaymentId = 0;
$accountId = 0;
$accountSnapshot = null;
$reference = 'MPT-' . strtoupper(bin2hex(random_bytes(6)));

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

function fetchAccount(mysqli $db, int $accountId): ?array
{
    $stmt = $db->prepare('SELECT * FROM student_fee_accounts WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

try {
    $page = source($root . '/accounts/fees_student_payments.php');
    $service = source($root . '/includes/manual_payment_helpers.php');
    $feeHelpers = source($root . '/includes/fees_helpers.php');

    check(strpos($page, 'manual_payment_record') !== false, 'manual-payment controller delegates record writes to the service');
    check(strpos($page, 'manual_payment_reverse') !== false, 'manual-payment controller delegates reversals to the service');
    check(strpos($page, "hash_equals(\$csrfToken, \$postedToken)") !== false, 'manual-payment controller uses constant-time CSRF validation');
    check(strpos($page, 'Database error:') === false, 'manual-payment controller does not expose raw database errors');
    check(strpos($page, '<option value="Bank Transfer">') === false, 'manual form cannot bypass bank-proof verification');
    check(strpos($page, '<option value="Airtel Money">') === false, 'manual form cannot fabricate Airtel completion');
    check(strpos($page, '<option value="Card">') === false, 'manual form cannot fabricate card completion');
    check(strpos($page, 'pendingPayments.php') !== false, 'manual form links staff to verified bank review');
    check(strpos($service, "FOR UPDATE") !== false, 'manual payment service locks mutable rows');
    check(strpos($service, "in_array(\$targetStatus, ['reversed', 'cancelled'], true)") !== false, 'reversal status is allowlisted server-side');
    check(strpos($service, "status = 'approved' AND payment_status = 'completed'") !== false, 'reversal update is state-guarded');
    check(strpos($feeHelpers, 'UPDATE student_accounts') === false, 'fee recalculation does not mutate the retired legacy balance mirror');
    check(strpos($feeHelpers, 'INSERT INTO student_accounts') === false, 'fee recalculation has one authoritative account write path');
    check(strpos($feeHelpers, '!empty($combinedRecords)') !== false, 'fee-account recalculation preserves de-duplicated historical ledger totals');

    $result = $db->query(
        "SELECT sfa.*
           FROM student_fee_accounts sfa
          WHERE sfa.status = 'active'
            AND sfa.student_id REGEXP '[^0-9]'
            AND NOT EXISTS (
                SELECT 1 FROM student_payments sp
                 WHERE sp.student_fee_account_id = sfa.id
                   AND sp.status = 'approved'
                   AND sp.payment_status = 'completed'
            )
            AND EXISTS (
                SELECT 1 FROM payments p
                 WHERE p.student_id = sfa.student_id
                   AND p.academic_year = sfa.academic_year
                   AND LOWER(p.status) IN ('completed','posted','confirmed','paid','success')
            )
          ORDER BY sfa.id DESC
          LIMIT 1"
    );
    $accountSnapshot = $result ? ($result->fetch_assoc() ?: null) : null;
    if ($result) {
        $result->free();
    }
    if (!$accountSnapshot) {
        throw new RuntimeException('No clean active alphanumeric fee account is available for the manual-payment fixture.');
    }
    $accountId = (int)$accountSnapshot['id'];

    $baseInput = [
        'student_fee_account_id' => $accountId,
        'amount' => 7.25,
        'payment_method' => 'Sponsor / Scholarship',
        'receipt_number' => strtolower($reference),
        'payment_date' => date('Y-m-d'),
        'notes' => 'Automated manual-payment integrity fixture',
    ];

    $bankAttempt = $baseInput;
    $bankAttempt['payment_method'] = 'Bank Transfer';
    $bankResult = manual_payment_record($db, $bankAttempt, 'ITC900');
    check(empty($bankResult['success']), 'unverified bank transfer is rejected by the backend service');

    $futureAttempt = $baseInput;
    $futureAttempt['payment_date'] = date('Y-m-d', strtotime('+1 day'));
    $futureResult = manual_payment_record($db, $futureAttempt, 'ITC900');
    check(empty($futureResult['success']), 'future-dated manual payment is rejected');

    $recorded = manual_payment_record($db, $baseInput, 'ITC900');
    $createdPaymentId = (int)($recorded['payment_id'] ?? 0);
    check(!empty($recorded['success']) && $createdPaymentId > 0, 'approved manual payment posts atomically', json_encode($recorded));
    check(($recorded['reference'] ?? '') === $reference, 'receipt/reference is normalized before storage');

    $stmt = $db->prepare('SELECT * FROM student_payments WHERE payment_id = ? LIMIT 1');
    $stmt->bind_param('i', $createdPaymentId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    check(($payment['status'] ?? '') === 'approved' && ($payment['payment_status'] ?? '') === 'completed', 'new ledger row has the approved/completed state');
    check(($payment['reference_number'] ?? '') === $reference && ($payment['receipt_number'] ?? '') === $reference, 'both reconciliation reference fields agree');

    $afterRecord = fetchAccount($db, $accountId);
    $expectedPaid = (float)$accountSnapshot['amount_paid'] + 7.25;
    check(
        abs((float)($afterRecord['amount_paid'] ?? 0) - $expectedPaid) < 0.001,
        'new manual payment preserves historical normalized-ledger totals',
        'amount_paid=' . (string)($afterRecord['amount_paid'] ?? '')
    );

    $duplicate = manual_payment_record($db, $baseInput, 'ITC900');
    check(empty($duplicate['success']), 'duplicate receipt/reference is rejected');
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM student_payments WHERE reference_number = ?');
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $referenceRows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    check($referenceRows === 1, 'duplicate attempt leaves one ledger row', 'rows=' . $referenceRows);

    $forgedStatus = manual_payment_reverse($db, $createdPaymentId, 'approved', 'Forged state change', 'ITC900');
    check(empty($forgedStatus['success']), 'forged reversal status is rejected');

    $reversed = manual_payment_reverse($db, $createdPaymentId, 'reversed', 'Automated integrity reversal', 'ITC900');
    check(!empty($reversed['success']), 'valid reversal completes atomically', json_encode($reversed));
    $stmt = $db->prepare('SELECT status, payment_status, reversal_reason FROM student_payments WHERE payment_id = ? LIMIT 1');
    $stmt->bind_param('i', $createdPaymentId);
    $stmt->execute();
    $reversedRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    check(($reversedRow['status'] ?? '') === 'reversed' && ($reversedRow['payment_status'] ?? '') === 'failed', 'reversed ledger row is excluded from completed totals');

    $afterReverse = fetchAccount($db, $accountId);
    check(
        abs((float)($afterReverse['amount_paid'] ?? 0) - (float)$accountSnapshot['amount_paid']) < 0.001
        && abs((float)($afterReverse['balance'] ?? 0) - (float)$accountSnapshot['balance']) < 0.001,
        'reversal restores the fee-account totals'
    );

    $replay = manual_payment_reverse($db, $createdPaymentId, 'reversed', 'Attempted duplicate reversal', 'ITC900');
    check(empty($replay['success']), 'a reversed payment cannot be reversed again');
} finally {
    if ($createdPaymentId > 0) {
        $stmt = $db->prepare('DELETE FROM student_payments WHERE payment_id = ?');
        $stmt->bind_param('i', $createdPaymentId);
        $stmt->execute();
        $stmt->close();
    }
    if ($accountId > 0 && is_array($accountSnapshot)) {
        $stmt = $db->prepare(
            'UPDATE student_fee_accounts
                SET amount_paid = ?, balance = ?, payment_status = ?, updated_at = ?
              WHERE id = ?'
        );
        $originalPaid = (float)$accountSnapshot['amount_paid'];
        $originalBalance = (float)$accountSnapshot['balance'];
        $originalStatus = (string)$accountSnapshot['payment_status'];
        $originalUpdatedAt = (string)$accountSnapshot['updated_at'];
        $stmt->bind_param('ddssi', $originalPaid, $originalBalance, $originalStatus, $originalUpdatedAt, $accountId);
        $stmt->execute();
        $stmt->close();
    }
}

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
