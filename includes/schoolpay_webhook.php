<?php
declare(strict_types=1);

/**
 * Authenticated SchoolPay webhook processing.
 *
 * The provider's school-fees webhook is signed with SHA-256 over the school's
 * API password followed by the SchoolPay receipt number. Only that signed,
 * nested payload is accepted here. Legacy flat callbacks are deliberately
 * rejected because they cannot be authenticated locally.
 */

require_once __DIR__ . '/portal_config.php';
require_once __DIR__ . '/payment_helpers.php';

if (!function_exists('schoolpay_webhook_config')) {
    /** @return array{enabled:bool,api_password:string} */
    function schoolpay_webhook_config(mysqli $db): array
    {
        $enabledValue = function_exists('wuc_portal_env')
            ? wuc_portal_env('WUC_SCHOOLPAY_WEBHOOK_ENABLED')
            : getenv('WUC_SCHOOLPAY_WEBHOOK_ENABLED');
        if ($enabledValue === null || $enabledValue === false || trim((string)$enabledValue) === '') {
            $enabledValue = payment_setting($db, 'schoolpay_webhook_enabled', '0');
        }

        $password = function_exists('wuc_portal_env')
            ? (string)(wuc_portal_env('WUC_SCHOOLPAY_API_PASSWORD', '') ?? '')
            : (string)(getenv('WUC_SCHOOLPAY_API_PASSWORD') ?: '');

        return [
            'enabled' => in_array(strtolower(trim((string)$enabledValue)), ['1', 'true', 'yes', 'on'], true),
            'api_password' => trim($password),
        ];
    }
}

if (!function_exists('schoolpay_webhook_parse')) {
    /** @return array{ok:bool,error:string,event:array<string,mixed>} */
    function schoolpay_webhook_parse(array $payload): array
    {
        $payment = $payload['payment'] ?? null;
        if (!is_array($payment)) {
            return ['ok' => false, 'error' => 'Unsupported callback payload.', 'event' => []];
        }

        $type = strtoupper(trim((string)($payload['type'] ?? '')));
        $signature = strtolower(trim((string)($payload['signature'] ?? '')));
        $receipt = trim((string)($payment['schoolpayReceiptNumber'] ?? ''));
        $transactionId = trim((string)($payment['sourceChannelTransactionId'] ?? ''));
        $status = strtolower(trim((string)($payment['transactionCompletionStatus'] ?? '')));
        $amountRaw = $payment['amount'] ?? null;

        if (!in_array($type, ['SCHOOL_FEES', 'OTHER_FEES'], true)) {
            return ['ok' => false, 'error' => 'Unsupported payment type.', 'event' => []];
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return ['ok' => false, 'error' => 'Missing or malformed signature.', 'event' => []];
        }
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,40}$/', $receipt)) {
            return ['ok' => false, 'error' => 'Missing or malformed receipt number.', 'event' => []];
        }
        if ($transactionId === '' || strlen($transactionId) > 100) {
            return ['ok' => false, 'error' => 'Missing or malformed transaction ID.', 'event' => []];
        }
        if (!is_numeric($amountRaw) || payment_decimal($amountRaw) <= 0.0) {
            return ['ok' => false, 'error' => 'Payment amount must be greater than zero.', 'event' => []];
        }

        $studentRegistration = trim((string)($payment['studentRegistrationNumber'] ?? ''));
        $studentPaymentCode = trim((string)($payment['studentPaymentCode'] ?? ''));
        if (strlen($studentRegistration) > 50 || strlen($studentPaymentCode) > 50) {
            return ['ok' => false, 'error' => 'Student reference is too long.', 'event' => []];
        }

        return [
            'ok' => true,
            'error' => '',
            'event' => [
                'type' => $type,
                'signature' => $signature,
                'amount' => payment_decimal($amountRaw),
                'receipt_number' => $receipt,
                'transaction_id' => $transactionId,
                'status' => $status,
                'student_registration_number' => $studentRegistration,
                'student_payment_code' => $studentPaymentCode,
                'channel' => substr(trim((string)($payment['sourcePaymentChannel'] ?? 'SchoolPay')), 0, 50),
                'paid_at' => substr(trim((string)($payment['transactionCompletionDateAndTime'] ?? $payment['paymentDateAndTime'] ?? '')), 0, 30),
                'fee_description' => substr(trim((string)($payment['supplementaryFeeDescription'] ?? '')), 0, 120),
            ],
        ];
    }
}

if (!function_exists('schoolpay_webhook_signature_valid')) {
    function schoolpay_webhook_signature_valid(array $event, string $apiPassword): bool
    {
        $receipt = trim((string)($event['receipt_number'] ?? ''));
        $signature = strtolower(trim((string)($event['signature'] ?? '')));
        if ($receipt === '' || $signature === '' || $apiPassword === '') {
            return false;
        }
        return hash_equals(hash('sha256', $apiPassword . $receipt), $signature);
    }
}

if (!function_exists('schoolpay_webhook_safe_event')) {
    /** @return array<string,mixed> */
    function schoolpay_webhook_safe_event(array $event): array
    {
        $allowed = [
            'type', 'amount', 'receipt_number', 'transaction_id', 'status',
            'student_registration_number', 'student_payment_code', 'channel',
            'paid_at', 'fee_description',
        ];
        return array_intersect_key($event, array_flip($allowed));
    }
}

if (!function_exists('schoolpay_webhook_reference')) {
    /** Shared gateway/ledger reference keeps reconciliation joins exact. */
    function schoolpay_webhook_reference(array $event): string
    {
        return 'SP-' . (string)($event['receipt_number'] ?? '');
    }
}

if (!function_exists('schoolpay_webhook_resolve_student')) {
    function schoolpay_webhook_resolve_student(mysqli $db, array $event): ?string
    {
        $candidates = array_values(array_unique(array_filter([
            trim((string)($event['student_registration_number'] ?? '')),
            trim((string)($event['student_payment_code'] ?? '')),
        ], static fn(string $value): bool => $value !== '')));

        foreach ($candidates as $candidate) {
            $stmt = $db->prepare('SELECT SID FROM students WHERE SID = ? LIMIT 1');
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('s', $candidate);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && trim((string)($row['SID'] ?? '')) !== '') {
                return trim((string)$row['SID']);
            }
        }

        return null;
    }
}

if (!function_exists('schoolpay_webhook_choose_invoice')) {
    /**
     * @param array<int,array<string,mixed>> $invoices
     * @return array{invoice:?array,reason:string}
     */
    function schoolpay_webhook_choose_invoice(array $invoices, float $amount): array
    {
        $amount = payment_decimal($amount);
        $open = [];
        foreach ($invoices as $invoice) {
            $outstanding = payment_invoice_outstanding($invoice);
            if ($outstanding > 0.0) {
                $invoice['_schoolpay_outstanding'] = $outstanding;
                $open[] = $invoice;
            }
        }

        if (!$open) {
            return ['invoice' => null, 'reason' => 'No outstanding invoice was found for the student.'];
        }

        $exact = array_values(array_filter($open, static function(array $invoice) use ($amount): bool {
            return abs((float)$invoice['_schoolpay_outstanding'] - $amount) <= 0.01;
        }));
        if (count($exact) === 1) {
            unset($exact[0]['_schoolpay_outstanding']);
            return ['invoice' => $exact[0], 'reason' => 'Matched a unique invoice balance.'];
        }
        if (count($exact) > 1) {
            return ['invoice' => null, 'reason' => 'Multiple invoices have the same matching balance.'];
        }

        if (count($open) === 1 && $amount <= ((float)$open[0]['_schoolpay_outstanding'] + 0.01)) {
            $invoice = $open[0];
            unset($invoice['_schoolpay_outstanding']);
            return ['invoice' => $invoice, 'reason' => 'Applied to the only outstanding invoice.'];
        }

        return ['invoice' => null, 'reason' => 'The payment cannot be allocated to one invoice without guessing.'];
    }
}

if (!function_exists('schoolpay_webhook_store_event')) {
    /** @return array<string,mixed>|null */
    function schoolpay_webhook_store_event(
        mysqli $db,
        array $event,
        string $studentId,
        ?array $invoice,
        string $status,
        string $note
    ): ?array {
        $gatewayReference = schoolpay_webhook_reference($event);
        $existing = payment_find_gateway_transaction($db, 'reference_number', $gatewayReference);
        $safePayload = schoolpay_webhook_safe_event($event);

        if ($existing) {
            $transactionId = (int)($existing['id'] ?? 0);
            if ($transactionId > 0 && (string)($existing['status'] ?? '') !== 'completed') {
                payment_update_gateway_transaction($db, $transactionId, [
                    'provider_transaction_id' => (string)$event['transaction_id'],
                    'status' => $status,
                    'notes' => $note,
                    'request_payload' => $safePayload,
                    'verified_by' => 'schoolpay_webhook',
                    'verified_at' => date('Y-m-d H:i:s'),
                ]);
                if ($invoice !== null || $studentId !== '') {
                    $invoiceNumber = trim((string)($invoice['invoice_number'] ?? $invoice['invoice_no'] ?? $invoice['invoice'] ?? ''));
                    $amount = payment_decimal($event['amount'] ?? 0);
                    $stmt = $db->prepare(
                        'UPDATE payment_gateway_transactions
                            SET invoice_number = ?, student_id = ?, amount = ?
                          WHERE id = ? LIMIT 1'
                    );
                    if ($stmt) {
                        $stmt->bind_param('ssdi', $invoiceNumber, $studentId, $amount, $transactionId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
            return payment_find_gateway_transaction_by_id($db, $transactionId);
        }

        $invoiceNumber = trim((string)($invoice['invoice_number'] ?? $invoice['invoice_no'] ?? $invoice['invoice'] ?? ''));
        $created = payment_create_gateway_transaction($db, [
            'invoice_number' => $invoiceNumber,
            'student_id' => $studentId,
            'payment_type' => 'fee_payment',
            'amount' => payment_decimal($event['amount'] ?? 0),
            'currency' => 'ZMW',
            'narration' => 'SchoolPay receipt ' . (string)$event['receipt_number'],
            'provider' => 'SCHOOLPAY',
            'reference_number' => $gatewayReference,
            'provider_transaction_id' => (string)$event['transaction_id'],
            'status' => $status,
            'notes' => $note,
            'request_payload' => $safePayload,
            'created_by' => 'schoolpay_webhook',
        ]);

        if (empty($created['success'])) {
            return payment_find_gateway_transaction($db, 'reference_number', $gatewayReference);
        }
        return is_array($created['transaction'] ?? null) ? $created['transaction'] : null;
    }
}

if (!function_exists('schoolpay_webhook_process')) {
    /**
     * @param array{enabled?:bool,api_password?:string} $config
     * @return array{http_status:int,success:bool,status:string,message:string}
     */
    function schoolpay_webhook_process(mysqli $db, array $payload, array $config): array
    {
        if (empty($config['enabled'])) {
            return ['http_status' => 503, 'success' => false, 'status' => 'disabled', 'message' => 'SchoolPay webhook is not enabled.'];
        }
        $apiPassword = trim((string)($config['api_password'] ?? ''));
        if ($apiPassword === '') {
            return ['http_status' => 503, 'success' => false, 'status' => 'misconfigured', 'message' => 'SchoolPay webhook is not configured.'];
        }

        $parsed = schoolpay_webhook_parse($payload);
        if (!$parsed['ok']) {
            return ['http_status' => 400, 'success' => false, 'status' => 'invalid', 'message' => $parsed['error']];
        }
        $event = $parsed['event'];
        if (!schoolpay_webhook_signature_valid($event, $apiPassword)) {
            return ['http_status' => 401, 'success' => false, 'status' => 'unauthorized', 'message' => 'Webhook signature verification failed.'];
        }
        if ((string)$event['status'] !== 'completed') {
            return ['http_status' => 200, 'success' => true, 'status' => 'ignored', 'message' => 'The callback is not a completed payment.'];
        }
        if (!payment_ensure_gateway_transactions_table($db)) {
            return ['http_status' => 503, 'success' => false, 'status' => 'unavailable', 'message' => 'Payment reconciliation storage is unavailable.'];
        }

        $gatewayReference = schoolpay_webhook_reference($event);
        $existing = payment_find_gateway_transaction($db, 'reference_number', $gatewayReference);
        if ($existing && (string)($existing['status'] ?? '') === 'completed') {
            return ['http_status' => 200, 'success' => true, 'status' => 'duplicate', 'message' => 'Payment was already processed.'];
        }

        $studentId = schoolpay_webhook_resolve_student($db, $event);
        if ($studentId === null) {
            schoolpay_webhook_store_event($db, $event, '', null, 'needs_review', 'Student reference did not match a portal student.');
            return ['http_status' => 200, 'success' => true, 'status' => 'needs_review', 'message' => 'Payment accepted for finance reconciliation.'];
        }

        if ((string)$event['type'] !== 'SCHOOL_FEES') {
            schoolpay_webhook_store_event($db, $event, $studentId, null, 'needs_review', 'Supplementary fee payments require finance allocation.');
            return ['http_status' => 200, 'success' => true, 'status' => 'needs_review', 'message' => 'Payment accepted for finance reconciliation.'];
        }

        $decision = schoolpay_webhook_choose_invoice(
            payment_fetch_student_outstanding_invoices($db, $studentId),
            (float)$event['amount']
        );
        $invoice = $decision['invoice'];
        if ($invoice === null) {
            schoolpay_webhook_store_event($db, $event, $studentId, null, 'needs_review', $decision['reason']);
            return ['http_status' => 200, 'success' => true, 'status' => 'needs_review', 'message' => 'Payment accepted for finance reconciliation.'];
        }

        $transaction = schoolpay_webhook_store_event($db, $event, $studentId, $invoice, 'verified', $decision['reason']);
        if (!$transaction) {
            return ['http_status' => 500, 'success' => false, 'status' => 'error', 'message' => 'Payment could not be recorded for reconciliation.'];
        }

        $ledgerReference = schoolpay_webhook_reference($event);
        $posted = payment_apply_completed_payment(
            $db,
            $invoice,
            (float)$event['amount'],
            (string)($event['channel'] ?: 'SchoolPay'),
            $ledgerReference,
            'SchoolPay payment ' . (string)$event['receipt_number'],
            ['student_id' => $studentId, 'posted_by' => 'schoolpay_webhook']
        );

        $transactionId = (int)($transaction['id'] ?? 0);
        if (empty($posted['success'])) {
            if ($transactionId > 0) {
                payment_update_gateway_transaction($db, $transactionId, [
                    'status' => 'needs_review',
                    'result_code' => 'POST_FAILED',
                    'result_desc' => substr((string)($posted['message'] ?? 'Ledger posting failed.'), 0, 255),
                    'notes' => 'Authenticated payment requires finance review after ledger posting failure.',
                ]);
            }
            error_log('SchoolPay webhook ledger posting failed for receipt ' . (string)$event['receipt_number']);
            return ['http_status' => 200, 'success' => true, 'status' => 'needs_review', 'message' => 'Payment accepted for finance reconciliation.'];
        }

        if ($transactionId > 0) {
            payment_update_gateway_transaction($db, $transactionId, [
                'status' => 'completed',
                'result_code' => '0',
                'result_desc' => !empty($posted['duplicate']) ? 'Payment was already posted.' : 'Payment posted successfully.',
                'receipt_no' => (string)($posted['receipt_no'] ?? ''),
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return ['http_status' => 200, 'success' => true, 'status' => !empty($posted['duplicate']) ? 'duplicate' : 'completed', 'message' => 'Payment processed successfully.'];
    }
}
