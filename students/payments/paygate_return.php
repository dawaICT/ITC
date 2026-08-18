<?php
/**
 * DPO Pay return + IPN endpoint for payments started by paygate_start.php.
 *
 * Handles both the browser redirect back from the hosted checkout and DPO's
 * server-to-server XML notification. In BOTH cases the only trusted signal is
 * our own verifyToken call to the DPO API — the redirect alone never posts
 * money or activates a registration.
 *
 * On a verified (Result 000) payment:
 *   - the ledger/invoice is posted once, idempotently
 *     (payment_apply_completed_payment: FOR UPDATE lock + duplicate-ref check)
 *   - course_registration transactions additionally activate the student's
 *     pending_payment course rows and sync the canonical/legacy mirrors.
 *
 * No session guard: DPO's server posts here without a student session. The
 * transaction row (matched by our unique reference/token) carries the context.
 */
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/payment_helpers.php';
require_once __DIR__ . '/../../includes/DpoGateway.php';

function pgr_redirect(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit();
    }
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    exit();
}

function pgr_xml_response(string $response = 'OK'): void
{
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?><API3G><Response>' . htmlspecialchars($response, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</Response></API3G>';
    exit();
}

function pgr_load_transaction(mysqli $db, string $localRef, string $companyRef, string $token): ?array
{
    foreach ([['reference_number', $localRef], ['reference_number', $companyRef], ['provider_token', $token]] as [$column, $value]) {
        if ($value !== '') {
            $tx = payment_find_gateway_transaction($db, $column, $value);
            if ($tx) {
                return $tx;
            }
        }
    }
    return null;
}

function pgr_process(mysqli $db, DpoGateway $gateway, array $transaction, string $token, string $companyRef): array
{
    $paymentType = trim((string)($transaction['payment_type'] ?? 'fee_payment'));
    $verify = $gateway->verifyToken($token, true, $companyRef !== '' ? $companyRef : null);
    $verifyData = (array)($verify['data'] ?? []);
    $resultCode = trim((string)($verifyData['Result'] ?? ''));
    $resultMessage = trim((string)($verifyData['ResultExplanation'] ?? $verify['message'] ?? ''));
    $providerTransactionId = trim((string)($verifyData['TransactionRef'] ?? $verifyData['TransRef'] ?? ''));
    $approval = trim((string)($verifyData['TransactionApproval'] ?? $verifyData['ApprovalNumber'] ?? ''));

    $updateFields = [
        'provider_token' => $token,
        'provider_transaction_id' => $providerTransactionId,
        'response_payload' => $verify['raw'] ?? '',
        'result_code' => $resultCode,
        'result_desc' => $resultMessage,
        'notes' => $resultMessage,
    ];

    if ($resultCode !== '000') {
        $updateFields['status'] = in_array(strtolower($resultMessage), ['cancelled', 'transaction cancelled'], true) ? 'cancelled' : 'failed';
        payment_update_gateway_transaction($db, (int)$transaction['id'], $updateFields);
        return [
            'success' => false,
            'payment_type' => $paymentType,
            'message' => $resultMessage !== '' ? $resultMessage : 'The payment was not confirmed by the gateway.',
            'invoice_number' => (string)($transaction['invoice_number'] ?? ''),
        ];
    }

    // Bind verified gateway payload to the stored local transaction (amount / company ref).
    $expectedAmount = payment_decimal($transaction['amount'] ?? 0);
    $verifiedAmount = payment_decimal(
        $verifyData['TransactionAmount']
            ?? $verifyData['Amount']
            ?? $verifyData['TotalAmount']
            ?? $expectedAmount
    );
    if ($expectedAmount > 0 && abs($verifiedAmount - $expectedAmount) > 0.009) {
        error_log(sprintf(
            'paygate_return amount mismatch tx=%d expected=%s verified=%s',
            (int)$transaction['id'],
            (string)$expectedAmount,
            (string)$verifiedAmount
        ));
        $updateFields['status'] = 'failed';
        $updateFields['notes'] = 'Gateway amount did not match the local transaction.';
        payment_update_gateway_transaction($db, (int)$transaction['id'], $updateFields);
        return [
            'success' => false,
            'payment_type' => $paymentType,
            'message' => 'Payment verification failed amount validation.',
            'invoice_number' => (string)($transaction['invoice_number'] ?? ''),
        ];
    }

    $localRef = trim((string)($transaction['reference_number'] ?? $transaction['company_ref'] ?? ''));
    $verifiedCompanyRef = trim((string)($verifyData['CompanyRef'] ?? $verifyData['CompanyRefUnique'] ?? ''));
    if ($localRef !== '' && $verifiedCompanyRef !== '' && strcasecmp($localRef, $verifiedCompanyRef) !== 0) {
        error_log(sprintf(
            'paygate_return company ref mismatch tx=%d local=%s verified=%s',
            (int)$transaction['id'],
            $localRef,
            $verifiedCompanyRef
        ));
        $updateFields['status'] = 'failed';
        $updateFields['notes'] = 'Gateway company reference did not match the local transaction.';
        payment_update_gateway_transaction($db, (int)$transaction['id'], $updateFields);
        return [
            'success' => false,
            'payment_type' => $paymentType,
            'message' => 'Payment verification failed reference validation.',
            'invoice_number' => (string)($transaction['invoice_number'] ?? ''),
        ];
    }

    $receiptNo = trim((string)($transaction['receipt_no'] ?? ''));

    if (strtolower((string)($transaction['status'] ?? '')) !== 'completed') {
        $invoice = payment_fetch_invoice($db, (string)($transaction['invoice_number'] ?? ''), (string)($transaction['student_id'] ?? ''));
        if (!$invoice) {
            $updateFields['status'] = 'failed';
            payment_update_gateway_transaction($db, (int)$transaction['id'], $updateFields);
            return ['success' => false, 'payment_type' => $paymentType, 'message' => 'The invoice linked to this payment could not be found.'];
        }

        $narration = trim((string)($transaction['narration'] ?? 'DPO Pay online payment'));
        $apply = payment_apply_completed_payment(
            $db,
            $invoice,
            payment_decimal($transaction['amount'] ?? 0),
            'DPO Pay',
            (string)($transaction['reference_number'] ?? ''),
            $narration,
            ['narration' => $narration, 'posted_by' => 'dpo-gateway', 'student_id' => (string)($transaction['student_id'] ?? '')]
        );
        if (empty($apply['success'])) {
            $updateFields['status'] = 'failed';
            payment_update_gateway_transaction($db, (int)$transaction['id'], $updateFields);
            return ['success' => false, 'payment_type' => $paymentType, 'message' => (string)$apply['message']];
        }
        if (!empty($apply['receipt_no'])) {
            $receiptNo = (string)$apply['receipt_no'];
        }

        if ($paymentType === 'course_registration') {
            $activation = payment_activate_pending_registration($db, $transaction);
            if (empty($activation['success'])) {
                // The money IS posted; never fail the payment because of a
                // mirror problem — flag it for staff instead.
                error_log('paygate_return: registration activation issue for tx ' . (int)$transaction['id'] . ': ' . (string)($activation['message'] ?? ''));
                $updateFields['notes'] = trim($resultMessage . ' | ACTIVATION NEEDS ATTENTION: ' . (string)($activation['message'] ?? ''));
            }
        }
    }

    $updateFields['status'] = 'completed';
    $updateFields['completed_at'] = date('Y-m-d H:i:s');
    if ($receiptNo !== '') {
        $updateFields['receipt_no'] = $receiptNo;
    }
    if ($approval !== '') {
        $updateFields['notes'] = trim((string)($updateFields['notes'] ?? $resultMessage) . ' Approval: ' . $approval);
    }
    payment_update_gateway_transaction($db, (int)$transaction['id'], $updateFields);

    return [
        'success' => true,
        'payment_type' => $paymentType,
        'message' => $resultMessage !== '' ? $resultMessage : 'Payment completed successfully.',
        'invoice_number' => (string)($transaction['invoice_number'] ?? ''),
        'receipt_no' => $receiptNo,
    ];
}

function pgr_redirect_for(array $result): void
{
    $status = !empty($result['success']) ? 'success' : 'error';
    $message = (string)($result['message'] ?? '');
    $invoiceNumber = (string)($result['invoice_number'] ?? '');

    if (($result['payment_type'] ?? '') === 'course_registration') {
        if ($status === 'success') {
            $note = 'Payment received — your course registration is now active.';
            if (!empty($result['receipt_no'])) {
                $note .= ' Receipt: ' . $result['receipt_no'];
            }
            pgr_redirect('index.php?payment_status=success&payment_message=' . urlencode($note));
        }
        pgr_redirect('../courseReg.php?payment_error=' . urlencode($message !== '' ? $message : 'Payment was not completed. Your registration is saved — you can retry the payment.'));
    }

    pgr_redirect('../fees.php?payment_status=' . $status
        . '&payment_message=' . urlencode($message)
        . '&invoice=' . urlencode($invoiceNumber));
}

$config = payment_get_dpo_config($db);
$gateway = new DpoGateway($config);

$rawBody = (string)file_get_contents('php://input');
$contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
$bodyTrimmed = ltrim($rawBody);
$isXmlPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && ($rawBody !== '')
    && ((strpos($contentType, 'xml') !== false) || (substr($bodyTrimmed, 0, 1) === '<'));

if ($isXmlPost) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($rawBody, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) {
        pgr_xml_response('ERROR');
    }
    $payload = json_decode((string)json_encode($xml), true);
    $token = trim((string)($payload['TransactionToken'] ?? ''));
    $companyRef = trim((string)($payload['CompanyRef'] ?? ''));
    $localRef = trim((string)($_GET['local_ref'] ?? ''));
    $transaction = pgr_load_transaction($db, $localRef, $companyRef, $token);
    if (!$transaction || $token === '') {
        pgr_xml_response('ERROR');
    }
    $result = pgr_process($db, $gateway, $transaction, $token, $companyRef);
    pgr_xml_response(!empty($result['success']) ? 'OK' : 'ERROR');
}

$localRef = trim((string)($_GET['local_ref'] ?? ''));
$token = trim((string)($_GET['TransactionToken'] ?? $_GET['TransID'] ?? ''));
$companyRef = trim((string)($_GET['CompanyRef'] ?? ''));
$transaction = pgr_load_transaction($db, $localRef, $companyRef, $token);

if (!$transaction || $token === '') {
    pgr_redirect('../fees.php?payment_status=error&payment_message=' . urlencode('We could not match the returned payment. If you were charged, contact finance with your reference.'));
}

$result = pgr_process($db, $gateway, $transaction, $token, $companyRef);
pgr_redirect_for($result);
