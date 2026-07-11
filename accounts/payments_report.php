<?php
/**
 * Accounts — Online Payment Gateway report (DPO Pay).
 *
 * Lists payment_gateway_transactions with filters, totals, CSV export and a
 * reconciliation check (verified gateway payments that have no matching row in
 * the student_payments ledger).
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/role_helpers.php';
require_once __DIR__ . '/../includes/payment_helpers.php';

// ---- shared filter parsing (used by both CSV export and the page) ----------
function pgrpt_filters(): array
{
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));

    $validDate = static function (string $d): string {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
    };
    $from = $validDate($from);
    $to = $validDate($to);
    if (!in_array($type, ['', 'course_registration', 'fee_payment', 'exam_fee', 'other'], true)) {
        $type = '';
    }
    if (!in_array($status, ['', 'completed', 'pending', 'token_created', 'failed', 'cancelled'], true)) {
        $status = '';
    }
    return ['from' => $from, 'to' => $to, 'type' => $type, 'status' => $status, 'q' => $q];
}

function pgrpt_query(mysqli $db, array $f, int $limit = 500): array
{
    $where = ['1=1'];
    $types = '';
    $params = [];
    if ($f['from'] !== '') {
        $where[] = 'created_at >= ?';
        $types .= 's';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if ($f['to'] !== '') {
        $where[] = 'created_at <= ?';
        $types .= 's';
        $params[] = $f['to'] . ' 23:59:59';
    }
    if ($f['type'] !== '') {
        $where[] = 'payment_type = ?';
        $types .= 's';
        $params[] = $f['type'];
    }
    if ($f['status'] !== '') {
        $where[] = 'status = ?';
        $types .= 's';
        $params[] = $f['status'];
    }
    if ($f['q'] !== '') {
        $where[] = '(student_id LIKE ? OR reference_number LIKE ? OR invoice_number LIKE ? OR receipt_no LIKE ?)';
        $types .= 'ssss';
        $needle = '%' . $f['q'] . '%';
        array_push($params, $needle, $needle, $needle, $needle);
    }

    $rows = [];
    $sql = "SELECT * FROM payment_gateway_transactions WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT " . (int)$limit;
    if ($stmt = $db->prepare($sql)) {
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
    }
    return $rows;
}

// ---- CSV export (before any HTML output) ------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    wuc_apply_security_headers(false);
    if (session_status() === PHP_SESSION_NONE) {
        wuc_configure_session_cookie();
        session_start();
    }
    if (!isset($_SESSION['user_id']) && isset($_SESSION['staff_id'])) {
        $_SESSION['user_id'] = $_SESSION['staff_id'];
    }
    if (!isset($_SESSION['user_id']) || (function_exists('canAccessFinance') && !canAccessFinance())) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
    if (!payment_ensure_gateway_transactions_table($db)) {
        http_response_code(503);
        echo 'Gateway transactions table is not available.';
        exit;
    }

    $rows = pgrpt_query($db, pgrpt_filters(), 5000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=online_payments_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Reference', 'Student ID', 'Type', 'Invoice', 'Amount', 'Currency', 'Status', 'Result Code', 'Result', 'Receipt', 'Provider Ref', 'Completed At']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['created_at'],
            $row['reference_number'],
            $row['student_id'],
            $row['payment_type'],
            $row['invoice_number'],
            $row['amount'],
            $row['currency'],
            $row['status'],
            $row['result_code'],
            $row['result_desc'],
            $row['receipt_no'],
            $row['provider_transaction_id'],
            $row['completed_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ---- page --------------------------------------------------------------------
$page_title = 'Online Payments (Gateway)';
require "includes/nav.php";

$tableReady = payment_ensure_gateway_transactions_table($db);
$filters = pgrpt_filters();
$rows = $tableReady ? pgrpt_query($db, $filters) : [];

$totalCompleted = 0.0;
$countByStatus = [];
foreach ($rows as $row) {
    $st = strtolower((string)$row['status']);
    $countByStatus[$st] = ($countByStatus[$st] ?? 0) + 1;
    if ($st === 'completed') {
        $totalCompleted += (float)$row['amount'];
    }
}

// Reconciliation: completed gateway payments with no matching ledger row.
$unreconciled = [];
if ($tableReady && wuc_table_exists($db, 'student_payments')) {
    $sql = "SELECT t.reference_number, t.student_id, t.amount, t.completed_at
            FROM payment_gateway_transactions t
            LEFT JOIN student_payments sp ON sp.reference_number = t.reference_number
            WHERE t.status = 'completed' AND sp.payment_id IS NULL
            ORDER BY t.id DESC LIMIT 50";
    if ($res = @$db->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $unreconciled[] = $row;
        }
        $res->free();
    }
}

function pgrpt_status_badge(string $status): string
{
    switch (strtolower($status)) {
        case 'completed': return 'success';
        case 'pending':
        case 'token_created': return 'warning text-dark';
        case 'cancelled': return 'secondary';
        default: return 'danger';
    }
}
?>

<div class="container-fluid px-4 pt-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 pb-3 border-bottom">
        <div>
            <h2 class="fw-bold" style="color:#6f42c1;"><i class="fas fa-credit-card me-2"></i>Online Payments (Gateway)</h2>
            <p class="text-muted mb-0">DPO Pay hosted-checkout transactions across fees and course registration.</p>
        </div>
        <a class="btn btn-outline-primary" href="payments_report.php?export=csv&amp;<?php echo htmlspecialchars(http_build_query(array_filter($filters, static fn($v) => $v !== ''))); ?>">
            <i class="fas fa-file-csv me-2"></i>Export CSV
        </a>
    </div>

    <?php if (!$tableReady): ?>
        <div class="alert alert-danger">
            <i class="fas fa-database me-2"></i>The payment gateway transactions table is missing. Run
            <code>migrations/20260703_dpo_paygate_payments.php</code> as the migration DB user.
        </div>
    <?php else: ?>

    <?php if (!empty($unreconciled)): ?>
        <div class="alert alert-warning">
            <h6 class="fw-bold mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Reconciliation needed — verified payments without a ledger row</h6>
            <ul class="mb-0 small">
                <?php foreach ($unreconciled as $u): ?>
                    <li>
                        <span class="font-monospace"><?php echo htmlspecialchars((string)$u['reference_number']); ?></span>
                        — student <?php echo htmlspecialchars((string)$u['student_id']); ?>,
                        ZMW <?php echo number_format((float)$u['amount'], 2); ?>,
                        completed <?php echo htmlspecialchars((string)$u['completed_at']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">From</label>
                    <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($filters['from']); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">To</label>
                    <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($filters['to']); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">Type</label>
                    <select name="type" class="form-select">
                        <option value="">All types</option>
                        <?php foreach (['course_registration' => 'Course Registration', 'fee_payment' => 'Fees Payment', 'exam_fee' => 'Exam Fee', 'other' => 'Other'] as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $filters['type'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All statuses</option>
                        <?php foreach (['completed', 'pending', 'token_created', 'failed', 'cancelled'] as $st): ?>
                            <option value="<?php echo $st; ?>" <?php echo $filters['status'] === $st ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_', ' ', $st)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Student / Reference / Invoice / Receipt</label>
                    <input type="text" name="q" class="form-control" placeholder="Search…" value="<?php echo htmlspecialchars($filters['q']); ?>">
                </div>
                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i></button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold">Completed total (shown)</div>
                    <div class="fs-4 fw-bold text-success">ZMW <?php echo number_format($totalCompleted, 2); ?></div>
                </div>
            </div>
        </div>
        <?php foreach (['completed' => 'success', 'failed' => 'danger', 'cancelled' => 'secondary'] as $st => $color): ?>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm rounded-3 h-100">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold"><?php echo ucfirst($st); ?></div>
                        <div class="fs-4 fw-bold text-<?php echo $color; ?>"><?php echo (int)($countByStatus[$st] ?? 0); ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-5">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Student</th>
                        <th>Type</th>
                        <th>Invoice</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Result</th>
                        <th>Receipt</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No gateway transactions match the current filters.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="text-muted"><small><?php echo htmlspecialchars((string)$row['created_at']); ?></small></td>
                            <td><span class="badge bg-light border text-primary font-monospace"><?php echo htmlspecialchars((string)$row['reference_number']); ?></span></td>
                            <td class="fw-medium"><?php echo htmlspecialchars((string)$row['student_id']); ?></td>
                            <td><small><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst((string)$row['payment_type']))); ?></small></td>
                            <td class="text-muted"><small><?php echo htmlspecialchars((string)$row['invoice_number'] ?: '—'); ?></small></td>
                            <td class="text-end fw-bold"><?php echo htmlspecialchars((string)$row['currency']); ?> <?php echo number_format((float)$row['amount'], 2); ?></td>
                            <td><span class="badge bg-<?php echo pgrpt_status_badge((string)$row['status']); ?> rounded-pill"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$row['status']))); ?></span></td>
                            <td class="text-muted" style="max-width:220px;"><small><?php echo htmlspecialchars(trim(((string)$row['result_code']) . ' ' . ((string)$row['result_desc']))); ?></small></td>
                            <td><small class="font-monospace"><?php echo htmlspecialchars((string)$row['receipt_no'] ?: '—'); ?></small></td>
                            <td>
                                <details>
                                    <summary class="small text-primary" style="cursor:pointer;">payloads</summary>
                                    <div class="small text-muted" style="max-width:420px; max-height:220px; overflow:auto; white-space:pre-wrap;"><?php
                                        echo htmlspecialchars("REQUEST:\n" . ((string)($row['request_payload'] ?? '') ?: '—') . "\n\nRESPONSE:\n" . ((string)($row['response_payload'] ?? '') ?: '—'));
                                    ?></div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
