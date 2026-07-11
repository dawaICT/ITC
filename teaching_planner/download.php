<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/session_guard.php';
wuc_enforce_session_guard([
    'context' => 'teaching-planner-export', 'session_keys' => ['staff_id','user_id'],
    'activity_keys' => ['last_activity','last_active_time'], 'timeout' => 1800,
    'login_path' => '/wucportal/staff_login.php', 'flash_key' => 'errorMessage',
    'timeout_message' => 'Your session expired. Please log in again.', 'login_message' => 'Please log in to export a teaching plan.',
]);
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/config/auth_check.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/teaching_planner/init.php';

$actor = tp_actor_id();
if ($actor === '') { http_response_code(401); exit('Authentication required.'); }
if (function_exists('hydrateStaffRolesFromDatabase')) hydrateStaffRolesFromDatabase($actor);
$planId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$format = strtolower((string)($_GET['format'] ?? 'docx'));
if (!$planId || !in_array($format, ['docx','pdf'], true)) { http_response_code(400); exit('Invalid export request.'); }
try {
    $export = (new TeachingPlannerExporter($db))->exportPlan($planId, $actor, $format);
    $path = $export['path'];
    header('Content-Type: ' . $export['mime']); header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . str_replace(['"',"\r","\n"], '', $export['filename']) . '"');
    header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    readfile($path); exit;
} catch (Throwable $e) {
    error_log('Teaching Planner export failed: ' . $e->getMessage());
    http_response_code(400); exit('Export could not be completed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

