<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/internal_staff_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode([]);
    exit;
}

if (!wuc_is_internal_staff_user($db, $userId)) {
    http_response_code(403);
    echo json_encode([
        'error' => wuc_users_roles_block_message(),
    ]);
    exit;
}

$roles = [];
$internalRoles = wuc_internal_staff_role_names();
$placeholders = implode(',', array_fill(0, count($internalRoles), '?'));
$types = 'i' . str_repeat('s', count($internalRoles));

$sql = "SELECT r.role_id, r.role_label
          FROM user_roles ur
          JOIN roles r ON r.role_id = ur.role_id
         WHERE ur.user_id = ?
           AND ur.status = 'active'
           AND r.role_name IN ($placeholders)
         ORDER BY r.role_label ASC";

$stmt = $db->prepare($sql);
if ($stmt) {
    $params = array_merge([$userId], $internalRoles);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $roles[] = $row;
    }
    $stmt->close();
}

echo json_encode($roles);
exit;
