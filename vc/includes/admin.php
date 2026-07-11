<?php
$legacy_chrome = [
    'module' => 'vc',
    'label' => 'Executive Portal',
    'role_label' => 'Executive View',
    // The live database has no dedicated VC position; preserve the legacy
    // login-only rule until an explicit executive role exists.
    'required_roles' => [],
    'menu' => [
        ['href' => 'index.php', 'icon' => 'fas fa-gauge-high', 'label' => 'Dashboard'],
        ['href' => 'students_by_admin.php', 'icon' => 'fas fa-user-graduate', 'label' => 'Students'],
        ['href' => 'staff.php', 'icon' => 'fas fa-users', 'label' => 'Staff'],
        ['href' => 'programs.php', 'icon' => 'fas fa-list', 'label' => 'Programs'],
        ['href' => 'courses.php', 'icon' => 'fas fa-book', 'label' => 'Courses'],
        ['href' => 'exams.php', 'icon' => 'fas fa-file-pen', 'label' => 'Exams'],
        ['href' => 'payments.php', 'icon' => 'fas fa-wallet', 'label' => 'Finance'],
        ['href' => 'reportManager.php', 'icon' => 'fas fa-chart-line', 'label' => 'Reports'],
    ],
];
require dirname(__DIR__, 2) . '/includes/legacy_staff_chrome.php';
