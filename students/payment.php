<?php
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/payment_helpers.php';

function student_payment_redirect(string $url): never
{
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit();
    }

    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

$studentId = (string)($_SESSION['Sid'] ?? '');
$invoiceReference = trim((string)($_GET['invoice'] ?? $_POST['invoice'] ?? ''));
$invoice = $invoiceReference !== '' ? payment_fetch_invoice($db, $invoiceReference, $studentId) : null;
$student = payment_fetch_student_summary($db, $studentId);
$dpoConfig = payment_get_dpo_config($db);
$bankDetails = payment_get_bank_details($db);
$errors = [];
$notice = '';

if (isset($_GET['error']) && trim((string)$_GET['error']) !== '') {
    $errors[] = trim((string)$_GET['error']);
}

if (isset($_GET['cancelled'])) {
    $notice = 'DPO Pay was cancelled. You can try again or submit a bank transfer proof instead.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'bank_upload') {
    if ($invoice === null) {
        $errors[] = 'Invoice not found for this student account.';
    }

    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        $errors[] = 'Your session expired. Please refresh and try again.';
    }

    $amount = isset($_POST['amount']) && is_numeric($_POST['amount']) ? payment_decimal($_POST['amount']) : 0.0;
    $bankReference = trim((string)($_POST['bank_reference'] ?? ''));
    $narration = trim((string)($_POST['narration'] ?? 'Tuition Fee'));
    $selectedBank = trim((string)($_POST['bank_name'] ?? ($bankDetails['bank_name'] ?? 'Bank Transfer')));

    if ($invoice !== null) {
        $outstanding = payment_invoice_outstanding($invoice);
        if ($amount <= 0.0 || $amount > ($outstanding + 0.01)) {
            $errors[] = 'Please enter an amount greater than zero and not more than the invoice balance.';
        }
    }

    if ($bankReference === '') {
        $errors[] = 'Please provide the bank reference, deposit slip number, or transaction ID.';
    }

    if (!isset($_FILES['payment_proof']) || (int)($_FILES['payment_proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Please upload a proof of payment file.';
    }

    if (empty($errors)) {
        $maxMb = max(1, (int)payment_setting($db, 'bank_proof_max_mb', '10'));
        $maxBytes = $maxMb * 1024 * 1024;
        $file = $_FILES['payment_proof'];

        if ((int)$file['size'] > $maxBytes) {
            $errors[] = 'Proof of payment is too large. Maximum size is ' . $maxMb . 'MB.';
        } else {
            $tmpFile = (string)$file['tmp_name'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo ? (string)finfo_file($finfo, $tmpFile) : '';
            if ($finfo) {
                finfo_close($finfo);
            }

            $allowedMimes = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

            if (!isset($allowedMimes[$detectedMime])) {
                $errors[] = 'Only PDF, JPG, PNG, and WEBP proof files are accepted.';
            } else {
                $uploadDir = __DIR__ . '/../uploads/payment_proofs';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    $errors[] = 'The proof upload directory could not be created.';
                } else {
                    $extension = $allowedMimes[$detectedMime];
                    $proofFile = $studentId . '-' . date('YmdHis');
                    try {
                        $proofFile .= '-' . substr(bin2hex(random_bytes(4)), 0, 8);
                    } catch (Throwable $e) {
                        $proofFile .= '-' . substr(md5((string)mt_rand()), 0, 8);
                    }
                    $proofFile .= '.' . $extension;
                    $destination = $uploadDir . DIRECTORY_SEPARATOR . $proofFile;

                    if (!move_uploaded_file($tmpFile, $destination)) {
                        $errors[] = 'The proof file could not be saved. Please try again.';
                    } else {
                        $referenceNumber = payment_generate_reference('BANK');
                        $notes = 'Bank reference: ' . $bankReference . ' | Bank: ' . $selectedBank;
                        $txResult = payment_create_gateway_transaction($db, [
                            'invoice_number' => (string)($invoice['invoice_number'] ?? $invoiceReference),
                            'student_id' => $studentId,
                            'amount' => $amount,
                            'currency' => 'ZMW',
                            'narration' => $narration,
                            'provider' => 'BANK_TRANSFER',
                            'reference_number' => $referenceNumber,
                            'status' => 'pending_verification',
                            'proof_file' => $proofFile,
                            'proof_mime' => $detectedMime,
                            'notes' => $notes,
                            'created_by' => $studentId,
                        ]);

                        if (!$txResult['success']) {
                            @unlink($destination);
                            $errors[] = 'Your proof was uploaded, but the payment request could not be recorded.';
                        } else {
                            student_payment_redirect(
                                'fees.php?payment_status=pending&payment_message='
                                . urlencode('Your bank transfer proof has been submitted for review. Finance will verify it before posting the payment.')
                                . '&invoice=' . urlencode((string)($invoice['invoice_number'] ?? $invoiceReference))
                            );
                        }
                    }
                }
            }
        }
    }
}

$invoiceNumber = (string)($invoice['invoice_number'] ?? $invoiceReference);
$invoiceTotal = $invoice ? payment_invoice_total($invoice) : 0.0;
$invoicePaid = $invoice ? payment_decimal($invoice['amount_paid'] ?? 0) : 0.0;
$invoiceBalance = $invoice ? payment_invoice_outstanding($invoice) : 0.0;
$invoiceStatus = $invoice ? payment_invoice_status($invoice) : 'pending';
$dpoReady = payment_dpo_is_ready($dpoConfig);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pay Invoice</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="../css/consistent-styles.css">
    <style>
        .checkout-shell {
            max-width: 1100px;
            margin: 0 auto;
        }
        .method-card {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 1rem;
            box-shadow: 0 0.5rem 1.5rem rgba(15, 23, 42, 0.05);
        }
        .method-card .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
        }
        .summary-chip {
            border-radius: 999px;
            padding: 0.45rem 0.9rem;
            background: rgba(13, 110, 253, 0.08);
            color: #0d6efd;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .metric-card {
            border-radius: 1rem;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(15, 23, 42, 0.06);
            padding: 1rem 1.1rem;
        }
        .method-card .list-group-item {
            border-color: rgba(15, 23, 42, 0.06);
        }
    </style>

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="bg-light">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="content-wrapper portal-dashboard pt-3">
    <div class="container-fluid checkout-shell">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-credit-card me-2 text-primary"></i>Invoice Checkout</h4>
                <p class="text-muted mb-0">Choose how you want to settle this invoice.</p>
            </div>
            <a href="fees.php<?= $invoiceNumber !== '' ? '?invoice=' . urlencode($invoiceNumber) : '' ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Fees
            </a>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <?php if ($notice !== ''): ?>
            <div class="alert alert-info"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <?php if ($invoice === null): ?>
            <div class="card method-card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No invoice selected</h5>
                    <p class="text-muted mb-4">Open an outstanding invoice from your fees page to continue.</p>
                    <a href="fees.php" class="btn btn-primary">Open Fees</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card method-card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <div class="summary-chip mb-3">
                                <i class="fas fa-receipt"></i>
                                Invoice <?= htmlspecialchars($invoiceNumber) ?>
                            </div>
                            <h5 class="mb-1">
                                <?= htmlspecialchars(trim((string)(($student['title'] ?? '') . ' ' . ($student['Fname'] ?? '') . ' ' . ($student['Lname'] ?? '')))) ?>
                            </h5>
                            <p class="text-muted mb-0">
                                Student ID: <strong><?= htmlspecialchars($studentId) ?></strong>
                                <?php if (!empty($student['program_name'])): ?>
                                    | Program: <strong><?= htmlspecialchars((string)$student['program_name']) ?></strong>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="badge bg-<?= $invoiceStatus === 'paid' ? 'success' : ($invoiceStatus === 'partial' ? 'warning text-dark' : 'secondary') ?> fs-6">
                            <?= htmlspecialchars(ucfirst($invoiceStatus)) ?>
                        </span>
                    </div>

                    <div class="row g-3 mt-2">
                        <div class="col-md-4">
                            <div class="metric-card h-100">
                                <div class="text-muted small mb-1">Invoice Total</div>
                                <div class="fs-4 fw-bold">ZMW <?= number_format($invoiceTotal, 2) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="metric-card h-100">
                                <div class="text-muted small mb-1">Already Paid</div>
                                <div class="fs-4 fw-bold text-success">ZMW <?= number_format($invoicePaid, 2) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="metric-card h-100">
                                <div class="text-muted small mb-1">Outstanding Balance</div>
                                <div class="fs-4 fw-bold text-primary">ZMW <?= number_format($invoiceBalance, 2) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 text-muted small">
                        Semester: <strong><?= htmlspecialchars((string)($invoice['semester'] ?? '-')) ?></strong>
                        | Academic Year: <strong><?= htmlspecialchars((string)(($invoice['academic_year'] ?? '') !== '' ? $invoice['academic_year'] : ($invoice['Year'] ?? '-'))) ?></strong>
                        | Due Date: <strong><?= htmlspecialchars((string)($invoice['due_date'] ?? '-')) ?></strong>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card method-card h-100">
                        <div class="card-header py-3">
                            <h5 class="mb-0 text-primary"><i class="fas fa-bolt me-2"></i>DPO Pay</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Pay online using the DPO hosted checkout page. This is the fastest option when the gateway is configured.</p>

                            <form method="post" action="payments/paygate_start.php" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="payment_type" value="fee_payment">
                                <input type="hidden" name="invoice" value="<?= htmlspecialchars($invoiceNumber) ?>">

                                <div class="col-md-6">
                                    <label class="form-label" for="dpoAmount">Amount to pay (ZMW)</label>
                                    <input type="number" step="0.01" min="0.01" max="<?= htmlspecialchars((string)$invoiceBalance) ?>" name="amount" id="dpoAmount" class="form-control" value="<?= htmlspecialchars((string)$invoiceBalance) ?>" <?= $invoiceBalance <= 0 ? 'readonly' : '' ?>>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="dpoNarration">Narration</label>
                                    <input type="text" name="narration" id="dpoNarration" class="form-control" value="Tuition Fee">
                                </div>

                                <?php if ($dpoReady): ?>
                                    <div class="col-12">
                                        <div class="alert alert-light border mb-0">
                                            <i class="fas fa-shield-alt text-success me-2"></i>
                                            You will be redirected to DPO's secure payment page. The payment will be verified before it is posted to your invoice.
                                        </div>
                                    </div>
                                    <div class="col-12 d-grid">
                                        <button type="submit" class="btn btn-primary btn-lg" <?= $invoiceBalance <= 0 ? 'disabled' : '' ?>>
                                            <i class="fas fa-arrow-right me-2"></i>Continue to DPO Pay
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="col-12">
                                        <div class="alert alert-warning mb-0">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            DPO Pay is not configured yet in this portal. Use the bank transfer option below or contact finance.
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card method-card h-100">
                        <div class="card-header py-3">
                            <h5 class="mb-0 text-warning-emphasis"><i class="fas fa-university me-2"></i>Bank Transfer / Deposit</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Pay into the school bank account, then upload the proof so finance can verify and post the payment.</p>

                            <div class="list-group mb-4">
                                <div class="list-group-item d-flex justify-content-between">
                                    <span>Bank</span>
                                    <strong><?= htmlspecialchars((string)$bankDetails['bank_name']) ?></strong>
                                </div>
                                <div class="list-group-item d-flex justify-content-between">
                                    <span>Account Name</span>
                                    <strong><?= htmlspecialchars((string)$bankDetails['account_name']) ?></strong>
                                </div>
                                <div class="list-group-item d-flex justify-content-between">
                                    <span>Account Number</span>
                                    <strong><?= htmlspecialchars((string)$bankDetails['account_number']) ?></strong>
                                </div>
                                <div class="list-group-item d-flex justify-content-between">
                                    <span>Branch</span>
                                    <strong><?= htmlspecialchars((string)$bankDetails['branch']) ?></strong>
                                </div>
                                <div class="list-group-item d-flex justify-content-between">
                                    <span>Branch Code</span>
                                    <strong><?= htmlspecialchars((string)$bankDetails['branch_code']) ?></strong>
                                </div>
                                <?php if (trim((string)$bankDetails['swift_code']) !== ''): ?>
                                    <div class="list-group-item d-flex justify-content-between">
                                        <span>SWIFT</span>
                                        <strong><?= htmlspecialchars((string)$bankDetails['swift_code']) ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <form method="post" enctype="multipart/form-data" class="row g-3">
                                <input type="hidden" name="action" value="bank_upload">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="invoice" value="<?= htmlspecialchars($invoiceNumber) ?>">

                                <div class="col-md-6">
                                    <label class="form-label" for="bankAmount">Amount paid (ZMW)</label>
                                    <input type="number" step="0.01" min="0.01" max="<?= htmlspecialchars((string)$invoiceBalance) ?>" name="amount" id="bankAmount" class="form-control" value="<?= htmlspecialchars((string)$invoiceBalance) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="bankName">Bank / channel</label>
                                    <input type="text" name="bank_name" id="bankName" class="form-control" value="<?= htmlspecialchars((string)$bankDetails['bank_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="bankReference">Bank reference / deposit slip no.</label>
                                    <input type="text" name="bank_reference" id="bankReference" class="form-control" placeholder="Enter bank transaction reference" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="bankNarration">Narration</label>
                                    <input type="text" name="narration" id="bankNarration" class="form-control" value="Tuition Fee" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="paymentProof">Proof of payment</label>
                                    <input type="file" name="payment_proof" id="paymentProof" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
                                    <small class="text-muted">Accepted formats: PDF, JPG, PNG, WEBP.</small>
                                </div>
                                <div class="col-12">
                                    <div class="alert alert-light border mb-0">
                                        <i class="fas fa-clock text-warning me-2"></i>
                                        Finance will review this submission and post it after verification. You will still see the invoice as outstanding until review is complete.
                                    </div>
                                </div>
                                <div class="col-12 d-grid">
                                    <button type="submit" class="btn btn-outline-dark btn-lg" <?= $invoiceBalance <= 0 ? 'disabled' : '' ?>>
                                        <i class="fas fa-upload me-2"></i>Submit Proof for Review
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
