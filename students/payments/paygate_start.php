<?php
/**
 * Unified DPO Pay hosted-checkout start endpoint.
 *
 * payment_type = fee_payment          → pay an existing invoice (amount clamped
 *                                       to the invoice's outstanding balance).
 * payment_type = course_registration  → pay the required registration amount for
 *                                       a term whose courses sit in
 *                                       course_registration as pending_payment.
 *                                       The amount is recomputed server-side and
 *                                       any posted amount is ignored.
 *
 * Money is never posted here: the transaction row is created as 'pending' and
 * only paygate_return.php (after a verifyToken call to DPO) posts the ledger.
 */
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../includes/payment_helpers.php';
require_once __DIR__ . '/../../includes/DpoGateway.php';

function pgs_redirect(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit();
    }
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    exit();
}

function pgs_fail(string $paymentType, string $message, string $invoiceRef = ''): void
{
    if ($paymentType === 'course_registration') {
        pgs_redirect('../courseReg.php?payment_error=' . urlencode($message));
    }
    if ($invoiceRef !== '') {
        pgs_redirect('../payment.php?invoice=' . urlencode($invoiceRef) . '&error=' . urlencode($message));
    }
    pgs_redirect('../fees.php?payment_status=error&payment_message=' . urlencode($message));
}

$paymentType = trim((string)($_POST['payment_type'] ?? 'fee_payment'));
if (!in_array($paymentType, ['fee_payment', 'course_registration'], true)) {
    $paymentType = 'fee_payment';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pgs_fail($paymentType, 'Invalid payment request.');
}

$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
$postedToken = (string)($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    pgs_fail($paymentType, 'Your payment session expired. Please try again.');
}

$studentId = (string)($_SESSION['Sid'] ?? '');

$config = payment_get_dpo_config($db);
if (!payment_dpo_is_ready($config)) {
    pgs_fail($paymentType, 'Online payment is not configured yet. Please use bank transfer or contact finance.');
}
if (!payment_ensure_gateway_transactions_table($db)) {
    pgs_fail($paymentType, 'Online payment is temporarily unavailable. Please contact finance.');
}

$amount = 0.0;
$invoice = null;
$invoiceReference = '';
$narration = 'Tuition Fee';
$semRegId = null;
$programCode = null;
$courseCodes = null;
$backUrl = payment_app_base_url() . '/wucportal/students/fees.php?payment_status=cancelled';

if ($paymentType === 'fee_payment') {
    $invoiceReference = trim((string)($_POST['invoice'] ?? ''));
    $invoice = payment_fetch_invoice($db, $invoiceReference, $studentId);
    if (!$invoice) {
        pgs_fail($paymentType, 'Invoice not found for this account.');
    }

    $outstanding = payment_invoice_outstanding($invoice);
    $amount = isset($_POST['amount']) && is_numeric($_POST['amount'])
        ? payment_decimal($_POST['amount'])
        : $outstanding;
    if ($amount <= 0.0 || $amount > ($outstanding + 0.01)) {
        pgs_fail($paymentType, 'Please enter a valid amount not exceeding the invoice balance.', $invoiceReference);
    }
    $narration = trim((string)($_POST['narration'] ?? 'Tuition Fee'));
    $backUrl = payment_app_base_url() . '/wucportal/students/payment.php?invoice=' . urlencode((string)($invoice['invoice_number'] ?? $invoiceReference)) . '&cancelled=1';
} else {
    // course_registration: every figure comes from the database, none from POST.
    $semester = (int)($_POST['semester'] ?? 0);
    $year = (int)($_POST['Year'] ?? 0);
    if ($semester <= 0 || $year <= 0) {
        pgs_fail($paymentType, 'Missing registration period.');
    }

    // The pending course rows created by processCourseReg.php are the obligation.
    $crCols = [];
    if ($m = $db->query('SHOW COLUMNS FROM course_registration')) {
        while ($c = $m->fetch_assoc()) { $crCols[strtolower((string)$c['Field'])] = (string)$c['Field']; }
        $m->free();
    }
    $crSidCol = $crCols['sid'] ?? ($crCols['student_id'] ?? 'Sid');
    $crSemCol = $crCols['semester'] ?? 'semester';
    $crYearCol = $crCols['year'] ?? 'Year';
    if (!isset($crCols['status'])) {
        pgs_fail($paymentType, 'Registration payment is not supported on this installation.');
    }

    $codes = [];
    $pendingSemRegId = null;
    $stmt = $db->prepare("SELECT course_code" . (isset($crCols['semester_registration_id']) ? ", semester_registration_id" : ", NULL AS semester_registration_id")
        . " FROM course_registration
           WHERE `{$crSidCol}` = ? AND `{$crSemCol}` = ? AND `{$crYearCol}` = ? AND status = 'pending_payment'");
    if ($stmt) {
        $semStr = (string)$semester;
        $yrStr = (string)$year;
        $stmt->bind_param('sss', $studentId, $semStr, $yrStr);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $codes[] = (string)$row['course_code'];
            if (!empty($row['semester_registration_id'])) {
                $pendingSemRegId = (int)$row['semester_registration_id'];
            }
        }
        $stmt->close();
    }
    if (empty($codes)) {
        pgs_fail($paymentType, 'No registration awaiting payment was found for that period. Please submit your course registration first.');
    }
    $courseCodes = array_values(array_unique($codes));
    $semRegId = $pendingSemRegId;

    // Academic year context comes from the semester_registration row when linked.
    $academicYear = null;
    if ($semRegId) {
        $stmt = $db->prepare("SELECT academic_year FROM semester_registration WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $semRegId);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $academicYear = trim((string)($row['academic_year'] ?? '')) ?: null;
            }
            $stmt->close();
        }
    }

    $requirement = payment_required_for_registration($db, $studentId, $year, $semester, $academicYear);
    $programCode = $requirement['program_code'] !== '' ? $requirement['program_code'] : null;
    if ($requirement['required_now'] <= 0.0) {
        pgs_redirect('../courseReg.php?payment_error=' . urlencode('No payment is currently due for this registration. Please submit it again to complete without payment.'));
    }

    // The obligation is invoiced at the full term tuition; the checkout amount
    // is only the threshold portion still owing.
    $academicYearForInvoice = $academicYear ?: date('Y');
    $invoice = payment_find_invoice_for_student_term($db, $studentId, $academicYearForInvoice, (string)$semester);
    if (!$invoice) {
        $created = payment_create_student_invoice(
            $db,
            $studentId,
            $requirement['tuition_total'] > 0 ? $requirement['tuition_total'] : $requirement['required_now'],
            $academicYearForInvoice,
            (string)$semester,
            'Tuition — ' . ($programCode ?: 'programme') . ' Year ' . $year . ' period ' . $semester
        );
        if (empty($created['success'])) {
            pgs_fail($paymentType, 'Unable to prepare your tuition invoice: ' . (string)($created['message'] ?? 'unknown error'));
        }
        $invoice = payment_fetch_invoice($db, (string)$created['invoice_number'], $studentId);
    }
    if (!$invoice) {
        pgs_fail($paymentType, 'Unable to locate your tuition invoice.');
    }
    $invoiceReference = (string)($invoice['invoice_number'] ?? '');

    $outstanding = payment_invoice_outstanding($invoice);
    $amount = min($requirement['required_now'], $outstanding > 0 ? $outstanding : $requirement['required_now']);
    $amount = payment_decimal($amount);
    if ($amount <= 0.0) {
        pgs_redirect('../courseReg.php?payment_error=' . urlencode('Your tuition invoice is already settled. Please submit your registration again to complete it.'));
    }
    $narration = 'Course registration payment — Year ' . $year . ', period ' . $semester;
    $backUrl = payment_app_base_url() . '/wucportal/students/courseReg.php?payment_error=' . urlencode('Payment was cancelled. Your registration is saved and you can pay to complete it.');
}

$student = payment_fetch_student_summary($db, $studentId);
$referenceNumber = payment_generate_reference('DPO');

$transactionResult = payment_create_gateway_transaction($db, [
    'invoice_number' => (string)($invoice['invoice_number'] ?? $invoiceReference),
    'student_id' => $studentId,
    'payment_type' => $paymentType,
    'semester_registration_id' => $semRegId,
    'program_code' => $programCode,
    'course_codes' => $courseCodes,
    'amount' => $amount,
    'currency' => (string)($config['currency'] ?? 'ZMW'),
    'narration' => $narration,
    'provider' => 'DPO',
    'reference_number' => $referenceNumber,
    'status' => 'pending',
    'created_by' => $studentId,
]);
if (empty($transactionResult['success'])) {
    pgs_fail($paymentType, 'Unable to prepare the payment transaction.', $invoiceReference);
}
$transactionId = (int)($transactionResult['id'] ?? 0);

$gateway = new DpoGateway(array_merge($config, [
    'redirect_url' => payment_app_base_url() . '/wucportal/students/payments/paygate_return.php?local_ref=' . urlencode($referenceNumber),
    'back_url' => $backUrl,
]));

$description = $paymentType === 'course_registration'
    ? 'Course registration payment - ' . $invoiceReference
    : 'Student fees payment - ' . $invoiceReference;

$response = $gateway->createToken([
    'amount' => $amount,
    'currency' => (string)($config['currency'] ?? 'ZMW'),
    'company_ref' => $referenceNumber,
    'company_acc_ref' => $invoiceReference,
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
    pgs_fail($paymentType, (string)($response['message'] ?? 'The payment gateway could not create a checkout session.'), $invoiceReference);
}

pgs_redirect($gateway->paymentUrl($providerToken));
