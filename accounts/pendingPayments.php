<?php
$page_title = 'Pending Payments';
require "includes/nav.php";
require_once __DIR__ . '/../includes/payment_helpers.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

$errors = [];
$successMessage = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        $errors[] = 'Invalid request token. Please refresh and try again.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        $transactionId = (int)($_POST['transaction_id'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        $targetInvoice = trim((string)($_POST['target_invoice'] ?? ''));
        $staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'accounts');

        $result = payment_review_bank_transfer($db, $transactionId, $action, $staffId, $notes, $targetInvoice);
        if (!empty($result['success'])) {
            $successMessage = (string)$result['message'];
        } else {
            $errors[] = (string)($result['message'] ?? 'The payment review could not be completed.');
        }
    }
}

$records = payment_list_pending_bank_transactions($db);
$reallocationCandidates = [];
foreach ($records as $record) {
    if ((string)($record['status'] ?? '') === 'needs_review') {
        $reallocationCandidates[(int)$record['id']] = payment_bank_reallocation_candidates($db, $record);
    }
}
?>
<div class="container-fluid px-4 portal-dashboard accounts-page pending-payments-page">
    <div class="dashboard-header finance-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Pending Payments</h1>
                <p class="text-muted mb-0">Review student bank transfer proofs before posting them to invoices.</p>
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
            <div class="data-table-card mb-4">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-clock me-2"></i>Bank Transfer Proof Queue
                        </h5>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($records)): ?>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>No bank transfer proofs are waiting for review.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Invoice</th>
                                    <th>Amount</th>
                                    <th>Reference</th>
                                    <th>Uploaded</th>
                                    <th>Status</th>
                                    <th>Proof</th>
                                    <th>Notes</th>
                                    <th>Review</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($records as $record): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)$record['student_id']) ?></td>
                                        <td><?= htmlspecialchars((string)$record['invoice_number']) ?></td>
                                        <td>ZMW <?= number_format((float)($record['amount'] ?? 0), 2) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars((string)($record['provider_transaction_id'] ?: $record['reference_number'])) ?></span></td>
                                        <td><?= htmlspecialchars((string)$record['created_at']) ?></td>
                                        <td>
                                            <?php if ((string)$record['status'] === 'needs_review'): ?>
                                                <span class="badge bg-warning text-dark"><i class="fas fa-triangle-exclamation me-1"></i>Reconciliation</span>
                                                <?php if (!empty($record['result_desc'])): ?>
                                                    <div class="small text-muted mt-1"><?= htmlspecialchars((string)$record['result_desc']) ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-info text-dark"><i class="fas fa-clock me-1"></i>Awaiting review</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!empty($record['proof_file'])): ?>
                                                <a href="paymentProof.php?transaction_id=<?= (int)$record['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-file-download me-1"></i>Download
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">No file</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?= htmlspecialchars((string)($record['notes'] ?? '')) ?></td>
                                        <td style="min-width: 300px;">
                                            <?php if ((string)$record['status'] === 'needs_review'): ?>
                                                <?php $candidates = $reallocationCandidates[(int)$record['id']] ?? []; ?>
                                                <?php if (!empty($candidates)): ?>
                                                    <form method="post" class="d-grid gap-2 mb-2">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                        <input type="hidden" name="transaction_id" value="<?= (int)$record['id'] ?>">
                                                        <label class="form-label small fw-semibold mb-0" for="targetInvoice<?= (int)$record['id'] ?>">
                                                            Allocate to an outstanding invoice
                                                        </label>
                                                        <select name="target_invoice" id="targetInvoice<?= (int)$record['id'] ?>" class="form-select form-select-sm" required>
                                                            <option value="">Select invoice</option>
                                                            <?php foreach ($candidates as $candidate): ?>
                                                                <?php
                                                                $candidateReference = (string)($candidate['invoice_number'] ?? $candidate['id'] ?? '');
                                                                $candidateLabel = $candidateReference
                                                                    . ' · AY ' . (string)($candidate['academic_year'] ?? '-')
                                                                    . ' · Period ' . (string)($candidate['semester'] ?? '-')
                                                                    . ' · ZMW ' . number_format(payment_invoice_outstanding($candidate), 2) . ' outstanding';
                                                                ?>
                                                                <option value="<?= htmlspecialchars($candidateReference) ?>"><?= htmlspecialchars($candidateLabel) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="Allocation note (optional)">
                                                        <button type="submit" name="action" value="reallocate" class="btn btn-sm btn-primary">
                                                            <i class="fas fa-share me-1"></i>Allocate and post
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <div class="alert alert-warning py-2 px-3 small mb-2">
                                                        <i class="fas fa-circle-exclamation me-1"></i>No same-student invoice can absorb this amount.
                                                    </div>
                                                <?php endif; ?>

                                                <form method="post" class="d-grid gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="transaction_id" value="<?= (int)$record['id'] ?>">
                                                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="Closure note">
                                                    <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger">
                                                        <i class="fas fa-times me-1"></i>Close as rejected
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" class="d-grid gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="transaction_id" value="<?= (int)$record['id'] ?>">
                                                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional review notes">
                                                    <div class="d-flex gap-2">
                                                        <button type="submit" name="action" value="approve" class="btn btn-sm btn-success flex-fill">
                                                            <i class="fas fa-check me-1"></i>Approve
                                                        </button>
                                                        <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger flex-fill">
                                                            <i class="fas fa-times me-1"></i>Reject
                                                        </button>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
