<?php
$page_title = "Financial and Accounting Overview";
//quire_once "includes/admin.php"; // Ensure this includes authentication

include "includes/admin.php";
require 'includes/header.php';

// Link admin dashboard stylesheets
error_reporting(0);
// Error handling configuration
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/php_errors/finance.log');

// Gate this finance/accounting overview on the real RBAC layer.
// (A local has_permission() stub here previously returned true unconditionally,
//  which bypassed all access control on this page. It has been removed in favour
//  of the canonical helpers so only systems admins or users with fees-module /
//  fees.view access can open the financial overview.)
require_once dirname(__DIR__) . '/includes/role_helpers.php';

$financeActor = (string) ($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? '');
$canViewFinance = (isset($isAdmin) && $isAdmin)
    || (function_exists('canAccessFinance') && canAccessFinance())
    || (function_exists('hasPermission') && $financeActor !== '' && hasPermission($financeActor, 'fees.view'));

if (!$canViewFinance) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-4" role="alert">'
        . '<i class="fas fa-ban me-2"></i>Access denied: you do not have permission to view the financial overview.'
        . '</div>';
    if (file_exists(__DIR__ . '/includes/footer.php')) {
        require_once __DIR__ . '/includes/footer.php';
    }
    exit;
}

// Currency configuration
define('CURRENCY', 'ZMK');
define('CURRENCY_SYMBOL', 'ZMW'); // Updated ISO code
define('DECIMAL_PLACES', 2);

// Get current academic period using prepared statement
$current_period = null;
$stmt = $db->prepare("SELECT id, academic_year, period_type, period_number AS semester_term
                     FROM academic_periods
                     WHERE is_current = TRUE
                     LIMIT 1");
if ($stmt->execute()) {
    $current_period = $stmt->get_result()->fetch_object();
}
$stmt->close();

// Get all academic periods with error handling
$academic_periods = [];
$stmt = $db->prepare("SELECT id, academic_year, period_type, period_number AS semester_term, is_current
                     FROM academic_periods
                     ORDER BY academic_year DESC, period_number DESC");
if ($stmt->execute()) {
    $academic_periods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Validate selected period
$selected_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : ($current_period ? $current_period->id : null);
if ($selected_period_id && !is_valid_period($selected_period_id)) {
    die("Invalid academic period selected");
}

// The normalized payments ledger stores academic_year directly.
// Resolve the chosen period to its academic year and filter ledger rows by that.
$selected_period_year = fo_period_year($db, $selected_period_id);

// Get financial summary using single query
$financial_summary = [
    'total_paid' => 0,
    'total_balance' => 0,
    'total_students' => 0,
    'avg_payment' => 0,
    'completion_rate' => 0,
    'total_revenue' => 0,
    'payment_count' => 0,
    'pending_payments' => 0
];

$query = "SELECT
    COALESCE(SUM(amount), 0) AS total_paid,
    COUNT(DISTINCT student_id) AS total_students,
    COALESCE(AVG(amount), 0) AS avg_payment,
    COUNT(*) AS payment_count,
    SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending_payments
FROM payments
WHERE academic_year = ?";

$stmt = $db->prepare($query);
$stmt->bind_param('s', $selected_period_year);
if ($stmt->execute()) {
    $result = $stmt->get_result()->fetch_assoc();
    $financial_summary = array_merge($financial_summary, $result);
}
$stmt->close();

$stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS total_due FROM invoices WHERE academic_year = ?");
$stmt->bind_param('s', $selected_period_year);
if ($stmt->execute()) {
    $dueRow = $stmt->get_result()->fetch_assoc();
    $totalDue = (float)($dueRow['total_due'] ?? 0);
    $financial_summary['total_balance'] = max(0, $totalDue - (float)$financial_summary['total_paid']);
    $financial_summary['total_revenue'] = $totalDue;
    $financial_summary['completion_rate'] = $totalDue > 0
        ? ((float)$financial_summary['total_paid'] / $totalDue) * 100
        : 0;
}
$stmt->close();

// Get payment methods distribution
$payment_methods = [];
$stmt = $db->prepare("SELECT
    method AS channel,
    COUNT(*) AS count,
    SUM(amount) AS total
FROM payments
WHERE academic_year = ?
GROUP BY method
ORDER BY total DESC");
$stmt->bind_param('s', $selected_period_year);
if ($stmt->execute()) {
    $payment_methods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Format currency values
function format_currency($value) {
    return number_format($value, DECIMAL_PLACES) . ' ' . CURRENCY_SYMBOL;
}

// Get period name
function get_period_name($period_id) {
    global $db;
    $stmt = $db->prepare("SELECT academic_year, period_type, period_number AS semester_term FROM academic_periods WHERE id = ?");
    $stmt->bind_param('i', $period_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_object();
    $stmt->close();

    if ($result) {
        return $result->academic_year . " - " . ucfirst($result->period_type) . " " . $result->semester_term;
    }
    return "Unknown Period";
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-chart-line me-2 text-primary"></i>Financial & Accounting Overview</h5>
                <p class="page-subtitle mb-0">Live monitoring of revenue, outstanding balances, and collection health</p>
            </div>
            <div class="header-actions d-flex gap-2">
                <a href="finance.php" class="btn btn-outline-info shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i>Finance Hub
                </a>
                <button class="btn btn-outline-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#filterModal">
                    <i class="fas fa-filter me-1"></i>Filter
                </button>
                <button class="btn btn-outline-secondary shadow-sm" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>

    <!-- Period Selector & Health -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="stat-card h-100 p-4 border-0 shadow-sm bg-white">
                <h6 class="text-muted text-uppercase small fw-bold mb-3"><i class="fas fa-calendar-alt me-2"></i>Academic Period</h6>
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-9">
                        <select class="form-select border-0 bg-light" id="period_id" name="period_id">
                            <?php foreach ($academic_periods as $period): ?>
                                <option value="<?= $period['id'] ?>" <?= $selected_period_id == $period['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($period['academic_year']) ?> - <?= htmlspecialchars(ucfirst($period['period_type'])) ?> <?= htmlspecialchars($period['semester_term']) ?>
                                    <?= $period['is_current'] ? ' (Current)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100 fw-bold">View</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="stat-card h-100 p-4 border-0 shadow-sm bg-white">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-0"><i class="fas fa-heartbeat me-2"></i>Financial Health Card</h6>
                    <span class="badge bg-success-subtle text-success"><?= round($financial_summary['completion_rate'], 1) ?>% Collected</span>
                </div>
                <div class="progress mb-3" style="height: 10px; border-radius: 5px;">
                    <div class="progress-bar bg-success"
                         role="progressbar"
                         style="width: <?= $financial_summary['completion_rate'] ?>%"
                         aria-valuenow="<?= $financial_summary['completion_rate'] ?>"
                         aria-valuemin="0"
                         aria-valuemax="100">
                    </div>
                </div>
                <div class="d-flex justify-content-between">
                    <small class="text-muted">Target: 100% Completion</small>
                    <small class="text-muted fw-bold"><?= $financial_summary['payment_count'] ?> Transactions</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Financial Stats Row -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 p-4 border-0 shadow-sm bg-white">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-coins"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= format_currency($financial_summary['total_paid']) ?></h4>
                        <p class="text-muted small mb-0">Total Collected</p>
                    </div>
                </div>
                <?php if ($financial_summary['avg_payment'] > 0): ?>
                    <div class="mt-3 pt-3 border-top">
                        <small class="text-success fw-bold"><i class="fas fa-chart-line me-1"></i> Avg. <?= format_currency($financial_summary['avg_payment']) ?></small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 p-4 border-0 shadow-sm bg-white">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger me-3 text-white"><i class="fas fa-hand-holding-usd"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= format_currency($financial_summary['total_balance']) ?></h4>
                        <p class="text-muted small mb-0">Outstanding Debt</p>
                    </div>
                </div>
                <?php if ($financial_summary['total_balance'] > 0 && $financial_summary['total_revenue'] > 0): ?>
                    <div class="mt-3 pt-3 border-top">
                        <small class="text-danger fw-bold"><i class="fas fa-clock me-1"></i> <?= round($financial_summary['total_balance'] / $financial_summary['total_revenue'] * 100, 1) ?>% of target</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 p-4 border-0 shadow-sm bg-white">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-user-graduate"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($financial_summary['total_students']) ?></h4>
                        <p class="text-muted small mb-0">Enrolled Students</p>
                    </div>
                </div>
                <?php if ($financial_summary['total_students'] > 0): ?>
                    <div class="mt-3 pt-3 border-top">
                        <small class="text-primary fw-bold"><i class="fas fa-info-circle me-1"></i> Per Period stats</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 p-4 border-0 shadow-sm bg-white">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning me-3 text-white"><i class="fas fa-hourglass-start"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($financial_summary['pending_payments']) ?></h4>
                        <p class="text-muted small mb-0">Pending Processing</p>
                    </div>
                </div>
                <?php if ($financial_summary['payment_count'] > 0): ?>
                    <div class="mt-3 pt-3 border-top">
                        <small class="text-warning fw-bold"><i class="fas fa-history me-1"></i> Needs verification</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="data-table-card h-100">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-line me-2"></i>Payment Trends
                        </h5>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="paymentTrendsChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="data-table-card h-100">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-money-bill-wave me-2"></i>Payment Methods
                        </h5>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="paymentMethodsChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Payments Table -->
    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Recent Payments
                </h5>
                <div class="header-actions">
                    <a href="search_payments.php?period_id=<?= $selected_period_id ?>" class="btn btn-sm btn-info">
                        View All
                    </a>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $recent_payments = [];
                        $stmt = $db->prepare("
                            SELECT
                                p.student_id AS Sid,
                                p.amount AS amount_paid,
                                p.method AS channel,
                                p.status AS payment_status,
                                p.payment_date,
                                s.Fname,
                                s.Lname
                            FROM payments p
                            LEFT JOIN students s ON s.SID = CONVERT(p.student_id USING utf8mb4) COLLATE utf8mb4_general_ci
                            WHERE p.academic_year = ?
                            ORDER BY p.payment_date DESC, p.id DESC
                            LIMIT 10
                        ");
                        $stmt->bind_param('s', $selected_period_year);
                        if ($stmt->execute()) {
                            $recent_payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        }
                        $stmt->close();

                        if (count($recent_payments) > 0):
                            foreach ($recent_payments as $payment):
                                $status_class = 'success';
                                if (strtolower((string)$payment['payment_status']) == 'pending') {
                                    $status_class = 'warning';
                                } elseif (strtolower((string)$payment['payment_status']) == 'failed') {
                                    $status_class = 'danger';
                                }
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($payment['Sid']) ?></td>
                                <td><?= htmlspecialchars(trim(($payment['Fname'] ?? '') . ' ' . ($payment['Lname'] ?? ''))) ?></td>
                                <td><?= format_currency($payment['amount_paid']) ?></td>
                                <td><?= date('M d, Y', strtotime($payment['payment_date'])) ?></td>
                                <td><?= htmlspecialchars($payment['channel']) ?></td>
                                <td><span class="badge bg-<?= $status_class ?>"><?= ucfirst($payment['payment_status']) ?></span></td>
                            </tr>
                        <?php
                            endforeach;
                        else:
                        ?>
                            <tr>
                                <td colspan="6" class="text-center">No payments found for this period</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Filter Modal -->
<div class="modal fade" id="filterModal" tabindex="-1" aria-labelledby="filterModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header finance-modal">
                <h5 class="modal-title" id="filterModalLabel">Filter Financial Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="filterForm">
                    <div class="mb-3">
                        <label for="filterStatus" class="form-label">Payment Status</label>
                        <select class="form-select" id="filterStatus">
                            <option value="">All Statuses</option>
                            <option value="completed">Completed</option>
                            <option value="pending">Pending</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="filterMethod" class="form-label">Payment Method</label>
                        <select class="form-select" id="filterMethod">
                            <option value="">All Methods</option>
                            <option value="bank">Bank Transfer</option>
                            <option value="mobile">Mobile Money</option>
                            <option value="cash">Cash</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="filterAmount" class="form-label">Amount Range</label>
                        <select class="form-select" id="filterAmount">
                            <option value="">All Amounts</option>
                            <option value="low">Low (0 - 1000)</option>
                            <option value="medium">Medium (1001 - 5000)</option>
                            <option value="high">High (5001+)</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="applyFilter">Apply Filter</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize charts
    if (typeof Chart === 'undefined') {
        console.error('Chart.js is not loaded');
        document.getElementById('paymentTrendsChart').innerHTML = 'Chart library not available';
        document.getElementById('paymentMethodsChart').innerHTML = 'Chart library not available';
        return;
    }

    // Payment Trends Chart
    try {
        const trendData = {
            labels: <?= json_encode(array_map(function($item) { return $item['day']; }, get_payment_trends($selected_period_id))) ?>,
            datasets: [{
                label: 'Payments Collected',
                data: <?= json_encode(array_map(function($item) { return $item['total']; }, get_payment_amounts($selected_period_id))) ?>,
                borderColor: '#4CAF50',
                backgroundColor: 'rgba(76, 175, 80, 0.1)',
                tension: 0.1,
                fill: true
            }]
        };

        if (!trendData.labels.length || !trendData.datasets[0].data.length) {
            document.getElementById('paymentTrendsChart').innerHTML = 'No payment data available';
        } else {
            new Chart(document.getElementById('paymentTrendsChart'), {
                type: 'line',
                data: trendData,
                options: {
                    responsive: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Daily Payment Collection Trends'
                        },
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '<?= CURRENCY_SYMBOL ?> ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }
    } catch (error) {
        console.error('Error initializing payment trends chart:', error);
        document.getElementById('paymentTrendsChart').innerHTML = 'Error loading chart';
    }

    // Payment Methods Chart
    try {
        const methodData = {
            labels: <?= json_encode(array_map(function($item) { return $item['channel']; }, $payment_methods)) ?>,
            datasets: [{
                data: <?= json_encode(array_map(function($item) { return $item['total']; }, $payment_methods)) ?>,
                backgroundColor: [
                    'rgba(54, 162, 235, 0.7)',
                    'rgba(255, 99, 132, 0.7)',
                    'rgba(255, 206, 86, 0.7)',
                    'rgba(75, 192, 192, 0.7)',
                    'rgba(153, 102, 255, 0.7)'
                ],
                borderColor: [
                    'rgba(54, 162, 235, 1)',
                    'rgba(255, 99, 132, 1)',
                    'rgba(255, 206, 86, 1)',
                    'rgba(75, 192, 192, 1)',
                    'rgba(153, 102, 255, 1)'
                ],
                borderWidth: 1
            }]
        };

        if (!methodData.labels.length || !methodData.datasets[0].data.length) {
            document.getElementById('paymentMethodsChart').innerHTML = 'No payment method data available';
        } else {
            new Chart(document.getElementById('paymentMethodsChart'), {
                type: 'doughnut',
                data: methodData,
                options: {
                    responsive: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Payment Methods Distribution'
                        },
                        legend: {
                            position: 'right',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${percentage}% (<?= CURRENCY_SYMBOL ?> ${value.toLocaleString()})`;
                                }
                            }
                        }
                    }
                }
            });
        }
    } catch (error) {
        console.error('Error initializing payment methods chart:', error);
        document.getElementById('paymentMethodsChart').innerHTML = 'Error loading chart';
    }

    // Filter functionality
    const applyFilterBtn = document.getElementById('applyFilter');
    const filterStatus = document.getElementById('filterStatus');
    const filterMethod = document.getElementById('filterMethod');
    const filterAmount = document.getElementById('filterAmount');

    applyFilterBtn.addEventListener('click', function() {
        const statusValue = filterStatus.value;
        const methodValue = filterMethod.value;
        const amountValue = filterAmount.value;

        // Add your filtering logic here
        // This is a placeholder for the actual filtering implementation

        // Close the modal
        const filterModal = bootstrap.Modal.getInstance(document.getElementById('filterModal'));
        filterModal.hide();
    });
});
</script>

<?php
// Close database connection
$db->close();

// Helper functions
function is_valid_period($period_id) {
    global $db;
    $stmt = $db->prepare("SELECT id FROM academic_periods WHERE id = ?");
    $stmt->bind_param('i', $period_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// Resolve an academic_periods.id to its academic_year string.
// payments rows are keyed by academic_year, not by period id.
function fo_period_year($db, $period_id) {
    if (!$period_id) { return null; }
    $stmt = $db->prepare("SELECT academic_year FROM academic_periods WHERE id = ?");
    $stmt->bind_param('i', $period_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_object();
    $stmt->close();
    return $row ? $row->academic_year : null;
}

function get_payment_trends($period_id) {
    global $db;
    $year = fo_period_year($db, $period_id);
    $stmt = $db->prepare("SELECT DATE(payment_date) AS day
                         FROM payments
                         WHERE academic_year = ?
                         GROUP BY day
                         ORDER BY day");
    $stmt->bind_param('s', $year);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_payment_amounts($period_id) {
    global $db;
    $year = fo_period_year($db, $period_id);
    $stmt = $db->prepare("SELECT SUM(amount) AS total
                         FROM payments
                         WHERE academic_year = ?
                         GROUP BY DATE(payment_date)
                         ORDER BY DATE(payment_date)");
    $stmt->bind_param('s', $year);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<?php require_once "includes/footer.php"; ?>

