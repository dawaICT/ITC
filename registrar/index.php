<?php
$page_title = 'Registrar Dashboard';
require __DIR__ . '/includes/nav.php';
error_reporting(0);

$countRows = function (mysqli $db, string $table): int {
    $safe = $db->real_escape_string($table);
    $exists = $db->query("SHOW TABLES LIKE '{$safe}'");
    if (!$exists || $exists->num_rows === 0) {
        return 0;
    }
    if ($exists) {
        $exists->free();
    }

    $result = $db->query("SELECT COUNT(*) AS total FROM `{$safe}`");
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_assoc();
    $result->free();
    return (int)($row['total'] ?? 0);
};

$registrationCount = 0;
if ($res = $db->query("SHOW TABLES LIKE 'semester_registration'")) {
    $hasRegistrations = $res->num_rows > 0;
    $res->free();
    if ($hasRegistrations && ($result = $db->query("SELECT COUNT(DISTINCT student_id) AS total FROM semester_registration"))) {
        $row = $result->fetch_assoc();
        $registrationCount = (int)($row['total'] ?? 0);
        $result->free();
    }
}

$dashboard_title = 'Registrar Dashboard';
$dashboard_subtitle = 'Manage records, registration, assessments, and reports';
$user_role = 'Registrar';
$user_role_class = 'bg-teal';
$header_section_class = 'registrar-section';
$stat_icon_class = 'bg-teal';
$profile_link = 'view_staff.php?view=' . ($_SESSION['staff_id'] ?? '');
$edit_profile_link = 'editStaff.php?update=' . ($_SESSION['staff_id'] ?? '');

$stat_cards = [
    [
        'icon' => 'fas fa-user-graduate',
        'value' => number_format($countRows($db, 'students')),
        'label' => 'Students',
        'bg_class' => 'bg-teal',
        'link' => 'search_student.php',
        'link_text' => 'Search'
    ],
    [
        'icon' => 'fas fa-clipboard-check',
        'value' => number_format($registrationCount),
        'label' => 'Registered Students',
        'bg_class' => 'bg-success',
        'link' => 'semester_registration.php',
        'link_text' => 'Open'
    ],
    [
        'icon' => 'fas fa-graduation-cap',
        'value' => number_format($countRows($db, 'programs')),
        'label' => 'Programs',
        'bg_class' => 'bg-info',
        'link' => 'programs.php',
        'link_text' => 'View'
    ],
    [
        'icon' => 'fas fa-book-open',
        'value' => number_format($countRows($db, 'courses')),
        'label' => 'Courses',
        'bg_class' => 'bg-warning',
        'link' => 'courses.php',
        'link_text' => 'Manage'
    ],
];

$quick_modules = [
    [
        'icon' => 'fas fa-search',
        'title' => 'Search Student',
        'description' => 'Find student records, registration, and documents.',
        'link' => 'search_student.php',
        'bg_class' => 'bg-teal'
    ],
    [
        'icon' => 'fas fa-upload',
        'title' => 'Upload CA',
        'description' => 'Upload CA results in CSV format.',
        'link' => 'upload_ca.php',
        'bg_class' => 'bg-success'
    ],
    [
        'icon' => 'fas fa-file-upload',
        'title' => 'Upload Exam Results',
        'description' => 'Submit final examination marks.',
        'link' => 'upload_exam_results.php',
        'bg_class' => 'bg-warning'
    ],
    [
        'icon' => 'fas fa-chart-bar',
        'title' => 'Reports',
        'description' => 'Open registrar academic reports and summaries.',
        'link' => 'academic_reports.php',
        'bg_class' => 'bg-info'
    ],
];

// No static welcome card — it only restated the sidebar and page subtitle.
$show_announcements = false;
$announcements = [];

require_once dirname(__DIR__) . '/includes/dashboard_template.php';
require __DIR__ . '/includes/footer.php';
