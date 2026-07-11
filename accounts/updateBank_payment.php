<?php
/**
 * Update Bank Pending Payments
 * Find bank transactions and post to student accounts
 */
$page_title = 'Update Bank Pending Payments';
require "includes/nav.php";
require_once __DIR__ . '/../includes/finance_helpers.php';

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Ensure UTF-8 charset
if (isset($db) && $db instanceof mysqli) {
    mysqli_set_charset($db, 'utf8mb4');
}

$errors = [];
$successMessage = '';
$records = [];
$lastUpdated = date('Y-m-d H:i:s');

// Check if transactions table has status column
$hasTransactionStatus = checkColumnExists($db, 'transactions', 'status');

/**
 * Check if a column exists in a table
 */
function checkColumnExists(mysqli $db, string $table, string $column): bool
{
    if (!checkTableExists($db, $table)) {
        return false;
    }

    $safeTable = $db->real_escape_string($table);
    $safeColumn = $db->real_escape_string($column);
    try {
        $result = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) $result->free();
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

function checkTableExists(mysqli $db, string $table): bool
{
    $safeTable = $db->real_escape_string($table);
    try {
        $result = $db->query("SHOW TABLES LIKE '{$safeTable}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) $result->free();
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Validate and fetch transaction from database
 */
function fetchTransaction(mysqli $db, string $transactionId, bool $hasStatus): ?array
{
    if (!checkTableExists($db, 'transactions')) return null;
    $columns = "transactionId, studentID, referenceID, amount, narration, transactionType";
    if ($hasStatus) $columns .= ", status";
    
    $stmt = $db->prepare("SELECT $columns FROM transactions WHERE transactionId = ? LIMIT 1");
    if (!$stmt) return null;
    
    $stmt->bind_param('s', $transactionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $tx = $result->fetch_assoc();
    $stmt->close();
    
    return $tx ?: null;
}

/**
 * Check if student exists
 */
function studentExists(mysqli $db, string $studentId): bool
{
    $stmt = $db->prepare("SELECT 1 FROM students WHERE SID = ? LIMIT 1");
    if (!$stmt) return false;
    
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();
    
    return $exists;
}

/**
 * Check if reference has already been posted
 */
function referenceAlreadyPosted(mysqli $db, string $referenceId): bool
{
    $checks = [];
    if (checkTableExists($db, 'payments') && checkColumnExists($db, 'payments', 'receipt_no')) {
        $checks[] = ['payments', 'receipt_no'];
    }
    if (checkTableExists($db, 'student_payments')) {
        if (checkColumnExists($db, 'student_payments', 'reference_number')) {
            $checks[] = ['student_payments', 'reference_number'];
        }
        if (checkColumnExists($db, 'student_payments', 'reference')) {
            $checks[] = ['student_payments', 'reference'];
        }
    }

    foreach ($checks as [$table, $column]) {
        $stmt = $db->prepare("SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1");
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('s', $referenceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;
        $stmt->close();
        if ($exists) {
            return true;
        }
    }

    return false;
}

/**
 * Calculate delta based on transaction type
 * Credit/Payment = negative (reduces balance), Debit/Charge = positive (increases balance)
 */
function calculateDelta(string $transactionType, float $amount): float
{
    $type = strtolower($transactionType);
    if (in_array($type, ['credit', 'payment', 'refund_credit'])) {
        return -abs($amount);
    }
    if (in_array($type, ['debit', 'charge', 'refund_debit'])) {
        return abs($amount);
    }
    return -abs($amount); // Default: treat as payment
}

/**
 * Post transaction to student account
 */
function postTransaction(mysqli $db, array $tx, string $semester, string $yearOfStudy, bool $hasStatus): array
{
    $studentId = $tx['studentID'];
    $referenceId = $tx['referenceID'];
    $amount = (float)$tx['amount'];
    $narration = $tx['narration'] ?? '';
    $transactionType = $tx['transactionType'] ?? '';
    $transactionId = $tx['transactionId'];

    // Validate amount
    if ($amount <= 0) {
        return ['success' => false, 'error' => 'Amount must be positive.'];
    }

    // Check status if available
    if ($hasStatus) {
        $status = strtolower($tx['status'] ?? '');
        if ($status !== 'pending') {
            return ['success' => false, 'error' => 'Transaction is not pending or was already completed.'];
        }
    }

    // Validate student exists
    if (!studentExists($db, $studentId)) {
        return ['success' => false, 'error' => 'Invalid student ID.'];
    }

    // Check for duplicate posting
    if (referenceAlreadyPosted($db, $referenceId)) {
        return ['success' => false, 'error' => 'This bank reference has already been posted.'];
    }

    $delta = calculateDelta($transactionType, $amount);
    
    $db->begin_transaction();

    try {
        // Update transaction status if supported
        if ($hasStatus) {
            $stmt = $db->prepare("UPDATE transactions SET status = 'completed' WHERE transactionId = ? AND LOWER(status) = 'pending'");
            $stmt->bind_param('s', $transactionId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                $db->rollback();
                return ['success' => false, 'error' => 'Transaction is not pending or was already completed.'];
            }
            $stmt->close();
        }

        // Update student account. Live schemas use either legacy SID/balance
        // or normalized student_id/current_balance.
        $accountStudentCol = checkColumnExists($db, 'student_accounts', 'student_id') ? 'student_id' : 'SID';
        $accountBalanceCol = checkColumnExists($db, 'student_accounts', 'current_balance') ? 'current_balance' : 'balance';
        $accountDateSet = checkColumnExists($db, 'student_accounts', 'last_payment_date')
            ? 'last_payment_date = NOW()'
            : (checkColumnExists($db, 'student_accounts', 'updated_at') ? 'updated_at = NOW()' : '');
        $updateParts = ["`{$accountBalanceCol}` = `{$accountBalanceCol}` + ?"];
        if ($accountDateSet !== '') {
            $updateParts[] = $accountDateSet;
        }
        $stmt = $db->prepare("UPDATE student_accounts SET " . implode(', ', $updateParts) . " WHERE `{$accountStudentCol}` = ? LIMIT 1");
        if (!$stmt) {
            $db->rollback();
            return ['success' => false, 'error' => 'Failed to prepare account update.'];
        }
        $stmt->bind_param('ds', $delta, $studentId);
        if (!$stmt->execute()) {
            $stmt->close();
            $db->rollback();
            return ['success' => false, 'error' => 'Failed to update student account.'];
        }
        $updatedAccountRows = $stmt->affected_rows;
        $stmt->close();

        if ($updatedAccountRows < 1) {
            $insertCols = "`{$accountStudentCol}`, `{$accountBalanceCol}`";
            $insertVals = "?, ?";
            if (checkColumnExists($db, 'student_accounts', 'status')) {
                $insertCols .= ", `status`";
                $insertVals .= ", 'active'";
            }
            if (checkColumnExists($db, 'student_accounts', 'created_at')) {
                $insertCols .= ", `created_at`";
                $insertVals .= ", NOW()";
            }
            if (checkColumnExists($db, 'student_accounts', 'updated_at')) {
                $insertCols .= ", `updated_at`";
                $insertVals .= ", NOW()";
            }
            $stmt = $db->prepare("INSERT INTO student_accounts ({$insertCols}) VALUES ({$insertVals})");
            if (!$stmt) {
                $db->rollback();
                return ['success' => false, 'error' => 'Failed to prepare account insert.'];
            }
            $stmt->bind_param('sd', $studentId, $delta);
            if (!$stmt->execute()) {
                $stmt->close();
                $db->rollback();
                return ['success' => false, 'error' => 'Failed to create student account.'];
            }
            $stmt->close();
        }

        // Get new balance
        $newBalance = 0.0;
        $stmt = $db->prepare("SELECT `{$accountBalanceCol}` AS balance FROM student_accounts WHERE `{$accountStudentCol}` = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $newBalance = (float)$row['balance'];
            }
            $stmt->close();
        }

        // Record payment in the current normalized ledger and mirror to the
        // legacy/slim student_payments table when available.
        $channel = $transactionType ?: 'bank';
        $description = $narration ?: 'Bank transaction posting';
        $amountPaid = ($delta < 0) ? abs($delta) : 0.0;

        if (checkTableExists($db, 'payments')) {
            $postedBy = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'system');
            $stmt = $db->prepare("INSERT INTO payments
                (student_id, receipt_no, amount, method, payment_date, status, description, posted_by, academic_year, semester)
                VALUES (?, ?, ?, ?, NOW(), 'completed', ?, ?, ?, ?)");
            if (!$stmt) {
                $db->rollback();
                return ['success' => false, 'error' => 'Failed to prepare payment ledger insert.'];
            }
            $stmt->bind_param('ssdsssss', $studentId, $referenceId, $amountPaid, $channel, $description, $postedBy, $yearOfStudy, $semester);
            if (!$stmt->execute()) {
                $stmt->close();
                $db->rollback();
                return ['success' => false, 'error' => 'Failed to record normalized payment.'];
            }
            $stmt->close();
        }

        if (checkTableExists($db, 'student_payments')) {
            $paymentColumns = [];
            if ($cols = $db->query("SHOW COLUMNS FROM student_payments")) {
                while ($row = $cols->fetch_assoc()) {
                    $paymentColumns[] = (string)$row['Field'];
                }
                $cols->free();
            }
            $fields = [];
            $placeholders = [];
            $types = '';
            $params = [];
            $add = static function(string $column, string $type, $value, bool $raw = false) use (&$fields, &$placeholders, &$types, &$params, $paymentColumns): void {
                if (!in_array($column, $paymentColumns, true)) {
                    return;
                }
                $fields[] = "`{$column}`";
                if ($raw) {
                    $placeholders[] = (string)$value;
                    return;
                }
                $placeholders[] = '?';
                $types .= $type;
                $params[] = $value;
            };

            $add('Sid', 's', $studentId);
            $add('SID', 's', $studentId);
            $add('student_id', 's', $studentId);
            $add('amount_paid', 'd', $amountPaid);
            $add('amount', 'd', $amountPaid);
            $add('balance', 'd', $newBalance);
            $add('channel', 's', $channel);
            $add('payment_method', 's', $channel);
            $add('payment_date', '', 'NOW()', true);
            $add('academic_year', 's', $yearOfStudy);
            $add('semester_term', 's', $semester);
            $add('semester', 's', $semester);
            $add('reference_number', 's', $referenceId);
            $add('reference', 's', $referenceId);
            $add('description', 's', $description);
            $add('status', 's', 'completed');
            $add('payment_status', 's', 'completed');
            $add('created_at', '', 'NOW()', true);

            if (!empty($fields)) {
                $stmt = $db->prepare("INSERT INTO student_payments (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
                if (!$stmt) {
                    $db->rollback();
                    return ['success' => false, 'error' => 'Failed to prepare student payment mirror insert.'];
                }
                if ($types !== '') {
                    $stmt->bind_param($types, ...$params);
                }
                if (!$stmt->execute()) {
                    $stmt->close();
                    $db->rollback();
                    return ['success' => false, 'error' => 'Failed to record student payment mirror.'];
                }
                $stmt->close();
            }
        }

        // Allocate to pending installments if payment
        if ($delta < 0) {
            allocateToInstallments($db, $studentId, $amountPaid);
        }

        // Audit log
        $userId = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'system';
        $details = json_encode([
            'transactionId' => $transactionId,
            'referenceID' => $referenceId,
            'studentID' => $studentId,
            'amount' => $amount,
            'transactionType' => $transactionType,
            'deltaApplied' => $delta,
            'semester' => $semester,
            'year' => $yearOfStudy
        ]);
        log_audit($db, (string)$userId, 'post_bank_transaction', $details);

        $db->commit();
        return ['success' => true, 'message' => 'Transaction posted successfully.'];

    } catch (Throwable $e) {
        $db->rollback();
        return ['success' => false, 'error' => 'Unexpected error: ' . $e->getMessage()];
    }
}

/**
 * Allocate payment to pending installments
 */
function allocateToInstallments(mysqli $db, string $studentId, float $amount): void
{
    $remaining = $amount;
    
    $stmt = @$db->prepare("SELECT id, amount FROM finance_student_installments 
                           WHERE student_id = ? AND status = 'pending' 
                           ORDER BY due_date ASC, id ASC");
    if (!$stmt) return;
    
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($remaining > 0 && ($row = $result->fetch_assoc())) {
        $instAmount = (float)$row['amount'];
        if ($remaining + 0.0001 >= $instAmount) {
            $update = $db->prepare("UPDATE finance_student_installments SET status = 'paid', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            if ($update) {
                $update->bind_param('i', $row['id']);
                $update->execute();
                $update->close();
            }
            $remaining -= $instAmount;
        } else {
            break; // Partial payment not tracked
        }
    }
    $stmt->close();
}

/**
 * Search for transactions
 */
function searchTransactions(mysqli $db, array $filters, bool $hasStatus): array
{
    if (!checkTableExists($db, 'transactions')) {
        return ['error' => 'Bank transaction staging table is not configured. Payments can still be posted from Payments (Returning Students) against invoices.'];
    }
    $where = [];
    $params = [];
    $types = '';

    if (!empty($filters['studentID'])) {
        $where[] = 't.studentID = ?';
        $params[] = $filters['studentID'];
        $types .= 's';
    }
    if (!empty($filters['referenceID'])) {
        $where[] = 't.referenceID = ?';
        $params[] = $filters['referenceID'];
        $types .= 's';
    }
    if (!empty($filters['fromDate'])) {
        $where[] = 't.transactionDate >= ?';
        $params[] = $filters['fromDate'] . ' 00:00:00';
        $types .= 's';
    }
    if (!empty($filters['toDate'])) {
        $where[] = 't.transactionDate <= ?';
        $params[] = $filters['toDate'] . ' 23:59:59';
        $types .= 's';
    }
    if (!empty($filters['transactionType']) && in_array(strtolower($filters['transactionType']), ['credit', 'debit'])) {
        $where[] = 't.transactionType = ?';
        $params[] = $filters['transactionType'];
        $types .= 's';
    }
    if ($hasStatus && !empty($filters['status'])) {
        $where[] = 't.status = ?';
        $params[] = $filters['status'];
        $types .= 's';
    }

    if (empty($where)) {
        return ['error' => 'Please provide at least one filter to search.'];
    }

    $sql = "SELECT s.*, t.* 
            FROM students s 
            INNER JOIN transactions t ON s.SID = t.studentID 
            WHERE " . implode(' AND ', $where) . " 
            ORDER BY t.transactionDate DESC";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return ['error' => 'Failed to prepare query.'];
    }

    if (!empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $stmt->close();

    if (empty($records)) {
        return ['error' => 'No matching transactions found.'];
    }

    return ['records' => $records];
}

// Helper for safe output
function h($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// Handle post transaction
if (isset($_POST['post_transaction'])) {
    $transactionId = trim($_POST['transactionId'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $yearOfStudy = trim($_POST['Year'] ?? '');

    if ($transactionId === '') {
        $errors[] = 'Missing transaction ID.';
    } elseif ($semester === '' || $yearOfStudy === '') {
        $errors[] = 'Semester and year of study are required.';
    } else {
        $tx = fetchTransaction($db, $transactionId, $hasTransactionStatus);
        if (!$tx) {
            $errors[] = 'Transaction not found.';
        } else {
            $result = postTransaction($db, $tx, $semester, $yearOfStudy, $hasTransactionStatus);
            if ($result['success']) {
                $successMessage = $result['message'];
            } else {
                $errors[] = $result['error'];
            }
        }
    }
}

// Handle search
if (isset($_POST['search'])) {
    $filters = [
        'studentID' => trim($_POST['studentID'] ?? ''),
        'referenceID' => trim($_POST['referenceID'] ?? ''),
        'fromDate' => trim($_POST['fromDate'] ?? ''),
        'toDate' => trim($_POST['toDate'] ?? ''),
        'transactionType' => trim($_POST['transactionType'] ?? ''),
        'status' => trim($_POST['status'] ?? '')
    ];
    
    $searchResult = searchTransactions($db, $filters, $hasTransactionStatus);
    if (isset($searchResult['error'])) {
        $errors[] = $searchResult['error'];
    } else {
        $records = $searchResult['records'];
    }
}
?>
<div class="container-fluid px-4 portal-dashboard accounts-page update-bank-page">
    <div class="dashboard-header finance-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Update Bank Pending Payments</h1>
                <p class="text-muted mb-0">Find transactions and post to student accounts</p>
            </div>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= h(implode(' ', $errors)) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= h($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search Form -->
    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 update-bank-header-row">
                <h5 class="mb-0">
                    <i class="bi bi-bank me-2"></i>Search Bank Transactions
                </h5>
                <div class="header-actions d-flex align-items-center gap-3 update-bank-actions">
                    <button id="btnRefresh" class="btn btn-sm btn-info" type="button">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </button>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="autoRefreshToggle">
                        <label class="form-check-label small" for="autoRefreshToggle">Auto refresh</label>
                    </div>
                    <small class="text-muted">Last updated: <?= h($lastUpdated) ?></small>
                </div>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" action="" class="update-bank-search-form">
                <div class="row g-3 update-bank-search-grid">
                    <div class="col-md-6">
                        <label for="studentID" class="form-label">
                            <i class="bi bi-person-badge me-1"></i> Student ID
                        </label>
                        <input type="text" class="form-control" id="studentID" name="studentID" 
                               placeholder="Enter Student ID" value="<?= h($_POST['studentID'] ?? '') ?>" autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label for="referenceID" class="form-label">
                            <i class="bi bi-hash me-1"></i> Bank Reference Number
                        </label>
                        <input type="text" class="form-control" id="referenceID" name="referenceID" 
                               placeholder="Enter Bank Reference" value="<?= h($_POST['referenceID'] ?? '') ?>" autocomplete="off">
                    </div>
                </div>
                <div class="row g-3 mt-1 update-bank-search-grid">
                    <div class="col-md-3">
                        <label for="fromDate" class="form-label">From Date</label>
                        <input type="date" class="form-control" id="fromDate" name="fromDate" 
                               value="<?= h($_POST['fromDate'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="toDate" class="form-label">To Date</label>
                        <input type="date" class="form-control" id="toDate" name="toDate" 
                               value="<?= h($_POST['toDate'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="transactionTypeFilter" class="form-label">Type</label>
                        <select class="form-select" id="transactionTypeFilter" name="transactionType">
                            <option value="">All</option>
                            <option value="credit" <?= ($_POST['transactionType'] ?? '') === 'credit' ? 'selected' : '' ?>>Credit</option>
                            <option value="debit" <?= ($_POST['transactionType'] ?? '') === 'debit' ? 'selected' : '' ?>>Debit</option>
                        </select>
                    </div>
                    <?php if ($hasTransactionStatus): ?>
                    <div class="col-md-3">
                        <label for="statusFilter" class="form-label">Status</label>
                        <select class="form-select" id="statusFilter" name="status">
                            <option value="">All</option>
                            <option value="pending" <?= strtolower($_POST['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="completed" <?= strtolower($_POST['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="failed" <?= strtolower($_POST['status'] ?? '') === 'failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mt-4 text-end update-bank-search-submit">
                    <button type="submit" class="btn btn-success px-4" name="search">
                        <i class="bi bi-search me-1"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Transaction Results -->
    <?php if ($records): ?>
        <?php foreach ($records as $index => $r): ?>
        <div class="data-table-card mb-4 update-bank-result-card">
            <div class="card-header">
                <h5 class="mb-0">
                    <strong><?= h($r['title'] ?? '') ?> <?= h($r['Fname'] ?? '') ?> <?= h($r['Lname'] ?? '') ?></strong>
                    <span class="text-muted ms-2">| <?= h($r['nrc_pass'] ?? '') ?></span>
                </h5>
            </div>
            <div class="card-body">
                <form action="" method="POST" class="row g-3 update-bank-result-form">
                    <input type="hidden" name="transactionId" value="<?= h($r['transactionId']) ?>">
                    
                    <div class="col-md-4">
                        <label class="form-label">Student ID</label>
                        <input type="text" class="form-control" value="<?= h($r['studentID']) ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Bank Reference ID</label>
                        <input type="text" class="form-control" value="<?= h($r['referenceID']) ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Amount (ZMW)</label>
                        <input type="text" class="form-control" value="<?= h(number_format((float)$r['amount'], 2)) ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Narration</label>
                        <input type="text" class="form-control" value="<?= h($r['narration'] ?? '') ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Payment Channel</label>
                        <input type="text" class="form-control" value="<?= h($r['transactionType'] ?? '') ?>" readonly>
                    </div>
                    <div class="col-md-3">
                        <label for="semester_<?= $index ?>" class="form-label">Semester/Term <span class="text-danger">*</span></label>
                        <select class="form-select" name="semester" id="semester_<?= $index ?>" required>
                            <option value="" disabled selected>Select</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="year_<?= $index ?>" class="form-label">Year <span class="text-danger">*</span></label>
                        <select class="form-select" id="year_<?= $index ?>" name="Year" required>
                            <option value="" disabled selected>Year of study</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                        </select>
                    </div>
                    <div class="col-12 text-end update-bank-result-submit">
                        <button class="btn btn-success" type="submit" name="post_transaction" value="1">
                            <i class="fas fa-check me-2"></i>Post to Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    // Refresh button
    $('#btnRefresh').on('click', function() {
        window.location.reload();
    });

    // Auto refresh toggle (every 5 minutes)
    var refreshInterval = null;
    $('#autoRefreshToggle').on('change', function() {
        if (this.checked) {
            refreshInterval = setInterval(function() {
                window.location.reload();
            }, 300000);
        } else if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

