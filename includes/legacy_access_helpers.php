<?php
declare(strict_types=1);

/**
 * Legacy access-control helpers bridging staff_positions/positions to RBAC tables.
 *
 * Pre-migration code joined role_permissions.PosID and selected permission_name.
 * The live schema uses role_id + permission_id instead.
 */

require_once __DIR__ . '/staff_role_helpers.php';
require_once __DIR__ . '/schema_guard.php';

if (!function_exists('wuc_staff_permission_labels')) {
    function wuc_staff_permission_labels(mysqli $db, string $staffId): string
    {
        $staffId = trim($staffId);
        if ($staffId === '') {
            return '';
        }

        $labels = [];

        if (wuc_table_exists($db, 'users') && wuc_table_exists($db, 'user_roles') && wuc_table_exists($db, 'role_permissions') && wuc_table_exists($db, 'permissions')) {
            $stmt = $db->prepare(
                'SELECT DISTINCT perm.permission_label
                   FROM users u
                   INNER JOIN user_roles ur ON ur.user_id = u.user_id AND ur.status = "active"
                   INNER JOIN role_permissions rp ON rp.role_id = ur.role_id AND (rp.status IS NULL OR rp.status = "active")
                   INNER JOIN permissions perm ON perm.permission_id = rp.permission_id AND perm.status = "active"
                  WHERE u.staff_id = ?
                  ORDER BY perm.permission_label'
            );
            if ($stmt) {
                $stmt->bind_param('s', $staffId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $labels[] = (string)$row['permission_label'];
                }
                $stmt->close();
            }
        }

        if (!$labels && wuc_table_exists($db, 'staff_positions') && wuc_table_exists($db, 'positions') && wuc_table_exists($db, 'roles')) {
            $spStmt = $db->prepare(
                'SELECT DISTINCT p.PosName
                   FROM staff_positions sp
                   INNER JOIN positions p ON p.PosID = sp.PosID
                  WHERE sp.staff_id = ?'
            );
            if ($spStmt) {
                $spStmt->bind_param('s', $staffId);
                $spStmt->execute();
                $spRes = $spStmt->get_result();
                while ($spRow = $spRes->fetch_assoc()) {
                    $canonical = wuc_normalize_staff_role((string)($spRow['PosName'] ?? ''));
                    $rStmt = $db->prepare(
                        'SELECT DISTINCT perm.permission_label
                           FROM roles r
                           INNER JOIN role_permissions rp ON rp.role_id = r.role_id AND (rp.status IS NULL OR rp.status = "active")
                           INNER JOIN permissions perm ON perm.permission_id = rp.permission_id AND perm.status = "active"
                          WHERE r.role_name = ? AND r.status = "active"
                          ORDER BY perm.permission_label'
                    );
                    if ($rStmt) {
                        $rStmt->bind_param('s', $canonical);
                        $rStmt->execute();
                        $rRes = $rStmt->get_result();
                        while ($pRow = $rRes->fetch_assoc()) {
                            $labels[] = (string)$pRow['permission_label'];
                        }
                        $rStmt->close();
                    }
                }
                $spStmt->close();
            }
        }

        return implode(', ', array_values(array_unique($labels)));
    }
}

if (!function_exists('wuc_fetch_rbac_role_permission_matrix')) {
    /**
     * @return list<array{PosName:string,permission_name:string,permission_description:string}>
     */
    function wuc_fetch_rbac_role_permission_matrix(mysqli $db): array
    {
        if (!wuc_table_exists($db, 'role_permissions') || !wuc_table_exists($db, 'roles') || !wuc_table_exists($db, 'permissions')) {
            return [];
        }

        $rows = [];
        $sql = 'SELECT r.role_label AS PosName,
                       perm.permission_label AS permission_name,
                       perm.description AS permission_description
                  FROM role_permissions rp
                  INNER JOIN roles r ON r.role_id = rp.role_id AND r.status = "active"
                  INNER JOIN permissions perm ON perm.permission_id = rp.permission_id AND perm.status = "active"
                 WHERE rp.status IS NULL OR rp.status = "active"
                 ORDER BY r.role_label ASC, perm.permission_label ASC';
        $res = $db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = [
                    'PosName' => (string)($row['PosName'] ?? ''),
                    'permission_name' => (string)($row['permission_name'] ?? ''),
                    'permission_description' => (string)($row['permission_description'] ?? ''),
                ];
            }
            $res->free();
        }

        return $rows;
    }
}

if (!function_exists('define_access_permissions_for_staff')) {
    function define_access_permissions_for_staff(mysqli $db, string $staffId): string
    {
        return wuc_staff_permission_labels($db, $staffId);
    }
}
