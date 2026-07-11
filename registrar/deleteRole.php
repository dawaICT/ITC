<?php
declare(strict_types=1);
require __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
wuc_require_systems_admin('/wucportal/portal_selection.php');
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request.'); }
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($id && $id > 0) {
    $stmt = $db->prepare('DELETE FROM staff_positions WHERE St_id = ?');
    $stmt->bind_param('i', $id); $stmt->execute();
    $_SESSION[$stmt->affected_rows ? 'successMssg' : 'errorMssg'] = $stmt->affected_rows ? 'Access assignment removed.' : 'Assignment was not found.';
    $stmt->close();
}
header('Location: defineAccess.php', true, 303); exit;
