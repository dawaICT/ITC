<?php
$module_config = [
    'role_label' => 'Executive View',
    // No dedicated VC role exists in the live positions table yet.
    'menu_sections' => [[
        'title' => 'Executive',
        'items' => [
            ['href' => 'index.php', 'icon' => 'fas fa-gauge-high', 'label' => 'Dashboard', 'active_on' => 'index.php'],
            ['href' => 'students_by_admin.php', 'icon' => 'fas fa-user-graduate', 'label' => 'Students', 'active_on' => 'students_by_admin.php'],
            ['href' => 'staff.php', 'icon' => 'fas fa-users', 'label' => 'Staff', 'active_on' => 'staff.php'],
            ['href' => 'programs.php', 'icon' => 'fas fa-list', 'label' => 'Programs', 'active_on' => 'programs.php'],
            ['href' => 'courses.php', 'icon' => 'fas fa-book', 'label' => 'Courses', 'active_on' => 'courses.php'],
            ['href' => 'reportManager.php', 'icon' => 'fas fa-chart-line', 'label' => 'Reports', 'active_on' => 'reportManager.php'],
        ],
    ]],
    'additional_css' => ['vc/css_main/admin.css'],
];
require dirname(__DIR__, 2) . '/includes/nav_unified.php';
