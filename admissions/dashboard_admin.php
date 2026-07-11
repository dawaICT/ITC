<?php
$page_title = 'Admissions Dashboard';
require "includes/nav.php";

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development' || isset($_GET['debug'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// --- Stats ---
$totalApplicants  = 0;
$pendingApps      = 0;
$admittedApps     = 0;
$rejectedApps     = 0;
$totalStudents    = 0;
$recentAdmissions = [];

$tableExists = function(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    if (!$res) {
        return false;
    }
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
};

$columnExists = function(mysqli $db, string $table, string $column): bool {
    $safeTable = str_replace('`', '``', $table);
    $safeColumn = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    if (!$res) {
        return false;
    }
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
};

$hasApplicants = $tableExists($db, 'online_applicants');

if ($hasApplicants) {
    $hasPrograms = $tableExists($db, 'programs');

    $q = $db->query("SELECT COUNT(*) AS c FROM online_applicants");
    if ($q && $r = $q->fetch_object()) $totalApplicants = (int)$r->c;

    $q = $db->query("SELECT COUNT(*) AS c FROM online_applicants WHERE status = 'pending' OR status IS NULL");
    if ($q && $r = $q->fetch_object()) $pendingApps = (int)$r->c;

    if ($tableExists($db, 'processed_applicants')) {
        $q = $db->query("SELECT COUNT(*) AS c FROM processed_applicants WHERE status = 'accepted' OR status = 'admitted'");
        if ($q && $r = $q->fetch_object()) $admittedApps = (int)$r->c;

        $q = $db->query("SELECT COUNT(*) AS c FROM processed_applicants WHERE status = 'rejected'");
        if ($q && $r = $q->fetch_object()) $rejectedApps = (int)$r->c;
    }

    // Recent applications
    $programJoin = $hasPrograms ? 'LEFT JOIN programs p ON a.program = p.program_code' : '';
    $programNameExpr = $hasPrograms ? 'p.program_name' : 'NULL AS program_name';
    $resRecent = $db->query("
        SELECT a.id, a.Fname, a.Lname, a.email, a.program AS program_code, a.status, a.dte_adm AS created_at,
               {$programNameExpr}
        FROM online_applicants a
        {$programJoin}
        ORDER BY a.dte_adm DESC, a.id DESC
        LIMIT 8
    ");
    while ($resRecent && $row = $resRecent->fetch_object()) {
        $recentAdmissions[] = $row;
    }
}

if ($tableExists($db, 'students')) {
    $q = $db->query("SELECT COUNT(*) AS c FROM students");
    if ($q && $r = $q->fetch_object()) $totalStudents = (int)$r->c;
}
?>

<div class="container-fluid px-4 py-4 portal-dashboard admissions-dashboard-page">

    <!-- Page Header -->
    <div class="dashboard-header admin-section admissions-section mb-4 mt-3">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title mb-0">
                    <i class="fas fa-user-plus me-2"></i>Admissions Dashboard
                </h1>
                <p class="text-muted mb-0 mt-1 small">
                    <?php $hour = (int)date('G'); echo $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'); ?>,
                    <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_id'] ?? 'Admissions Officer'); ?></strong>
                    &mdash; <?php echo date('l, F j, Y'); ?>
                </p>
            </div>
            <div class="col-auto d-flex gap-2">
                <a href="regNewStud.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-user-graduate me-1"></i>New Student
                </a>
                <a href="applicants.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-file-alt me-1"></i>Applications
                </a>
            </div>
        </div>
    </div>

    <!-- User Profile Summary -->
    <div class="adm-profile-strip mb-4">
        <div class="adm-profile-avatar">
            <i class="fas fa-user-plus"></i>
        </div>
        <div class="adm-profile-copy">
            <div class="adm-profile-label">Signed in as</div>
            <div class="adm-profile-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_id'] ?? 'Admissions Officer'); ?></div>
        </div>
        <div class="adm-profile-meta">
            <span class="badge bg-primary">Admissions</span>
            <span class="text-muted small"><i class="fas fa-calendar me-1"></i><?php echo date('M d, Y'); ?></span>
        </div>
    </div>

    <!-- Pending Applications Alert -->
    <?php if ($pendingApps > 0): ?>
    <div class="alert alert-warning d-flex align-items-center gap-3 mb-4 rounded-3">
        <i class="fas fa-exclamation-circle fa-lg"></i>
        <div>
            <strong><?php echo number_format($pendingApps); ?> Application<?php echo $pendingApps !== 1 ? 's' : ''; ?> Awaiting Review</strong>
            <div class="small">Process these applications to admit or reject candidates.</div>
        </div>
        <a href="applicants.php" class="btn btn-warning btn-sm ms-auto">Process Now</a>
    </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="adm-stat-card h-100">
                <div class="adm-stat-icon" style="background:linear-gradient(135deg,#2E3190,#667eea)">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="adm-stat-body">
                    <div class="adm-stat-value"><?php echo number_format($totalApplicants); ?></div>
                    <div class="adm-stat-label">Total Applications</div>
                </div>
                <a href="applicants.php" class="adm-stat-link">View All <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="adm-stat-card h-100">
                <div class="adm-stat-icon" style="background:linear-gradient(135deg,#f6d365,#fda085)">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="adm-stat-body">
                    <div class="adm-stat-value"><?php echo number_format($pendingApps); ?></div>
                    <div class="adm-stat-label">Pending Review</div>
                </div>
                <a href="applicants.php?status=pending" class="adm-stat-link">Review <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="adm-stat-card h-100">
                <div class="adm-stat-icon" style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="adm-stat-body">
                    <div class="adm-stat-value"><?php echo number_format($admittedApps); ?></div>
                    <div class="adm-stat-label">Admitted</div>
                </div>
                <a href="manage_admitted_students.php" class="adm-stat-link">Manage <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="adm-stat-card h-100">
                <div class="adm-stat-icon" style="background:linear-gradient(135deg,#667eea,#764ba2)">
                    <i class="fas fa-users"></i>
                </div>
                <div class="adm-stat-body">
                    <div class="adm-stat-value"><?php echo number_format($totalStudents); ?></div>
                    <div class="adm-stat-label">Enrolled Students</div>
                </div>
                <a href="students.php" class="adm-stat-link">View <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
    </div>

    <!-- Main Grid: Quick Actions + Announcements -->
    <div class="row g-4 mb-4">

        <!-- Quick Actions -->
        <div class="col-xl-8 col-lg-7">
            <div class="adm-card h-100">
                <div class="adm-card-header">
                    <i class="fas fa-bolt me-2 text-warning"></i>Quick Actions
                </div>
                <div class="adm-card-body">
                    <div class="adm-actions-list">
                        <a href="applicants.php" class="adm-action-item">
                            <div class="adm-ai-icon" style="background:rgba(46,49,144,.1);color:#2E3190">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div>
                                <div class="adm-ai-title">Online Applications</div>
                                <div class="adm-ai-desc">Review incoming applications<?php if($pendingApps>0): ?> <span class="badge bg-warning text-dark"><?php echo $pendingApps; ?></span><?php endif; ?></div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="regNewStud.php" class="adm-action-item">
                            <div class="adm-ai-icon" style="background:rgba(17,153,142,.1);color:#11998e">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <div>
                                <div class="adm-ai-title">Register New Student</div>
                                <div class="adm-ai-desc">Directly enrol a student</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="manage_admitted_students.php" class="adm-action-item">
                            <div class="adm-ai-icon" style="background:rgba(102,126,234,.1);color:#667eea">
                                <i class="fas fa-user-cog"></i>
                            </div>
                            <div>
                                <div class="adm-ai-title">Manage Admitted</div>
                                <div class="adm-ai-desc">Admitted student records</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="regOldStud.php" class="adm-action-item">
                            <div class="adm-ai-icon" style="background:rgba(249,173,89,.1);color:#F9AD59">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <div>
                                <div class="adm-ai-title">Transfer Students</div>
                                <div class="adm-ai-desc">Re-register existing students</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="search_student.php" class="adm-action-item">
                            <div class="adm-ai-icon" style="background:rgba(245,87,108,.1);color:#f5576c">
                                <i class="fas fa-search"></i>
                            </div>
                            <div>
                                <div class="adm-ai-title">Search Students</div>
                                <div class="adm-ai-desc">Look up a student record</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="recent_students.php" class="adm-action-item">
                            <div class="adm-ai-icon" style="background:rgba(17,153,142,.08);color:#38ef7d">
                                <i class="fas fa-user-clock"></i>
                            </div>
                            <div>
                                <div class="adm-ai-title">Recently Admitted</div>
                                <div class="adm-ai-desc">View new enrolments</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Announcements -->
        <div class="col-xl-4 col-lg-5">
            <div class="adm-card h-100">
                <div class="adm-card-header">
                    <i class="fas fa-bullhorn me-2 text-primary"></i>Announcements
                </div>
                <div class="adm-card-body">
                    <div class="adm-announcement">
                        <strong>Admissions workspace</strong>
                        <p class="mb-2">Use the quick actions to review applications, register students, manage admitted records, and search student profiles.</p>
                        <?php if ($pendingApps > 0): ?>
                            <a href="applicants.php?status=pending" class="btn btn-sm btn-warning">Review <?php echo number_format($pendingApps); ?> pending</a>
                        <?php else: ?>
                            <span class="badge bg-success">No pending application alerts</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /row -->

</div><!-- /container-fluid -->

<style>
/* Admissions Dashboard Styles */
.admissions-dashboard-page .adm-profile-strip {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    padding: 1rem 1.15rem;
    display: flex;
    align-items: center;
    gap: .9rem;
}
.admissions-dashboard-page .adm-profile-avatar {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: linear-gradient(135deg,#2E3190,#00A651);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.admissions-dashboard-page .adm-profile-copy { min-width: 0; }
.admissions-dashboard-page .adm-profile-label {
    font-size: .72rem;
    color: #64748b;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.admissions-dashboard-page .adm-profile-name {
    font-size: 1rem;
    color: #1e293b;
    font-weight: 700;
    line-height: 1.2;
}
.admissions-dashboard-page .adm-profile-meta {
    font-size: .8rem;
    color: #64748b;
}
.admissions-dashboard-page .dashboard-header.admissions-section {
    border-left: 4px solid #2E3190;
}
.admissions-dashboard-page .adm-stat-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    padding: 1.25rem 1.35rem;
    display: flex;
    flex-direction: column;
    gap: .75rem;
    transition: box-shadow .2s, transform .2s;
}
.admissions-dashboard-page .adm-stat-card:hover {
    box-shadow: 0 6px 20px rgba(0,0,0,.1);
    transform: translateY(-2px);
}
.admissions-dashboard-page .adm-stat-icon {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    color: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,.15);
}
.admissions-dashboard-page .adm-stat-body { flex: 1; }
.admissions-dashboard-page .adm-stat-value {
    font-size: 1.9rem;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.1;
}
.admissions-dashboard-page .adm-stat-label {
    font-size: .78rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-top: .15rem;
}
.admissions-dashboard-page .adm-stat-link {
    font-size: .75rem;
    font-weight: 600;
    color: #2E3190;
    text-decoration: none;
}
.admissions-dashboard-page .adm-stat-link:hover { text-decoration: underline; }

.admissions-dashboard-page .adm-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.admissions-dashboard-page .adm-card-header {
    padding: .85rem 1.15rem;
    border-bottom: 1px solid #f0f0f0;
    font-weight: 700;
    font-size: .88rem;
    color: #334155;
    background: #f8fafc;
    display: flex;
    align-items: center;
}
.admissions-dashboard-page .adm-card-body { padding: .9rem 1.1rem; flex: 1; }

.admissions-dashboard-page .adm-actions-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: .5rem;
}
.admissions-dashboard-page .adm-action-item {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .6rem .65rem;
    border-radius: 10px;
    text-decoration: none;
    transition: background .18s;
}
.admissions-dashboard-page .adm-action-item:hover {
    background: #f8fafc;
    text-decoration: none;
}
.admissions-dashboard-page .adm-ai-icon {
    width: 36px;
    height: 36px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: .9rem;
}
.admissions-dashboard-page .adm-ai-title {
    font-size: .82rem;
    font-weight: 600;
    color: #1e293b;
    line-height: 1.2;
}
.admissions-dashboard-page .adm-ai-desc {
    font-size: .72rem;
    color: #64748b;
}
.admissions-dashboard-page .adm-announcement {
    background: #f8fafc;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: .95rem;
    color: #334155;
}
.admissions-dashboard-page .adm-announcement strong {
    display: block;
    color: #1e293b;
    margin-bottom: .35rem;
}
.admissions-dashboard-page .adm-announcement p {
    font-size: .82rem;
    color: #64748b;
}
.admissions-dashboard-page .adm-empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #94a3b8;
}
.admissions-dashboard-page .adm-empty-state i {
    font-size: 2.5rem;
    opacity: .2;
    display: block;
    margin-bottom: .75rem;
}
.admissions-dashboard-page .adm-empty-state p { font-size: .85rem; margin: 0; }
</style>

<?php require "includes/footer.php"; ?>
