<?php
$page_title = "Student Payments";
include "includes/admin.php";
require_once "includes/header.php";
require_once __DIR__ . '/../includes/payment_helpers.php';
echo '<link rel="stylesheet" href="css/payments.css">';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

function admin_invoice_year(array $invoice): string
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
        $errors[] = "Invalid request. Please refresh the page and try again.";
    }

    $studentId = trim((string)($_POST['Sid'] ?? ''));
    $invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));
    $amount = isset($_POST['amount_paid']) && is_numeric($_POST['amount_paid']) ? payment_decimal($_POST['amount_paid']) : 0.0;
    $narration = trim((string)($_POST['narration'] ?? ''));
    $channel = trim((string)($_POST['channel'] ?? ''));

    $searchSid = $studentId;

    if ($studentId === '' || $invoiceNumber === '') {
        $errors[] = "Student ID and invoice are required.";
    }
    if ($amount <= 0.0) {
        $errors[] = "Valid amount paid is required.";
    }
    if ($narration === '') {
        $errors[] = "Narration is required.";
    }
    if ($channel === '') {
        $errors[] = "Payment channel is required.";
    }

    if (empty($errors)) {
        $invoice = payment_fetch_invoice($db, $invoiceNumber, $studentId);
        if (!$invoice) {
            $errors[] = "The selected invoice could not be found for this student.";
        } else {
            $result = payment_apply_completed_payment(
                $db,
                $invoice,
                $amount,
                $channel,
                payment_generate_reference('ADM'),
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

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Student Payments</h1>
        <p class="text-muted">Process student fee payments against outstanding invoices</p>
      </div>
      <div class="col-auto d-flex gap-2">
        <a href="/wucportal/accounts/pendingPayments.php" class="btn btn-sm btn-outline-secondary">
          <i class="fas fa-clock"></i> Pending Proof Reviews
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

  <div class="row">
    <div class="col-12">
      <div class="card shadow-sm data-table-card">
        <div class="card-body">
          <form method="POST" class="mb-4">
            <input type="hidden" name="action" value="search">
            <div class="row g-3 align-items-end">
              <div class="col-md-8">
                <label for="search_sid" class="form-label">Search Student</label>
                <input type="text" class="form-control" name="search_sid" id="search_sid" value="<?= htmlspecialchars($searchSid) ?>" placeholder="Enter student ID" required>
              </div>
              <div class="col-md-4">
                <button type="submit" class="btn btn-primary">
                  <i class="fas fa-search"></i> Search
                </button>
              </div>
            </div>
          </form>

          <?php if ($searchSid !== '' && !$studentRecord): ?>
            <div class="alert alert-warning">
              <i class="fas fa-info-circle me-2"></i>Student ID <strong><?= htmlspecialchars($searchSid) ?></strong> was not found.
            </div>
          <?php endif; ?>

          <?php if ($studentRecord): ?>
            <div class="card mb-4 border-primary data-table-card">
              <div class="card-header bg-primary text-white">
                <div class="d-flex align-items-center">
                  <div class="stat-icon bg-primary me-3"><i class="fas fa-user text-white"></i></div>
                  <h5 class="card-title mb-0">
                    <?= htmlspecialchars(trim((string)(($studentRecord['title'] ?? '') . ' ' . ($studentRecord['Fname'] ?? '') . ' ' . ($studentRecord['Lname'] ?? '')))) ?>
                    <?php if (!empty($studentRecord['nrc_pass'])): ?>
                      - <?= htmlspecialchars((string)$studentRecord['nrc_pass']) ?>
                    <?php endif; ?>
                  </h5>
                </div>
              </div>
              <div class="card-body">
                <p class="mb-1"><strong>Student ID:</strong> <?= htmlspecialchars((string)$studentRecord['SID']) ?></p>
                <p class="mb-0"><strong>Program:</strong> <?= htmlspecialchars((string)$studentRecord['program_name']) ?></p>
              </div>
            </div>

            <?php if (empty($outstandingInvoices)): ?>
              <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>This student has no outstanding invoices to receive payment against.
              </div>
            <?php else: ?>
              <form method="POST" action="payments.php" class="row g-3">
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
                        data-year="<?= htmlspecialchars(admin_invoice_year($invoice)) ?>"
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
                  <label for="amount_paid" class="form-label">Amount (ZMW) <span class="text-danger">*</span></label>
                  <input type="number" step="0.01" min="0.01" max="<?= htmlspecialchars((string)payment_invoice_outstanding($firstInvoice)) ?>" class="form-control" name="amount_paid" id="amount_paid" value="<?= htmlspecialchars((string)payment_invoice_outstanding($firstInvoice)) ?>" required>
                </div>

                <div class="col-md-4">
                  <label for="semester_display" class="form-label">Semester</label>
                  <input type="text" id="semester_display" class="form-control" value="<?= htmlspecialchars((string)($firstInvoice['semester'] ?? '')) ?>" readonly>
                </div>

                <div class="col-md-4">
                  <label for="year_display" class="form-label">Academic Year</label>
                  <input type="text" id="year_display" class="form-control" value="<?= htmlspecialchars(admin_invoice_year($firstInvoice)) ?>" readonly>
                </div>

                <div class="col-md-6">
                  <label for="narration" class="form-label">Narration <span class="text-danger">*</span></label>
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
                  <label for="channel" class="form-label">Payment Channel <span class="text-danger">*</span></label>
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

                <div class="col-12 pt-3">
                  <button type="submit" class="btn btn-success btn-lg" name="submit">
                    <i class="fas fa-credit-card"></i> Process Payment
                  </button>
                </div>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
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

<?php require_once "includes/footer.php"; ?>
