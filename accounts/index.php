<?php
$page_title = 'Accounts Dashboard';
require "includes/nav.php";
error_reporting(0);

// Define root URL for links
$root_url = '/wucportal';

// Helper functions for flexible schema detection
$tableExists = function(mysqli $db, string $table): bool {
    if ($res = $db->query("SHOW TABLES LIKE '".$db->real_escape_string($table)."'")) {
        $exists = $res->num_rows > 0; $res->free(); return $exists;
    }
    return false;
};
$columnExists = function(mysqli $db, string $table, string $column) use ($tableExists): bool {
    if (!$tableExists($db, $table)) {
        return false;
    }
    if ($res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '".$db->real_escape_string($column)."'")) {
        $exists = $res->num_rows > 0; $res->free(); return $exists;
    }
    return false;
};
$countPayments = function(mysqli $db, string $table, ?string $status = null, bool $today = false) use ($columnExists): int {
    $statusCol = $columnExists($db, $table, 'payment_status') ? 'payment_status' : ($columnExists($db, $table, 'status') ? 'status' : null);
    $dateCol = $columnExists($db, $table, 'payment_date') ? 'payment_date' : ($columnExists($db, $table, 'created_at') ? 'created_at' : null);
    $sql = "SELECT COUNT(*) AS total FROM `{$table}` WHERE 1=1";
    if ($status !== null && $statusCol !== null) {
        $sql .= " AND `{$statusCol}` = '".$db->real_escape_string($status)."'";
    }
    if ($today && $dateCol !== null) {
        $sql .= " AND DATE(`{$dateCol}`) = CURDATE()";
    }
    if ($res = $db->query($sql)) {
        $total = $res->num_rows > 0 ? (int)$res->fetch_object()->total : 0;
        $res->free();
        return $total;
    }
    return 0;
};

// Initialize stats with dynamic data
$pendingPayments = 0;

// Fees overview metrics (merged in from the former Fees Management Dashboard so
// the accounts module has a single landing dashboard instead of two).
$totalExpected = 0.00;
$totalPaid     = 0.00;
$totalBalance  = 0.00;
$unpaidCount   = 0;
$partialCount  = 0;
$paidCount     = 0;
$overpaidCount = 0;

if (isset($_SESSION['staff_id'])) {
    $paymentTable = $tableExists($db, 'payments') ? 'payments' : ($tableExists($db, 'student_payments') ? 'student_payments' : null);
    if ($paymentTable !== null) {
        $pendingPayments = $countPayments($db, $paymentTable, 'pending');
    }

    // Aggregate live fee figures straight from the student fee ledger.
    if ($tableExists($db, 'student_fee_accounts')) {
        $feeSql = "SELECT
            SUM(total_payable) AS expected,
            SUM(amount_paid)   AS paid,
            SUM(balance)       AS balance,
            SUM(CASE WHEN payment_status = 'Unpaid'         THEN 1 ELSE 0 END) AS unpaid,
            SUM(CASE WHEN payment_status = 'Partially Paid' THEN 1 ELSE 0 END) AS partial,
            SUM(CASE WHEN payment_status = 'Paid'           THEN 1 ELSE 0 END) AS paid_cnt,
            SUM(CASE WHEN payment_status = 'Overpaid'       THEN 1 ELSE 0 END) AS overpaid
            FROM student_fee_accounts WHERE status = 'active'";
        if (($feeRes = $db->query($feeSql)) && ($row = $feeRes->fetch_assoc())) {
            $totalExpected = (float)$row['expected'];
            $totalPaid     = (float)$row['paid'];
            $totalBalance  = (float)$row['balance'];
            $unpaidCount   = (int)$row['unpaid'];
            $partialCount  = (int)$row['partial'];
            $paidCount     = (int)$row['paid_cnt'];
            $overpaidCount = (int)$row['overpaid'];
        }
    }
}

// Configure unified dashboard
$dashboard_title = 'Accounts Dashboard';
$dashboard_subtitle = 'Fees overview, student balances, payments, and reports — all in one place.';
$user_role = 'Accountant';
$user_role_class = 'bg-finance';
$header_section_class = 'finance-section';
$stat_icon_class = 'bg-finance';
$dashboard_container_class = 'accounts-page accounts-index-page';
$profile_link = 'view_staff.php?view=' . ($_SESSION['staff_id'] ?? '');
$edit_profile_link = 'editStaff.php?update=' . ($_SESSION['staff_id'] ?? '');

// Stats cards configuration — live financial KPIs from the fee ledger.
$stat_cards = [
    [
        'icon' => 'fas fa-file-invoice-dollar',
        'value' => 'ZMW ' . number_format($totalExpected, 2),
        'label' => 'Total Expected Fees',
        'bg_class' => 'bg-finance',
        'link' => 'fees_student_accounts.php',
        'link_text' => 'View Accounts'
    ],
    [
        'icon' => 'fas fa-hand-holding-usd',
        'value' => 'ZMW ' . number_format($totalPaid, 2),
        'label' => 'Total Collected Fees',
        'bg_class' => 'bg-success',
        'link' => 'fees_student_payments.php',
        'link_text' => 'Process Payments'
    ],
    [
        'icon' => 'fas fa-balance-scale',
        'value' => 'ZMW ' . number_format($totalBalance, 2),
        'label' => 'Outstanding Balance',
        'bg_class' => 'bg-danger',
        'link' => 'fees_reports.php',
        'link_text' => 'Open Reports'
    ],
    [
        'icon' => 'fas fa-clock',
        'value' => number_format($pendingPayments),
        'label' => 'Pending Payments',
        'bg_class' => 'bg-warning',
        'link' => 'pendingPayments.php',
        'link_text' => 'View'
    ]
];

// Announcements — live status summary of student fee accounts.
$show_announcements = true;
$announcements = [
    [
        'icon' => 'fas fa-info-circle',
        'color' => 'primary',
        'title' => 'Fee Payment Breakdown Status',
        'content' => "Status summary of student accounts: {$unpaidCount} unpaid, {$partialCount} partially paid, {$paidCount} fully paid, and {$overpaidCount} overpaid accounts.",
        'badge' => 'Summary',
        'badge_color' => 'primary',
        'date' => 'Live'
    ]
];

// Quick access modules — consolidated operations + full fees-management grid
// (folded in from fees_dashboard.php so there is one accounts dashboard).
$quick_modules = [
    [
        'icon' => 'fas fa-receipt',
        'title' => 'Record Payments',
        'description' => 'Post payments and issue receipt printouts.',
        'link' => 'fees_student_payments.php',
        'bg_class' => 'bg-success'
    ],
    [
        'icon' => 'fas fa-user-circle',
        'title' => 'Student Accounts',
        'description' => 'Review student account balances and statements.',
        'link' => 'fees_student_accounts.php',
        'bg_class' => 'bg-success'
    ],
    [
        'icon' => 'fas fa-clock',
        'title' => 'Pending Payments',
        'description' => 'Review payments awaiting posting.',
        'link' => 'pendingPayments.php',
        'bg_class' => 'bg-warning'
    ],
    [
        'icon' => 'fas fa-file-invoice-dollar',
        'title' => 'Student Invoices',
        'description' => 'Create and manage student invoices.',
        'link' => 'invoice_student.php',
        'bg_class' => 'bg-info'
    ],
    [
        'icon' => 'fas fa-hand-holding-usd',
        'title' => 'Receivables',
        'description' => 'Check balances and receivable reports.',
        'link' => 'receivables.php',
        'bg_class' => 'bg-finance'
    ],
    [
        'icon' => 'fas fa-building',
        'title' => 'Departments',
        'description' => 'Manage academic departments and statuses.',
        'link' => 'fees_departments.php',
        'bg_class' => 'bg-finance'
    ],
    [
        'icon' => 'fas fa-graduation-cap',
        'title' => 'Courses',
        'description' => 'Configure course parameters and durations.',
        'link' => 'fees_courses.php',
        'bg_class' => 'bg-finance'
    ],
    [
        'icon' => 'fas fa-book-open',
        'title' => 'Training Modes',
        'description' => 'Manage attendance options (Full Time, Evening, etc.).',
        'link' => 'fees_training_modes.php',
        'bg_class' => 'bg-finance'
    ],
    [
        'icon' => 'fas fa-money-check-alt',
        'title' => 'Base Course Fees',
        'description' => 'Set base tuition rates per course and mode.',
        'link' => 'fees_course_fees.php',
        'bg_class' => 'bg-finance'
    ],
    [
        'icon' => 'fas fa-tags',
        'title' => 'Additional Fee Items',
        'description' => 'Define RTSA licenses, registrations, and exam fees.',
        'link' => 'fees_items.php',
        'bg_class' => 'bg-finance'
    ],
    [
        'icon' => 'fas fa-chart-pie',
        'title' => 'Fee Breakdowns',
        'description' => 'Link fee catalogue items to specific courses.',
        'link' => 'fees_course_fee_breakdown.php',
        'bg_class' => 'bg-finance'
    ],
    [
        'icon' => 'fas fa-chart-line',
        'title' => 'Financial Reports',
        'description' => 'Generate collections reports and audit summaries.',
        'link' => 'fees_reports.php',
        'bg_class' => 'bg-success'
    ],
];

// Include the unified dashboard template
require_once dirname(__DIR__) . '/includes/dashboard_template.php';
require_once __DIR__ . '/includes/footer.php';
?>
