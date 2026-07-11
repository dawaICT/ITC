<?php
$page_title = 'Admin Dashboard';
require "includes/nav.php";

// Fetch headline stats
$totalStudents   = 0;
$totalStaff      = 0;
$totalPrograms   = 0;
$pendingApps     = 0;
$totalCourses    = 0;
$activeShortCourses = 0;

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

if ($tableExists($db, 'staff')) {
    $q = $db->query("SELECT COUNT(*) AS c FROM staff");
    if ($q && $r = $q->fetch_object()) $totalStaff = (int)$r->c;
}

if ($tableExists($db, 'programs')) {
    $programWhere = $columnExists($db, 'programs', 'is_active')
        ? 'WHERE COALESCE(is_active, 1) = 1'
        : '';
    $q = $db->query("SELECT COUNT(*) AS c FROM programs {$programWhere}");
    if ($q && $r = $q->fetch_object()) $totalPrograms = (int)$r->c;
}

if ($tableExists($db, 'courses')) {
    $q = $db->query("SELECT COUNT(*) AS c FROM courses");
    if ($q && $r = $q->fetch_object()) $totalCourses = (int)$r->c;
}

// Check if online_applicants table exists before querying
$hasApplicants = $tableExists($db, 'online_applicants');
if ($hasApplicants) {
    $appWhere = $columnExists($db, 'online_applicants', 'status') ? "WHERE status = 'pending' OR status IS NULL" : "";
    $q = $db->query("SELECT COUNT(*) AS c FROM online_applicants {$appWhere}");
    if ($q && $r = $q->fetch_object()) $pendingApps = (int)$r->c;
}

$hasShortCourses = $tableExists($db, 'short_courses');
if ($hasShortCourses) {
    $shortWhere = $columnExists($db, 'short_courses', 'status') ? "WHERE status = 'active'" : "";
    $q = $db->query("SELECT COUNT(*) AS c FROM short_courses {$shortWhere}");
    if ($q && $r = $q->fetch_object()) $activeShortCourses = (int)$r->c;
}

// Recent announcements
$announcements = [];
if ($tableExists($db, 'announcement')) {
    $annRes = $db->query("SELECT title, descript, created FROM announcement ORDER BY created DESC LIMIT 5");
    while ($annRes && $row = $annRes->fetch_object()) {
        $announcements[] = $row;
    }
}

// Recent registrations (last 5 students registered this semester)
$recentStudents = [];
if ($tableExists($db, 'semester_registration') && $tableExists($db, 'students')) {
    $regRes = $db->query("
        SELECT s.Fname, s.Lname, s.SID, sr.academic_year, sr.semester
        FROM semester_registration sr
        INNER JOIN students s ON s.SID = sr.student_id
        ORDER BY sr.id DESC
        LIMIT 5
    ");
    while ($regRes && $row = $regRes->fetch_object()) {
        $recentStudents[] = $row;
    }
}
?>

<div class="container-fluid px-4 portal-dashboard admin-dashboard-page">

    <!-- Page Header -->
    <div class="dashboard-header admin-section mb-4 mt-3">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title mb-0">
                    <i class="fas fa-tachometer-alt me-2 text-primary"></i>Admin Dashboard
                </h1>
                <p class="text-muted mb-0 mt-1 small">
                    <?php $hour = (int)date('G'); echo $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'); ?>,
                    <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_id'] ?? 'Administrator'); ?></strong>
                    &mdash; <?php echo date('l, F j, Y'); ?>
                </p>
            </div>
            <div class="col-auto d-flex gap-2">
                <a href="students_by_admin.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-user-graduate me-1"></i>Students
                </a>
                <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>

    <!-- User Profile Summary -->
    <div class="admin-profile-strip mb-4">
        <div class="admin-profile-avatar">
            <i class="fas fa-user-shield"></i>
        </div>
        <div class="admin-profile-copy">
            <div class="admin-profile-label">Signed in as</div>
            <div class="admin-profile-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_id'] ?? 'Administrator'); ?></div>
        </div>
        <div class="admin-profile-meta">
            <span class="badge bg-primary">Administrator</span>
            <span class="text-muted small"><i class="fas fa-calendar me-1"></i><?php echo date('M d, Y'); ?></span>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="admin-stat-card h-100">
                <div class="admin-stat-icon bg-students">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="admin-stat-body">
                    <div class="admin-stat-value"><?php echo number_format($totalStudents); ?></div>
                    <div class="admin-stat-label">Total Students</div>
                </div>
                <a href="students_by_admin.php" class="admin-stat-link">View All <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="admin-stat-card h-100">
                <div class="admin-stat-icon bg-staff">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="admin-stat-body">
                    <div class="admin-stat-value"><?php echo number_format($totalStaff); ?></div>
                    <div class="admin-stat-label">Staff Members</div>
                </div>
                <a href="staff.php" class="admin-stat-link">View All <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="admin-stat-card h-100">
                <div class="admin-stat-icon bg-programs">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="admin-stat-body">
                    <div class="admin-stat-value"><?php echo number_format($totalPrograms); ?></div>
                    <div class="admin-stat-label">Active Programs</div>
                </div>
                <a href="programs.php" class="admin-stat-link">View All <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="admin-stat-card h-100">
                <div class="admin-stat-icon bg-courses">
                    <i class="fas fa-book-open"></i>
                </div>
                <div class="admin-stat-body">
                    <div class="admin-stat-value"><?php echo number_format($totalCourses); ?></div>
                    <div class="admin-stat-label">Total Courses</div>
                </div>
                <a href="courses.php" class="admin-stat-link">View All <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
    </div>

    <!-- Secondary Stats Row -->
    <?php if ($pendingApps > 0 || $activeShortCourses > 0): ?>
    <div class="row g-3 mb-4">
        <?php if ($pendingApps > 0): ?>
        <div class="col-md-6">
            <div class="alert alert-warning d-flex align-items-center gap-3 mb-0 rounded-3">
                <div class="fs-icon-xl"><i class="fas fa-user-plus"></i></div>
                <div>
                    <strong><?php echo number_format($pendingApps); ?> Pending Application<?php echo $pendingApps !== 1 ? 's' : ''; ?></strong>
                    <div class="small">Review and process new applicants</div>
                </div>
                <a href="applicants.php" class="btn btn-warning btn-sm ms-auto">Review</a>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($activeShortCourses > 0): ?>
        <div class="col-md-6">
            <div class="alert alert-info d-flex align-items-center gap-3 mb-0 rounded-3">
                <div class="fs-icon-xl"><i class="fas fa-certificate"></i></div>
                <div>
                    <strong><?php echo number_format($activeShortCourses); ?> Active Short Course<?php echo $activeShortCourses !== 1 ? 's' : ''; ?></strong>
                    <div class="small">Currently running short courses</div>
                </div>
                <a href="short_courses.php" class="btn btn-info btn-sm ms-auto text-white">Manage</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Quick Actions + Announcements -->
    <div class="row g-4 mb-4">

        <!-- Quick Actions -->
        <div class="col-xl-8 col-lg-7">
            <div class="admin-card h-100">
                <div class="admin-card-header">
                    <i class="fas fa-bolt me-2 text-warning"></i>Quick Actions
                </div>
                <div class="admin-card-body">
                    <div class="quick-actions-list">
                        <a href="regNewStud.php" class="quick-action-item">
                            <div class="qa-icon-wrap qa-icon-students">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div class="qa-details">
                                <div class="qa-title">Add Student</div>
                                <div class="qa-desc">Enrol a new student</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="applicants.php" class="quick-action-item">
                            <div class="qa-icon-wrap qa-icon-applicants">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="qa-details">
                                <div class="qa-title">Applicants</div>
                                <div class="qa-desc">Manage applications
                                    <?php if ($pendingApps > 0): ?><span class="badge bg-warning text-dark ms-1"><?php echo $pendingApps; ?></span><?php endif; ?>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="programs.php" class="quick-action-item">
                            <div class="qa-icon-wrap qa-icon-programs">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <div class="qa-details">
                                <div class="qa-title">Programs</div>
                                <div class="qa-desc">Academic programs</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="courses.php" class="quick-action-item">
                            <div class="qa-icon-wrap qa-icon-courses">
                                <i class="fas fa-book-open"></i>
                            </div>
                            <div class="qa-details">
                                <div class="qa-title">Courses</div>
                                <div class="qa-desc">Manage course catalogue</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="assign_course_lecturer.php" class="quick-action-item">
                            <div class="qa-icon-wrap qa-icon-lecturers">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <div class="qa-details">
                                <div class="qa-title">Assign Lecturers</div>
                                <div class="qa-desc">Course lecturer assignments</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="semester_registration.php" class="quick-action-item">
                            <div class="qa-icon-wrap qa-icon-register">
                                <i class="fas fa-user-edit"></i>
                            </div>
                            <div class="qa-details">
                                <div class="qa-title">Semester Registration</div>
                                <div class="qa-desc">Manage student registration</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                        <a href="defineAccess.php" class="quick-action-item">
                            <div class="qa-icon-wrap qa-icon-access">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <div class="qa-details">
                                <div class="qa-title">Access Control</div>
                                <div class="qa-desc">Roles &amp; permissions</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto text-muted small"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Announcements -->
        <div class="col-xl-4 col-lg-5">
            <div class="admin-card h-100">
                <div class="admin-card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-bullhorn me-2 text-primary"></i>Announcements</span>
                    <?php if (!empty($announcements)): ?>
                        <span class="badge bg-primary"><?php echo count($announcements); ?></span>
                    <?php endif; ?>
                </div>
                <div class="admin-card-body scrollable">
                    <?php if (!empty($announcements)): ?>
                        <?php foreach ($announcements as $ann): ?>
                            <div class="ann-item">
                                <div class="ann-title">
                                    <span class="ann-dot"></span>
                                    <?php echo htmlspecialchars($ann->title ?? 'Notice'); ?>
                                </div>
                                <?php if (!empty($ann->descript)): ?>
                                    <div class="ann-body"><?php echo htmlspecialchars(mb_strimwidth($ann->descript, 0, 120, '…')); ?></div>
                                <?php endif; ?>
                                <div class="ann-date">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    <?php echo !empty($ann->created) ? date('M d, Y', strtotime($ann->created)) : '—'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-sm">
                            <i class="fas fa-bell-slash"></i>
                            <p>No announcements yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /row -->

</div><!-- /container-fluid -->

<?php require "includes/footer.php"; ?>
