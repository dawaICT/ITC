<?php
$page_title = 'Financial Fee Reports';
require "includes/nav.php";
require_once __DIR__ . '/../includes/fees_helpers.php';
require_once __DIR__ . '/../includes/report_print.php';
require_once __DIR__ . '/../includes/ai_portal.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Grouping logic for tabs or sections
$tab = $_GET['tab'] ?? 'summary';

// ── 1. General Summary Metrics ──
$summary = [
    'expected' => 0.00,
    'paid' => 0.00,
    'balance' => 0.00,
    'unpaid' => 0,
    'partial' => 0,
    'paid_cnt' => 0,
    'overpaid' => 0
];
$res = $db->query("SELECT 
    SUM(total_payable) AS expected, 
    SUM(amount_paid) AS paid, 
    SUM(balance) AS balance,
    SUM(CASE WHEN payment_status = 'Unpaid' THEN 1 ELSE 0 END) AS unpaid,
    SUM(CASE WHEN payment_status = 'Partially Paid' THEN 1 ELSE 0 END) AS partial,
    SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END) AS paid_cnt,
    SUM(CASE WHEN payment_status = 'Overpaid' THEN 1 ELSE 0 END) AS overpaid
    FROM student_fee_accounts WHERE status = 'active'");
if ($res && $row = $res->fetch_assoc()) {
    $summary = [
        'expected' => (float)$row['expected'],
        'paid' => (float)$row['paid'],
        'balance' => (float)$row['balance'],
        'unpaid' => (int)$row['unpaid'],
        'partial' => (int)$row['partial'],
        'paid_cnt' => (int)$row['paid_cnt'],
        'overpaid' => (int)$row['overpaid']
    ];
}

// ── 2. Collections by Course ──
$byCourse = [];
$res = $db->query("SELECT c.course_name, c.course_code, 
                  SUM(sfa.total_payable) AS expected, 
                  SUM(sfa.amount_paid) AS paid, 
                  SUM(sfa.balance) AS balance,
                  COUNT(sfa.id) AS student_count
                  FROM student_fee_accounts sfa
                  INNER JOIN courses c ON sfa.course_id = c.id
                  WHERE sfa.status = 'active'
                  GROUP BY sfa.course_id
                  ORDER BY expected DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) { $byCourse[] = $row; }
    $res->free();
}

// ── 3. Collections by Department ──
$byDept = [];
$res = $db->query("SELECT COALESCE(d.department_name, 'Unassigned') AS dept_name, 
                  SUM(sfa.total_payable) AS expected, 
                  SUM(sfa.amount_paid) AS paid, 
                  SUM(sfa.balance) AS balance,
                  COUNT(sfa.id) AS student_count
                  FROM student_fee_accounts sfa
                  INNER JOIN courses c ON sfa.course_id = c.id
                  LEFT JOIN departments d ON c.department_id = d.id
                  WHERE sfa.status = 'active'
                  GROUP BY c.department_id
                  ORDER BY expected DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) { $byDept[] = $row; }
    $res->free();
}

// ── 4. Collections by Training Mode ──
$byMode = [];
$res = $db->query("SELECT tm.mode_name, 
                  SUM(sfa.total_payable) AS expected, 
                  SUM(sfa.amount_paid) AS paid, 
                  SUM(sfa.balance) AS balance,
                  COUNT(sfa.id) AS student_count
                  FROM student_fee_accounts sfa
                  INNER JOIN training_modes tm ON sfa.training_mode_id = tm.id
                  WHERE sfa.status = 'active'
                  GROUP BY sfa.training_mode_id
                  ORDER BY expected DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) { $byMode[] = $row; }
    $res->free();
}

// ── 5. Payments by Method ──
$byMethod = [];
$res = $db->query("SELECT channel AS method, SUM(amount_paid) AS total_collected, COUNT(payment_id) AS tx_count 
                  FROM student_payments 
                  WHERE status = 'approved' AND payment_status = 'completed'
                  GROUP BY channel
                  ORDER BY total_collected DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) { $byMethod[] = $row; }
    $res->free();
}

// ── 6. Daily Payments (Last 30 Days) ──
$dailyPayments = [];
$res = $db->query("SELECT DATE(payment_date) AS pay_date, SUM(amount_paid) AS amount, COUNT(payment_id) AS tx_count 
                  FROM student_payments 
                  WHERE status = 'approved' AND payment_status = 'completed'
                  GROUP BY pay_date
                  ORDER BY pay_date DESC LIMIT 30");
if ($res) {
    while ($row = $res->fetch_assoc()) { $dailyPayments[] = $row; }
    $res->free();
}

// ── 7. Monthly Payments ──
$monthlyPayments = [];
$res = $db->query("SELECT DATE_FORMAT(payment_date, '%Y-%m') AS pay_month, SUM(amount_paid) AS amount, COUNT(payment_id) AS tx_count 
                  FROM student_payments 
                  WHERE status = 'approved' AND payment_status = 'completed'
                  GROUP BY pay_month
                  ORDER BY pay_month DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) { $monthlyPayments[] = $row; }
    $res->free();
}

// ── 8. Balances by Intake ──
$byIntake = [];
$res = $db->query("SELECT intake, SUM(total_payable) AS expected, SUM(amount_paid) AS paid, SUM(balance) AS balance
                  FROM student_fee_accounts 
                  WHERE status = 'active'
                  GROUP BY intake
                  ORDER BY intake ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) { $byIntake[] = $row; }
    $res->free();
}

// ── 9. Balances by Academic Year ──
$byYear = [];
$res = $db->query("SELECT academic_year, SUM(total_payable) AS expected, SUM(amount_paid) AS paid, SUM(balance) AS balance
                  FROM student_fee_accounts 
                  WHERE status = 'active'
                  GROUP BY academic_year
                  ORDER BY academic_year DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) { $byYear[] = $row; }
    $res->free();
}

// AI summary calculation
$aiResult = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ai_summary') {
    if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $context = [
            'report' => 'Financial Fee Reports Summary',
            'role' => 'Accountant / Finance Officer',
            'tab' => $tab,
            'summary' => $summary,
            'by_course_excerpt' => array_slice($byCourse, 0, 10),
            'by_dept' => $byDept,
            'by_mode' => $byMode,
            'by_method' => $byMethod,
            'by_intake' => $byIntake,
            'by_year' => $byYear,
            'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
        ];
        $ctxJson = wuc_ai_context_json($context, 14000);
        $aiResult = wuc_ai_generate($db, [
            'feature' => 'finance_report_summary',
            'user_role' => 'accountant',
            'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'finance'),
            'input_summary' => 'Financial Fee Report Summary',
            'context_hash' => hash('sha256', $ctxJson),
            'messages' => [
                ['role' => 'system', 'content' => 'You summarise a Financial Fee Report of academic and short-course trainees. Use ONLY the supplied data. Give a concise narrative of expected vs collected fees, outstanding balance, account status breakdown, and recommendations.'],
                ['role' => 'user', 'content' => "Financial data (JSON):\n{$ctxJson}\n\nWrite the summary."],
            ],
            'fallback' => static function () use ($summary): string {
                return "Financial Fee Report Summary — Expected ZMW " . number_format($summary['expected'], 2) . ", Collected ZMW " . number_format($summary['paid'], 2) . ", Outstanding Balance ZMW " . number_format($summary['balance'], 2) . ". AI summary unavailable.";
            },
        ]);
    }
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <?php
    render_report_print_styles();
    render_report_print_script();
    render_report_print_header(
        'Financial Fee Reports',
        ucfirst($tab) . ' Overview',
        [
            'Report'   => 'Financial Fee Audit',
            'View'     => ucfirst($tab),
            'Expected' => 'ZMW ' . number_format($summary['expected'], 2),
            'Collected' => 'ZMW ' . number_format($summary['paid'], 2),
            'Outstanding' => 'ZMW ' . number_format($summary['balance'], 2),
        ]
    );
    ?>

    <!-- Header -->
    <div class="dashboard-header finance-section mb-4 mt-2 d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-chart-line me-2 text-primary"></i>Financial Fee Reports</h1>
                <p class="text-muted mb-0">Audit collections, analyze receivables, and monitor balance outstanding by courses, departments, daily or monthly schedules.</p>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills mb-4 bg-white p-2 rounded-4 shadow-sm border d-print-none" id="reportTabs">
        <li class="nav-item">
            <a class="nav-link rounded-pill <?= $tab === 'summary' ? 'active' : '' ?>" href="?tab=summary"><i class="fas fa-wallet me-2"></i>Summary Overview</a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill <?= $tab === 'demographics' ? 'active' : '' ?>" href="?tab=demographics"><i class="fas fa-graduation-cap me-2"></i>Academic Segments</a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill <?= $tab === 'payments' ? 'active' : '' ?>" href="?tab=payments"><i class="fas fa-cash-register me-2"></i>Collections Ledger</a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill <?= $tab === 'intake_year' ? 'active' : '' ?>" href="?tab=intake_year"><i class="fas fa-calendar-alt me-2"></i>Period Balances</a>
        </li>
    </ul>

    <!-- AI Summary Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>BI Summary Insights</h5>
            <form method="post" action="?tab=<?= htmlspecialchars($tab) ?>" class="m-0">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="ai_summary">
                <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                    <i class="fas fa-arrows-rotate me-1"></i>Generate AI Insights
                </button>
            </form>
        </div>
        <div class="card-body p-4">
            <?php if ($aiResult !== null): ?>
                <?php if (empty($aiResult['used_ai'])): ?>
                    <div class="alert alert-warning small py-2 mb-3"><?php echo htmlspecialchars(wuc_ai_fallback_notice($aiResult)); ?></div>
                <?php endif; ?>
                <div class="bg-light border rounded-3 p-3">
                    <?php echo wuc_ai_output_block((string)$aiResult['text']); ?>
                </div>
            <?php else: ?>
                <span class="text-muted small">Click <strong>Generate AI Insights</strong> to analyze the financial datasets and output a plain-English report overview.</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tab Contents -->
    <div class="tab-content" id="print">
        <!-- ── TAB: SUMMARY OVERVIEW ── -->
        <?php if ($tab === 'summary'): ?>
            <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-money-check-dollar me-2"></i>Financial Position</h5>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" onclick="printReport('Financial Summary')">
                    <i class="fas fa-print me-1"></i>Print Summary
                </button>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                            <h5 class="fw-bold mb-0 text-primary">General Collections Status</h5>
                        </div>
                        <div class="card-body p-4">
                            <table class="table align-middle">
                                <tr>
                                    <th>Total Expected Fees:</th>
                                    <td class="text-end font-monospace fw-bold">ZMW <?= number_format($summary['expected'], 2) ?></td>
                                </tr>
                                <tr>
                                    <th>Total Collected:</th>
                                    <td class="text-end font-monospace fw-bold text-success">ZMW <?= number_format($summary['paid'], 2) ?></td>
                                </tr>
                                <tr>
                                    <th>Outstanding Balance:</th>
                                    <td class="text-end font-monospace fw-bold text-danger">ZMW <?= number_format($summary['balance'], 2) ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                            <h5 class="fw-bold mb-0 text-primary">Student Account Summary</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-3">
                                <div class="col-6">
                                    <div class="bg-light p-3 rounded-3 text-center">
                                        <div class="text-muted small fw-semibold">Unpaid Students</div>
                                        <h3 class="fw-bold mb-0"><?= $summary['unpaid'] ?></h3>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-light p-3 rounded-3 text-center">
                                        <div class="text-muted small fw-semibold">Partially Paid</div>
                                        <h3 class="fw-bold mb-0 text-warning"><?= $summary['partial'] ?></h3>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-light p-3 rounded-3 text-center">
                                        <div class="text-muted small fw-semibold">Fully Paid</div>
                                        <h3 class="fw-bold mb-0 text-success"><?= $summary['paid_cnt'] ?></h3>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-light p-3 rounded-3 text-center">
                                        <div class="text-muted small fw-semibold">Overpaid Accounts</div>
                                        <h3 class="fw-bold mb-0 text-info"><?= $summary['overpaid'] ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <!-- ── TAB: ACADEMIC SEGMENTS ── -->
        <?php elseif ($tab === 'demographics'): ?>
            <!-- By Course -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-graduation-cap me-2"></i>Fees by Course</h5>
                    <div class="d-print-none d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel('courseTable', 'fees_by_course.xls')">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" onclick="printReport('Fees by Course')">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="courseTable">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Students</th>
                                    <th>Expected</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byCourse as $row): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['course_name']) ?></strong> (<?= htmlspecialchars($row['course_code']) ?>)</td>
                                        <td><?= $row['student_count'] ?></td>
                                        <td class="font-monospace fw-semibold">ZMW <?= number_format($row['expected'], 2) ?></td>
                                        <td class="font-monospace text-success fw-semibold">ZMW <?= number_format($row['paid'], 2) ?></td>
                                        <td class="font-monospace text-danger fw-semibold">ZMW <?= number_format($row['balance'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- By Department -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-building me-2"></i>Fees by Department</h5>
                    <div class="d-print-none d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel('deptTable', 'fees_by_department.xls')">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="deptTable">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Students</th>
                                    <th>Expected</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byDept as $row): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['dept_name']) ?></strong></td>
                                        <td><?= $row['student_count'] ?></td>
                                        <td class="font-monospace fw-semibold">ZMW <?= number_format($row['expected'], 2) ?></td>
                                        <td class="font-monospace text-success fw-semibold">ZMW <?= number_format($row['paid'], 2) ?></td>
                                        <td class="font-monospace text-danger fw-semibold">ZMW <?= number_format($row['balance'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- By Training Mode -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-book-reader me-2"></i>Fees by Training Mode</h5>
                    <div class="d-print-none d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel('modeTable', 'fees_by_training_mode.xls')">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="modeTable">
                            <thead>
                                <tr>
                                    <th>Training Mode</th>
                                    <th>Students</th>
                                    <th>Expected</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byMode as $row): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['mode_name']) ?></strong></td>
                                        <td><?= $row['student_count'] ?></td>
                                        <td class="font-monospace fw-semibold">ZMW <?= number_format($row['expected'], 2) ?></td>
                                        <td class="font-monospace text-success fw-semibold">ZMW <?= number_format($row['paid'], 2) ?></td>
                                        <td class="font-monospace text-danger fw-semibold">ZMW <?= number_format($row['balance'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <!-- ── TAB: COLLECTIONS LEDGER ── -->
        <?php elseif ($tab === 'payments'): ?>
            <!-- By Method -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-credit-card me-2"></i>Collections by Payment Method</h5>
                    <div class="d-print-none d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel('methodTable', 'collections_by_method.xls')">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" onclick="printReport('Collections Ledger')">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0" id="methodTable">
                            <thead>
                                <tr>
                                    <th>Payment Method</th>
                                    <th>Transactions</th>
                                    <th class="text-end">Total Collected</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byMethod as $row): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['method'] ?: 'Other') ?></strong></td>
                                        <td><?= $row['tx_count'] ?></td>
                                        <td class="text-end font-monospace fw-bold text-success">ZMW <?= number_format($row['total_collected'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Daily Payments -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-calendar-day me-2"></i>Daily Collections (Last 30 Days)</h5>
                    <div class="d-print-none d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel('dailyTable', 'daily_collections.xls')">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover mb-0" id="dailyTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Transactions</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dailyPayments as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['pay_date']) ?></td>
                                        <td><?= $row['tx_count'] ?></td>
                                        <td class="text-end font-monospace fw-bold text-success">ZMW <?= number_format($row['amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Monthly Payments -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-calendar-alt me-2"></i>Monthly Collections</h5>
                    <div class="d-print-none d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel('monthlyTable', 'monthly_collections.xls')">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="monthlyTable">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Transactions</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthlyPayments as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['pay_month']) ?></td>
                                        <td><?= $row['tx_count'] ?></td>
                                        <td class="text-end font-monospace fw-bold text-success">ZMW <?= number_format($row['amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <!-- ── TAB: PERIOD BALANCES ── -->
        <?php elseif ($tab === 'intake_year'): ?>
            <!-- By Intake -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-clock me-2"></i>Balances by Intake Period</h5>
                    <div class="d-print-none d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel('intakeTable', 'balances_by_intake.xls')">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" onclick="printReport('Balances by Period')">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="intakeTable">
                            <thead>
                                <tr>
                                    <th>Intake</th>
                                    <th>Expected</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byIntake as $row): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['intake']) ?></strong></td>
                                        <td class="font-monospace">ZMW <?= number_format($row['expected'], 2) ?></td>
                                        <td class="font-monospace text-success">ZMW <?= number_format($row['paid'], 2) ?></td>
                                        <td class="font-monospace text-danger fw-bold">ZMW <?= number_format($row['balance'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- By Academic Year -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-calendar me-2"></i>Balances by Academic Year</h5>
                    <div class="d-print-none d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel('yearTable', 'balances_by_year.xls')">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="yearTable">
                            <thead>
                                <tr>
                                    <th>Academic Year</th>
                                    <th>Expected</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byYear as $row): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['academic_year']) ?></strong></td>
                                        <td class="font-monospace">ZMW <?= number_format($row['expected'], 2) ?></td>
                                        <td class="font-monospace text-success">ZMW <?= number_format($row['paid'], 2) ?></td>
                                        <td class="font-monospace text-danger fw-bold">ZMW <?= number_format($row['balance'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function exportToExcel(tableId, filename) {
    var table = document.getElementById(tableId);
    if (!table) { alert('No data to export.'); return; }
    var html = table.outerHTML;
    var url  = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    var link = document.createElement('a');
    document.body.appendChild(link);
    link.href     = url;
    link.download = filename || 'report.xls';
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
