<?php
declare(strict_types=1);
require __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
wuc_require_systems_admin('/wucportal/portal_selection.php');
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/action_confirmation.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_GET['del'])) {
    wuc_render_action_confirmation('Remove staff member?', 'The account and active access mappings will be removed.', 'deleteStaff.php', ['staff_id' => trim((string)$_GET['del'])]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request.'); }
$staffId = trim((string)($_POST['staff_id'] ?? ''));
if ($staffId !== '') {
    $db->begin_transaction();
    try {
        foreach (['staff_positions', 'user_credentials', 'access_right'] as $table) {
            $stmt = $db->prepare("DELETE FROM `{$table}` WHERE staff_id = ?");
            $stmt->bind_param('s', $staffId); $stmt->execute(); $stmt->close();
        }
        $stmt = $db->prepare("UPDATE staff SET status = 'Deleted' WHERE staff_id = ?");
        $stmt->bind_param('s', $staffId); $stmt->execute(); $stmt->close();
        $db->commit(); $_SESSION['successMssg'] = 'Staff member removed.';
    } catch (Throwable $e) {
        $db->rollback(); error_log('Registrar staff removal failed: ' . $e->getMessage()); $_SESSION['errorMssg'] = 'Staff member could not be removed.';
    }
}
header('Location: staff.php', true, 303); exit;
