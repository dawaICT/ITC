<?php
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/payment_helpers.php';
require_once __DIR__ . '/../includes/DpoGateway.php';

function dpo_callback_redirect(string $url): never
{
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit();
    }

    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    exit();
}

function dpo_callback_xml_response(string $response = 'OK'): never
{
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?><API3G><Response>' . htmlspecialchars($response, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</Response></API3G>';
    exit();
}

function dpo_callback_load_transaction(mysqli $db, string $localRef, string $companyRef, string $token): ?array
{
    if ($localRef !== '') {
        $tx = payment_find_gateway_transaction($db, 'reference_number', $localRef);
        if ($tx) {
            return $tx;
        }
    }

    if ($companyRef !== '') {
        $tx = payment_find_gateway_transaction($db, 'reference_number', $companyRef);
        if ($tx) {
            return $tx;
        }
    }

    if ($token !== '') {
        $tx = payment_find_gateway_transaction($db, 'provider_token', $token);
        if ($tx) {
            return $tx;
        }
    }

    return null;
}

function dpo_callback_process(mysqli $db, DpoGateway $gateway, array $transaction, string $token, string $companyRef): array
{
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
        'notes' => $resultMessage,
    ];

    if ($resultCode === '000') {
        if (strtolower((string)($transaction['status'] ?? '')) !== 'completed') {
            $invoice = payment_fetch_invoice($db, (string)($transaction['invoice_number'] ?? ''), (string)($transaction['student_id'] ?? ''));
            if (!$invoice) {
                $updateFields['status'] = 'failed';
                payment_update_gateway_transaction($db, (int)$transaction['id'], $updateFields);
                return ['success' => false, 'message' => 'The invoice linked to this DPO payment could not be found.'];
            }

            $apply = payment_apply_completed_payment(
                $db,
                $invoice,
                payment_decimal($transaction['amount'] ?? 0),
                'DPO Pay',
                (string)($transaction['reference_number'] ?? ''),
                trim((string)($transaction['narration'] ?? 'DPO Pay online payment')),
                ['narration' => trim((string)($transaction['narration'] ?? 'DPO Pay online payment'))]
            );

            if (!$apply['success']) {
                $updateFields['status'] = 'failed';
                payment_update_gateway_transaction($db, (int)$transaction['id'], $updateFields);
                return ['success' => false, 'message' => (string)$apply['message']];
            }
        }

        $updateFields['status'] = 'completed';
        $updateFields['completed_at'] = date('Y-m-d H:i:s');
        if ($approval !== '') {
            $updateFields['notes'] = trim($resultMessage . ' Approval: ' . $approval);
        }
        payment_update_gateway_transaction($db, (int)$transaction['id'], $updateFields);

        return [
            'success' => true,
            'message' => $resultMessage !== '' ? $resultMessage : 'Payment completed successfully.',
            'invoice_number' => (string)($transaction['invoice_number'] ?? ''),
        ];
    }

    $updateFields['status'] = in_array(strtolower($resultMessage), ['cancelled', 'transaction cancelled'], true) ? 'cancelled' : 'failed';
    payment_update_gateway_transaction($db, (int)$transaction['id'], $updateFields);

    return [
        'success' => false,
        'message' => $resultMessage !== '' ? $resultMessage : 'DPO did not confirm this payment.',
        'invoice_number' => (string)($transaction['invoice_number'] ?? ''),
    ];
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
        dpo_callback_xml_response('ERROR');
    }

    $payload = json_decode(json_encode($xml), true);
    $token = trim((string)($payload['TransactionToken'] ?? ''));
    $companyRef = trim((string)($payload['CompanyRef'] ?? ''));
    $localRef = trim((string)($_GET['local_ref'] ?? ''));
    $transaction = dpo_callback_load_transaction($db, $localRef, $companyRef, $token);

    if (!$transaction || $token === '') {
        dpo_callback_xml_response('ERROR');
    }

    $result = dpo_callback_process($db, $gateway, $transaction, $token, $companyRef);
    dpo_callback_xml_response($result['success'] ? 'OK' : 'ERROR');
}

$localRef = trim((string)($_GET['local_ref'] ?? ''));
$token = trim((string)($_GET['TransactionToken'] ?? $_GET['TransID'] ?? ''));
$companyRef = trim((string)($_GET['CompanyRef'] ?? ''));
$transaction = dpo_callback_load_transaction($db, $localRef, $companyRef, $token);

if (!$transaction || $token === '') {
    dpo_callback_redirect('fees.php?payment_status=error&payment_message=' . urlencode('We could not match the returned DPO payment to an invoice.'));
}

$result = dpo_callback_process($db, $gateway, $transaction, $token, $companyRef);
$status = $result['success'] ? 'success' : 'error';
$message = urlencode((string)$result['message']);
$invoiceNumber = urlencode((string)($result['invoice_number'] ?? ''));
dpo_callback_redirect('fees.php?payment_status=' . $status . '&payment_message=' . $message . '&invoice=' . $invoiceNumber);
