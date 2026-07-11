<?php
$page_title = 'Admin Dashboard';
require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();

// Gate error reporting for development/debug mode only
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

// Load navigation (handles session, auth, and DB connection)
require "includes/nav.php";

// Define root URL for links
$root_url = '/wucportal';
$transportModuleUrl = '/wucportal/transport.php';
$transportRouteUrls = [
    'trainee' => '/wucportal/transport/trainees.php',
    'session' => '/wucportal/transport/sessions.php',
    'check' => '/wucportal/transport/preuse_checks.php',
    'cohort' => '/wucportal/transport/cohorts.php',
    'fleet' => '/wucportal/transport/fleet.php',
    'instructor' => '/wucportal/transport/instructors.php',
    'client' => '/wucportal/transport/clients.php',
];

// Access staff_id from session (already verified by nav.php)
$staff_id = $_SESSION['user_id'];

// Helper for table existence
$tableExists = function(mysqli $db, string $table): bool {
    if ($res = $db->query("SHOW TABLES LIKE '".$db->real_escape_string($table)."'")) {
        $exists = $res->num_rows > 0; $res->free(); return $exists;
    }
    return false;
};

// Get counts for dashboard cards
$totalStudents = 0;
$totalStaff = 0;
$totalShortCourses = 0;
$totalTransportTrainees = 0;
$totalTransportCohorts = 0;
$totalTransportFleet = 0;
$totalTransportFleetAlerts = 0;
$totalTransportSessionsMonth = 0;
$totalPrograms = 0;
$totalCollected = 0.0;

$transportTables = [
    'transport_campuses',
    'transport_programs',
    'transport_cohorts',
    'transport_trainees',
    'transport_enrollments',
    'transport_vehicles',
    'transport_instructors',
    'transport_sessions',
    'transport_preuse_checks',
    'transport_corporate_clients',
];
$transportTablesReady = true;
foreach ($transportTables as $transportTable) {
    if (!$tableExists($db, $transportTable)) {
        $transportTablesReady = false;
        break;
    }
}

$dashboardScalar = function(string $sql) use ($db): float {
    if ($res = $db->query($sql)) {
        $row = $res->fetch_row();
        $res->free();
        return isset($row[0]) ? (float)$row[0] : 0.0;
    }
    error_log('Admin dashboard query failed: ' . $db->error);
    return 0.0;
};

// Count students
if ($res = $db->query("SELECT COUNT(*) AS total FROM students")) {
    if ($res->num_rows > 0) { $totalStudents = (int)$res->fetch_object()->total; }
    $res->free();
}

// Count staff
if ($res = $db->query("SELECT COUNT(*) AS total FROM staff")) {
    if ($res->num_rows > 0) { $totalStaff = (int)$res->fetch_object()->total; }
    $res->free();
}

// Count short courses (card is labeled "Short Courses" and links to short_courses.php)
if ($tableExists($db, 'short_courses')) {
    if ($res = $db->query("SELECT COUNT(*) AS total FROM short_courses")) {
        if ($res->num_rows > 0) { $totalShortCourses = (int)$res->fetch_object()->total; }
        $res->free();
    }
}

if ($transportTablesReady) {
    $totalTransportTrainees = (int)$dashboardScalar("SELECT COUNT(*) FROM transport_enrollments WHERE status IN ('enrolled','active')");
    $totalTransportCohorts = (int)$dashboardScalar("SELECT COUNT(*) FROM transport_cohorts WHERE status IN ('open','in_progress')");
    $totalTransportFleet = (int)$dashboardScalar("SELECT COUNT(*) FROM transport_vehicles WHERE status IN ('available','assigned')");
    $totalTransportFleetAlerts = (int)$dashboardScalar("SELECT COUNT(*) FROM transport_vehicles WHERE status IN ('maintenance','unavailable') OR (fitness_expiry IS NOT NULL AND fitness_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) OR (insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))");
    $totalTransportSessionsMonth = (int)$dashboardScalar("SELECT COUNT(*) FROM transport_sessions WHERE session_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND status <> 'cancelled'");
}

// Count programs
if ($tableExists($db, 'programs')) {
    if ($res = $db->query("SELECT COUNT(*) AS total FROM programs")) {
        if ($res->num_rows > 0) { $totalPrograms = (int)$res->fetch_object()->total; }
        $res->free();
    }
}

// Sum collected payments for the Financial Overview card
if ($tableExists($db, 'payments')) {
    if ($res = $db->query("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'posted'")) {
        if ($res->num_rows > 0) { $totalCollected = (float)$res->fetch_object()->total; }
        $res->free();
    }
}

// Configure unified dashboard
$dashboard_title = 'Admin Dashboard';
$dashboard_subtitle = 'Manage students, staff, courses, and system settings';
$user_role = $_SESSION['role_raw'] ?? 'Administrator';
$user_role_class = 'bg-admin';
$header_section_class = 'admin-section';
$stat_icon_class = 'bg-primary';
$profile_link = 'view_staff.php?view=' . ($_SESSION['user_id'] ?? '');
$edit_profile_link = 'editStaff.php?update=' . ($_SESSION['user_id'] ?? '');
$canShowTransportDashboard = function_exists('canAccessTransport') ? canAccessTransport() : true;

// Stats cards configuration
$stat_cards = [
    [
        'icon' => 'fas fa-user-graduate',
        'value' => number_format($totalStudents),
        'label' => 'Total Students',
        'bg_class' => 'bg-admissions',
        'link' => 'students_by_admin.php',
        'link_text' => 'Manage Students'
    ],
    [
        'icon' => 'fas fa-chalkboard-teacher',
        'value' => number_format($totalStaff),
        'label' => 'Total Staff',
        'bg_class' => 'bg-lecturer',
        'link' => 'staff.php',
        'link_text' => 'Browse Staff'
    ],
    [
        'icon' => 'fas fa-book',
        'value' => number_format($totalShortCourses),
        'label' => 'Short Courses',
        'bg_class' => 'bg-purple',
        'link' => 'short_courses.php',
        'link_text' => 'Manage Courses'
    ],
    [
        'icon' => 'fas fa-money-bill-wave',
        'value' => 'ZMW ' . number_format($totalCollected, 2),
        'label' => 'Payments Collected',
        'bg_class' => 'bg-finance',
        'link' => 'financial_overview.php',
        'link_text' => 'View Reports'
    ],
    [
        'icon' => 'fas fa-bus',
        'value' => number_format($totalTransportTrainees),
        'label' => 'Transport Trainees',
        'bg_class' => 'bg-teal',
        'link' => $transportModuleUrl,
        'link_text' => 'Open Transport'
    ]
];

if ($canShowTransportDashboard && $transportTablesReady) {
    $stat_cards[] = [
        'icon' => 'fas fa-layer-group',
        'value' => number_format($totalTransportCohorts),
        'label' => 'Active Cohorts',
        'bg_class' => 'bg-purple',
        'link' => $transportRouteUrls['cohort'],
        'link_text' => 'Manage Cohorts'
    ];
    $stat_cards[] = [
        'icon' => 'fas fa-truck',
        'value' => number_format($totalTransportFleet),
        'label' => 'Fleet Ready',
        'bg_class' => 'bg-success',
        'link' => $transportRouteUrls['fleet'],
        'link_text' => 'View Fleet'
    ];
    $stat_cards[] = [
        'icon' => 'fas fa-triangle-exclamation',
        'value' => number_format($totalTransportFleetAlerts),
        'label' => 'Fleet Alerts',
        'bg_class' => $totalTransportFleetAlerts > 0 ? 'bg-danger' : 'bg-teal',
        'link' => $transportRouteUrls['check'],
        'link_text' => 'Review Checks'
    ];
    $stat_cards[] = [
        'icon' => 'fas fa-calendar-check',
        'value' => number_format($totalTransportSessionsMonth),
        'label' => 'Sessions This Month',
        'bg_class' => 'bg-admin',
        'link' => $transportRouteUrls['session'],
        'link_text' => 'Schedule'
    ];
}

// No static welcome card — it only restated the sidebar and page subtitle.
$show_announcements = false;
$announcements = [];

// Quick access modules
$quick_modules = [
    [
        'icon' => 'fas fa-user-graduate',
        'title' => 'Students',
        'description' => 'Manage student records and registration.',
        'bg_class' => 'bg-admissions',
        'link' => 'students_by_admin.php',
    ],
    [
        'icon' => 'fas fa-users',
        'title' => 'Staff',
        'description' => 'Open staff records and role assignments.',
        'bg_class' => 'bg-lecturer',
        'link' => 'staff.php',
    ],
    [
        'icon' => 'fas fa-book-open',
        'title' => 'Courses',
        'description' => 'Manage courses and programme structure.',
        'bg_class' => 'bg-purple',
        'link' => 'courses.php',
    ],
    [
        'icon' => 'fas fa-money-bill-wave',
        'title' => 'Finance',
        'description' => 'Open payments and financial reports.',
        'bg_class' => 'bg-finance',
        'link' => 'financial_overview.php',
    ],
];
if ($canShowTransportDashboard) {
    $quick_modules[] = [
        'icon' => 'fas fa-bus',
        'title' => 'Transport',
        'description' => 'Open transport trainees, fleet, and checks.',
        'bg_class' => 'bg-teal',
        'link' => $transportModuleUrl,
    ];
}

if (false) {
    ob_start();
    ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="mb-1 text-primary"><i class="fas fa-bus me-2"></i>Transport Operations</h5>
                    <p class="text-muted mb-0 small">Live training, fleet, and compliance snapshot.</p>
                </div>
                <a href="<?php echo htmlspecialchars($transportModuleUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
                    Open Transport <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
        <div class="card-body">
            <?php if (!$transportTablesReady): ?>
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-circle-info me-2"></i>
                    Transport tables are not installed yet. Run the transport installer before using cohorts, fleet, trainee enrolment, and checks.
                    <a class="alert-link" href="/wucportal/transport/scripts/install_transport_module.php">Open installer</a>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <div class="col-xl-3 col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-muted small">Active Trainees</div>
                            <div class="h4 mb-1"><?php echo number_format($totalTransportTrainees); ?></div>
                            <a href="<?php echo htmlspecialchars($transportRouteUrls['trainee'], ENT_QUOTES, 'UTF-8'); ?>" class="small text-decoration-none">Enroll or review trainees</a>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-muted small">Active Cohorts</div>
                            <div class="h4 mb-1"><?php echo number_format($totalTransportCohorts); ?></div>
                            <a href="<?php echo htmlspecialchars($transportRouteUrls['cohort'], ENT_QUOTES, 'UTF-8'); ?>" class="small text-decoration-none">Create or update cohorts</a>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-muted small">Fleet Ready</div>
                            <div class="h4 mb-1"><?php echo number_format($totalTransportFleet); ?></div>
                            <a href="<?php echo htmlspecialchars($transportRouteUrls['fleet'], ENT_QUOTES, 'UTF-8'); ?>" class="small text-decoration-none">Add vehicles and service dates</a>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-muted small">Alerts</div>
                            <div class="h4 mb-1"><?php echo number_format($totalTransportFleetAlerts); ?></div>
                            <a href="<?php echo htmlspecialchars($transportRouteUrls['check'], ENT_QUOTES, 'UTF-8'); ?>" class="small text-decoration-none">Record checks and defects</a>
                        </div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <a href="<?php echo htmlspecialchars($transportRouteUrls['session'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary btn-sm"><i class="fas fa-calendar-plus me-1"></i>Schedule Session</a>
                    <a href="<?php echo htmlspecialchars($transportRouteUrls['check'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-clipboard-check me-1"></i>Record Check</a>
                    <a href="<?php echo htmlspecialchars($transportRouteUrls['fleet'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-truck me-1"></i>Add Vehicle</a>
                    <a href="<?php echo htmlspecialchars($transportRouteUrls['client'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-building me-1"></i>Add Client</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    $extra_dashboard_content = ob_get_clean();
}

// Include the unified dashboard template
require_once dirname(__DIR__) . '/includes/dashboard_template.php';
require_once __DIR__ . '/includes/footer.php';
