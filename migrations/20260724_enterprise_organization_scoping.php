<?php
declare(strict_types=1);

/**
 * Add organization_id to agriculture tables + organizations.manage permission.
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

function ep_org2_column_exists(mysqli $db, string $table, string $column): bool
{
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW COLUMNS FROM `{$safe}` LIKE '" . $db->real_escape_string($column) . "'");
    $ok = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $ok;
}

$db->begin_transaction();
try {
    $orgId = 0;
    $r = $db->query("SELECT id FROM enterprise_organizations WHERE org_code = 'DEFAULT' LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) {
        $orgId = (int)$row['id'];
    }

    foreach (['enterprise_produce_listings', 'enterprise_buyer_crop_demands'] as $table) {
        $res = @$db->query("SHOW TABLES LIKE '{$table}'");
        if (!$res || $res->num_rows === 0) {
            continue;
        }
        if (!ep_org2_column_exists($db, $table, 'organization_id')) {
            if (!$db->query("ALTER TABLE `{$table}` ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER id, ADD KEY idx_{$table}_org (organization_id)")) {
                throw new RuntimeException($db->error);
            }
            if ($orgId > 0) {
                $db->query("UPDATE `{$table}` SET organization_id = {$orgId} WHERE organization_id IS NULL");
            }
        }
    }

    $stmt = $db->prepare('INSERT INTO permissions (permission_key, permission_label, description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permission_label=VALUES(permission_label)');
    if ($stmt) {
        $k = 'enterprise.organizations.manage';
        $l = 'Manage organizations';
        $d = 'Create organizations and assign officers to workspaces';
        $stmt->bind_param('sss', $k, $l, $d);
        $stmt->execute();
        $stmt->close();
    }

    $db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id)
        SELECT r.role_id, p.permission_id FROM roles r
        CROSS JOIN permissions p
        WHERE r.role_name IN ('systems_admin','registrar') AND p.permission_key = 'enterprise.organizations.manage'");

    $db->commit();
    echo "OK: Organization scoping columns and permissions migrated.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
