<?php
$page_title = 'Payments (Returning Students)';
require "includes/nav.php";
require_once __DIR__ . '/../includes/payment_helpers.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

function accounts_invoice_year(array $invoice): string
{
    $academicYear = trim((string)($invoice['academic_year'] ?? ''));
    if ($academicYear !== '') {
        return $academicYear;
    }
    return trim((string)($invoice['Year'] ?? ''));
}

$errors = [];
$successMessage = '';
$searchSid = trim((string)($_POST['search_sid'] ?? $_POST['Sid'] ?? $_GET['Sid'] ?? ''));
$studentRecord = null;
$outstandingInvoices = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'record_payment') {
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        $errors[] = 'Invalid request token. Please refresh the page and try again.';
    }

    $studentId = trim((string)($_POST['Sid'] ?? ''));
    $invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));
    $amount = isset($_POST['amount_paid']) && is_numeric($_POST['amount_paid']) ? payment_decimal($_POST['amount_paid']) : 0.0;
    $narration = trim((string)($_POST['narration'] ?? ''));
    $channel = trim((string)($_POST['channel'] ?? ''));

    $searchSid = $studentId;

    if ($studentId === '' || $invoiceNumber === '') {
        $errors[] = 'Student ID and invoice are required.';
    }
    if ($amount <= 0.0) {
        $errors[] = 'Please enter a valid payment amount.';
    }
    if ($narration === '') {
        $errors[] = 'Please provide a narration.';
    }
    if ($channel === '') {
        $errors[] = 'Please choose a payment channel.';
    }

    if (empty($errors)) {
        $invoice = payment_fetch_invoice($db, $invoiceNumber, $studentId);
        if (!$invoice) {
            $errors[] = 'The selected invoice could not be found for this student.';
        } else {
            $result = payment_apply_completed_payment(
                $db,
                $invoice,
                $amount,
                $channel,
                payment_generate_reference('RCPT'),
                $narration,
                ['narration' => $narration]
            );

            if ($result['success']) {
                $successMessage = (string)$result['message'];
            } else {
                $errors[] = (string)$result['message'];
            }
        }
    }
}

if ($searchSid !== '') {
    $studentRecord = payment_fetch_student_summary($db, $searchSid);
    if ($studentRecord) {
        $outstandingInvoices = payment_fetch_student_outstanding_invoices($db, $searchSid);
    }
}

$firstInvoice = !empty($outstandingInvoices) ? $outstandingInvoices[0] : null;
?>
<div class="container-fluid px-4 portal-dashboard accounts-page payments-returning-page">
    <div class="dashboard-header finance-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Payments (Returning Students)</h1>
                <p class="text-muted mb-0">Post verified payments against outstanding invoices.</p>
            </div>
            <div class="col-auto">
                <a href="pendingPayments.php" class="btn btn-outline-secondary">
                    <i class="fas fa-clock me-2"></i>Pending Proof Reviews
                </a>
            </div>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-10 mx-auto">
            <div class="data-table-card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-search me-2"></i>Search Student</h5>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3 align-items-end">
                        <input type="hidden" name="action" value="search">
                        <div class="col-md-9">
                            <label for="search_sid" class="form-label">Student ID</label>
                            <input type="text" class="form-control" name="search_sid" id="search_sid" value="<?= htmlspecialchars($searchSid) ?>" placeholder="Enter student ID" required>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-2"></i>Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($searchSid !== '' && !$studentRecord): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>No student record was found for <strong><?= htmlspecialchars($searchSid) ?></strong>.
                </div>
            <?php endif; ?>

            <?php if ($studentRecord): ?>
                <div class="alert alert-info text-center">
                    <h5 class="mb-1">
                        <?= htmlspecialchars(trim((string)(($studentRecord['title'] ?? '') . ' ' . ($studentRecord['Fname'] ?? '') . ' ' . ($studentRecord['Lname'] ?? '')))) ?>
                    </h5>
                    <div>
                        <strong><?= htmlspecialchars((string)$studentRecord['SID']) ?></strong>
                        <?php if (!empty($studentRecord['nrc_pass'])): ?>
                            | <?= htmlspecialchars((string)$studentRecord['nrc_pass']) ?>
                        <?php endif; ?>
                        <?php if (!empty($studentRecord['program_name'])): ?>
                            | <?= htmlspecialchars((string)$studentRecord['program_name']) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (empty($outstandingInvoices)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>This student has no outstanding invoices to pay.
                    </div>
                <?php else: ?>
                    <div class="data-table-card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h5>
                        </div>
                        <div class="card-body">
                            <form method="post" class="row g-3" id="accounts-payment-form">
                                <input type="hidden" name="action" value="record_payment">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="Sid" value="<?= htmlspecialchars((string)$studentRecord['SID']) ?>">

                                <div class="col-md-6">
                                    <label for="invoice_number" class="form-label">Outstanding Invoice</label>
                                    <select name="invoice_number" id="invoice_number" class="form-select" required>
                                        <?php foreach ($outstandingInvoices as $invoice): ?>
                                            <?php
                                            $invoiceValue = (string)($invoice['invoice_number'] ?? '');
                                            $invoiceBalance = payment_invoice_outstanding($invoice);
                                            $invoiceTotal = payment_invoice_total($invoice);
                                            ?>
                                            <option
                                                value="<?= htmlspecialchars($invoiceValue) ?>"
                                                data-balance="<?= htmlspecialchars((string)$invoiceBalance) ?>"
                                                data-total="<?= htmlspecialchars((string)$invoiceTotal) ?>"
                                                data-semester="<?= htmlspecialchars((string)($invoice['semester'] ?? '')) ?>"
                                                data-year="<?= htmlspecialchars(accounts_invoice_year($invoice)) ?>"
                                            >
                                                <?= htmlspecialchars($invoiceValue) ?> | Balance ZMW <?= number_format($invoiceBalance, 2) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3">
                                    <label for="invoice_total" class="form-label">Invoice Total</label>
                                    <input type="text" id="invoice_total" class="form-control" value="ZMW <?= number_format(payment_invoice_total($firstInvoice), 2) ?>" readonly>
                                </div>

                                <div class="col-md-3">
                                    <label for="outstanding_balance" class="form-label">Outstanding Balance</label>
                                    <input type="text" id="outstanding_balance" class="form-control" value="ZMW <?= number_format(payment_invoice_outstanding($firstInvoice), 2) ?>" readonly>
                                </div>

                                <div class="col-md-4">
                                    <label for="amount_paid" class="form-label">Amount Paid (ZMW)</label>
                                    <input type="number" step="0.01" min="0.01" max="<?= htmlspecialchars((string)payment_invoice_outstanding($firstInvoice)) ?>" name="amount_paid" id="amount_paid" class="form-control" value="<?= htmlspecialchars((string)payment_invoice_outstanding($firstInvoice)) ?>" required>
                                </div>

                                <div class="col-md-4">
                                    <label for="semester_display" class="form-label">Semester</label>
                                    <input type="text" id="semester_display" class="form-control" value="<?= htmlspecialchars((string)($firstInvoice['semester'] ?? '')) ?>" readonly>
                                </div>

                                <div class="col-md-4">
                                    <label for="year_display" class="form-label">Academic Year</label>
                                    <input type="text" id="year_display" class="form-control" value="<?= htmlspecialchars(accounts_invoice_year($firstInvoice)) ?>" readonly>
                                </div>

                                <div class="col-md-6">
                                    <label for="narration" class="form-label">Narration</label>
                                    <select class="form-select" name="narration" id="narration" required>
                                        <option value="">Choose narration</option>
                                        <option value="Tuition Fees">Tuition Fees</option>
                                        <option value="Exam Fees">Exam Fees</option>
                                        <option value="Registration Fees">Registration Fees</option>
                                        <option value="Accommodation Fees">Accommodation Fees</option>
                                        <option value="Other Fees">Other Fees</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="channel" class="form-label">Payment Channel</label>
                                    <select class="form-select" name="channel" id="channel" required>
                                        <option value="">Choose payment channel</option>
                                        <option value="Over the Counter">Over the Counter</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                        <option value="Airtel Money">Airtel Money</option>
                                        <option value="DPO Manual Confirmation">DPO Manual Confirmation</option>
                                        <option value="Scholarship">Scholarship</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>

                                <div class="col-12 text-end">
                                    <button class="btn btn-success px-4" type="submit">
                                        <i class="fas fa-check me-2"></i>Post Payment
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var invoiceSelect = document.getElementById('invoice_number');
    if (!invoiceSelect) {
        return;
    }

    var totalField = document.getElementById('invoice_total');
    var balanceField = document.getElementById('outstanding_balance');
    var amountField = document.getElementById('amount_paid');
    var semesterField = document.getElementById('semester_display');
    var yearField = document.getElementById('year_display');

    function updateInvoiceMeta() {
        var option = invoiceSelect.options[invoiceSelect.selectedIndex];
        if (!option) {
            return;
        }

        var balance = parseFloat(option.getAttribute('data-balance') || '0');
        var total = parseFloat(option.getAttribute('data-total') || '0');

        totalField.value = 'ZMW ' + total.toFixed(2);
        balanceField.value = 'ZMW ' + balance.toFixed(2);
        amountField.max = balance.toFixed(2);
        amountField.value = balance.toFixed(2);
        semesterField.value = option.getAttribute('data-semester') || '';
        yearField.value = option.getAttribute('data-year') || '';
    }

    invoiceSelect.addEventListener('change', updateInvoiceMeta);
    updateInvoiceMeta();
});
</script>
