<?php
declare(strict_types=1);

/**
 * Grant Skills and Enterprise Portal permissions to roles by role_name (not hard-coded IDs).
 * Also ensures module enterprise_portal exists.
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

function ep_rbac_table_exists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    $ok = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $ok;
}

$db->begin_transaction();
try {
    if (!ep_rbac_table_exists($db, 'permissions') || !ep_rbac_table_exists($db, 'roles') || !ep_rbac_table_exists($db, 'role_permissions')) {
        throw new RuntimeException('RBAC tables missing.');
    }

    $moduleId = 0;
    if (ep_rbac_table_exists($db, 'modules')) {
        $mk = 'enterprise_portal';
        $mn = 'Skills and Enterprise Portal';
        $chk = $db->prepare('SELECT module_id FROM modules WHERE module_key = ? LIMIT 1');
        $chk->bind_param('s', $mk);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($row) {
            $moduleId = (int)$row['module_id'];
        } else {
            $ins = $db->prepare("INSERT INTO modules (module_name, module_key, module_url, status, display_order) VALUES (?, ?, '/enterprise/index.php', 'active', 90)");
            $ins->bind_param('ss', $mn, $mk);
            $ins->execute();
            $moduleId = (int)$db->insert_id;
            $ins->close();
        }
    }

    $reviewPerms = [
        'enterprise.review.access',
        'enterprise.review.request_changes',
        'enterprise.review.verify',
        'enterprise.review.reject',
        'enterprise.review.reassign',
    ];
    $managePerms = [
        'enterprise.memberships.manage',
        'enterprise.approve',
        'enterprise.publish',
        'enterprise.unpublish',
        'enterprise.feature',
        'enterprise.interests.manage',
        'enterprise.leads.assign',
        'enterprise.outcomes.manage',
        'enterprise.reports.view',
        'enterprise.settings.manage',
        'enterprise.audit.view',
    ];
    $allPortal = array_values(array_unique(array_merge(
        [
            'enterprise.portal.join',
            'enterprise.portal.access',
            'enterprise.portal.withdraw',
            'enterprise.profile.manage_own',
            'enterprise.skills.manage_own',
            'enterprise.opportunity.create',
            'enterprise.opportunity.edit_own',
            'enterprise.opportunity.submit',
            'enterprise.opportunity.archive_own',
            'enterprise.interests.view_own',
        ],
        $reviewPerms,
        $managePerms
    )));

    // Ensure permission rows exist
    $pIns = $db->prepare('INSERT INTO permissions (permission_key, permission_label, description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permission_label=VALUES(permission_label)');
    foreach ($allPortal as $key) {
        $label = ucwords(str_replace(['enterprise.', '_', '.'], ['', ' ', ' '], $key));
        $pIns->bind_param('sss', $key, $label, $label);
        $pIns->execute();
    }
    $pIns->close();

    $roleGrants = [
        'systems_admin' => $allPortal,
        'lecturer' => $reviewPerms,
        'head_of_department' => array_values(array_unique(array_merge($reviewPerms, $managePerms))),
        'dean' => array_values(array_unique(array_merge($reviewPerms, $managePerms))),
        'registrar' => $managePerms,
    ];

    $hasStatus = false;
    $colRes = $db->query("SHOW COLUMNS FROM role_permissions LIKE 'status'");
    if ($colRes && $colRes->num_rows > 0) {
        $hasStatus = true;
    }
    if ($colRes) {
        $colRes->free();
    }

    $granted = 0;
    foreach ($roleGrants as $roleName => $keys) {
        $rStmt = $db->prepare('SELECT role_id FROM roles WHERE role_name = ? LIMIT 1');
        $rStmt->bind_param('s', $roleName);
        $rStmt->execute();
        $rRow = $rStmt->get_result()->fetch_assoc();
        $rStmt->close();
        if (!$rRow) {
            echo "Skip missing role: {$roleName}\n";
            continue;
        }
        $roleId = (int)$rRow['role_id'];
        foreach ($keys as $pk) {
            $pidStmt = $db->prepare('SELECT permission_id FROM permissions WHERE permission_key = ? LIMIT 1');
            $pidStmt->bind_param('s', $pk);
            $pidStmt->execute();
            $pidRow = $pidStmt->get_result()->fetch_assoc();
            $pidStmt->close();
            if (!$pidRow) {
                continue;
            }
            $permissionId = (int)$pidRow['permission_id'];

            // Avoid duplicates without relying on unique key shape
            $exists = $db->prepare('SELECT id FROM role_permissions WHERE role_id = ? AND permission_id = ? LIMIT 1');
            $exists->bind_param('ii', $roleId, $permissionId);
            $exists->execute();
            $ex = $exists->get_result()->fetch_assoc();
            $exists->close();
            if ($ex) {
                if ($hasStatus) {
                    $upd = $db->prepare("UPDATE role_permissions SET status = 'active' WHERE id = ?");
                    $id = (int)$ex['id'];
                    $upd->bind_param('i', $id);
                    $upd->execute();
                    $upd->close();
                }
                continue;
            }

            if ($moduleId > 0 && $hasStatus) {
                $ins = $db->prepare("INSERT INTO role_permissions (role_id, module_id, permission_id, status) VALUES (?,?,?,'active')");
                $ins->bind_param('iii', $roleId, $moduleId, $permissionId);
            } elseif ($moduleId > 0) {
                $ins = $db->prepare('INSERT INTO role_permissions (role_id, module_id, permission_id) VALUES (?,?,?)');
                $ins->bind_param('iii', $roleId, $moduleId, $permissionId);
            } elseif ($hasStatus) {
                $ins = $db->prepare("INSERT INTO role_permissions (role_id, permission_id, status) VALUES (?,?,'active')");
                $ins->bind_param('ii', $roleId, $permissionId);
            } else {
                $ins = $db->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)');
                $ins->bind_param('ii', $roleId, $permissionId);
            }
            $ins->execute();
            $ins->close();
            $granted++;
        }
        echo "Granted enterprise_portal permissions to role: {$roleName}\n";
    }

    $db->commit();
    echo "enterprise_portal RBAC migration completed. New grants inserted: {$granted}\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
