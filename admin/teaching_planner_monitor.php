<?php
/**
 * Admin — Teaching Plan Compliance monitor (Admin-native).
 * Distinct from admin/teaching_planner.php (template/syllabus administration).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/teaching_planner/init.php';

$page_title = 'Teaching Plan Compliance';
require_once __DIR__ . '/includes/nav.php';

if (!function_exists('canManageAcademicOfficeOps') || !canManageAcademicOfficeOps()) {
    http_response_code(403);
    echo '<div class="container-fluid px-4 py-4"><div class="alert alert-danger">Access denied.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

require dirname(__DIR__) . '/includes/teaching_planner_monitor_content.php';
require_once __DIR__ . '/includes/footer.php';
