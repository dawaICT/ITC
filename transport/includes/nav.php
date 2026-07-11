<?php
require_once __DIR__ . '/transport.php';

$transportBase = '/wucportal/transport/';
// Transport is a self-contained workspace: transport-only staff are NOT linked
// back to the staff dashboard. Only systems admins keep a link out, to the
// Admin Dashboard (appended below).
$transportIsAdmin = function_exists('isSystemsAdmin') && isSystemsAdmin();
$menu_sections = [
    [
        'title' => 'Home',
        'items' => [
            ['href' => '/wucportal/transport.php', 'icon' => 'fas fa-house', 'label' => 'Transport Hub', 'active_on' => 'transport.php'],
            ['href' => $transportBase . 'transport_management.php', 'icon' => 'fas fa-gauge-high', 'label' => 'Operations Overview', 'active_on' => 'transport_management.php'],
        ],
    ],
    [
        'title' => 'Training Operations',
        'items' => [
            ['href' => $transportBase . 'trainees.php', 'icon' => 'fas fa-user-plus', 'label' => 'Trainees', 'active_on' => 'trainees.php'],
            ['href' => $transportBase . 'cohorts.php', 'icon' => 'fas fa-layer-group', 'label' => 'Cohorts', 'active_on' => 'cohorts.php'],
            ['href' => $transportBase . 'sessions.php', 'icon' => 'fas fa-calendar-plus', 'label' => 'Sessions', 'active_on' => 'sessions.php'],
            ['href' => $transportBase . 'attendance.php', 'icon' => 'fas fa-user-check', 'label' => 'Attendance', 'active_on' => 'attendance.php'],
        ],
    ],
    [
        'title' => 'Learning & Quality',
        'items' => [
            ['href' => $transportBase . 'curriculum.php', 'icon' => 'fas fa-book-open', 'label' => 'Curriculum', 'active_on' => 'curriculum.php'],
            ['href' => $transportBase . 'trainee_assessments.php', 'icon' => 'fas fa-clipboard-check', 'label' => 'Assessments', 'active_on' => 'trainee_assessments.php'],
            ['href' => $transportBase . 'ai_assessment_bank.php', 'icon' => 'fas fa-wand-magic-sparkles', 'label' => 'Assessment Bank', 'active_on' => 'ai_assessment_bank.php'],
            ['href' => $transportBase . 'instructors.php', 'icon' => 'fas fa-id-card', 'label' => 'Instructors', 'active_on' => 'instructors.php'],
        ],
    ],
    [
        'title' => 'Fleet & Safety',
        'items' => [
            ['href' => $transportBase . 'fleet_dashboard.php', 'icon' => 'fas fa-chart-line', 'label' => 'Fleet Dashboard', 'active_on' => 'fleet_dashboard.php'],
            ['href' => $transportBase . 'fleet.php', 'icon' => 'fas fa-truck', 'label' => 'Fleet Assets', 'active_on' => 'fleet.php'],
            ['href' => $transportBase . 'preuse_checks.php', 'icon' => 'fas fa-clipboard-check', 'label' => 'Pre-use Checks', 'active_on' => 'preuse_checks.php'],
            ['href' => $transportBase . 'compliance.php', 'icon' => 'fas fa-shield-halved', 'label' => 'Compliance', 'active_on' => 'compliance.php'],
        ],
    ],
    [
        'title' => 'Finance & Partners',
        'items' => [
            ['href' => $transportBase . 'payments.php', 'icon' => 'fas fa-money-check-dollar', 'label' => 'Payments & Booking', 'active_on' => 'payments.php'],
            ['href' => $transportBase . 'fees.php', 'icon' => 'fas fa-coins', 'label' => 'Course Fees', 'active_on' => 'fees.php'],
            ['href' => $transportBase . 'clients.php', 'icon' => 'fas fa-building', 'label' => 'Corporate Clients', 'active_on' => 'clients.php'],
        ],
    ],
    [
        'title' => 'Insights & Governance',
        'items' => [
            ['href' => $transportBase . 'reports.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Reports & Summaries', 'active_on' => 'reports.php'],
            ['href' => $transportBase . 'training_reports.php', 'icon' => 'fas fa-filter', 'label' => 'Training Reports', 'active_on' => 'training_reports.php'],
            ['href' => $transportBase . 'policies.php', 'icon' => 'fas fa-file-contract', 'label' => 'Policies & Audit', 'active_on' => 'policies.php'],
        ],
    ],
];

// Only systems admins get a link out of the transport workspace (to the Admin
// Dashboard). Transport-only staff stay within transport.
if ($transportIsAdmin) {
    $menu_sections[] = [
        'title' => 'Portal',
        'items' => [
            ['href' => '/wucportal/admin/index.php', 'icon' => 'fas fa-table-columns', 'label' => 'Admin Dashboard', 'active_on' => ['admin/index.php']],
        ],
    ];
}

$module_config = [
    'role_label' => 'Transport Management',
    'additional_css' => [
        'transport/css/transport-module.css',
    ],
    'brand_color_primary' => '#6f42c1',
    'brand_color_secondary' => '#5a32a3',
    'brand_color_accent' => '#6f42c1',
    'menu_sections' => $menu_sections,
    'footer_profile_href' => '/wucportal/admin/profile.php',
    'footer_logout_href' => '/wucportal/logout.php?to=staff',
];

require dirname(__DIR__, 2) . '/includes/nav_unified.php';
