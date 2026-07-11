<?php
require_once "includes/admin.php";
require_once "../includes/permissions.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: defineAccess.php");
    exit();
}

// Support JSON API for templates/bulk updates
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $payload = json_decode(file_get_contents('php://input'), true);
    $action = $payload['action'] ?? '';
    header('Content-Type: application/json');
    if ($action === 'apply_template') {
        $posId = $payload['PosID'] ?? '';
        if ($posId === '') { echo json_encode(['error'=>'invalid']); exit; }
        // For demo, no-op success
        echo json_encode(['message'=>'Template applied']);
        exit;
    }
    if ($action === 'apply_permissions_bulk') {
        $perms = $payload['permissions'] ?? [];
        $insert = $db->prepare("INSERT INTO role_permissions (PosID, permission_name, permission_description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permission_description=VALUES(permission_description)");
        foreach ($perms as $p) {
            $PosID = $p['PosID'] ?? '';
            $name = $p['permission_name'] ?? '';
            $desc = $p['permission_description'] ?? '';
            if ($PosID && $name) { $insert->bind_param('sss', $PosID, $name, $desc); $insert->execute(); }
        }
        echo json_encode(['message'=>'Permissions applied']);
        exit;
    }
    echo json_encode(['error'=>'unknown']);
    exit;
}

$role_id = trim($_POST['role_id']);
$permissions = isset($_POST['permissions']) ? $_POST['permissions'] : array();
$new_permission = trim($_POST['new_permission']);
$permission_description = trim($_POST['permission_description']);

// Start transaction
$db->begin_transaction();

try {
    // First, delete all existing permissions for this role
    $delete = $db->prepare("DELETE FROM role_permissions WHERE PosID = ?");
    $delete->bind_param("s", $role_id);
    $delete->execute();

    // Re-add selected permissions using provided names; look up description if exists; otherwise insert with empty description
    if (!empty($permissions)) {
        $lookup = $db->prepare("SELECT permission_name, permission_description FROM role_permissions WHERE permission_name = ? LIMIT 1");
        $insert = $db->prepare("INSERT INTO role_permissions (PosID, permission_name, permission_description) VALUES (?,?,?)");
        foreach ($permissions as $permission) {
            $desc = '';
            $lookup->bind_param('s', $permission);
            if ($lookup->execute() && ($res = $lookup->get_result()) && $res->num_rows) {
                $desc = $res->fetch_assoc()['permission_description'] ?? '';
            }
            $insert->bind_param("sss", $role_id, $permission, $desc);
            $insert->execute();
        }
    }

    // Add new permission if provided
    if (!empty($new_permission)) {
        $insert_new = $db->prepare("INSERT INTO role_permissions (PosID, permission_name, permission_description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE permission_description=VALUES(permission_description)");
        $insert_new->bind_param("sss", $role_id, $new_permission, $permission_description);
        $insert_new->execute();
    }

    // Commit transaction
    $db->commit();
    $_SESSION['success_message'] = "Permissions updated successfully!";

} catch (Exception $e) {
    // Rollback on error
    $db->rollback();
    $_SESSION['error_message'] = "Error updating permissions: " . $e->getMessage();
}

header("Location: defineAccess.php");
exit();