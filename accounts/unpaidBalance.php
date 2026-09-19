<?php
/**
 * Student Payment Records - View balance statements and filter transactions
 */
$page_title = 'Student Payment Records';
require "includes/nav.php";
require_once __DIR__ . '/../includes/report_print.php';

// Enable error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Log errors but don't display to users

// Ensure UTF-8 charset for DB connection
if (isset($db) && $db instanceof mysqli) {
    mysqli_set_charset($db, 'utf8mb4');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper function for safe HTML output
function h($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function paymentRecordsTableExists(mysqli $db, string $table): bool
{
    if ($res = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
}

function paymentRecordsColumnExists(mysqli $db, string $table, string $column): bool
{
    if (!paymentRecordsTableExists($db, $table)) {
        return false;
    }
    if ($res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $db->real_escape_string($column) . "'")) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
}

/**
 * Get a student's payment records and balance statement.
 *
 * @param mysqli $db Database connection
 * @param string $studentId Student ID to look up
 * @return array Result with success status and statement data or error
 */
function getStudentBalanceStatement(mysqli $db, string $studentId): array
{
    $studentId = trim($studentId);
    if ($studentId === '') {
        return ['success' => false, 'error' => 'Student ID is required.'];
    }

    // Fetch student info
    $stmt = $db->prepare("SELECT SID, Fname, Lname FROM students WHERE SID = ? LIMIT 1");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error.'];
    }
    
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();

    if (!$student) {
        return ['success' => false, 'error' => 'Student not found.'];
    }

    $studentSID = $student['SID'];
    $studentName = trim(($student['Fname'] ?? '') . ' ' . ($student['Lname'] ?? ''));

    // Get payment records
    $payments = [];
    $totalPaid = 0.0;
    
    if (paymentRecordsTableExists($db, 'payments')) {
        $stmt = $db->prepare("SELECT payment_date, amount, method, receipt_no FROM payments WHERE student_id = ? ORDER BY payment_date ASC");
        if ($stmt) {
            $stmt->bind_param('s', $studentSID);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $amount = (float)$row['amount'];
                $totalPaid += $amount;
                $payments[] = [
                    'date' => $row['payment_date'],
                    'amount' => $amount,
                    'method' => $row['method'],
                    'reference' => $row['receipt_no']
                ];
            }
            $stmt->close();
        }
    } elseif (paymentRecordsTableExists($db, 'student_payments')) {
        $sidCol = paymentRecordsColumnExists($db, 'student_payments', 'Sid') ? 'Sid' : (paymentRecordsColumnExists($db, 'student_payments', 'SID') ? 'SID' : null);
        $amountCol = paymentRecordsColumnExists($db, 'student_payments', 'amount_paid') ? 'amount_paid' : (paymentRecordsColumnExists($db, 'student_payments', 'amount') ? 'amount' : null);
        $methodCol = paymentRecordsColumnExists($db, 'student_payments', 'channel') ? 'channel' : (paymentRecordsColumnExists($db, 'student_payments', 'payment_method') ? 'payment_method' : null);
        $refCol = paymentRecordsColumnExists($db, 'student_payments', 'reference_number') ? 'reference_number' : (paymentRecordsColumnExists($db, 'student_payments', 'reference') ? 'reference' : null);
        if ($sidCol && $amountCol) {
            $methodExpr = $methodCol ? "`{$methodCol}`" : "''";
            $refExpr = $refCol ? "`{$refCol}`" : "''";
            $stmt = $db->prepare("SELECT payment_date, `{$amountCol}` AS amount_paid, {$methodExpr} AS channel, {$refExpr} AS reference_number FROM student_payments WHERE `{$sidCol}` = ? ORDER BY payment_date ASC");
            if ($stmt) {
                $stmt->bind_param('s', $studentSID);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $amount = (float)$row['amount_paid'];
                    $totalPaid += $amount;
                    $payments[] = [
                        'date' => $row['payment_date'],
                        'amount' => $amount,
                        'method' => $row['channel'],
                        'reference' => $row['reference_number']
                    ];
                }
                $stmt->close();
            }
        }
    }

    // Get total fees - try fees table first, then invoices
    $totalFees = getTotalFees($db, $studentSID);
    $feesFound = $totalFees !== null;
    $effectiveTotalFees = $totalFees ?? 0.0;
    $outstanding = $feesFound ? max(0, round($effectiveTotalFees - $totalPaid, 2)) : 0.0;

    return [
        'success' => true,
        'statement' => [
            'studentId' => $studentSID,
            'studentName' => $studentName,
            'payments' => $payments,
            'totalFees' => $feesFound ? round($effectiveTotalFees, 2) : null,
            'totalPaid' => round($totalPaid, 2),
            'outstanding' => $outstanding,
            'feesFound' => $feesFound
        ]
    ];
}

/**
 * Get total fees for a student from fees or invoices table.
 */
function getTotalFees(mysqli $db, string $studentId): ?float
{
    // Check student_fee_accounts table first (canonical fees ledger)
    if (paymentRecordsTableExists($db, 'student_fee_accounts')) {
        try {
            $stmt = $db->prepare("SELECT total_payable FROM student_fee_accounts WHERE student_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $stmt->close();
                    if ($row['total_payable'] !== null) {
                        return (float)$row['total_payable'];
                    }
                } else {
                    $stmt->close();
                }
            }
        } catch (Throwable $e) {
            // Fall through to other sources
        }
    }

    // Try fees table with common column variations
    $feeQueries = [
        "SELECT TotalFees FROM fees WHERE StudentID = ? LIMIT 1",
        "SELECT TotalFees FROM fees WHERE SID = ? LIMIT 1",
        "SELECT total_fees FROM fees WHERE StudentID = ? LIMIT 1",
        "SELECT total_fees FROM fees WHERE SID = ? LIMIT 1",
    ];

    foreach ($feeQueries as $query) {
        // Under strict mysqli reporting a missing table/column makes prepare()
        // throw rather than return false — treat either as "try next variant".
        try {
            $stmt = $db->prepare($query);
        } catch (Throwable $e) {
            continue;
        }
        if ($stmt === false) {
            continue;
        }

        $foundValue = null;
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $value = reset($row); // Get first column value
            if ($value !== null && $value !== '') {
                $foundValue = (float)$value;
            }
        }
        $stmt->close();

        if ($foundValue !== null) {
            return $foundValue;
        }
    }

    // Fallback: sum from invoices table
    $invoiceQueries = [
        "SELECT SUM(amount) as total FROM invoices WHERE student_id = ?",
        "SELECT SUM(amount) as total FROM invoices WHERE SID = ?",
    ];

    foreach ($invoiceQueries as $query) {
        try {
            $stmt = $db->prepare($query);
        } catch (Throwable $e) {
            continue;
        }
        if ($stmt === false) {
            continue;
        }

        $foundValue = null;
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (($row['total'] ?? null) !== null) {
                $foundValue = max(0, (float)$row['total']);
            }
        }
        $stmt->close();

        if ($foundValue !== null) {
            return $foundValue;
        }
    }

    return null;
}

/**
 * Get available payment channels for dropdown.
 */
function getPaymentChannels(mysqli $db): array
{
    $channels = [];
    if (paymentRecordsTableExists($db, 'payments')) {
        $result = $db->query("SELECT DISTINCT method AS channel FROM payments WHERE method IS NOT NULL AND method <> '' ORDER BY method");
    } elseif (paymentRecordsColumnExists($db, 'student_payments', 'channel')) {
        $result = $db->query("SELECT DISTINCT channel FROM student_payments WHERE channel IS NOT NULL ORDER BY channel");
    } elseif (paymentRecordsColumnExists($db, 'student_payments', 'payment_method')) {
        $result = $db->query("SELECT DISTINCT payment_method AS channel FROM student_payments WHERE payment_method IS NOT NULL ORDER BY payment_method");
    } else {
        $result = false;
    }
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $channels[] = $row['channel'];
        }
        $result->free();
    }
    return $channels;
}

/**
 * Get filtered payment records by channel.
 */
function getPaymentRecords(mysqli $db, string $channel): array
{
    $records = [];
    if (paymentRecordsTableExists($db, 'payments')) {
        $sql = "SELECT p.student_id AS Sid, s.Fname, s.Lname, prog.program_code,
                       p.amount AS amount_paid, 0 AS balance, p.method AS channel, p.receipt_no AS reference_number,
                       p.payment_date, p.id AS payment_id
                 FROM payments p
                 LEFT JOIN student_program prog ON prog.Sid COLLATE utf8mb4_unicode_ci = p.student_id COLLATE utf8mb4_unicode_ci
                 LEFT JOIN students s ON s.SID COLLATE utf8mb4_unicode_ci = p.student_id COLLATE utf8mb4_unicode_ci
                 WHERE p.method = ? AND COALESCE(p.receipt_no, '') <> ''
                 ORDER BY p.payment_date DESC
                 LIMIT 2000";
    } elseif (paymentRecordsTableExists($db, 'student_payments')) {
        $sidCol = paymentRecordsColumnExists($db, 'student_payments', 'Sid') ? 'Sid' : (paymentRecordsColumnExists($db, 'student_payments', 'SID') ? 'SID' : null);
        $amountCol = paymentRecordsColumnExists($db, 'student_payments', 'amount_paid') ? 'amount_paid' : (paymentRecordsColumnExists($db, 'student_payments', 'amount') ? 'amount' : null);
        $methodCol = paymentRecordsColumnExists($db, 'student_payments', 'channel') ? 'channel' : (paymentRecordsColumnExists($db, 'student_payments', 'payment_method') ? 'payment_method' : null);
        $refCol = paymentRecordsColumnExists($db, 'student_payments', 'reference_number') ? 'reference_number' : (paymentRecordsColumnExists($db, 'student_payments', 'reference') ? 'reference' : null);
        $idCol = paymentRecordsColumnExists($db, 'student_payments', 'payment_id') ? 'payment_id' : 'id';
        if (!$sidCol || !$amountCol || !$methodCol || !$refCol) {
            return [];
        }
        $sql = "SELECT sp.`{$sidCol}` AS Sid, s.Fname, s.Lname, prog.program_code,
                       sp.`{$amountCol}` AS amount_paid, 0 AS balance, sp.`{$methodCol}` AS channel, sp.`{$refCol}` AS reference_number,
                       sp.payment_date, sp.`{$idCol}` AS payment_id
                 FROM student_payments sp
                 LEFT JOIN student_program prog ON prog.Sid COLLATE utf8mb4_unicode_ci = CAST(sp.`{$sidCol}` AS CHAR(50)) COLLATE utf8mb4_unicode_ci
                 LEFT JOIN students s ON s.SID COLLATE utf8mb4_unicode_ci = CAST(sp.`{$sidCol}` AS CHAR(50)) COLLATE utf8mb4_unicode_ci
                 WHERE sp.`{$methodCol}` = ? AND COALESCE(sp.`{$refCol}`, '') <> ''
                 ORDER BY sp.payment_date DESC
                 LIMIT 2000";
    } else {
        return [];
    }

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $channel);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        $stmt->close();
    }
    return $records;
}

// Process requests
$channels = getPaymentChannels($db);
$records = [];
$statementData = null;
$errorMessages = [
    'statement' => [],
    'filter' => []
];
$resultsCapped = false;
$currentAction = $_GET['action'] ?? '';

// Handle balance statement lookup
if ($currentAction === 'statement') {
    $sid = trim($_GET['sid'] ?? '');
    $result = getStudentBalanceStatement($db, $sid);
    if ($result['success']) {
        $statementData = $result['statement'];
    } else {
        $errorMessages['statement'][] = $result['error'];
    }
} elseif ($currentAction === 'filter') {
    $channel = trim($_GET['channel'] ?? '');
    if ($channel === '') {
        $errorMessages['filter'][] = 'Payment channel is required.';
    } else {
        $records = getPaymentRecords($db, $channel);
        $resultsCapped = count($records) === 2000;
        if (empty($records)) {
            $errorMessages['filter'][] = 'No records found for the selected channel.';
        }
    }
}
?>
<div class="container-fluid px-4 portal-dashboard accounts-page unpaid-balance-page">
    <div class="dashboard-header finance-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Student Payment Records</h1>
                <p class="text-muted mb-0">Filter and print receipts</p>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="mb-4 d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#balanceStatementForm">
            <i class="fas fa-file-invoice-dollar me-2"></i>Balance Statement by Student ID
        </button>
        <button type="button" class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#filterPaymentsForm">
            <i class="fas fa-filter me-2"></i>Filter Payment Records
        </button>
    </div>

    <div class="row g-4">
        <div class="col-12">
            <!-- Balance Statement Search (Collapsible) -->
            <div class="collapse <?= $currentAction === 'statement' ? 'show' : '' ?> mb-4" id="balanceStatementForm">
                <div class="data-table-card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-file-invoice-dollar me-2"></i>Balance Statement by Student ID
                            </h5>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php foreach ($errorMessages['statement'] as $message): ?>
                            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                <?= h($message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endforeach; ?>
                        <form method="GET" action="">
                            <input type="hidden" name="action" value="statement">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <label for="sid" class="form-label">
                                        <i class="fas fa-user-tag me-1"></i> Student ID
                                    </label>
                                    <input type="text" class="form-control" name="sid" id="sid" 
                                           placeholder="Enter Student ID" 
                                           value="<?= h($_GET['sid'] ?? '') ?>" autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search me-1"></i> Get Statement
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($statementData):
                render_report_print_styles();
                render_report_print_script();
                render_report_print_header(
                    'Outstanding Fees Statement',
                    $statementData['studentName'] ?? '',
                    [
                        'Student ID'  => $statementData['studentId'] ?? '',
                        'Name'        => $statementData['studentName'] ?? '',
                        'Total Fees'  => isset($statementData['totalFees']) ? 'ZMW ' . number_format((float)$statementData['totalFees'], 2) : '—',
                        'Total Paid'  => 'ZMW ' . number_format((float)($statementData['totalPaid'] ?? 0), 2),
                        'Outstanding' => isset($statementData['outstanding']) ? 'ZMW ' . number_format((float)$statementData['outstanding'], 2) : '—',
                    ]
                );
            ?>
            <!-- Balance Statement Display -->
            <div class="data-table-card mb-4" id="statementPrint">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-file-invoice-dollar me-2"></i>Balance Statement
                        </h5>
                        <div class="header-actions">
                            <button type="button" class="btn btn-primary btn-sm no-print" onclick="printReport('Outstanding Fees Statement')">
                                <i class="fas fa-print me-1"></i> Print Statement
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3 d-print-none">
                        <h4 class="mb-1"><strong>Industrial Training Centre</strong></h4>
                        <img src="images/LOGO2.jpeg" alt="ITC Logo" style="height:72px;width:auto;max-width:100%">
                        <div class="text-muted small mt-1">Generated on <?= date('Y-m-d H:i') ?></div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6"><strong>Student ID:</strong> <?= h($statementData['studentId']) ?></div>
                        <div class="col-md-6"><strong>Name:</strong> <?= h($statementData['studentName']) ?></div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-4 mb-2">
                            <div class="p-3 border rounded bg-light">
                                <div class="text-muted small">Total Fees</div>
                                <div class="fs-5">
                                    <?php if ($statementData['feesFound']): ?>
                                        ZMW <?= number_format((float)$statementData['totalFees'], 2) ?>
                                    <?php else: ?>
                                        <span class="text-warning-emphasis">Fee record not found</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="p-3 border rounded bg-light">
                                <div class="text-muted small">Total Paid</div>
                                <div class="fs-5">ZMW <?= number_format($statementData['totalPaid'], 2) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <?php
                            $hasBalance = $statementData['feesFound'] && $statementData['outstanding'] > 0;
                            $outstandingCardClass = !$statementData['feesFound']
                                ? 'bg-warning-subtle'
                                : ($hasBalance ? 'bg-warning-subtle' : 'bg-success-subtle');
                            $outstandingBadgeClass = !$statementData['feesFound']
                                ? 'bg-warning text-dark'
                                : ($hasBalance ? 'bg-warning text-dark' : 'bg-success');
                            $outstandingBadgeText = !$statementData['feesFound']
                                ? 'Fee record not found'
                                : ($hasBalance ? 'Balance Due' : 'Cleared');
                            ?>
                            <div class="p-3 border rounded <?= $outstandingCardClass ?>">
                                <div class="text-muted small">Outstanding</div>
                                <div class="fs-5">
                                    <?php if ($statementData['feesFound']): ?>
                                        ZMW <?= number_format($statementData['outstanding'], 2) ?>
                                    <?php else: ?>
                                        <span class="text-warning-emphasis">Unavailable</span>
                                    <?php endif; ?>
                                </div>
                                <span class="badge <?= $outstandingBadgeClass ?> mt-2">
                                    <?= $outstandingBadgeText ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <h6 class="mt-2">Payments</h6>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th class="text-end">Amount (ZMW)</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($statementData['payments']): ?>
                                    <?php foreach ($statementData['payments'] as $payment): ?>
                                    <tr>
                                        <td><?= h($payment['date']) ?></td>
                                        <td class="text-end"><?= number_format($payment['amount'], 2) ?></td>
                                        <td><?= h($payment['method']) ?></td>
                                        <td><?= h($payment['reference']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-muted">No payments found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th class="text-end">Total Paid:</th>
                                    <th class="text-end">ZMW <?= number_format($statementData['totalPaid'], 2) ?></th>
                                    <th colspan="2"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Channel Filter (Collapsible) -->
            <div class="collapse <?= $currentAction === 'filter' ? 'show' : '' ?> mb-4" id="filterPaymentsForm">
                <div class="data-table-card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-filter me-2"></i>Filter Payment Records
                            </h5>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php foreach ($errorMessages['filter'] as $message): ?>
                            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                <?= h($message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endforeach; ?>
                        <form method="GET" action="">
                            <input type="hidden" name="action" value="filter">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <label for="channel" class="form-label">
                                        <i class="fas fa-project-diagram me-1"></i> Payment Channel
                                    </label>
                                    <select class="form-select" name="channel" id="channel" required>
                                        <option value="" disabled <?= empty($_GET['channel']) ? 'selected' : '' ?>>-- Select Channel --</option>
                                        <?php foreach ($channels as $ch): ?>
                                            <option value="<?= h($ch) ?>" <?= ($ch === ($_GET['channel'] ?? '')) ? 'selected' : '' ?>><?= h($ch) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-search me-1"></i> Filter
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Transaction History Table -->
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Transaction History
                        </h5>
                        <div class="header-actions">
                            <a href="searchStatement.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-file-invoice-dollar me-1"></i> Balance Statement
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <h4><strong>Industrial Training Centre</strong></h4>
                        <img src="images/LOGO2.jpeg" alt="ITC Logo" style="height:72px;width:auto;max-width:100%">
                    </div>
                    <?php if (!empty($records)): ?>
                        <?php if ($resultsCapped): ?>
                            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                Results capped at 2,000 rows. Use date filters to narrow the result.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table id="myTable" class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>No</th>
                                        <th>SID</th>
                                        <th>First Name</th>
                                        <th>Last Name</th>
                                        <th>Program</th>
                                        <th>Amount Paid</th>
                                        <th>Balance at Payment</th>
                                        <th>Channel</th>
                                        <th>Reference No</th>
                                        <th>Date Paid</th>
                                        <th class="text-center">Print</th>
                                        <th class="text-center">Delete</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $rowNum = 1; foreach ($records as $record): ?>
                                    <tr>
                                        <td><?= $rowNum++ ?></td>
                                        <td><?= h($record['Sid']) ?></td>
                                        <td><?= ($record['Fname'] ?? null) !== null ? h($record['Fname']) : '<span class="text-muted">&mdash;</span>' ?></td>
                                        <td><?= ($record['Lname'] ?? null) !== null ? h($record['Lname']) : '<span class="text-muted">&mdash;</span>' ?></td>
                                        <td><?= ($record['program_code'] ?? null) !== null ? h($record['program_code']) : '<span class="text-muted">&mdash;</span>' ?></td>
                                        <td><?= number_format((float)$record['amount_paid'], 2) ?></td>
                                        <td><?= number_format((float)$record['balance'], 2) ?></td>
                                        <td><?= h($record['channel']) ?></td>
                                        <td><?= h($record['reference_number']) ?></td>
                                        <td><?= h($record['payment_date']) ?></td>
                                        <td class="text-center">
                                            <a href="printReceipt.php?view=<?= urlencode($record['reference_number']) ?>" 
                                               class="btn btn-sm btn-outline-primary rounded-pill" title="Print Receipt">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" action="delete_payment.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this payment permanently?')">
                                                <input type="hidden" name="del" value="<?= h($record['payment_id']) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill" title="Delete Payment">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">Select a payment channel above and click Filter to view transactions.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($records)): ?>
<script>
$(document).ready(function() {
    $('#myTable').DataTable({
        language: { emptyTable: 'No transactions to display.' },
        ordering: true,
        paging: true,
        searching: true,
        autoWidth: false,
        order: [[9, 'desc']] // Sort by date descending
    });
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
