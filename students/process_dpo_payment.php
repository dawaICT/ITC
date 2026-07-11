<?php
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/payment_helpers.php';
require_once __DIR__ . '/../includes/DpoGateway.php';

function dpo_redirect(string $url): never
{
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit();
    }

    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    dpo_redirect('fees.php?payment_status=error&payment_message=' . urlencode('Invalid payment request.'));
}

$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
$postedToken = (string)($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    dpo_redirect('fees.php?payment_status=error&payment_message=' . urlencode('Your payment session expired. Please try again.'));
}

$studentId = (string)($_SESSION['Sid'] ?? '');
$invoiceReference = trim((string)($_POST['invoice'] ?? ''));
$invoice = payment_fetch_invoice($db, $invoiceReference, $studentId);

if (!$invoice) {
    dpo_redirect('fees.php?payment_status=error&payment_message=' . urlencode('Invoice not found for this account.'));
}

$outstanding = payment_invoice_outstanding($invoice);
$amount = isset($_POST['amount']) && is_numeric($_POST['amount'])
    ? payment_decimal($_POST['amount'])
    : $outstanding;

if ($amount <= 0.0 || $amount > ($outstanding + 0.01)) {
    dpo_redirect('payment.php?invoice=' . urlencode($invoiceReference) . '&error=' . urlencode('Please enter a valid amount not exceeding the invoice balance.'));
}

$config = payment_get_dpo_config($db);
if (!payment_dpo_is_ready($config)) {
    dpo_redirect('payment.php?invoice=' . urlencode($invoiceReference) . '&error=' . urlencode('DPO Pay is not configured yet. Please use bank transfer or contact finance.'));
}

$student = payment_fetch_student_summary($db, $studentId);
$referenceNumber = payment_generate_reference('DPO');
$transactionResult = payment_create_gateway_transaction($db, [
    'invoice_number' => (string)($invoice['invoice_number'] ?? $invoiceReference),
    'student_id' => $studentId,
    'amount' => $amount,
    'currency' => (string)($config['currency'] ?? 'ZMW'),
    'narration' => trim((string)($_POST['narration'] ?? 'Tuition Fee')),
    'provider' => 'DPO',
    'reference_number' => $referenceNumber,
    'status' => 'pending',
    'created_by' => $studentId,
]);

if (!$transactionResult['success']) {
    dpo_redirect('payment.php?invoice=' . urlencode($invoiceReference) . '&error=' . urlencode('Unable to prepare the payment transaction.'));
}

$transactionId = (int)($transactionResult['id'] ?? 0);
$gateway = new DpoGateway(array_merge($config, [
    'redirect_url' => payment_app_base_url() . '/wucportal/students/dpo_callback.php?local_ref=' . urlencode($referenceNumber),
    'back_url' => payment_app_base_url() . '/wucportal/students/payment.php?invoice=' . urlencode((string)($invoice['invoice_number'] ?? $invoiceReference)) . '&cancelled=1',
]));

$description = 'Student fees payment - ' . (string)($invoice['invoice_number'] ?? $invoiceReference);
$response = $gateway->createToken([
    'amount' => $amount,
    'currency' => (string)($config['currency'] ?? 'ZMW'),
    'company_ref' => $referenceNumber,
    'company_acc_ref' => (string)($invoice['invoice_number'] ?? $invoiceReference),
    'description' => $description,
    'customer_email' => (string)($student['email'] ?? ''),
    'customer_first_name' => (string)($student['Fname'] ?? 'Student'),
    'customer_last_name' => (string)($student['Lname'] ?? ''),
    'customer_country' => 'ZM',
    'service_date' => date('Y/m/d H:i'),
]);

$dpoData = (array)($response['data'] ?? []);
$providerToken = trim((string)($dpoData['TransToken'] ?? $dpoData['TransactionToken'] ?? ''));
$providerTransactionId = trim((string)($dpoData['TransRef'] ?? $dpoData['TransactionRef'] ?? ''));

payment_update_gateway_transaction($db, $transactionId, [
    'provider_token' => $providerToken,
    'provider_transaction_id' => $providerTransactionId,
    'status' => $response['success'] ? 'token_created' : 'failed',
    'response_payload' => $response['raw'] ?? '',
    'notes' => (string)($response['message'] ?? ''),
]);

if (!$response['success'] || $providerToken === '') {
    dpo_redirect('payment.php?invoice=' . urlencode((string)($invoice['invoice_number'] ?? $invoiceReference)) . '&error=' . urlencode((string)($response['message'] ?? 'DPO Pay could not create a checkout session.')));
}

dpo_redirect($gateway->paymentUrl($providerToken));

