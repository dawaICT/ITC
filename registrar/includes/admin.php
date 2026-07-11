<?php
$legacy_chrome = [
    'module' => 'registrar',
    'label' => 'Registrar Portal',
    'role_label' => 'Registrar',
    'required_roles' => ['registrar'],
    'menu' => [
        ['href' => 'index.php', 'icon' => 'fas fa-gauge-high', 'label' => 'Dashboard'],
        ['href' => 'search_student.php', 'icon' => 'fas fa-user-graduate', 'label' => 'Students'],
        ['href' => 'programs.php', 'icon' => 'fas fa-list', 'label' => 'Programs'],
        ['href' => 'courses.php', 'icon' => 'fas fa-book', 'label' => 'Courses'],
        ['href' => 'assessments.php', 'icon' => 'fas fa-clipboard-check', 'label' => 'Assessments'],
        ['href' => 'exams.php', 'icon' => 'fas fa-file-pen', 'label' => 'Exams'],
        ['href' => 'academic_reports.php', 'icon' => 'fas fa-chart-pie', 'label' => 'Academic Reports'],
    ],
];
require dirname(__DIR__, 2) . '/includes/legacy_staff_chrome.php';
