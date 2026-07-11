<?php
/**
 * Bank Transactions Report
 * Shows paginated list of bank payments with filters and CSV export
 */

require_once __DIR__ . '/../includes/security.php';

function bankReportTableExists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '{$safe}'");
    if (!$result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function bankReportColumnExists(mysqli $db, string $table, string $column): bool {
    $safeTable = str_replace('`', '', $table);
    $safeColumn = $db->real_escape_string($column);
    $result = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    if (!$result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function bankReportTransactionSource(mysqli $db): array {
    if (bankReportTableExists($db, 'transactions')) {
        return [
            'source_name' => 'transactions',
            'date_expr' => 'transactions.transactionDate',
            'student_expr' => 'transactions.studentID',
            'reference_expr' => 'transactions.referenceID',
            'amount_expr' => 'transactions.amount',
            'type_expr' => 'transactions.transactionType',
            'base_sql' => "
                FROM transactions
                LEFT JOIN student_program ON transactions.studentID COLLATE utf8mb4_general_ci = student_program.Sid COLLATE utf8mb4_general_ci
                LEFT JOIN students ON student_program.Sid COLLATE utf8mb4_general_ci = students.SID COLLATE utf8mb4_general_ci
            ",
            'base_where' => [],
        ];
    }

    if (bankReportTableExists($db, 'student_payments')) {
        $amountExpr = bankReportColumnExists($db, 'student_payments', 'amount_paid')
            ? 'CASE WHEN COALESCE(sp.amount_paid, 0) > 0 THEN sp.amount_paid ELSE COALESCE(sp.amount, 0) END'
            : 'COALESCE(sp.amount, 0)';
        $dateExpr = bankReportColumnExists($db, 'student_payments', 'payment_date')
            ? 'COALESCE(sp.payment_date, sp.dte_time, sp.created_at)'
            : 'COALESCE(sp.dte_time, sp.created_at)';
        $referenceParts = [];
        foreach (['reference_number', 'referenceID', 'receiptNum', 'invoice'] as $column) {
            if (bankReportColumnExists($db, 'student_payments', $column)) {
                $referenceParts[] = "NULLIF(sp.`{$column}`, '')";
            }
        }
        $referenceExpr = $referenceParts ? 'COALESCE(' . implode(', ', $referenceParts) . ", '')" : "''";
        $baseWhere = ["{$amountExpr} > 0"];
        if (bankReportColumnExists($db, 'student_payments', 'channel')) {
            $baseWhere[] = "LOWER(COALESCE(sp.channel, '')) <> 'invoice'";
        }
        if (bankReportColumnExists($db, 'student_payments', 'payment_channel')) {
            $baseWhere[] = "LOWER(COALESCE(sp.payment_channel, '')) <> 'invoice'";
        }
        if (bankReportColumnExists($db, 'student_payments', 'payment_status')) {
            $baseWhere[] = "LOWER(COALESCE(sp.payment_status, 'completed')) NOT IN ('pending', 'unpaid', 'invoice')";
        }

        return [
            'source_name' => 'student_payments',
            'date_expr' => $dateExpr,
            'student_expr' => 'sp.Sid',
            'reference_expr' => $referenceExpr,
            'amount_expr' => $amountExpr,
            'type_expr' => "'credit'",
            'base_sql' => "
                FROM student_payments sp
                LEFT JOIN student_program ON sp.Sid COLLATE utf8mb4_general_ci = student_program.Sid COLLATE utf8mb4_general_ci
                LEFT JOIN students ON sp.Sid COLLATE utf8mb4_general_ci = students.SID COLLATE utf8mb4_general_ci
            ",
            'base_where' => $baseWhere,
        ];
    }

    return [
        'source_name' => 'none',
        'date_expr' => 'NULL',
        'student_expr' => "''",
        'reference_expr' => "''",
        'amount_expr' => '0',
        'type_expr' => "'credit'",
        'base_sql' => ' FROM (SELECT 1) empty_source ',
        'base_where' => ['1 = 0'],
    ];
}

// Helper function to build filter conditions (used by both CSV export and page display)
function buildTransactionFilters($get, mysqli $db, array $source) {
    $where = [];
    $params = [];
    $types = '';
    foreach ($source['base_where'] as $condition) {
        $where[] = $condition;
    }
    
    if (!empty($get['from'])) {
        $where[] = $source['date_expr'] . ' >= ?';
        $params[] = $get['from'] . ' 00:00:00';
        $types .= 's';
    }
    if (!empty($get['to'])) {
        $where[] = $source['date_expr'] . ' <= ?';
        $params[] = $get['to'] . ' 23:59:59';
        $types .= 's';
    }
    if (!empty($get['type']) && in_array($get['type'], ['credit', 'debit'], true)) {
        $where[] = $source['type_expr'] . ' = ?';
        $params[] = $get['type'];
        $types .= 's';
    }
    if (!empty($get['q'])) {
        $like = '%' . $get['q'] . '%';
        $where[] = '(' . $source['reference_expr'] . ' LIKE ? OR students.Fname LIKE ? OR students.Lname LIKE ? OR ' . $source['student_expr'] . ' LIKE ?)';
        array_push($params, $like, $like, $like, $like);
        $types .= 'ssss';
    }
    
    return [
        'sql' => $where ? 'WHERE ' . implode(' AND ', $where) : '',
        'params' => $params,
        'types' => $types
    ];
}

function bankReportSelectSql(array $source): string {
    return "SELECT "
        . $source['date_expr'] . " AS transactionDate, "
        . $source['student_expr'] . " AS studentID, "
        . $source['reference_expr'] . " AS referenceID, "
        . $source['amount_expr'] . " AS amount, "
        . $source['type_expr'] . " AS transactionType, "
        . "students.Fname, students.Lname, student_program.program_code";
}

function requireFinanceReportAuth(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        wuc_configure_session_cookie();
        session_start();
    }

    wuc_apply_security_headers(true);

    if (!isset($_SESSION['user_id']) && isset($_SESSION['staff_id'])) {
        $_SESSION['user_id'] = $_SESSION['staff_id'];
    }

    $userId = (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? '');
    if ($userId === '') {
        http_response_code(403);
        exit('Unauthorized');
    }

    return $userId;
}

// CSV Export - must happen before any HTML output
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require "../db/connect.php";
    $authUserId = requireFinanceReportAuth();

    $transactionSource = bankReportTransactionSource($db);
    $filters = buildTransactionFilters($_GET, $db, $transactionSource);
    
    // Audit log
    require_once __DIR__ . '/../includes/audit.php';
    audit_log($db, $authUserId, 'export_bank_transactions', [
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
        'type' => $_GET['type'] ?? '',
        'q' => $_GET['q'] ?? ''
    ]);

    $sql = bankReportSelectSql($transactionSource)
         . $transactionSource['base_sql'] . $filters['sql']
         . " ORDER BY transactionDate DESC, referenceID DESC LIMIT 50000";
    
    $stmt = $db->prepare($sql);
    if ($filters['types']) $stmt->bind_param($filters['types'], ...$filters['params']);
    $stmt->execute();
    $result = $stmt->get_result();

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=bank_transactions_' . date('Y-m-d') . '.csv');
    
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Student ID', 'First Name', 'Last Name', 'Program', 'Amount', 'Reference', 'Type']);
    $exportedCount = 0;
    while ($row = $result->fetch_assoc()) {
        $exportedCount++;
        fputcsv($out, [
            $row['transactionDate'], $row['studentID'], $row['Fname'], $row['Lname'],
            $row['program_code'], $row['amount'], $row['referenceID'], $row['transactionType']
        ]);
    }
    if ($exportedCount === 50000) {
        fputcsv($out, ['WARNING: Results truncated at 50,000 rows. Use date filters to export smaller ranges.']);
    }
    fclose($out);
    exit;
}

requireFinanceReportAuth();
require "includes/nav.php";
require_once __DIR__ . '/../includes/report_print.php';

// Build filters and pagination
$transactionSource = bankReportTransactionSource($db);
$filters = buildTransactionFilters($_GET, $db, $transactionSource);
$pp = (int)($_GET['per_page'] ?? 25);
$perPage = in_array($pp, [25, 50, 100], true) ? $pp : 25;
$page = max(1, (int)($_GET['page'] ?? 1));

// Get total count and filtered grand total
$countSql = "SELECT 
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN " . $transactionSource['type_expr'] . " = 'credit' THEN " . $transactionSource['amount_expr'] . " ELSE -(" . $transactionSource['amount_expr'] . ") END), 0) AS filtered_total"
    . $transactionSource['base_sql'] . $filters['sql'];
$stmt = $db->prepare($countSql);
if ($filters['types']) $stmt->bind_param($filters['types'], ...$filters['params']);
$stmt->execute();
$countRow = $stmt->get_result()->fetch_assoc();
$totalRows = (int)($countRow['total'] ?? 0);
$filteredTotal = (float)($countRow['filtered_total'] ?? 0);
$stmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Get paginated records
$sql = bankReportSelectSql($transactionSource)
     . $transactionSource['base_sql'] . $filters['sql']
     . " ORDER BY transactionDate DESC, referenceID DESC LIMIT ? OFFSET ?";

$stmt = $db->prepare($sql);
$types = $filters['types'] . 'ii';
$params = array_merge($filters['params'], [$perPage, $offset]);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}
$stmt->close();

// Calculate totals for display
$pageSubtotal = array_reduce($records, function($sum, $r) {
    $amount = (float)($r['amount'] ?? 0);
    return $sum + (($r['transactionType'] ?? '') === 'credit' ? $amount : -$amount);
}, 0.0);
$fromNum = $totalRows ? $offset + 1 : 0;
$toNum = $offset + count($records);
$exportParams = array_intersect_key($_GET, array_flip(['from', 'to', 'type', 'q', 'per_page']));
?>

<div class="container-fluid px-4 portal-dashboard accounts-page bank-transaction-page">
    <?php
    render_report_print_styles();
    render_report_print_script();
    render_report_print_header(
        'Bank Transactions Report',
        'Recorded bank payments and transactions',
        [
            'From'     => $_GET['from']   ?? 'Any',
            'To'       => $_GET['to']     ?? 'Any',
            'Type'     => $_GET['type']   ?? 'All',
            'Search'   => $_GET['q']      ?? '',
            'Total'    => number_format($filteredTotal, 2) . ' ZMW',
            'Records'  => number_format($totalRows),
        ]
    );
    ?>

    <div class="dashboard-header finance-section mb-4 d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Bank Transactions</h1>
                <p class="text-muted mb-0">Overview of recorded bank payments</p>
            </div>
        </div>
    </div>

    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">
                    <i class="fas fa-university me-2"></i>Bank Transactions Report
                </h5>
                <div class="header-actions d-flex gap-2 align-items-center no-print">
                    <a href="?<?= htmlspecialchars(http_build_query(array_merge($exportParams, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-file-csv me-1"></i>Export CSV
                    </a>
                    <button class="btn btn-sm btn-secondary" onclick="printReport('Bank Transactions Report')">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                    <button id="btnRefresh" class="btn btn-sm btn-info" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-1"></i>Refresh
                    </button>
                </div>
            </div>
        </div>
        
        <div class="card-body">
            <!-- Filters -->
            <form method="GET" class="row g-3 mb-3 no-print">
                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" class="form-control form-control-sm" name="from" value="<?= htmlspecialchars($_GET['from'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" class="form-control form-control-sm" name="to" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select class="form-select form-select-sm" name="type">
                        <option value="">All</option>
                        <option value="credit" <?= ($_GET['type'] ?? '') === 'credit' ? 'selected' : '' ?>>Credit</option>
                        <option value="debit" <?= ($_GET['type'] ?? '') === 'debit' ? 'selected' : '' ?>>Debit</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control form-control-sm" name="q" placeholder="Reference or name" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label">Rows</label>
                    <select class="form-select form-select-sm" name="per_page">
                        <?php foreach ([25, 50, 100] as $opt): ?>
                            <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">Filter</button>
                    <a href="bankTransaction.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                </div>
            </form>

            <!-- Results summary -->
            <div class="d-flex justify-content-between align-items-center mb-2">
                <small class="text-muted">
                    Showing <?= number_format($fromNum) ?>&ndash;<?= number_format($toNum) ?> of <?= number_format($totalRows) ?> records
                </small>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge bg-light text-dark">Page subtotal: ZMW <?= number_format($pageSubtotal, 2) ?></span>
                    <span class="badge bg-light text-dark">Filtered total: ZMW <?= number_format($filteredTotal, 2) ?></span>
                </div>
            </div>

            <!-- Standard print header (logo + title + date) is rendered above by render_report_print_header(). -->

            <?php if (empty($records)): ?>
                <div class="alert alert-info">No records found matching your criteria.</div>
            <?php else: ?>
                <div class="table-container table-responsive">
                    <table class="table table-hover align-middle table-sticky">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Program</th>
                                <th class="text-end">Amount</th>
                                <th>Reference</th>
                                <th>Type</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $i => $r): ?>
                            <tr>
                                <td><?= $offset + $i + 1 ?></td>
                                <td><?= htmlspecialchars($r['studentID'] ?? '') ?></td>
                                <td>
                                    <?php
                                    $fullName = trim((string)($r['Fname'] ?? '') . ' ' . (string)($r['Lname'] ?? ''));
                                    echo $fullName !== ''
                                        ? htmlspecialchars($fullName)
                                        : '<span class="text-muted">&mdash;</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    echo !empty($r['program_code'])
                                        ? htmlspecialchars($r['program_code'])
                                        : '<span class="text-muted">&mdash;</span>';
                                    ?>
                                </td>
                                <td class="text-end"><?= number_format((float)($r['amount'] ?? 0), 2) ?></td>
                                <td><?= htmlspecialchars($r['referenceID'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['transactionType'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['transactionDate'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): 
                    $qs = array_intersect_key($_GET, array_flip(['from', 'to', 'type', 'q', 'per_page']));
                ?>
                <nav class="mt-3 no-print">
                    <ul class="pagination pagination-sm justify-content-end mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($qs, ['page' => 1])) ?>">First</a>
                        </li>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($qs, ['page' => $page - 1])) ?>">Prev</a>
                        </li>
                        <li class="page-item disabled">
                            <span class="page-link">Page <?= $page ?> / <?= $totalPages ?></span>
                        </li>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($qs, ['page' => $page + 1])) ?>">Next</a>
                        </li>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($qs, ['page' => $totalPages])) ?>">Last</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
