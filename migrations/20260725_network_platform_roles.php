<?php
declare(strict_types=1);

/**
 * Platform admin + public role permissions for Skills and Enterprise Network.
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$db->begin_transaction();
try {
    $perms = [
        ['platform.admin', 'Platform administrator', 'Full Skills and Enterprise Network admin console (separate from WUC academic admin)'],
        ['platform.support', 'Platform support', 'Support and read-only platform operations'],
        ['network.member.access', 'Network member access', 'Signed-in participant access to the network app workspace'],
        ['network.public.browse', 'Public directory browse', 'Anonymous public directory (informational RBAC key)'],
    ];
    $stmt = $db->prepare('INSERT INTO permissions (permission_key, permission_label, description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permission_label=VALUES(permission_label), description=VALUES(description)');
    foreach ($perms as [$k, $l, $d]) {
        if ($stmt) {
            $stmt->bind_param('sss', $k, $l, $d);
            $stmt->execute();
        }
    }
    if ($stmt) {
        $stmt->close();
    }

    $db->query("INSERT INTO roles (role_name, role_label) VALUES ('platform_admin', 'Skills and Enterprise Network platform administrator')
        ON DUPLICATE KEY UPDATE role_label=VALUES(role_label)");

    $db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id)
        SELECT r.role_id, p.permission_id FROM roles r
        CROSS JOIN permissions p
        WHERE r.role_name = 'platform_admin' AND p.permission_key IN ('platform.admin','platform.support','network.member.access','enterprise.organizations.manage')");

    $db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id)
        SELECT r.role_id, p.permission_id FROM roles r
        CROSS JOIN permissions p
        WHERE r.role_name = 'systems_admin' AND p.permission_key IN ('platform.admin','platform.support')");

    $db->commit();
    echo "OK: Network platform roles migrated.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
