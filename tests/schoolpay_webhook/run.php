<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/db/connect.php';
require dirname(__DIR__, 2) . '/includes/schoolpay_webhook.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passes = 0;
$failures = 0;
$check = static function(string $label, bool $condition, string $detail = '') use (&$passes, &$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
    $condition ? $passes++ : $failures++;
};

$password = 'test-schoolpay-secret';
$receipt = '18847257';
$payload = [
    'signature' => hash('sha256', $password . $receipt),
    'type' => 'SCHOOL_FEES',
    'payment' => [
        'amount' => '500.00',
        'paymentDateAndTime' => '2026-07-17 10:00:00',
        'schoolpayReceiptNumber' => $receipt,
        'sourceChannelTransDetail' => 'Private payer name must not be persisted',
        'sourceChannelTransactionId' => 'TXN_9876543220',
        'sourcePaymentChannel' => 'MTN MobileMoney',
        'studentName' => 'Private Student Name',
        'studentPaymentCode' => '1006480152',
        'studentRegistrationNumber' => 'CSE26456789',
        'transactionCompletionStatus' => 'Completed',
    ],
];

$parsed = schoolpay_webhook_parse($payload);
$check('official nested payload parses', $parsed['ok'] === true, (string)($parsed['error'] ?? ''));
$event = $parsed['event'] ?? [];
$check('SHA-256 signature validates', schoolpay_webhook_signature_valid($event, $password));
$check('wrong API password is rejected', !schoolpay_webhook_signature_valid($event, 'wrong-secret'));
$check('amount is normalized to two decimals', ($event['amount'] ?? null) === 500.0, json_encode($event['amount'] ?? null));

$safe = schoolpay_webhook_safe_event($event);
$check('sanitized audit payload excludes signature', !array_key_exists('signature', $safe));
$check('sanitized audit payload excludes payer and student names',
    !array_key_exists('sourceChannelTransDetail', $safe) && !array_key_exists('studentName', $safe)
);
$check('gateway and ledger share one reconciliation reference', schoolpay_webhook_reference($event) === 'SP-' . $receipt);

$legacy = schoolpay_webhook_parse([
    'invoice' => 'INV-123',
    'transaction_id' => 'TX-123',
    'status' => 'paid',
    'amount' => 500,
    'student_id' => 'CSE26456789',
]);
$check('unsigned legacy flat callback is rejected', $legacy['ok'] === false);

$exact = schoolpay_webhook_choose_invoice([
    ['id' => 1, 'amount' => 1000, 'amount_paid' => 500, 'balance' => 500],
    ['id' => 2, 'amount' => 800, 'amount_paid' => 100, 'balance' => 700],
], 500);
$check('unique matching invoice balance is selected', (int)($exact['invoice']['id'] ?? 0) === 1);

$ambiguous = schoolpay_webhook_choose_invoice([
    ['id' => 1, 'amount' => 500, 'amount_paid' => 0, 'balance' => 500],
    ['id' => 2, 'amount' => 500, 'amount_paid' => 0, 'balance' => 500],
], 500);
$check('duplicate matching balances require review', $ambiguous['invoice'] === null);

$partial = schoolpay_webhook_choose_invoice([
    ['id' => 3, 'amount' => 1000, 'amount_paid' => 0, 'balance' => 1000],
], 250);
$check('partial payment applies to the only outstanding invoice', (int)($partial['invoice']['id'] ?? 0) === 3);

$overpayment = schoolpay_webhook_choose_invoice([
    ['id' => 4, 'amount' => 1000, 'amount_paid' => 800, 'balance' => 200],
], 250);
$check('overpayment is quarantined rather than guessed', $overpayment['invoice'] === null);

$resolved = schoolpay_webhook_resolve_student($db, $event);
$check('live student registration number resolves with a prepared lookup', $resolved === 'CSE26456789', (string)$resolved);

$countGatewayRows = static function(mysqli $db): int {
    $result = $db->query('SELECT COUNT(*) AS total FROM payment_gateway_transactions');
    return (int)($result->fetch_assoc()['total'] ?? 0);
};
$before = $countGatewayRows($db);
$badPayload = $payload;
$badPayload['signature'] = str_repeat('0', 64);
$badResult = schoolpay_webhook_process($db, $badPayload, ['enabled' => true, 'api_password' => $password]);
$after = $countGatewayRows($db);
$check('invalid signature returns unauthorized', ($badResult['http_status'] ?? 0) === 401 && ($badResult['status'] ?? '') === 'unauthorized');
$check('invalid signature performs no database write', $before === $after, "before={$before} after={$after}");

$disabledResult = schoolpay_webhook_process($db, $payload, ['enabled' => false, 'api_password' => $password]);
$check('disabled integration fails closed', ($disabledResult['http_status'] ?? 0) === 503 && ($disabledResult['status'] ?? '') === 'disabled');

$endpoint = (string)file_get_contents(dirname(__DIR__, 2) . '/students/schoolpay_callback.php');
$check('endpoint never appends raw callback payloads to a log', !str_contains($endpoint, 'file_put_contents'));
$check('endpoint uses the authenticated processor', str_contains($endpoint, 'schoolpay_webhook_process'));

// End-to-end database path with a dedicated, self-cleaning fixture.
$suffix = strtoupper(bin2hex(random_bytes(4)));
$fixtureSid = 'SPWH' . $suffix;
$fixtureInvoice = 'TSP-' . $suffix;
$fixtureReceipt = 'R' . $suffix;
$fixtureReference = 'SP-' . $fixtureReceipt;
$reviewReceipt = 'Q' . $suffix;
$reviewReference = 'SP-' . $reviewReceipt;
$fixtureAmount = 123.45;
$fixturePayload = [
    'signature' => hash('sha256', $password . $fixtureReceipt),
    'type' => 'SCHOOL_FEES',
    'payment' => [
        'amount' => (string)$fixtureAmount,
        'schoolpayReceiptNumber' => $fixtureReceipt,
        'sourceChannelTransDetail' => 'Sensitive payer detail',
        'sourceChannelTransactionId' => 'TX-' . $suffix,
        'sourcePaymentChannel' => 'Airtel Money',
        'studentName' => 'Webhook Test Student',
        'studentPaymentCode' => '',
        'studentRegistrationNumber' => $fixtureSid,
        'transactionCompletionStatus' => 'Completed',
    ],
];

try {
    $stmt = $db->prepare("INSERT INTO students (SID, Fname, Lname, sex, status) VALUES (?, 'Webhook', 'Fixture', 'M', 'Active')");
    $stmt->bind_param('s', $fixtureSid);
    $stmt->execute();
    $stmt->close();

    $academicYear = date('Y');
    $stmt = $db->prepare(
        "INSERT INTO invoices
         (invoice_number, student_id, SID, program_code, semester, status, academic_year, amount, amount_paid, balance, payment_status)
         VALUES (?, ?, ?, 'TEST', '1', 'Pending', ?, ?, 0.00, ?, 'PENDING')"
    );
    $stmt->bind_param('ssssdd', $fixtureInvoice, $fixtureSid, $fixtureSid, $academicYear, $fixtureAmount, $fixtureAmount);
    $stmt->execute();
    $stmt->close();

    $completed = schoolpay_webhook_process($db, $fixturePayload, ['enabled' => true, 'api_password' => $password]);
    $check('authenticated callback completes an unambiguous invoice',
        ($completed['http_status'] ?? 0) === 200 && ($completed['status'] ?? '') === 'completed',
        json_encode($completed)
    );

    $stmt = $db->prepare('SELECT status, amount_paid, balance FROM invoices WHERE invoice_number = ? LIMIT 1');
    $stmt->bind_param('s', $fixtureInvoice);
    $stmt->execute();
    $invoiceAfter = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $check('invoice totals and status are updated atomically',
        ($invoiceAfter['status'] ?? '') === 'Paid'
        && abs((float)($invoiceAfter['amount_paid'] ?? 0) - $fixtureAmount) < 0.01
        && abs((float)($invoiceAfter['balance'] ?? -1)) < 0.01,
        json_encode($invoiceAfter)
    );

    $stmt = $db->prepare('SELECT status, request_payload FROM payment_gateway_transactions WHERE reference_number = ? LIMIT 1');
    $stmt->bind_param('s', $fixtureReference);
    $stmt->execute();
    $gatewayAfter = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $check('gateway transaction is completed with sanitized metadata',
        ($gatewayAfter['status'] ?? '') === 'completed'
        && !str_contains((string)($gatewayAfter['request_payload'] ?? ''), 'Sensitive payer detail')
        && !str_contains((string)($gatewayAfter['request_payload'] ?? ''), 'Webhook Test Student'),
        (string)($gatewayAfter['status'] ?? 'missing')
    );

    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM student_payments WHERE Sid = ? AND reference_number = ?');
    $stmt->bind_param('ss', $fixtureSid, $fixtureReference);
    $stmt->execute();
    $ledgerCountBeforeReplay = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    $check('provider reference is present in the legacy reconciliation ledger', $ledgerCountBeforeReplay === 1);

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total
           FROM payment_gateway_transactions t
           JOIN student_payments sp ON sp.reference_number = t.reference_number
          WHERE t.reference_number = ? AND t.status = 'completed'"
    );
    $stmt->bind_param('s', $fixtureReference);
    $stmt->execute();
    $reconciledCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    $check('finance reconciliation join finds the completed ledger row', $reconciledCount === 1);

    $replayed = schoolpay_webhook_process($db, $fixturePayload, ['enabled' => true, 'api_password' => $password]);
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM student_payments WHERE Sid = ? AND reference_number = ?');
    $stmt->bind_param('ss', $fixtureSid, $fixtureReference);
    $stmt->execute();
    $ledgerCountAfterReplay = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    $check('replayed callback is idempotent',
        ($replayed['status'] ?? '') === 'duplicate' && $ledgerCountAfterReplay === $ledgerCountBeforeReplay,
        json_encode(['result' => $replayed, 'rows' => $ledgerCountAfterReplay])
    );

    $reviewPayload = $fixturePayload;
    $reviewPayload['payment']['schoolpayReceiptNumber'] = $reviewReceipt;
    $reviewPayload['payment']['sourceChannelTransactionId'] = 'QTX-' . $suffix;
    $reviewPayload['payment']['studentRegistrationNumber'] = 'UNKNOWN-' . $suffix;
    $reviewPayload['signature'] = hash('sha256', $password . $reviewReceipt);
    $reviewResult = schoolpay_webhook_process($db, $reviewPayload, ['enabled' => true, 'api_password' => $password]);
    $stmt = $db->prepare('SELECT status, student_id FROM payment_gateway_transactions WHERE reference_number = ? LIMIT 1');
    $stmt->bind_param('s', $reviewReference);
    $stmt->execute();
    $reviewRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $check('unmatched signed payment is quarantined for finance review',
        ($reviewResult['status'] ?? '') === 'needs_review'
        && ($reviewRow['status'] ?? '') === 'needs_review'
        && ($reviewRow['student_id'] ?? null) === '',
        json_encode(['result' => $reviewResult, 'row' => $reviewRow])
    );
} catch (Throwable $e) {
    $check('authenticated callback database path', false, $e->getMessage());
} finally {
    $cleanup = [
        ['DELETE FROM portal_alerts WHERE user_id = ?', 's', [$fixtureSid]],
        ['DELETE FROM payment_gateway_transactions WHERE reference_number IN (?, ?)', 'ss', [$fixtureReference, $reviewReference]],
        ['DELETE FROM student_payments WHERE Sid = ? AND reference_number = ?', 'ss', [$fixtureSid, $fixtureReference]],
        ['DELETE FROM payments WHERE student_id = ?', 's', [$fixtureSid]],
        ['DELETE FROM invoices WHERE invoice_number = ?', 's', [$fixtureInvoice]],
        ['DELETE FROM students WHERE SID = ?', 's', [$fixtureSid]],
    ];
    foreach ($cleanup as [$sql, $types, $params]) {
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }
    }
}

echo PHP_EOL . "PASS: {$passes}  FAIL: {$failures}" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
