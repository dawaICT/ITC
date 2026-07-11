<?php
$page_title = 'Registrar Dashboard';
require "includes/nav.php";

// Fetch headline stats for the registrar
$totalStudents       = 0;
$registeredThisSem   = 0;
$totalPrograms       = 0;
$pendingExamResults  = 0;

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

if ($tableExists($db, 'students')) {
    $q = $db->query("SELECT COUNT(*) AS c FROM students");
    if ($q && $r = $q->fetch_object()) $totalStudents = (int)$r->c;
}

if ($tableExists($db, 'programs')) {
    $programWhere = $columnExists($db, 'programs', 'status') ? "WHERE status = 'active' OR status IS NULL" : "";
    $q = $db->query("SELECT COUNT(*) AS c FROM programs {$programWhere}");
    if ($q && $r = $q->fetch_object()) $totalPrograms = (int)$r->c;
}

// Students registered in the most recent semester
if ($tableExists($db, 'semester_registration')) {
    $q = $db->query("
        SELECT COUNT(DISTINCT student_id) AS c
        FROM semester_registration
        WHERE (academic_year, semester) = (
            SELECT academic_year, semester FROM semester_registration
            ORDER BY id DESC LIMIT 1
        )
    ");
    if ($q && $r = $q->fetch_object()) $registeredThisSem = (int)$r->c;
}

// Exam results pending publication
if ($tableExists($db, 'exams') && $columnExists($db, 'exams', 'status')) {
    $q = $db->query("SELECT COUNT(*) AS c FROM exams WHERE status = 'Pending'");
    if ($q && $r = $q->fetch_object()) $pendingExamResults = (int)$r->c;
}

// Recent semester registrations
$recentReg = [];
if ($tableExists($db, 'semester_registration') && $tableExists($db, 'students')) {
    $regRes = $db->query("
        SELECT s.Fname, s.Lname, s.SID, sr.academic_year, sr.semester, sr.year_of_study
        FROM semester_registration sr
        INNER JOIN students s ON s.SID = sr.student_id
        ORDER BY sr.id DESC
        LIMIT 8
    ");
    while ($regRes && $row = $regRes->fetch_object()) {
        $recentReg[] = $row;
    }
}

// Assessment upload summary
$caCount = 0;
if ($tableExists($db, 'continuous_assessment')) {
    $q = $db->query("SELECT COUNT(*) AS c FROM continuous_assessment");
    if ($q && $r = $q->fetch_object()) $caCount = (int)$r->c;
}
?>

<div class="container-fluid px-4 portal-dashboard registrar-dashboard-page">

    <!-- Page Header -->
    <div class="dashboard-header admin-section mb-4 mt-3">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title mb-0">
                    <i class="fas fa-university me-2"></i>Registrar Dashboard
                </h1>
                <p class="text-muted mb-0 mt-1 small">
                    <?php $hour = (int)date('G'); echo $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'); ?>,
                    <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_id'] ?? 'Registrar'); ?></strong>
                    &mdash; <?php echo date('l, F j, Y'); ?>
                </p>
            </div>
            <div class="col-auto d-flex gap-2">
                <a href="search_student.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-search me-1"></i>Search Student
                </a>
                <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>

    <!-- User Profile Summary -->
    <div class="reg-profile-strip mb-4">
        <div class="reg-profile-avatar">
            <i class="fas fa-user-tie"></i>
        </div>
        <div class="reg-profile-copy">
            <div class="reg-profile-label">Signed in as</div>
            <div class="reg-profile-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_id'] ?? 'Registrar'); ?></div>
            <div class="reg-profile-meta">Registrar workspace</div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="reg-stat-card h-100">
                <div class="reg-stat-icon" style="background:linear-gradient(135deg,#667eea,#764ba2)">
                    <i class="fas fa-users"></i>
                </div>
                <div class="reg-stat-body">
                    <div class="reg-stat-value"><?php echo number_format($totalStudents); ?></div>
                    <div class="reg-stat-label">Total Students</div>
                </div>
                <a href="search_student.php" class="reg-stat-link">Search <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="reg-stat-card h-100">
                <div class="reg-stat-icon" style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="reg-stat-body">
                    <div class="reg-stat-value"><?php echo number_format($registeredThisSem); ?></div>
                    <div class="reg-stat-label">Registered This Semester</div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="reg-stat-card h-100">
                <div class="reg-stat-icon" style="background:linear-gradient(135deg,#f093fb,#f5576c)">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="reg-stat-body">
                    <div class="reg-stat-value"><?php echo number_format($totalPrograms); ?></div>
                    <div class="reg-stat-label">Active Programs</div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="reg-stat-card h-100">
                <div class="reg-stat-icon" style="background:linear-gradient(135deg,#f6d365,#fda085)">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="reg-stat-body">
                    <div class="reg-stat-value"><?php echo number_format($caCount); ?></div>
                    <div class="reg-stat-label">Assessment Records</div>
                </div>
                <a href="upload_ca.php" class="reg-stat-link">Upload <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
    </div>

    <?php if ($pendingExamResults > 0): ?>
    <div class="alert alert-warning d-flex align-items-center gap-3 mb-4 rounded-3">
        <i class="fas fa-exclamation-triangle fa-lg"></i>
        <div>
            <strong><?php echo number_format($pendingExamResults); ?> Exam Result<?php echo $pendingExamResults !== 1 ? 's' : ''; ?> Pending</strong>
            <div class="small">These records need approval before they can be published.</div>
        </div>
        <a href="upload_exam_results.php" class="btn btn-warning btn-sm ms-auto">Review</a>
    </div>
    <?php endif; ?>

    <!-- Main grid: Quick Actions + Announcements -->
    <div class="row g-4 mb-4">

        <!-- Quick Actions -->
        <div class="col-xl-8 col-lg-7">
            <div class="reg-card h-100">
                <div class="reg-card-header">
                    <i class="fas fa-bolt me-2 text-warning"></i>Quick Actions
                </div>
                <div class="reg-card-body">
                    <div class="reg-actions-list">
                        <a href="search_student.php" class="reg-action-item">
                            <div class="reg-ai-icon" style="background:rgba(102,126,234,.1);color:#667eea">
                                <i class="fas fa-search"></i>
                            </div>
                            <div>
                                <div class="reg-ai-title">Search Student</div>
                                <div class="reg-ai-desc">Find student records</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="upload_ca.php" class="reg-action-item">
                            <div class="reg-ai-icon" style="background:rgba(17,153,142,.1);color:#11998e">
                                <i class="fas fa-upload"></i>
                            </div>
                            <div>
                                <div class="reg-ai-title">Upload CA</div>
                                <div class="reg-ai-desc">Continuous assessment marks (CSV)</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="upload_exam_results.php" class="reg-action-item">
                            <div class="reg-ai-icon" style="background:rgba(245,87,108,.1);color:#f5576c">
                                <i class="fas fa-file-upload"></i>
                            </div>
                            <div>
                                <div class="reg-ai-title">Upload Exam Results</div>
                                <div class="reg-ai-desc">Final examination marks
                                    <?php if ($pendingExamResults > 0): ?><span class="badge bg-danger ms-1"><?php echo $pendingExamResults; ?></span><?php endif; ?>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Announcements -->
        <div class="col-xl-4 col-lg-5">
            <div class="reg-card h-100">
                <div class="reg-card-header">
                    <i class="fas fa-bullhorn me-2 text-primary"></i>Announcements
                </div>
                <div class="reg-card-body">
                    <div class="reg-announcement">
                        <strong>Registrar workspace</strong>
                        <p class="mb-2">Use the quick actions to search student records, upload assessments, and review exam result uploads.</p>
                        <?php if ($pendingExamResults > 0): ?>
                            <a href="upload_exam_results.php" class="btn btn-sm btn-warning">Review <?php echo number_format($pendingExamResults); ?> pending</a>
                        <?php else: ?>
                            <span class="badge bg-success">No pending exam result alerts</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /row -->

</div><!-- /container-fluid -->

<style>
/* Registrar Dashboard Styles */
.registrar-dashboard-page .reg-profile-strip {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    padding: 1rem 1.15rem;
    display: flex;
    align-items: center;
    gap: .9rem;
}
.registrar-dashboard-page .reg-profile-avatar {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: linear-gradient(135deg,#6f42c1,#11998e);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.registrar-dashboard-page .reg-profile-copy { min-width: 0; }
.registrar-dashboard-page .reg-profile-label {
    font-size: .72rem;
    color: #64748b;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.registrar-dashboard-page .reg-profile-name {
    font-size: 1rem;
    color: #1e293b;
    font-weight: 700;
    line-height: 1.2;
}
.registrar-dashboard-page .reg-profile-meta {
    font-size: .8rem;
    color: #64748b;
}
.registrar-dashboard-page .reg-stat-card {
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
.registrar-dashboard-page .reg-stat-card:hover {
    box-shadow: 0 6px 20px rgba(0,0,0,.1);
    transform: translateY(-2px);
}
.registrar-dashboard-page .reg-stat-icon {
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
.registrar-dashboard-page .reg-stat-body { flex: 1; }
.registrar-dashboard-page .reg-stat-value {
    font-size: 1.9rem;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.1;
}
.registrar-dashboard-page .reg-stat-label {
    font-size: .78rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-top: .15rem;
}
.registrar-dashboard-page .reg-stat-link {
    font-size: .75rem;
    font-weight: 600;
    color: #6f42c1;
    text-decoration: none;
}
.registrar-dashboard-page .reg-stat-link:hover { text-decoration: underline; }

.registrar-dashboard-page .reg-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.registrar-dashboard-page .reg-card-header {
    padding: .85rem 1.15rem;
    border-bottom: 1px solid #f0f0f0;
    font-weight: 700;
    font-size: .88rem;
    color: #334155;
    background: #f8fafc;
    display: flex;
    align-items: center;
}
.registrar-dashboard-page .reg-card-body { padding: .9rem 1.1rem; flex: 1; }

.registrar-dashboard-page .reg-actions-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: .5rem;
}
.registrar-dashboard-page .reg-action-item {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .75rem .65rem;
    border-radius: 10px;
    text-decoration: none;
    transition: background .18s;
}
.registrar-dashboard-page .reg-action-item:hover {
    background: #f8fafc;
    text-decoration: none;
}
.registrar-dashboard-page .reg-ai-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: .95rem;
}
.registrar-dashboard-page .reg-ai-title {
    font-size: .83rem;
    font-weight: 600;
    color: #1e293b;
}
.registrar-dashboard-page .reg-ai-desc {
    font-size: .72rem;
    color: #64748b;
}
.registrar-dashboard-page .reg-announcement {
    background: #f8fafc;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: .95rem;
    color: #334155;
}
.registrar-dashboard-page .reg-announcement strong {
    display: block;
    color: #1e293b;
    margin-bottom: .35rem;
}
.registrar-dashboard-page .reg-announcement p {
    font-size: .82rem;
    color: #64748b;
}
.registrar-dashboard-page .reg-empty-state {
    text-align: center;
    padding: 2.5rem 1rem;
    color: #94a3b8;
}
.registrar-dashboard-page .reg-empty-state i {
    font-size: 2.2rem;
    opacity: .25;
    display: block;
    margin-bottom: .6rem;
}
.registrar-dashboard-page .reg-empty-state p { font-size: .85rem; margin: 0; }
</style>

<?php require "includes/footer.php"; ?>
