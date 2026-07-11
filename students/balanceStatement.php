<?php
require_once __DIR__ . '/includes/guard.php';
// Note: guard.php already includes db/connect.php which provides $db (MySQLi)

// 1. Session safety check
$studentId = $_SESSION['Sid'] ?? null;
if (!$studentId) {
    http_response_code(401);
    exit('<div class="alert alert-danger">Session expired. Please log in again.</div>');
}

// 2. DB connection validation
if (!isset($db) || !($db instanceof mysqli)) {
    http_response_code(500);
    exit('<div class="alert alert-danger">Database connection not available.</div>');
}

function balance_statement_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$total_fees = 0.0;
$total_paid = 0.0;
$programCode = '';
$feeItems = [];
$registeredPeriods = []; // Array of [year_of_study, semester] pairs (normalized to int)
$paymentsByPeriod = [];  // Payments grouped by [year_of_study][semester] (normalized to int)

try {
    $tableExists = function (string $table) use ($db): bool {
        $safeTable = $db->real_escape_string($table);
        if ($result = $db->query("SHOW TABLES LIKE '{$safeTable}'")) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }
        return false;
    };

    // Get student's program code
    $progStmt = $db->prepare("SELECT program_code FROM student_program WHERE Sid = ? LIMIT 1");
    $progStmt->bind_param("s", $studentId);
    $progStmt->execute();
    $result = $progStmt->get_result();
    if ($row = $result->fetch_object()) {
        $programCode = (string)$row->program_code;
    }
    $progStmt->close();

    // Get year+semester combinations where student has registered (invoice generated)
    if ($programCode) {
        $regStmt = $db->prepare("
            SELECT DISTINCT
                COALESCE(sp.year_of_study, s.year, 1) AS year_of_study,
                sr.semester
            FROM semester_registration sr
            LEFT JOIN student_program sp
                ON sp.Sid COLLATE utf8mb4_unicode_ci = CONVERT(sr.SID USING utf8mb4) COLLATE utf8mb4_unicode_ci
            LEFT JOIN students s
                ON s.SID = CONVERT(sr.SID USING utf8mb4) COLLATE utf8mb4_general_ci
            WHERE sr.SID = ?
            ORDER BY year_of_study, sr.semester
        ");
        $regStmt->bind_param("s", $studentId);
        $regStmt->execute();
        $regResult = $regStmt->get_result();
        while ($p = $regResult->fetch_object()) {
            // 3. Normalize to integer keys for consistent indexing
            $registeredPeriods[] = [(int)$p->year_of_study, (int)$p->semester];
        }
        $regStmt->close();

        if ($tableExists('payments')) {
            // Get payments grouped by year_of_study and semester (normalized to int)
            $payStmt = $db->prepare("
                SELECT academic_year AS year_of_study, semester AS semester_term, SUM(amount) as paid 
                FROM payments 
                WHERE student_id = ? 
                  AND LOWER(status) IN ('completed', 'paid', 'success')
                  AND academic_year IS NOT NULL 
                  AND semester IS NOT NULL 
                GROUP BY academic_year, semester
            ");
            if ($payStmt) {
                $payStmt->bind_param("s", $studentId);
                $payStmt->execute();
                $payResult = $payStmt->get_result();
                while ($pay = $payResult->fetch_object()) {
                    // 3. Normalize to integer keys
                    $y = (int)$pay->year_of_study;
                    $s = (int)$pay->semester_term;
                    $paymentsByPeriod[$y][$s] = (float)$pay->paid;
                }
                $payStmt->close();
            }
        }
    }

    if ($programCode && !empty($registeredPeriods)) {
        // Build WHERE clause for year+semester combinations
        $conditions = [];
        $params = [$programCode];
        $types = "s";
        foreach ($registeredPeriods as [$y, $s]) {
            $conditions[] = "(year_of_study = ? AND semester = ?)";
            $params[] = $y;
            $params[] = $s;
            $types .= "ii";
        }
        $whereClause = implode(' OR ', $conditions);

        // Calculate total fees only for registered year+semester combinations
        $feesQuery = "
            SELECT COALESCE(SUM(amount), 0) as t 
            FROM fee_structure 
            WHERE program_code = ? 
              AND status = 'active' 
              AND ($whereClause)
        ";
        $feesStmt = $db->prepare($feesQuery);
        $feesStmt->bind_param($types, ...$params);
        $feesStmt->execute();
        $feesResult = $feesStmt->get_result();
        if ($row = $feesResult->fetch_object()) {
            $total_fees = (float)$row->t;
        }
        $feesStmt->close();

        // Get fee items only for registered year+semester combinations
        $itemsQuery = "
            SELECT fee_description, year_of_study, semester, amount 
            FROM fee_structure 
            WHERE program_code = ? 
              AND status = 'active' 
              AND ($whereClause) 
            ORDER BY year_of_study, semester, fee_description
        ";
        $itemsStmt = $db->prepare($itemsQuery);
        $itemsStmt->bind_param($types, ...$params);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();
        while ($item = $itemsResult->fetch_object()) {
            // Normalize to int for consistent indexing later
            $item->year_of_study = (int)$item->year_of_study;
            $item->semester = (int)$item->semester;
            $item->amount = (float)$item->amount;
            $feeItems[] = $item;
        }
        $itemsStmt->close();

        // 5. FIX: Calculate total paid ONLY for registered periods (aligned with total_fees)
        $payConditions = [];
        $payParams = [$studentId];
        $payTypes = "s";
        foreach ($registeredPeriods as [$y, $s]) {
            $payConditions[] = "(academic_year = ? AND semester = ?)";
            $payParams[] = (string)$y;
            $payParams[] = (string)$s;
            $payTypes .= "ss";
        }
        $payWhere = implode(" OR ", $payConditions);

        if ($tableExists('payments')) {
            $paidStmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as t 
                FROM payments 
                WHERE student_id = ? 
                  AND LOWER(status) IN ('completed', 'paid', 'success')
                  AND ($payWhere)
            ");
            if ($paidStmt) {
                $paidStmt->bind_param($payTypes, ...$payParams);
                $paidStmt->execute();
                $paidResult = $paidStmt->get_result();
                if ($row = $paidResult->fetch_object()) {
                    $total_paid = (float)$row->t;
                }
                $paidStmt->close();
            }
        }
    }

} catch (Throwable $e) {
    // Log error and show user-friendly message
    error_log('Balance Statement Error: ' . $e->getMessage());
    echo '<div class="alert alert-danger">Unable to load balance statement. Please try again later.</div>';
    exit;
}

$balance = $total_fees - $total_paid;

// Group fees by year
$feesByYear = [];
foreach ($feeItems as $f) {
    $year = (int)$f->year_of_study;
    $feesByYear[$year] ??= [];
    $feesByYear[$year][] = $f;
}
ksort($feesByYear);

// 6. FIX: Prepare remaining paid buckets per year+semester for correct allocation per line item
$remainingPaid = [];
foreach ($paymentsByPeriod as $y => $sems) {
    foreach ($sems as $s => $paid) {
        $remainingPaid[(int)$y][(int)$s] = (float)$paid;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Balance Statement - ITC</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <style>
        :root { --primary-blue: #2563eb; --success-green: #059669; --danger-red: #dc2626; }
        
        .content-wrapper { padding: 2rem; max-width: 1200px; margin: 0 auto; position: relative; }
        .content-wrapper::before {
            content: '';
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 400px;
            height: 400px;
            background: url('../images/favicon.png') no-repeat center center;
            background-size: contain;
            opacity: 0.04;
            pointer-events: none;
            z-index: 0;
        }
        .content-wrapper > * { position: relative; z-index: 1; }
        
        .page-header { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e2e8f0; text-align: center; }
        .page-logo { width: 70px; height: 70px; object-fit: contain; }
        .page-header-text h1 { font-size: 1.3rem; font-weight: 700; color: #1e293b; margin: 0; }
        .page-header-text p { font-size: 0.85rem; color: #64748b; margin: 0; }
        
        .page-title { font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 0.25rem; }
        .page-subtitle { color: #64748b; font-size: 0.9rem; margin-bottom: 1.5rem; }
        
        .nav-tabs { border-bottom: 2px solid #e2e8f0; margin-bottom: 1.5rem; }
        .nav-tabs .nav-link { border: none; color: #64748b; padding: 0.75rem 1rem; font-weight: 500; }
        .nav-tabs .nav-link:hover { color: var(--primary-blue); border: none; }
        .nav-tabs .nav-link.active { color: var(--primary-blue); border: none; border-bottom: 2px solid var(--primary-blue); background: none; }
        
        .stat-row { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .stat-card { flex: 1; min-width: 180px; background: white; border-radius: 12px; padding: 1.25rem; box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); }
        .stat-card .label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .stat-card .value { font-size: 1.5rem; font-weight: 700; color: #1e293b; }
        .stat-card.fees { border-left: 4px solid var(--primary-blue); }
        .stat-card.paid { border-left: 4px solid var(--success-green); }
        .stat-card.paid .value { color: var(--success-green); }
        .stat-card.balance { border-left: 4px solid var(--danger-red); }
        .stat-card.balance .value { color: var(--danger-red); }
        .stat-card.credit { border-left: 4px solid #06b6d4; }
        .stat-card.credit .value { color: #0891b2; }
        
        .year-section { margin-bottom: 2rem; }
        
        /* Year header colors - each year gets a distinct gradient */
        .year-header { color: white; padding: 0.85rem 1.25rem; border-radius: 12px 12px 0 0; font-weight: 600; font-size: 1rem; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
        .year-header .year-title { display: flex; align-items: center; gap: 0.5rem; }
        .year-header .year-badge { background: rgba(255,255,255,0.2); padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; }
        .year-1 .year-header { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); }
        .year-2 .year-header { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
        .year-3 .year-header { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); }
        .year-4 .year-header { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .year-5 .year-header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        
        .table-container { background: white; border-radius: 0 0 12px 12px; overflow: hidden; box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); }
        .data-table { width: 100%; margin-bottom: 0; }
        .data-table thead { background: #f8fafc; }
        .data-table th { padding: 0.875rem 1rem; text-align: left; font-weight: 600; color: #475569; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; }
        .data-table td { padding: 0.875rem 1rem; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 0.9rem; }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover { background: #f8fafc; }
        .data-table tfoot td { background: #f1f5f9; border-top: 2px solid #e2e8f0; font-weight: 700; }
        
        .amount { font-weight: 600; font-family: 'Monaco', 'Consolas', monospace; }
        .amount-positive { color: var(--success-green); }
        .amount-negative { color: var(--danger-red); }
        
        /* Semester color coding */
        .semester-tag { font-size: 0.8rem; font-weight: 600; padding: 0.3rem 0.75rem; border-radius: 20px; display: inline-block; }
        .semester-1 { background: #dbeafe; color: #1e40af; }
        .semester-2 { background: #fce7f3; color: #9d174d; }
        .semester-3 { background: #d1fae5; color: #065f46; }
        
        /* Row highlighting by semester */
        .data-table tbody tr.row-sem-1 { background: linear-gradient(90deg, rgba(59, 130, 246, 0.05) 0%, transparent 100%); }
        .data-table tbody tr.row-sem-1:hover { background: linear-gradient(90deg, rgba(59, 130, 246, 0.12) 0%, rgba(59, 130, 246, 0.05) 100%); }
        .data-table tbody tr.row-sem-2 { background: linear-gradient(90deg, rgba(236, 72, 153, 0.05) 0%, transparent 100%); }
        .data-table tbody tr.row-sem-2:hover { background: linear-gradient(90deg, rgba(236, 72, 153, 0.12) 0%, rgba(236, 72, 153, 0.05) 100%); }
        
        /* Semester divider */
        .semester-divider { background: #f1f5f9; }
        .semester-divider td { padding: 0.5rem 1rem; font-weight: 600; color: #64748b; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-top: 2px solid #e2e8f0; }
        
        .empty-msg { text-align: center; padding: 4rem 1rem; color: #94a3b8; background: white; border-radius: 12px; box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); }
        .empty-msg i { font-size: 3rem; margin-bottom: 1rem; display: block; opacity: 0.5; }
        .empty-msg p { font-size: 1rem; margin: 0; }
        .empty-msg .contact-info { font-size: 0.85rem; color: #64748b; margin-top: 0.5rem; }
        
        .summary-table { border-radius: 12px; overflow: hidden; margin-top: 1rem; box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); }
        
        @media print {
            .nav-tabs, .no-print { display: none !important; }
            .content-wrapper { padding: 0; max-width: 100%; }
            .content-wrapper::before { display: none; }
        }
        
        @media (max-width: 768px) {
            .content-wrapper { padding: 1rem; }
            .data-table { font-size: 0.8rem; }
            .data-table th, .data-table td { padding: 0.5rem; }
            .stat-card { min-width: 100%; }
        }
    <link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css">
</head>
<body class="bg-light single-page-document">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <div class="content-wrapper wuc-a4-sheet">
        <div class="page-header no-print">
            <img src="../images/favicon.png" alt="ITC" class="page-logo">
            <div class="page-header-text">
                <h1>Industrial Training Centre</h1>
                <p>Student Balance Statement</p>
            </div>
        </div>

        <h1 class="page-title" style="text-align: center;">Balance Statement</h1>
        <p class="page-subtitle" style="text-align: center;">View your fees breakdown and payment summary</p>
        <div class="text-center mb-3 no-print">
            <button type="button" class="btn btn-primary" onclick="wucPrintSinglePage()">
                <i class="fas fa-print me-1"></i> Print Statement
            </button>
        </div>

        <div class="container-fluid px-0">
            <ul class="nav nav-tabs no-print">
                <li class="nav-item">
                    <a class="nav-link" href="fees.php">Payment Records</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="#">Balance Statement</a>
                </li>
            </ul>

            <!-- Summary Stats -->
            <div class="stat-row">
                <div class="stat-card fees">
                    <div class="label">Total Fees</div>
                    <div class="value">ZMW <?= number_format($total_fees, 2) ?></div>
                </div>
                <div class="stat-card paid">
                    <div class="label">Total Paid</div>
                    <div class="value">ZMW <?= number_format($total_paid, 2) ?></div>
                </div>
                <div class="stat-card <?= $balance > 0 ? 'balance' : 'credit' ?>">
                    <div class="label"><?= $balance > 0 ? 'Balance Due' : 'Credit Balance' ?></div>
                    <div class="value">ZMW <?= number_format(abs($balance), 2) ?></div>
                </div>
            </div>
            
            <!-- Color Legend -->
            <?php if (!empty($feeItems)): ?>
            <div class="mb-3 d-flex flex-wrap gap-3 align-items-center" style="font-size: 0.8rem;">
                <span class="text-muted"><i class="fas fa-palette me-1"></i> Legend:</span>
                <span><span class="semester-tag semester-1">Sem 1</span> First Semester</span>
                <span><span class="semester-tag semester-2">Sem 2</span> Second Semester</span>
            </div>
            <?php endif; ?>

            <!-- Fee Breakdown by Year -->
            <?php if (empty($feeItems)): ?>
                <div class="empty-msg">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <?php if (empty($registeredPeriods)): ?>
                        <p>No semester registrations found.</p>
                        <p class="contact-info">Fees will appear once you register for a semester.</p>
                    <?php else: ?>
                        <p>No active fee structure found for your program.</p>
                        <p class="contact-info">Please contact the Finance Office for assistance.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($feesByYear as $year => $fees): 
                    $yearTotal = array_sum(array_map(fn($f) => (float)$f->amount, $fees));
                    // 7. FIX: Calculate year paid total correctly (avoid precedence bug)
                    $paidSem1 = $paymentsByPeriod[$year][1] ?? 0;
                    $paidSem2 = $paymentsByPeriod[$year][2] ?? 0;
                    $paidYearTotal = (float)$paidSem1 + (float)$paidSem2;
                    
                    // Group fees by semester for better display
                    $feesBySemester = [];
                    foreach ($fees as $f) {
                        $sem = (int)$f->semester;
                        $feesBySemester[$sem] ??= [];
                        $feesBySemester[$sem][] = $f;
                    }
                    ksort($feesBySemester);
                    
                    // Year class for color coding
                    $yearClass = 'year-' . min($year, 5);
                ?>
                <div class="year-section <?= balance_statement_h($yearClass) ?>">
                    <div class="year-header">
                        <div class="year-title">
                            <i class="fas fa-graduation-cap"></i> Year <?= balance_statement_h($year) ?> of Study
                        </div>
                        <span class="year-badge"><?= balance_statement_h(count($fees)) ?> fee items</span>
                    </div>
                    <div class="table-container table-responsive">
                        <table class="table table-hover align-middle data-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Fee Description</th>
                                    <th>Semester</th>
                                    <th style="text-align: right;">Amount</th>
                                    <th style="text-align: right;">Applied Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // 6. FIX: Use per-year+semester bucket for correct paid allocation
                            // Display fees grouped by semester with visual separation
                            foreach ($feesBySemester as $semester => $semesterFees): 
                                $semesterTotal = array_sum(array_map(fn($f) => (float)$f->amount, $semesterFees));
                                $semesterPaid = $paymentsByPeriod[$year][$semester] ?? 0;
                            ?>
                            <!-- Semester Header Row -->
                            <tr class="semester-divider">
                                <td colspan="4">
                                    <i class="fas fa-calendar-alt me-2"></i>Semester <?= balance_statement_h($semester) ?>
                                    <span style="float: right; font-weight: 400;">Subtotal: ZMW <?= number_format($semesterTotal, 2) ?></span>
                                </td>
                            </tr>
                            <?php foreach ($semesterFees as $f): 
                                $y = (int)$f->year_of_study;
                                $s = (int)$f->semester;
                                $bucket = $remainingPaid[$y][$s] ?? 0.0;
                                
                                $applied = min((float)$f->amount, (float)$bucket);
                                $remainingPaid[$y][$s] = max(0.0, $bucket - (float)$f->amount);
                            ?>
                            <tr class="row-sem-<?= balance_statement_h($s) ?>">
                                <td><?= balance_statement_h($f->fee_description) ?></td>
                                <td><span class="semester-tag semester-<?= balance_statement_h($s) ?>">Sem <?= balance_statement_h($s) ?></span></td>
                                <td style="text-align: right;" class="amount">ZMW <?= number_format($f->amount, 2) ?></td>
                                <td style="text-align: right;" class="amount">
                                    <?php if ($applied > 0): ?>
                                        <span class="amount-positive">ZMW <?= number_format($applied, 2) ?></span>
                                    <?php else: ?>
                                        <span class="amount-negative">ZMW 0.00</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2">Year <?= balance_statement_h($year) ?> Subtotal</td>
                                <td style="text-align: right;" class="amount">ZMW <?= number_format($yearTotal, 2) ?></td>
                                <td style="text-align: right;" class="amount amount-positive">ZMW <?= number_format($paidYearTotal, 2) ?></td>
                            </tr>
                        </tfoot>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <!-- Grand Total Summary -->
                <div class="table-container summary-table table-responsive">
                    <table class="table table-hover align-middle data-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Description</th>
                                <th style="text-align: right;">Amount</th>
                                <th style="text-align: right;">Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="background: #f8fafc;">
                                <td style="font-weight: 600;">Total Program Fees (Registered Periods)</td>
                                <td style="text-align: right;" class="amount">ZMW <?= number_format($total_fees, 2) ?></td>
                                <td style="text-align: right;" class="amount amount-positive">ZMW <?= number_format($total_paid, 2) ?></td>
                            </tr>
                            <tr style="background: <?= $balance > 0 ? '#fef2f2' : '#ecfdf5' ?>;">
                                <td style="font-weight: 700;" class="<?= $balance > 0 ? 'amount-negative' : 'amount-positive' ?>">
                                    <?= $balance > 0 ? 'Outstanding Balance' : 'Credit Balance' ?>
                                </td>
                                <td style="text-align: right; font-size: 1.1rem;" class="amount <?= $balance > 0 ? 'amount-negative' : 'amount-positive' ?>">
                                    ZMW <?= number_format(abs($balance), 2) ?>
                                </td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
