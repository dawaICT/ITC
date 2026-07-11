<?php
$page_title = 'Admissions Dashboard';

// Gate error reporting for development/debug mode only
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

// Load navigation (handles session, auth, and DB connection)
require "includes/nav.php";

// Define root URL for links
$root_url = '/wucportal';

// Access staff_id from session (already verified by nav.php)
$staff_id = $_SESSION['staff_id'];

// Get counts for dashboard cards
$applicant_count = 0;
$student_count = 0;
$program_count = 0;
$pending_count = 0;

$tableExists = function(mysqli $db, string $table): bool {
    if ($res = $db->query("SHOW TABLES LIKE '".$db->real_escape_string($table)."'")) {
        $exists = $res->num_rows > 0; $res->free(); return $exists;
    }
    return false;
};

// Count all submissions (online_applicants is the intake log; processed rows are copies)
if ($tableExists($db, 'online_applicants')) {
    if ($res = $db->query("SELECT COUNT(*) AS total FROM online_applicants")) {
        if ($res->num_rows > 0) { $applicant_count = (int)$res->fetch_object()->total; }
        $res->free();
    }
}

// Count students
if ($res = $db->query("SELECT COUNT(*) AS total FROM students")) {
    if ($res->num_rows > 0) { $student_count = (int)$res->fetch_object()->total; }
    $res->free();
}

// Count active programmes (schema column is is_active, not status)
if ($tableExists($db, 'programs')) {
    if ($res = $db->query("SELECT COUNT(*) AS total FROM programs WHERE COALESCE(is_active, 1) = 1")) {
        if ($res->num_rows > 0) { $program_count = (int)$res->fetch_object()->total; }
        $res->free();
    }
}

// Count pending applications still in the review queue
if ($tableExists($db, 'online_applicants')) {
    if ($res = $db->query("SELECT COUNT(*) AS total FROM online_applicants WHERE status = 'pending' OR status IS NULL")) {
        if ($res->num_rows > 0) { $pending_count = (int)$res->fetch_object()->total; }
        $res->free();
    }
}

// Configure unified dashboard
$dashboard_title = 'Admissions Dashboard';
$dashboard_subtitle = 'Manage student admissions and applications';
$user_role = 'Admissions Officer';
$user_role_class = 'bg-admissions';
$header_section_class = 'admissions-section';
$stat_icon_class = 'bg-admissions';
$profile_link = 'edit_profile.php';
$edit_profile_link = 'edit_profile.php';

// Stats cards configuration
$stat_cards = [
    [
        'icon' => 'fas fa-file-alt',
        'value' => number_format($applicant_count),
        'label' => 'All Submissions',
        'bg_class' => 'bg-admissions',
        'link' => 'applicants.php',
        'link_text' => 'View'
    ],
    [
        'icon' => 'fas fa-clock',
        'value' => number_format($pending_count),
        'label' => 'Pending Review',
        'bg_class' => 'bg-warning',
        'link' => 'applicants.php?status=pending',
        'link_text' => 'Review'
    ],
    [
        'icon' => 'fas fa-user-graduate',
        'value' => number_format($student_count),
        'label' => 'Enrolled Students',
        'bg_class' => 'bg-success',
        'link' => 'students.php',
        'link_text' => 'View'
    ],
    [
        'icon' => 'fas fa-graduation-cap',
        'value' => number_format($program_count),
        'label' => 'Programs',
        'bg_class' => 'bg-info',
        'link' => 'programs.php',
        'link_text' => 'View'
    ]
];

// No static welcome card — it only restated the sidebar and page subtitle.
$show_announcements = false;
$announcements = [];

// Quick access modules
$quick_modules = [
    [
        'icon' => 'fas fa-file-alt',
        'title' => 'Applications',
        'description' => 'Review and process online applications.',
        'link' => 'applicants.php',
        'bg_class' => 'bg-admissions'
    ],
    [
        'icon' => 'fas fa-user-graduate',
        'title' => 'Register Student',
        'description' => 'Create a new student admission record.',
        'link' => 'regNewStud.php',
        'bg_class' => 'bg-success'
    ],
    [
        'icon' => 'fas fa-user-check',
        'title' => 'Admitted Students',
        'description' => 'Manage students admitted from applications.',
        'link' => 'manage_admitted_students.php',
        'bg_class' => 'bg-info'
    ],
    [
        'icon' => 'fas fa-search',
        'title' => 'Search Students',
        'description' => 'Find and update admission records.',
        'link' => 'search_student.php',
        'bg_class' => 'bg-warning'
    ],
];

// Include the unified dashboard template
require_once dirname(__DIR__) . '/includes/dashboard_template.php';
require_once __DIR__ . '/includes/footer.php';
?>
