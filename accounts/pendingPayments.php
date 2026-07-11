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
        $staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'accounts');

        $transaction = payment_find_gateway_transaction_by_id($db, $transactionId);
        if (!$transaction || strtoupper((string)($transaction['provider'] ?? '')) !== 'BANK_TRANSFER') {
            $errors[] = 'The selected payment proof could not be found.';
        } elseif ((string)($transaction['status'] ?? '') !== 'pending_verification') {
            $errors[] = 'This payment request has already been reviewed.';
        } elseif ($action === 'approve') {
            $invoice = payment_fetch_invoice($db, (string)($transaction['invoice_number'] ?? ''), (string)($transaction['student_id'] ?? ''));
            if (!$invoice) {
                $errors[] = 'The linked invoice could not be found.';
            } else {
                $result = payment_apply_completed_payment(
                    $db,
                    $invoice,
                    payment_decimal($transaction['amount'] ?? 0),
                    'Bank Transfer (Verified)',
                    (string)($transaction['reference_number'] ?? ''),
                    trim((string)($transaction['narration'] ?? 'Bank transfer payment')),
                    ['narration' => trim((string)($transaction['narration'] ?? 'Bank transfer payment'))]
                );

                if ($result['success']) {
                    payment_update_gateway_transaction($db, $transactionId, [
                        'status' => 'completed',
                        'verified_by' => $staffId,
                        'verified_at' => date('Y-m-d H:i:s'),
                        'completed_at' => date('Y-m-d H:i:s'),
                        'notes' => trim(($transaction['notes'] ?? '') . ($notes !== '' ? ' | Review notes: ' . $notes : '')),
                    ]);
                    $successMessage = 'Bank transfer proof approved and posted to the student invoice.';
                } else {
                    $errors[] = (string)$result['message'];
                }
            }
        } elseif ($action === 'reject') {
            payment_update_gateway_transaction($db, $transactionId, [
                'status' => 'rejected',
                'verified_by' => $staffId,
                'verified_at' => date('Y-m-d H:i:s'),
                'notes' => trim(($transaction['notes'] ?? '') . ($notes !== '' ? ' | Review notes: ' . $notes : ' | Review notes: Rejected by finance')),
            ]);
            $successMessage = 'Bank transfer proof rejected.';
        } else {
            $errors[] = 'Unknown review action.';
        }
    }
}

$records = payment_list_pending_bank_transactions($db);
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
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars((string)$record['reference_number']) ?></span></td>
                                        <td><?= htmlspecialchars((string)$record['created_at']) ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($record['proof_file'])): ?>
                                                <a href="/wucportal/uploads/payment_proofs/<?= rawurlencode((string)$record['proof_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-file-alt me-1"></i>Open
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">No file</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?= htmlspecialchars((string)($record['notes'] ?? '')) ?></td>
                                        <td style="min-width: 260px;">
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
