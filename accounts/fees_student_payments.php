<?php
$page_title = 'Process Student Payments';
require "includes/nav.php";
require_once __DIR__ . '/../includes/fees_helpers.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrfToken) {
        $error = 'CSRF validation failed.';
    } else {
        $action = $_POST['action'] ?? '';
    
    if ($action === 'record') {
        $student_fee_account_id = (int)($_POST['student_fee_account_id'] ?? 0);
        $amount = isset($_POST['amount']) && is_numeric($_POST['amount']) ? (float)$_POST['amount'] : 0.00;
        $payment_method = trim($_POST['payment_method'] ?? 'Cash');
        $receipt_number = trim($_POST['receipt_number'] ?? '');
        $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');
        $recorded_by = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'system';

        // Fetch student fee account info
        $account = null;
        $stmt = $db->prepare("SELECT student_id, academic_year, intake FROM student_fee_accounts WHERE id = ? AND status = 'active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $student_fee_account_id);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if (!$account) {
            $error = 'Select a valid active student fee account.';
        } elseif ($amount <= 0) {
            $error = 'Payment amount must be greater than zero.';
        } elseif ($receipt_number === '') {
            $error = 'Receipt number is required.';
        } else {
            try {
                // Validate receipt number is unique
                $check = $db->prepare("SELECT payment_id FROM student_payments WHERE receipt_number = ? LIMIT 1");
                $check->bind_param('s', $receipt_number);
                $check->execute();
                $dup = $check->get_result()->num_rows > 0;
                $check->close();

                if ($dup) {
                    $error = "Receipt number '$receipt_number' has already been used.";
                } else {
                    $db->begin_transaction();

                    // Insert payment record
                    // Maps to student_payments table, keeping fallback fields aligned
                    $stmt = $db->prepare("INSERT INTO student_payments 
                        (student_fee_account_id, Sid, amount_paid, channel, payment_date, academic_year, semester_term, payment_status, reference_number, description, status, recorded_by, receipt_number) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, 'approved', ?, ?)");
                    
                    $studentId = $account['student_id'];
                    $academicYear = $account['academic_year'];
                    $intake = $account['intake'];
                    $statusVal = 'completed';

                    $stmt->bind_param(
                        'isdssssssss', 
                        $student_fee_account_id, 
                        $studentId, 
                        $amount, 
                        $payment_method, 
                        $payment_date, 
                        $academicYear, 
                        $intake, 
                        $receipt_number, 
                        $notes, 
                        $recorded_by, 
                        $receipt_number
                    );

                    if ($stmt->execute()) {
                        $stmt->close();

                        // Recalculate balance & status
                        fees_recalculate_student_balance($db, $student_fee_account_id);

                        $db->commit();
                        $message = 'Payment recorded successfully.';
                    } else {
                        $db->rollback();
                        $error = 'A database error occurred. Please try again.';
                        error_log("Database error in accounts/fees_student_payments.php: " . $db->error);
                        $stmt->close();
                    }
                }
            } catch (Exception $e) {
                $db->rollback();
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'reverse') {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $reason = trim($_POST['reversal_reason'] ?? '');
        $status = $_POST['status'] ?? 'reversed'; // reversed or cancelled

        if ($payment_id <= 0 || $reason === '') {
            $error = 'Reversal reason is required.';
        } else {
            $db->begin_transaction();
            try {
                // Get student fee account id
                $student_fee_account_id = 0;
                $stmt = $db->prepare("SELECT student_fee_account_id FROM student_payments WHERE payment_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $payment_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $student_fee_account_id = (int)$row['student_fee_account_id'];
                    }
                    $stmt->close();
                }

                // Update payment status
                $stmt = $db->prepare("UPDATE student_payments 
                                      SET status = ?, reversal_reason = ?, payment_status = 'failed' 
                                      WHERE payment_id = ? LIMIT 1");
                $stmt->bind_param('ssi', $status, $reason, $payment_id);
                if ($stmt->execute()) {
                    $stmt->close();

                    if ($student_fee_account_id > 0) {
                        fees_recalculate_student_balance($db, $student_fee_account_id);
                    }

                    $db->commit();
                    $message = "Payment reversed successfully.";
                } else {
                    $db->rollback();
                    $error = 'A database error occurred. Please try again.';
                    error_log("Database error in accounts/fees_student_payments.php: " . $db->error);
                    $stmt->close();
                }
            } catch (Exception $e) {
                $db->rollback();
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
    }
}

// Fetch active student fee accounts for select box
$accounts = [];
$res = $db->query("SELECT sfa.id, sfa.student_id, sfa.balance, s.Fname, s.Lname, c.course_name 
                  FROM student_fee_accounts sfa
                  INNER JOIN students s ON sfa.student_id = s.SID
                  INNER JOIN courses c ON sfa.course_id = c.id
                  WHERE sfa.status = 'active'
                  ORDER BY s.Fname ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $accounts[] = $row;
    }
    $res->free();
}

// Fetch payment history
$payments = [];
$query = "SELECT sp.*, sfa.student_id, s.Fname, s.Lname, c.course_name 
          FROM student_payments sp
          INNER JOIN student_fee_accounts sfa ON sp.student_fee_account_id = sfa.id
          INNER JOIN students s ON sfa.student_id = s.SID
          INNER JOIN courses c ON sfa.course_id = c.id
          ORDER BY sp.payment_id DESC LIMIT 100";
$res = $db->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $payments[] = $row;
    }
    $res->free();
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header finance-section mb-4 mt-2">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-receipt me-2 text-primary"></i>Process Payments</h1>
                <p class="text-muted mb-0">Post cash or bank payments to student fee accounts, issue receipts, and manage reversals or cancellations.</p>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-primary" onclick="openRecordModal()">
                    <i class="fas fa-plus me-2"></i>Record Payment
                </button>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Payments Ledger -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-history me-2"></i>Recent Payments Ledger</h5>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Receipt Number</th>
                            <th>Student ID / Name</th>
                            <th>Course</th>
                            <th>Amount</th>
                            <th>Payment Date</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fas fa-receipt fa-2x mb-2 d-block"></i> No payments recorded yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark font-monospace fs-6 px-3 py-2 rounded-3"><?= htmlspecialchars($p['receipt_number'] ?: $p['reference_number'] ?: 'N/A') ?></span></td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($p['student_id']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($p['Fname'] . ' ' . $p['Lname']) ?></small>
                                    </td>
                                    <td class="small fw-semibold text-truncate" style="max-width:180px;"><?= htmlspecialchars($p['course_name']) ?></td>
                                    <td class="fw-bold text-success">ZMW <?= number_format($p['amount_paid'], 2) ?></td>
                                    <td><?= htmlspecialchars(date('Y-m-d', strtotime($p['payment_date']))) ?></td>
                                    <td><span class="badge bg-light text-dark"><?= htmlspecialchars($p['channel']) ?></span></td>
                                    <td>
                                        <?php if ($p['status'] === 'approved'): ?>
                                            <span class="badge bg-success rounded-pill px-3 py-1">Approved</span>
                                        <?php elseif ($p['status'] === 'reversed'): ?>
                                            <span class="badge bg-danger rounded-pill px-3 py-1" title="Reason: <?= htmlspecialchars($p['reversal_reason']) ?>">Reversed</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark rounded-pill px-3 py-1" title="Reason: <?= htmlspecialchars($p['reversal_reason']) ?>">Cancelled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <a href="printReceipt.php?view=<?= urlencode($p['receipt_number'] ?: $p['reference_number']) ?>" class="btn btn-outline-primary btn-sm rounded-start-pill px-3" target="_blank">
                                                <i class="fas fa-print"></i> Receipt
                                            </a>
                                            <?php if ($p['status'] === 'approved'): ?>
                                                <button class="btn btn-outline-danger btn-sm rounded-end-pill px-3" onclick="openReverseModal(<?= htmlspecialchars(json_encode($p)) ?>)">
                                                    <i class="fas fa-undo"></i> Reverse
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-outline-secondary btn-sm rounded-end-pill px-3" disabled>
                                                    <i class="fas fa-ban"></i> Void
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Record Modal -->
<div class="modal fade" id="recordModal" tabindex="-1" aria-labelledby="recordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary" id="recordModalLabel">Record Student Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="record">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="p_account" class="form-label fw-semibold">Student Account</label>
                        <select class="form-select rounded-3" name="student_fee_account_id" id="p_account" required>
                            <option value="">-- Select Student Account --</option>
                            <?php foreach ($accounts as $ac): ?>
                                <option value="<?= htmlspecialchars($ac['id']) ?>"><?= htmlspecialchars($ac['Fname'] . ' ' . $ac['Lname']) ?> (<?= htmlspecialchars($ac['student_id']) ?>) - [<?= htmlspecialchars($ac['course_name']) ?>] - Balance ZMW <?= number_format($ac['balance'], 2) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="p_amount" class="form-label fw-semibold">Payment Amount (ZMW)</label>
                        <input type="number" step="0.01" class="form-control rounded-3" name="amount" id="p_amount" required placeholder="0.00">
                    </div>
                    <div class="mb-3">
                        <label for="p_method" class="form-label fw-semibold">Payment Method</label>
                        <select class="form-select rounded-3" name="payment_method" id="p_method">
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Airtel Money">Airtel Money</option>
                            <option value="Card">Credit/Debit Card</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="p_receipt" class="form-label fw-semibold">Receipt / Reference Number (Unique)</label>
                        <input type="text" class="form-control rounded-3" name="receipt_number" id="p_receipt" required placeholder="e.g., RCPT-12345">
                    </div>
                    <div class="mb-3">
                        <label for="p_date" class="form-label fw-semibold">Payment Date</label>
                        <input type="date" class="form-control rounded-3" name="payment_date" id="p_date" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="p_notes" class="form-label fw-semibold">Notes / Narration</label>
                        <textarea class="form-control rounded-3" name="notes" id="p_notes" rows="2" placeholder="Sponsor payment, balance clearance, etc."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Post Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reverse Modal -->
<div class="modal fade" id="reverseModal" tabindex="-1" aria-labelledby="reverseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger" id="reverseModalLabel">Reverse Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="reverse">
                <input type="hidden" name="payment_id" id="rev_id" value="0">
                <div class="modal-body p-4">
                    <p class="text-muted small">You are about to reverse receipt number <strong id="rev_receipt"></strong> for student <strong id="rev_student"></strong>. This transaction will be marked as failed, and the student's balance will be recalculated.</p>
                    <div class="mb-3">
                        <label for="rev_status" class="form-label fw-semibold">Action Status</label>
                        <select class="form-select rounded-3" name="status" id="rev_status">
                            <option value="reversed">Reversed (Funds returned)</option>
                            <option value="cancelled">Cancelled (Clerical error)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="rev_reason" class="form-label fw-semibold">Reason for Reversal / Cancellation</label>
                        <textarea class="form-control rounded-3" name="reversal_reason" id="rev_reason" rows="3" required placeholder="Provide a detailed explanation..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4">Confirm Reversal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openRecordModal() {
    document.getElementById('p_account').value = '';
    document.getElementById('p_amount').value = '';
    document.getElementById('p_receipt').value = 'RCPT-' + Date.now();
    document.getElementById('p_notes').value = '';
    var myModal = new bootstrap.Modal(document.getElementById('recordModal'));
    myModal.show();
}

function openReverseModal(p) {
    document.getElementById('rev_id').value = p.payment_id;
    document.getElementById('rev_receipt').innerText = p.receipt_number || p.reference_number;
    document.getElementById('rev_student').innerText = p.Fname + ' ' + p.Lname;
    document.getElementById('rev_reason').value = '';
    var myModal = new bootstrap.Modal(document.getElementById('reverseModal'));
    myModal.show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
