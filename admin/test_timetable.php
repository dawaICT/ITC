<?php
/**
 * Admin — Test Timetable management (Admin-native; shared manage UI).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/test_timetable.php';

$page_title = 'Test Timetable';
require_once __DIR__ . '/includes/nav.php';

if (!function_exists('canManageAcademicOfficeOps') || !canManageAcademicOfficeOps()) {
    http_response_code(403);
    echo '<div class="container-fluid px-4 py-4"><div class="alert alert-danger">Access denied.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$ttSelfUrl = 'test_timetable.php';
require dirname(__DIR__) . '/includes/test_timetable_manage.php';
require_once __DIR__ . '/includes/footer.php';
