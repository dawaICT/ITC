<?php
// Admin module navigation via unified include
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/../includes/role_helpers.php';

// Check session timeout automatically for all pages using this nav
// Note: admin.php already does some session checking, but nav_unified.php also has checks.
// We'll let nav_unified.php handle the view logic.

// Define the base menu items
$adminBase = '/wucportal/admin/';
$transportBase = '/wucportal/transport/';

// Transport-only staff get a focused menu, not the full administrator sidebar.
$transportOnly = function_exists('isTransportOnlyUser') && isTransportOnlyUser();

$menu_sections = [];

// Convenience flags (computed once; each menu item still keeps its own guard so
// permissions are unchanged — sections below are purely a functional regrouping).
$canAcademics  = function_exists('canAccessAcademics') && canAccessAcademics();
$canAdmissions = function_exists('canAccessAdmissions') && canAccessAdmissions();
$canStudents   = function_exists('canManageStudents') && canManageStudents();
$canExams      = function_exists('canAccessExams') && canAccessExams();
$canSettings   = function_exists('canAccessSettings') && canAccessSettings();
$canFinance    = function_exists('canAccessFinance') && canAccessFinance();
$canElearning  = function_exists('canAccessElearning') && canAccessElearning();
$canLibrary    = function_exists('canAccessLibrary') && canAccessLibrary();
$canStaff      = function_exists('canManageStaff') && canManageStaff();
$canCityGuilds = function_exists('canManageCityGuilds') && canManageCityGuilds();
$canEnterExamMarks = function_exists('canEnterExamMarks') && canEnterExamMarks();
$canReports    = (function_exists('canAccessReports') && canAccessReports()) || (function_exists('isAdmin') && isAdmin());
$canAccessTransport = function_exists('canAccessTransport') ? canAccessTransport() : $canAcademics;

// 1. DASHBOARD
$dashboard_items = [];
$dashboard_items[] = ['href' => $adminBase . 'index.php', 'icon' => 'fas fa-home', 'label' => 'Dashboard', 'active_on' => 'index.php'];
if (file_exists(dirname(dirname(__DIR__)) . '/admin/analytics_dashboard.php')) {
    $dashboard_items[] = ['href' => $adminBase . 'analytics_dashboard.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Decision Support', 'active_on' => 'analytics_dashboard.php'];
}
$menu_sections[] = [
    'title' => 'Dashboard',
    'items' => $dashboard_items
];

// 2. ACADEMIC MANAGEMENT (structure & catalogue)
$academics_items = [];
if (!$transportOnly) {
    $academics_items[] = ['href' => $adminBase . 'departments.php', 'icon' => 'fas fa-building', 'label' => 'Departments', 'active_on' => 'departments.php'];
    if (function_exists('isSystemsAdmin') && isSystemsAdmin()) {
        $academics_items[] = ['href' => $adminBase . 'department_assignments.php', 'icon' => 'fas fa-sitemap', 'label' => 'Dept Assignments', 'active_on' => 'department_assignments.php'];
    }
}
if ($canAcademics) {
    $academics_items[] = ['href' => $adminBase . 'course_program_mgmt.php', 'icon' => 'fas fa-sitemap', 'label' => 'Academic Structure', 'active_on' => 'course_program_mgmt.php'];
    $academics_items[] = ['href' => $adminBase . 'programs.php', 'icon' => 'fas fa-graduation-cap', 'label' => 'Programmes', 'active_on' => 'programs.php'];
    $academics_items[] = ['href' => $adminBase . 'course_catalogue.php', 'icon' => 'fas fa-book', 'label' => 'Course Catalogue', 'active_on' => 'course_catalogue.php'];
    $academics_items[] = ['href' => $adminBase . 'courses.php', 'icon' => 'fas fa-book-open', 'label' => 'Courses / Modules', 'active_on' => 'courses.php'];
    $academics_items[] = ['href' => $adminBase . 'course_prerequisites.php', 'icon' => 'fas fa-project-diagram', 'label' => 'Prerequisites', 'active_on' => 'course_prerequisites.php'];
    $academics_items[] = ['href' => $adminBase . 'ai_curriculum_analyzer.php', 'icon' => 'fas fa-wand-magic-sparkles', 'label' => 'AI Curriculum Analyzer', 'active_on' => 'ai_curriculum_analyzer.php'];
    $academics_items[] = ['href' => $adminBase . 'timetable_settings.php', 'icon' => 'fas fa-calendar-alt', 'label' => 'Timetable Settings', 'active_on' => 'timetable_settings.php'];
}
if (!empty($academics_items)) {
    $menu_sections[] = [
        'title' => 'Academic Management',
        'items' => $academics_items
    ];
}

// 3. ADMISSIONS & REGISTRATION
$admissions_items = [];
if ($canAdmissions) {
    $admissions_items[] = ['href' => $adminBase . 'applicants.php', 'icon' => 'fas fa-user-plus', 'label' => 'Applications', 'active_on' => 'applicants.php'];
    $admissions_items[] = ['href' => $adminBase . 'manage_admitted_students.php', 'icon' => 'fas fa-user-check', 'label' => 'Approved Applicants', 'active_on' => 'manage_admitted_students.php'];
    $admissions_items[] = ['href' => $adminBase . 'processedApp.php', 'icon' => 'fas fa-tasks', 'label' => 'Processed Applications', 'active_on' => 'processedApp.php'];
}
if (!$transportOnly) {
    $admissions_items[] = ['href' => '/wucportal/admissions/search_student.php?from=admin', 'icon' => 'fas fa-search', 'label' => 'Search Student', 'active_on' => 'search_student.php'];
    if ($canStudents) {
        $admissions_items[] = ['href' => $adminBase . 'students_by_admin.php', 'icon' => 'fas fa-user-graduate', 'label' => 'Student Records', 'active_on' => 'students_by_admin.php'];
        $admissions_items[] = ['href' => $adminBase . 'regNewStud.php', 'icon' => 'fas fa-user-plus', 'label' => 'Add Student', 'active_on' => ['regNewStud.php', 'add_student.php', 'register_student.php']];
    }
}
if ($canAcademics) {
    $admissions_items[] = ['href' => $adminBase . 'registration_setup.php', 'icon' => 'fas fa-tools', 'label' => 'Registration Setup', 'active_on' => ['registration_setup.php', 'setup_semester_registration.php']];
    $admissions_items[] = ['href' => $adminBase . 'semester_registration.php', 'icon' => 'fas fa-user-edit', 'label' => 'Term Registration', 'active_on' => 'semester_registration.php'];
    $admissions_items[] = ['href' => $adminBase . 'courseReg.php', 'icon' => 'fas fa-clipboard-list', 'label' => 'Course Enrollment', 'active_on' => 'courseReg.php'];
}
if (!empty($admissions_items)) {
    $menu_sections[] = [
        'title' => 'Admissions & Registration',
        'items' => $admissions_items
    ];
}

// 4. SHORT COURSES & VOCATIONAL
$short_course_items = [];
if ($canAcademics) {
    $short_course_items[] = ['href' => $adminBase . 'short_courses.php', 'icon' => 'fas fa-certificate', 'label' => 'Training Catalogue', 'active_on' => 'short_courses.php'];
    $short_course_items[] = ['href' => $adminBase . 'itc_intakes.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Training Intakes', 'active_on' => 'itc_intakes.php'];
    $short_course_items[] = ['href' => $adminBase . 'short_course_registration.php', 'icon' => 'fas fa-user-check', 'label' => 'Short Course Registration', 'active_on' => 'short_course_registration.php'];
}
if ($canCityGuilds) {
    $short_course_items[] = ['href' => $adminBase . 'city_guilds.php',              'icon' => 'fas fa-chart-pie',      'label' => 'CG Overview',             'active_on' => 'city_guilds.php'];
    $short_course_items[] = ['href' => $adminBase . 'city_guilds_exports.php',      'icon' => 'fas fa-file-export',    'label' => 'CG Exports',              'active_on' => 'city_guilds_exports.php'];
    $short_course_items[] = ['href' => $adminBase . 'city_guilds_enrolment.php',    'icon' => 'fas fa-user-plus',      'label' => 'CG Enrolment & Units',    'active_on' => 'city_guilds_enrolment.php'];
    $short_course_items[] = ['href' => $adminBase . 'city_guilds_assessment.php',   'icon' => 'fas fa-clipboard-list', 'label' => 'CG Assessment & Support', 'active_on' => 'city_guilds_assessment.php'];
    $short_course_items[] = ['href' => $adminBase . 'city_guilds_verification.php', 'icon' => 'fas fa-check-double',   'label' => 'CG Verification & QA',    'active_on' => 'city_guilds_verification.php'];
    $short_course_items[] = ['href' => $adminBase . 'city_guilds_learners.php',     'icon' => 'fas fa-chart-line',     'label' => 'CG Learners',             'active_on' => 'city_guilds_learners.php'];
}
if (!empty($short_course_items)) {
    $menu_sections[] = [
        'title' => 'Short Courses & Vocational',
        'items' => $short_course_items
    ];
}

// 5. STAFF & LECTURER MANAGEMENT
$staff_items = [];
if ($canStaff) {
    $staff_items[] = ['href' => $adminBase . 'staff.php', 'icon' => 'fas fa-user-tie', 'label' => 'Staff Records', 'active_on' => 'staff.php'];
    $staff_items[] = ['href' => $adminBase . 'lecturers.php', 'icon' => 'fas fa-chalkboard-teacher', 'label' => 'Lecturers Directory', 'active_on' => 'lecturers.php'];
}
if ($canAcademics) {
    $staff_items[] = ['href' => $adminBase . 'assign_course.php', 'icon' => 'fas fa-chalkboard-teacher', 'label' => 'Lecturer Assignments', 'active_on' => 'assign_course.php'];
}
if (!empty($staff_items)) {
    $menu_sections[] = [
        'title' => 'Staff & Lecturer Management',
        'items' => $staff_items
    ];
}

// 6. ASSESSMENT & RESULTS
if ($canExams) {
    $exams_items = [];
    if ($canSettings) {
        $exams_items[] = ['href' => $adminBase . 'ca_settings.php', 'icon' => 'fas fa-sliders-h', 'label' => 'Grade Settings', 'active_on' => 'ca_settings.php'];
    }
    $exams_items[] = ['href' => $adminBase . 'assessments.php', 'icon' => 'fas fa-tasks', 'label' => 'Assessment Schemes', 'active_on' => 'assessments.php'];
    $exams_items[] = ['href' => $adminBase . 'upload_ca.php', 'icon' => 'fas fa-upload', 'label' => 'CA Upload / Review', 'active_on' => 'upload_ca.php'];
    $exams_items[] = ['href' => $adminBase . 'exams.php', 'icon' => 'fas fa-file-alt', 'label' => 'Exams', 'active_on' => 'exams.php'];
    if ($canEnterExamMarks) {
        $exams_items[] = ['href' => $adminBase . 'upload_exam_results.php', 'icon' => 'fas fa-upload', 'label' => 'Upload Results', 'active_on' => 'upload_exam_results.php'];
        $exams_items[] = ['href' => $adminBase . 'process_exam_results.php', 'icon' => 'fas fa-cogs', 'label' => 'Process Results', 'active_on' => 'process_exam_results.php'];
        $exams_items[] = ['href' => $adminBase . 'publishResults.php', 'icon' => 'fas fa-bullhorn', 'label' => 'Publish Results', 'active_on' => 'publishResults.php'];
    }
    $menu_sections[] = [
        'title' => 'Assessment & Results',
        'items' => $exams_items
    ];
}

// 7. E-LEARNING MANAGEMENT (LMS)
if ($canElearning) {
    $menu_sections[] = [
        'title' => 'E-Learning Management',
        'items' => [
            ['href' => $adminBase . 'elearning/index.php', 'icon' => 'fas fa-chalkboard', 'label' => 'LMS Dashboard', 'active_on' => 'elearning/index.php'],
            ['href' => $adminBase . 'elearning/manage.php', 'icon' => 'fas fa-cogs', 'label' => 'Course Management', 'active_on' => 'elearning/manage.php'],
            ['href' => $adminBase . 'elearning/assessments.php', 'icon' => 'fas fa-clipboard-check', 'label' => 'Assessments', 'active_on' => 'elearning/assessments.php'],
            ['href' => $adminBase . 'elearning/sessions.php', 'icon' => 'fas fa-video', 'label' => 'Live Sessions', 'active_on' => 'elearning/sessions.php'],
            ['href' => $adminBase . 'elearning/session_analytics.php', 'icon' => 'fas fa-clipboard-list', 'label' => 'Class Reports', 'active_on' => 'elearning/session_analytics.php'],
            ['href' => $adminBase . 'elearning/forum.php', 'icon' => 'fas fa-comments', 'label' => 'Discussion Forum', 'active_on' => 'elearning/forum.php'],
            ['href' => $adminBase . 'elearning/analytics.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Analytics', 'active_on' => 'elearning/analytics.php'],
        ]
    ];
}

if ($canElearning || $canLibrary || $canAcademics || (function_exists('isSystemsAdmin') && isSystemsAdmin())) {
    $menu_sections[] = [
        'title' => 'Digital Learning Repository',
        'items' => [
            ['href' => $adminBase . 'repository/index.php', 'icon' => 'fas fa-folder-open', 'label' => 'Repository', 'active_on' => 'repository/index.php'],
            ['href' => $adminBase . 'repository/pending.php', 'icon' => 'fas fa-hourglass-half', 'label' => 'Pending Uploads', 'active_on' => 'repository/pending.php'],
            ['href' => $adminBase . 'repository/categories.php', 'icon' => 'fas fa-tags', 'label' => 'Categories', 'active_on' => 'repository/categories.php'],
            ['href' => $adminBase . 'repository/reports.php', 'icon' => 'fas fa-chart-column', 'label' => 'Repository Reports', 'active_on' => 'repository/reports.php'],
            ['href' => $adminBase . 'repository/ai_processing.php', 'icon' => 'fas fa-wand-magic-sparkles', 'label' => 'AI Processing', 'active_on' => 'repository/ai_processing.php'],
        ],
    ];
}

// 8. LIBRARY / RESOURCE CENTRE
if ($canLibrary) {
    $menu_sections[] = [
        'title' => 'Library / Resource Centre',
        'items' => [
            ['href' => $adminBase . 'library.php', 'icon' => 'fas fa-book', 'label' => 'Library Dashboard', 'active_on' => 'library.php'],
            ['href' => $adminBase . 'library_catalog.php', 'icon' => 'fas fa-th-list', 'label' => 'Library Catalogue', 'active_on' => 'library_catalog.php'],
            ['href' => $adminBase . 'library_circulation.php', 'icon' => 'fas fa-exchange-alt', 'label' => 'Borrowing Records', 'active_on' => 'library_circulation.php'],
            ['href' => $adminBase . 'library_fines.php', 'icon' => 'fas fa-coins', 'label' => 'Fines', 'active_on' => 'library_fines.php'],
            ['href' => $adminBase . 'library_digital.php', 'icon' => 'fas fa-cloud', 'label' => 'Digital Resources', 'active_on' => 'library_digital.php'],
        ]
    ];
}

// 9. FINANCE & ACCOUNTS
if ($canFinance) {
    $menu_sections[] = [
        'title' => 'Finance & Accounts',
        'items' => [
            ['href' => $adminBase . 'finance.php', 'icon' => 'fas fa-money-bill-wave', 'label' => 'Finance Dashboard', 'active_on' => 'finance.php'],
            ['href' => $adminBase . 'financial_overview.php', 'icon' => 'fas fa-chart-line', 'label' => 'Financial Overview', 'active_on' => 'financial_overview.php'],
            ['href' => $adminBase . 'fee_structure.php', 'icon' => 'fas fa-file-invoice-dollar', 'label' => 'Fees', 'active_on' => 'fee_structure.php'],
            ['href' => $adminBase . 'payments.php', 'icon' => 'fas fa-cash-register', 'label' => 'Payments', 'active_on' => 'payments.php'],
            ['href' => $adminBase . 'sponsorship_dashboard.php', 'icon' => 'fas fa-chart-pie', 'label' => 'Sponsorship Dashboard', 'active_on' => 'sponsorship_dashboard.php'],
            ['href' => $adminBase . 'student_sponsorship.php', 'icon' => 'fas fa-user-shield', 'label' => 'Student Sponsorships', 'active_on' => 'student_sponsorship.php'],
            ['href' => $adminBase . 'sponsorship_management.php', 'icon' => 'fas fa-hand-holding-dollar', 'label' => 'Sponsor Configuration', 'active_on' => 'sponsorship_management.php'],
        ]
    ];
    if ($canSettings) {
        $menu_sections[count($menu_sections) - 1]['items'][] = ['href' => $adminBase . 'payment_gateway_settings.php', 'icon' => 'fas fa-credit-card', 'label' => 'Payment Gateway', 'active_on' => 'payment_gateway_settings.php'];
    }
}

// 10. TRANSPORT & OPERATIONS
$ops_items = [];
if ($canAccessTransport) {
    $ops_items[] = ['href' => $transportBase . 'transport_management.php', 'icon' => 'fas fa-bus', 'label' => 'Transport Operations', 'active_on' => ['transport_management.php', 'trainees.php', 'sessions.php', 'preuse_checks.php', 'cohorts.php', 'fleet.php', 'instructors.php', 'clients.php']];
    $ops_items[] = ['href' => $transportBase . 'reports.php', 'icon' => 'fas fa-chart-line', 'label' => 'Transport Reports', 'active_on' => 'reports.php'];
}
if (!$transportOnly) {
    $ops_items[] = ['href' => $adminBase . 'hostels.php', 'icon' => 'fas fa-bed', 'label' => 'Hostels', 'active_on' => 'hostels.php'];
}
if (!empty($ops_items)) {
    $menu_sections[] = [
        'title' => 'Transport & Operations',
        'items' => $ops_items
    ];
}

// 11. REPORTS
if ($canReports) {
    $menu_sections[] = [
        'title' => 'Reports',
        'items' => [
            ['href' => $adminBase . 'admittedStud_report.php', 'icon' => 'fas fa-user-graduate', 'label' => 'Student Reports', 'active_on' => 'admittedStud_report.php'],
            ['href' => $adminBase . 'ai_reports.php', 'icon' => 'fas fa-wand-magic-sparkles', 'label' => 'AI Reports', 'active_on' => 'ai_reports.php'],
            ['href' => $adminBase . 'itc_academic_reports.php', 'icon' => 'fas fa-chart-pie', 'label' => 'Academic Reports', 'active_on' => 'itc_academic_reports.php'],
            ['href' => $adminBase . 'training_reports.php', 'icon' => 'fas fa-filter', 'label' => 'Training Reports', 'active_on' => 'training_reports.php'],
            ['href' => $adminBase . 'semesterReg_stud.php', 'icon' => 'fas fa-clipboard-list', 'label' => 'Registration Reports', 'active_on' => 'semesterReg_stud.php'],
            ['href' => $adminBase . 'student_progression_report.php', 'icon' => 'fas fa-triangle-exclamation', 'label' => 'Progression Alerts', 'active_on' => 'student_progression_report.php'],
            ['href' => $adminBase . 'report_year_intake.php', 'icon' => 'fas fa-hand-holding-usd', 'label' => 'Sponsorship Reports', 'active_on' => 'report_year_intake.php'],
            ['href' => $adminBase . 'reportStudy_mode.php', 'icon' => 'fas fa-book-reader', 'label' => 'Study Mode', 'active_on' => 'reportStudy_mode.php'],
            ['href' => $adminBase . 'print_registers.php', 'icon' => 'fas fa-print', 'label' => 'Print Registers', 'active_on' => 'print_registers.php'],
            ['href' => $adminBase . 'login_activity.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Login Activity', 'active_on' => 'login_activity.php'],
        ]
    ];
}

// 12. USER & ACCESS CONTROL
$access_items = [];
if (function_exists('isSystemsAdmin') && isSystemsAdmin()) {
    $access_items[] = ['href' => $adminBase . 'users_roles.php', 'icon' => 'fas fa-users-cog', 'label' => 'Staff & Lecturer Roles', 'active_on' => 'users_roles.php'];
    $access_items[] = ['href' => $adminBase . 'portal_access.php', 'icon' => 'fas fa-table-columns', 'label' => 'Portal Access', 'active_on' => 'portal_access.php'];
    $access_items[] = ['href' => $adminBase . 'portal_permission_scope.php', 'icon' => 'fas fa-shield-halved', 'label' => 'Portal Permissions', 'active_on' => 'portal_permission_scope.php'];
}
if ($canSettings) {
    if (!(function_exists('isSystemsAdmin') && isSystemsAdmin())) {
        $access_items[] = ['href' => $adminBase . 'portal_access.php', 'icon' => 'fas fa-table-columns', 'label' => 'Portal Access', 'active_on' => 'portal_access.php'];
        $access_items[] = ['href' => $adminBase . 'portal_permission_scope.php', 'icon' => 'fas fa-shield-halved', 'label' => 'Portal Permissions', 'active_on' => 'portal_permission_scope.php'];
    }
    // defineAccess.php and user_role_mgmt.php were retired (2026-07-08): the legacy
    // staff_positions role store they edited is fully superseded by users_roles.php +
    // portal_permission_scope.php above, so their duplicate links are removed here.
    $access_items[] = ['href' => $adminBase . 'resetPassword.php', 'icon' => 'fas fa-key', 'label' => 'Reset Password', 'active_on' => 'resetPassword.php'];
}
if (!empty($access_items)) {
    $menu_sections[] = [
        'title' => 'User & Access Control',
        'items' => $access_items
    ];
}

// 13. SYSTEM SETTINGS (audit/security keeps its original Reports/Admin guard)
$system_items = [];
if ($canReports) {
    $system_items[] = ['href' => $adminBase . 'audit_logs.php', 'icon' => 'fas fa-shield-alt', 'label' => 'Audit Logs', 'active_on' => 'audit_logs.php'];
}
if ($canSettings) {
    $system_items[] = ['href' => $adminBase . 'enterprise_audit.php', 'icon' => 'fas fa-clipboard-check', 'label' => 'Enterprise Audit', 'active_on' => 'enterprise_audit.php'];
}
if (!empty($system_items)) {
    $menu_sections[] = [
        'title' => 'System Settings',
        'items' => $system_items
    ];
}

$module_config = array(
	'role_label' => function_exists('getRoleDisplayName')
		? getRoleDisplayName($_SESSION['role'] ?? '')
		: ($_SESSION['role'] ?? 'Administrator'),
	'additional_css' => array(
        'admin/css/admin-dashboard.css'
    ),
	'brand_color_primary' => '#1B2A4A', // ITC navy (matches unified sidebar)
	'brand_color_secondary' => '#0B1530', // Deep navy (card-header gradient)
	'additional_scripts' => array(),
	'menu_sections' => $menu_sections,
	'footer_profile_href' => 'profile.php',
    'footer_logout_href' => '/wucportal/logout.php?to=staff'
);

require dirname(__DIR__) . '/../includes/nav_unified.php';
