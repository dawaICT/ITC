<?php
require "includes/admin.php";
require_once __DIR__ . '/../includes/sponsorship_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = 'Sponsorship Dashboard';
// $db is established by includes/admin.php -> db/connect.php. (There is no $conn
// in this codebase; aliasing from it raised "Undefined variable $conn" and the
// strict error handler turned that into a fatal "could not load this page".)
if (!isset($db) || !$db instanceof mysqli) {
    throw new RuntimeException('Database connection not available for sponsorship dashboard.');
}

// Load general counts
$stats = [
    'total_sponsored' => 0,
    'pending_approvals' => 0,
    'approved_sponsors' => 0,
    'total_approved_amount' => 0.0,
    'total_released_amount' => 0.0,
    'outstanding_amount' => 0.0,
];

// Query counters
if ($res = $db->query("SELECT COUNT(*) as count FROM finance_student_sponsors")) {
    $stats['total_sponsored'] = (int)$res->fetch_assoc()['count'];
}
if ($res = $db->query("SELECT COUNT(*) as count FROM finance_student_sponsors WHERE approval_status = 'pending'")) {
    $stats['pending_approvals'] = (int)$res->fetch_assoc()['count'];
}
if ($res = $db->query("SELECT COUNT(*) as count FROM finance_student_sponsors WHERE approval_status = 'approved'")) {
    $stats['approved_sponsors'] = (int)$res->fetch_assoc()['count'];
}
if ($res = $db->query("SELECT SUM(amount_approved) as total FROM finance_student_sponsors WHERE approval_status = 'approved'")) {
    $stats['total_approved_amount'] = (float)$res->fetch_assoc()['total'];
}
if ($res = $db->query("SELECT SUM(amount_released) as total FROM finance_student_sponsors WHERE approval_status = 'approved'")) {
    $stats['total_released_amount'] = (float)$res->fetch_assoc()['total'];
}
$stats['outstanding_amount'] = max(0.0, $stats['total_approved_amount'] - $stats['total_released_amount']);

// Get sponsor types summary
$sponsor_types_summary = [];
$q = "SELECT st.name, st.code, COUNT(fss.id) as student_count, SUM(COALESCE(fss.amount_approved, 0)) as total_funding
      FROM sponsor_types st
      LEFT JOIN finance_student_sponsors fss ON fss.sponsor_type_id = st.id AND fss.approval_status = 'approved'
      GROUP BY st.id
      ORDER BY student_count DESC";
if ($res = $db->query($q)) {
    while ($row = $res->fetch_assoc()) {
        $sponsor_types_summary[] = $row;
    }
}

// Get recent pending approval list
$pending_list = [];
$pq = "SELECT fss.*, s.Fname, s.Lname, st.name as sponsor_type_name
       FROM finance_student_sponsors fss
       JOIN students s ON s.SID = fss.student_id
       JOIN sponsor_types st ON st.id = fss.sponsor_type_id
       WHERE fss.approval_status = 'pending'
       ORDER BY fss.created_at DESC
       LIMIT 10";
if ($res = $db->query($pq)) {
    while ($row = $res->fetch_assoc()) {
        $pending_list[] = $row;
    }
}

// Get recent transactions/activities
$recent_activities = [];
// audit_log real columns are: action, details, created_at, user_id (a free-text
// actor label, e.g. "admissions"). Alias them to the names the template below
// uses (action_type / action_payload / timestamp). The actor join is best-effort
// against users.staff_id|username; when it misses we show the raw actor label.
$aq = "SELECT log.action AS action_type,
              log.details AS action_payload,
              log.created_at AS `timestamp`,
              COALESCE(u.username, log.user_id) AS actor_name
       FROM audit_log log
       LEFT JOIN users u ON u.staff_id = log.user_id OR u.username = log.user_id
       WHERE log.action LIKE '%sponsor%' OR log.action LIKE '%bursary%'
       ORDER BY log.created_at DESC
       LIMIT 8";
if ($res = $db->query($aq)) {
    while ($row = $res->fetch_assoc()) {
        $recent_activities[] = $row;
    }
}

require 'includes/header.php';
?>

<div class="container-fluid px-4 py-4 portal-dashboard">
    <!-- Page Title Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-chart-pie me-2 text-primary"></i>Sponsorship Dashboard</h1>
                <p class="text-muted mb-0">Overview of student sponsorships, approvals, funding allocations, and BI insights.</p>
            </div>
            <div class="col-auto">
                <div class="d-flex gap-2">
                    <a href="sponsorship_management.php" class="btn btn-outline-primary d-flex align-items-center gap-2 rounded-pill px-3">
                        <i class="fas fa-hand-holding-dollar"></i> Manage Sponsors
                    </a>
                    <a href="report_year_intake.php" class="btn btn-primary d-flex align-items-center gap-2 rounded-pill px-3">
                        <i class="fas fa-file-invoice-dollar"></i> Sponsorship Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="row g-4 mb-4">
        <!-- Total Sponsored -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3" style="background: linear-gradient(135deg, #6f42c1, #8553d1); color: #fff;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-white-50 small fw-semibold text-uppercase">Total Applications</span>
                        <h2 class="fw-bold mt-1 mb-0"><?php echo number_format($stats['total_sponsored']); ?></h2>
                    </div>
                    <div class="p-2 rounded-3 bg-white-10 text-white">
                        <i class="fas fa-users fa-lg"></i>
                    </div>
                </div>
                <div class="mt-3 text-white-50 small">
                    <i class="fas fa-circle-check me-1"></i> <?php echo number_format($stats['approved_sponsors']); ?> approved
                </div>
            </div>
        </div>

        <!-- Pending Approvals -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3 bg-white">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase">Pending Approvals</span>
                        <h2 class="fw-bold mt-1 mb-0 text-warning"><?php echo number_format($stats['pending_approvals']); ?></h2>
                    </div>
                    <div class="p-2 rounded-3 bg-warning-subtle text-warning">
                        <i class="fas fa-hourglass-half fa-lg"></i>
                    </div>
                </div>
                <div class="mt-3 text-muted small">
                    <i class="fas fa-arrow-right me-1"></i> Awaiting verification
                </div>
            </div>
        </div>

        <!-- Total Approved Funding -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3 bg-white">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase">Approved Funding</span>
                        <h2 class="fw-bold mt-1 mb-0 text-success">K<?php echo number_format($stats['total_approved_amount'], 2); ?></h2>
                    </div>
                    <div class="p-2 rounded-3 bg-success-subtle text-success">
                        <i class="fas fa-coins fa-lg"></i>
                    </div>
                </div>
                <div class="mt-3 text-muted small">
                    <i class="fas fa-circle-down me-1"></i> Active allocations
                </div>
            </div>
        </div>

        <!-- Outstanding Balances -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3 bg-white">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase">Outstanding Release</span>
                        <h2 class="fw-bold mt-1 mb-0 text-danger">K<?php echo number_format($stats['outstanding_amount'], 2); ?></h2>
                    </div>
                    <div class="p-2 rounded-3 bg-danger-subtle text-danger">
                        <i class="fas fa-file-invoice-dollar fa-lg"></i>
                    </div>
                </div>
                <div class="mt-3 text-muted small">
                    <i class="fas fa-circle-exclamation me-1"></i> Awaiting sponsor payout
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Pending Approvals & Applications list -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-clock me-2"></i>Awaiting Sponsorship Approval</h5>
                </div>
                <div class="card-body px-4 pb-4">
                    <?php if (empty($pending_list)): ?>
                        <div class="text-center py-5">
                            <img src="../assets/img/empty-sps.svg" alt="No Pending approvals" class="mb-3" style="max-height: 100px; display: none;">
                            <i class="fas fa-circle-check text-success fa-3x mb-3"></i>
                            <h6 class="text-muted mb-0">All clear! No pending sponsorship applications.</h6>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Student Name</th>
                                        <th>Sponsor Type</th>
                                        <th>Academic Year</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_list as $row): ?>
                                        <tr>
                                            <td class="fw-semibold text-dark"><?php echo htmlspecialchars($row['student_id']); ?></td>
                                            <td><?php echo htmlspecialchars($row['Fname'] . ' ' . $row['Lname']); ?></td>
                                            <td>
                                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['sponsor_type_name']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['academic_year']); ?></td>
                                            <td class="text-end">
                                                <a href="student_sponsorship.php?sid=<?php echo urlencode($row['student_id']); ?>" class="btn btn-sm btn-primary rounded-pill px-3">
                                                    <i class="fas fa-eye me-1"></i> Review
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sponsor Type Distribution -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-chart-bar me-2"></i>Sponsor Allocation Breakdown</h5>
                </div>
                <div class="card-body px-4 pb-4">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Sponsor Name</th>
                                    <th>Code</th>
                                    <th class="text-center">Sponsored Students</th>
                                    <th class="text-end">Allocated Funding</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sponsor_types_summary as $t): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="p-2 rounded bg-light text-primary me-3">
                                                    <i class="fas fa-landmark"></i>
                                                </div>
                                                <span class="fw-semibold"><?php echo htmlspecialchars($t['name']); ?></span>
                                            </div>
                                        </td>
                                        <td><span class="text-monospace text-uppercase text-muted"><?php echo htmlspecialchars($t['code']); ?></span></td>
                                        <td class="text-center fw-bold"><?php echo number_format($t['student_count']); ?></td>
                                        <td class="text-end text-success fw-semibold">K<?php echo number_format($t['total_funding'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar Activities and logs -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-history me-2"></i>Sponsorship Audit Log</h5>
                </div>
                <div class="card-body px-4">
                    <?php if (empty($recent_activities)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-folder-open fa-2x mb-2"></i>
                            <p class="small mb-0">No recent sponsorship activities logged.</p>
                        </div>
                    <?php else: ?>
                        <div class="timeline mt-3">
                            <?php foreach ($recent_activities as $act): ?>
                                <div class="d-flex mb-3 pb-3 border-bottom">
                                    <div class="me-3">
                                        <div class="p-2 rounded bg-light text-primary">
                                            <i class="fas fa-user-gear"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="fw-bold text-dark small"><?php echo htmlspecialchars($act['actor_name'] ?: 'System'); ?></span>
                                            <span class="text-muted small text-monospace" style="font-size: 0.75rem;"><?php echo date('M d, H:i', strtotime($act['timestamp'])); ?></span>
                                        </div>
                                        <div class="text-muted small mt-1">
                                            <span class="badge bg-purple-subtle text-purple border-0 text-monospace me-1"><?php echo htmlspecialchars($act['action_type']); ?></span>
                                            <?php
                                            $payload = json_decode($act['action_payload'], true);
                                            if (is_array($payload)) {
                                                echo htmlspecialchars($payload['student_id'] ?? $payload['code'] ?? '');
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require 'includes/footer.php';
?>
