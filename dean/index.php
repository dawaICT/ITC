<?php
$page_title = 'Dean Dashboard';
require_once __DIR__ . '/includes/guard.php';
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

// Get quick stats
$totalStudents = 0;
$totalFaculty = 0;
$pendingRequests = 0;
$totalPrograms = 0;

if($statsQuery = $db->query("SELECT COUNT(*) as total FROM students")) {
    if($statsQuery->num_rows > 0) {
        $totalStudents = (int)$statsQuery->fetch_object()->total;
    }
    $statsQuery->free();
}

// PosID is a numeric FK to positions.PosID, not a code like "LEC001"
$facultySql = "SELECT COUNT(DISTINCT sp.staff_id) as total
               FROM staff_positions sp
               INNER JOIN positions p ON sp.PosID = p.PosID
               WHERE p.PosName = 'Lecturer'";
if($statsQuery = $db->query($facultySql)) {
    if($statsQuery->num_rows > 0) {
        $totalFaculty = (int)$statsQuery->fetch_object()->total;
    }
    $statsQuery->free();
}

// Count programs
if ($tableExists($db, 'programs')) {
    if($statsQuery = $db->query("SELECT COUNT(*) as total FROM programs")) {
        if($statsQuery->num_rows > 0) {
            $totalPrograms = (int)$statsQuery->fetch_object()->total;
        }
        $statsQuery->free();
    }
}

// Robust detection of a pending status across possible tables/columns
$pendingSources = [
    ['table' => 'continuous_assessment', 'columns' => ['status','state']],
    ['table' => 'assessments',           'columns' => ['status','state']],
    ['table' => 'student_program',       'columns' => ['status','state','approval_status']],
    ['table' => 'semester_registration', 'columns' => ['status','state']],
];
foreach ($pendingSources as $source) {
    $table = $source['table'];
    if ($checkTable = @$db->query("SHOW TABLES LIKE '".$db->real_escape_string($table)."'")) {
        $hasTable = $checkTable->num_rows > 0; $checkTable->free();
        if (!$hasTable) { continue; }
        foreach ($source['columns'] as $col) {
            if ($checkCol = @$db->query("SHOW COLUMNS FROM `{$table}` LIKE '".$db->real_escape_string($col)."'")) {
                $hasCol = $checkCol->num_rows > 0; $checkCol->free();
                if (!$hasCol) { continue; }
                if ($res = @$db->query("SELECT COUNT(*) AS total FROM `{$table}` WHERE `{$col}` = 'Pending'")) {
                    if ($res->num_rows > 0) { $pendingRequests = (int)$res->fetch_object()->total; }
                    $res->free();
                    break 2; // done
                }
            }
        }
    }
}

// Configure unified dashboard
$dashboard_title = "Dean's Dashboard";
$dashboard_subtitle = 'Academic management and oversight';
$user_role = 'Dean';
$user_role_class = 'bg-dean';
$header_section_class = 'dean-section';
$stat_icon_class = 'bg-dean';
$profile_link = '#';
$edit_profile_link = '#';

// Stats cards configuration
$stat_cards = [
    [
        'icon' => 'fas fa-user-graduate',
        'value' => number_format($totalStudents),
        'label' => 'Total Students',
        'bg_class' => 'bg-dean',
        'link' => 'students.php',
        'link_text' => 'View'
    ],
    [
        'icon' => 'fas fa-chalkboard-teacher',
        'value' => number_format($totalFaculty),
        'label' => 'Faculty Members',
        'bg_class' => 'bg-info',
        'link' => '#',
        'link_text' => 'View'
    ],
    [
        'icon' => 'fas fa-clipboard-list',
        'value' => number_format($pendingRequests),
        'label' => 'Pending Requests',
        'bg_class' => 'bg-warning',
        'link' => '#',
        'link_text' => 'Review'
    ],
    [
        'icon' => 'fas fa-graduation-cap',
        'value' => number_format($totalPrograms),
        'label' => 'Programs',
        'bg_class' => 'bg-success',
        'link' => '#',
        'link_text' => 'View'
    ]
];

// No static welcome card — it only restated the sidebar and page subtitle.
$show_announcements = false;
$announcements = [];

// Quick access modules
$quick_modules = [
    [
        'icon' => 'fas fa-user-graduate',
        'title' => 'Students',
        'description' => 'Review student records and academic standing.',
        'link' => 'students.php',
        'bg_class' => 'bg-dean'
    ],
    [
        'icon' => 'fas fa-chart-bar',
        'title' => 'Reports',
        'description' => 'Open faculty and academic reports.',
        'link' => 'reports.php',
        'bg_class' => 'bg-info'
    ],
    [
        'icon' => 'fas fa-file-alt',
        'title' => 'Admin Slip',
        'description' => 'Access administrative student slip tools.',
        'link' => 'adminSlip.php',
        'bg_class' => 'bg-warning'
    ],
];

// Include the unified dashboard template
require_once dirname(__DIR__) . '/includes/dashboard_template.php';
require_once __DIR__ . '/includes/footer.php';
?>
