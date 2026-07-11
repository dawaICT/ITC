<?php
/**
 * Payments & Booking pane (BR001).
 * Included by transport_management.php when $activeTab === 'payments'.
 * Relies on the hub having required transport_payment_guard.php and provided
 * $db, $csrfToken, and the tm_h() helper.
 */
if (!isset($db) || !($db instanceof mysqli)) {
    return;
}

$canVerify = tpay_user_can_verify();
$canBook   = tpay_user_can_book();

// Enrolments needing attention first: awaiting verification, then ready-to-book.
$enrollments = $db->query(
    "SELECT e.id, e.cohort_id, e.fee_amount, e.amount_paid, e.payment_status, e.booking_status,
            e.status, e.enrollment_type, e.payment_verified_by, e.payment_verified_at,
            c.cohort_name, t.first_name, t.last_name, t.student_id
     FROM transport_enrollments e
     JOIN transport_cohorts c ON c.id = e.cohort_id
     JOIN transport_trainees t ON t.id = e.trainee_id
     ORDER BY CASE e.payment_status
                WHEN 'payment_submitted' THEN 0 WHEN 'verified' THEN 1
                WHEN 'partial' THEN 2 WHEN 'awaiting_payment' THEN 3 ELSE 4 END,
              e.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

// Payments grouped by enrolment.
$paymentsByEnrollment = [];
$payRes = $db->query("SELECT * FROM transport_payments ORDER BY created_at DESC");
while ($row = $payRes->fetch_assoc()) {
    $paymentsByEnrollment[(int)$row['enrollment_id']][] = $row;
}

$awaitingCount = $readyCount = $bookedCount = 0;
foreach ($enrollments as $e) {
    if ($e['payment_status'] === 'payment_submitted') { $awaitingCount++; }
    if ($e['payment_status'] === 'verified' && $e['booking_status'] !== 'booked') { $readyCount++; }
    if ($e['booking_status'] === 'booked') { $bookedCount++; }
}
?>
<div class="transport-payments-pane">

    <div class="transport-setup-note mb-3">
        <i class="fas fa-shield-halved me-1"></i>
        <strong>BR001:</strong> a trainee can only be booked into a cohort after an accounts
        officer has <strong>verified</strong> payment. Recorded amounts reflect verified payments only.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="rounded-3 border bg-light p-3 h-100">
                <div class="small text-muted">Awaiting verification</div>
                <div class="h3 mb-0"><?php echo (int)$awaitingCount; ?></div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="rounded-3 border bg-light p-3 h-100">
                <div class="small text-muted">Verified &middot; ready to book</div>
                <div class="h3 mb-0"><?php echo (int)$readyCount; ?></div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="rounded-3 border bg-light p-3 h-100">
                <div class="small text-muted">Booked for training</div>
                <div class="h3 mb-0"><?php echo (int)$bookedCount; ?></div>
            </div>
        </div>
    </div>

    <?php if (!$enrollments): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-receipt fa-3x mb-3 opacity-50"></i>
            <p class="mb-0">No trainee enrolments yet. Enrol a trainee first, then record their payment here.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($enrollments as $e):
        $eid       = (int)$e['id'];
        $fee       = (float)$e['fee_amount'];
        $paid      = (float)$e['amount_paid'];
        $balance   = max(0, $fee - $paid);
        [$payLabel, $payClass]   = tpay_payment_badge((string)$e['payment_status']);
        [$bookLabel, $bookClass] = tpay_booking_badge((string)$e['booking_status']);
        $gate    = tpay_can_book($e);
        $rows    = $paymentsByEnrollment[$eid] ?? [];
        $name    = tm_h(trim($e['first_name'] . ' ' . $e['last_name']));
    ?>
    <div class="card mb-3 shadow-sm">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong><i class="fas fa-user-graduate me-2 text-primary"></i><?php echo $name; ?></strong>
                <span class="text-muted ms-2 small"><?php echo tm_h($e['student_id']); ?></span>
                <span class="text-muted ms-2 small"><i class="fas fa-layer-group me-1"></i><?php echo tm_h($e['cohort_name']); ?></span>
            </div>
            <div class="d-flex gap-2">
                <span class="badge <?php echo $payClass; ?>"><?php echo $payLabel; ?></span>
                <span class="badge <?php echo $bookClass; ?>"><?php echo $bookLabel; ?></span>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-center mb-3">
                <div class="col-6 col-md-3"><div class="small text-muted">Fee</div><div class="fw-semibold">K <?php echo number_format($fee, 2); ?></div></div>
                <div class="col-6 col-md-3"><div class="small text-muted">Verified paid</div><div class="fw-semibold text-success">K <?php echo number_format($paid, 2); ?></div></div>
                <div class="col-6 col-md-3"><div class="small text-muted">Balance</div><div class="fw-semibold <?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">K <?php echo number_format($balance, 2); ?></div></div>
                <div class="col-6 col-md-3 text-md-end">
                    <?php if ($e['booking_status'] === 'booked'): ?>
                        <span class="badge bg-success rounded-pill px-3 py-2"><i class="fas fa-circle-check me-1"></i>Booked for training</span>
                    <?php elseif ($e['booking_status'] === 'cancelled'): ?>
                        <span class="badge bg-dark rounded-pill px-3 py-2"><i class="fas fa-ban me-1"></i>Cancelled</span>
                    <?php elseif ($gate['allowed'] && $canBook): ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                            <input type="hidden" name="action" value="book_trainee">
                            <input type="hidden" name="enrollment_id" value="<?php echo $eid; ?>">
                            <button class="btn btn-success btn-sm"><i class="fas fa-calendar-check me-1"></i>Book into cohort</button>
                        </form>
                    <?php elseif ($gate['allowed']): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2">
                            <i class="fas fa-circle-check me-1"></i>Ready to book
                        </span>
                        <div class="small text-muted mt-1">A training officer can book this trainee.</div>
                    <?php else: ?>
                        <span class="d-inline-flex align-items-center text-secondary fw-semibold"
                              title="A trainee can only be booked after an accounts officer verifies payment (BR001).">
                            <i class="fas fa-lock me-1"></i>Booking locked
                        </span>
                        <div class="small text-muted mt-1"><?php echo tm_h(tpay_booking_block_hint((string)$e['payment_status'])); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Documents: invoice (always) + receipt (after verification, BR011) -->
            <div class="mb-3 d-flex gap-2 flex-wrap">
                <a href="/wucportal/transport/invoice.php?enrollment_id=<?php echo $eid; ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-file-invoice me-1"></i>Invoice
                </a>
                <?php if ($e['payment_status'] === 'verified'): ?>
                    <a href="/wucportal/transport/receipt.php?enrollment_id=<?php echo $eid; ?>" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm">
                        <i class="fas fa-receipt me-1"></i>Receipt
                    </a>
                <?php else: ?>
                    <span class="btn btn-outline-secondary btn-sm disabled" title="A receipt is available after payment verification (BR011).">
                        <i class="fas fa-receipt me-1"></i>Receipt (after verification)
                    </span>
                <?php endif; ?>
            </div>

            <!-- Payments ledger -->
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-2">
                    <thead>
                        <tr>
                            <th>Date</th><th>Amount</th><th>Method</th><th>Reference</th>
                            <th>Proof</th><th>Status</th><?php if ($canVerify): ?><th class="text-end">Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="<?php echo $canVerify ? 7 : 6; ?>" class="text-muted text-center py-3">No payments recorded yet.</td></tr>
                        <?php else: foreach ($rows as $p):
                            $pid = (int)$p['id'];
                            $pStatus = (string)$p['status'];
                            $pBadge = $pStatus === 'verified' ? 'bg-success' : ($pStatus === 'rejected' ? 'bg-danger' : 'bg-info text-dark');
                        ?>
                        <tr>
                            <td class="small"><?php echo tm_h(date('d M Y', strtotime((string)$p['created_at']))); ?></td>
                            <td class="fw-semibold">K <?php echo number_format((float)$p['amount'], 2); ?></td>
                            <td class="small"><?php echo tm_h($p['payment_method'] ?: '—'); ?><?php echo $p['bank_name'] ? '<br><span class="text-muted">' . tm_h($p['bank_name']) . '</span>' : ''; ?></td>
                            <td class="small"><?php echo tm_h($p['reference_number'] ?: '—'); ?></td>
                            <td class="small">
                                <?php if (!empty($p['proof_path'])): ?>
                                    <a href="/wucportal/<?php echo tm_h($p['proof_path']); ?>" target="_blank" rel="noopener"><i class="fas fa-file-arrow-down me-1"></i>View</a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $pBadge; ?>"><?php echo ucfirst($pStatus); ?></span>
                                <?php if ($pStatus === 'rejected' && $p['rejection_reason']): ?>
                                    <div class="small text-danger"><?php echo tm_h($p['rejection_reason']); ?></div>
                                <?php endif; ?>
                            </td>
                            <?php if ($canVerify): ?>
                            <td class="text-end">
                                <?php if ($pStatus === 'submitted'): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                        <input type="hidden" name="action" value="verify_payment">
                                        <input type="hidden" name="payment_id" value="<?php echo $pid; ?>">
                                        <button class="btn btn-outline-success btn-sm" title="Verify payment"><i class="fas fa-check"></i></button>
                                    </form>
                                    <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#reject-<?php echo $pid; ?>" title="Reject payment"><i class="fas fa-xmark"></i></button>
                                    <div class="collapse mt-2 text-start" id="reject-<?php echo $pid; ?>">
                                        <form method="post" class="input-group input-group-sm">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="reject_payment">
                                            <input type="hidden" name="payment_id" value="<?php echo $pid; ?>">
                                            <input type="text" name="reason" class="form-control" placeholder="Reason" maxlength="255" required>
                                            <button class="btn btn-danger">Reject</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Record a payment / upload proof -->
            <?php if ($e['booking_status'] !== 'booked'): ?>
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#pay-<?php echo $eid; ?>">
                <i class="fas fa-upload me-1"></i>Record payment / upload proof
            </button>
            <div class="collapse mt-3" id="pay-<?php echo $eid; ?>">
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                    <input type="hidden" name="action" value="submit_payment_proof">
                    <input type="hidden" name="enrollment_id" value="<?php echo $eid; ?>">
                    <div class="col-6 col-md-2">
                        <label class="form-label small">Amount (K)</label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" value="<?php echo $balance > 0 ? number_format($balance, 2, '.', '') : ''; ?>" required>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small">Method</label>
                        <input name="payment_method" class="form-control form-control-sm" placeholder="Bank / MoMo / Cash" maxlength="50">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small">Bank</label>
                        <input name="bank_name" class="form-control form-control-sm" maxlength="120">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small">Reference</label>
                        <input name="reference_number" class="form-control form-control-sm" maxlength="80">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Proof (PDF/JPG/PNG)</label>
                        <input type="file" name="proof" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-primary btn-sm"><i class="fas fa-paper-plane me-1"></i>Submit</button>
                    </div>
                    <div class="col-12"><div class="form-text">Attach a proof file or enter a reference number. Submitted payments stay unverified until an accounts officer confirms them.</div></div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
