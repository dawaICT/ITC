<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/auth_check.php';
// Verify admin auth
checkAdminAuth();

// auth_check.php does not open a DB connection; without this, $db is null here.
require_once dirname(__DIR__) . '/db/connect.php';

header('Content-Type: application/json');

$roleId = (int)($_GET['role_id'] ?? 0);
if ($roleId <= 0) {
    echo json_encode([]);
    exit;
}

$portalScopeRaw = trim((string)($_GET['portal_id'] ?? ''));
$portalId = null;
if ($portalScopeRaw !== '') {
    if (!ctype_digit($portalScopeRaw)) {
        echo json_encode([]);
        exit;
    }
    $portalId = (int)$portalScopeRaw;
    $portalStmt = $db->prepare("SELECT 1 FROM portals WHERE id = ? AND status = 'active' LIMIT 1");
    $portalStmt->bind_param('i', $portalId);
    $portalStmt->execute();
    $portalOk = $portalStmt->get_result()->num_rows === 1;
    $portalStmt->close();
    if (!$portalOk) {
        echo json_encode([]);
        exit;
    }
}

$permissions = [];
$sql = "SELECT permission_id
        FROM role_permissions
        WHERE role_id = ? AND status = 'active'";
$sql .= $portalId === null ? " AND portal_id IS NULL" : " AND portal_id = ?";
$stmt = $db->prepare($sql);
if ($stmt) {
    if ($portalId === null) {
        $stmt->bind_param('i', $roleId);
    } else {
        $stmt->bind_param('ii', $roleId, $portalId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $permissions[] = (int)$row['permission_id'];
    }
    $stmt->close();
}

echo json_encode($permissions);
exit;
?>
