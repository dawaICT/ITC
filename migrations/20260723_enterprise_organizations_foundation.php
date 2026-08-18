<?php
declare(strict_types=1);

/**
 * Multi-organization foundation for Skills and Enterprise Network (Stage 2).
 * Additive only. Integrated WUCPortal uses DEFAULT org until more tenants onboard.
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

function ep_org_exec(mysqli $db, string $sql): void
{
    if (!$db->query($sql)) {
        throw new RuntimeException('SQL failed: ' . $db->error);
    }
}

function ep_org_column_exists(mysqli $db, string $table, string $column): bool
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
    ep_org_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_organizations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            org_code VARCHAR(40) NOT NULL,
            org_name VARCHAR(200) NOT NULL,
            org_type VARCHAR(40) NOT NULL,
            province VARCHAR(80) NULL,
            district VARCHAR(80) NULL,
            contact_email VARCHAR(160) NULL,
            contact_phone VARCHAR(20) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'active',
            settings_json JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_org_code (org_code),
            KEY idx_ep_org_type (org_type),
            KEY idx_ep_org_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_org_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_organization_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            organization_id BIGINT UNSIGNED NOT NULL,
            user_id INT NOT NULL,
            role_in_org VARCHAR(60) NOT NULL DEFAULT 'member',
            status VARCHAR(40) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_org_user (organization_id, user_id),
            KEY idx_ep_org_users_user (user_id),
            CONSTRAINT fk_ep_org_users_org FOREIGN KEY (organization_id) REFERENCES enterprise_organizations(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("INSERT INTO enterprise_organizations (org_code, org_name, org_type, status)
        VALUES ('DEFAULT', 'Host Organization', 'platform_operator', 'active')
        ON DUPLICATE KEY UPDATE org_name = VALUES(org_name)");

    $orgId = 0;
    $r = $db->query("SELECT id FROM enterprise_organizations WHERE org_code = 'DEFAULT' LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) {
        $orgId = (int)$row['id'];
    }

    if ($orgId > 0) {
        $db->query("INSERT INTO enterprise_portal_settings (setting_key, setting_value)
            VALUES ('primary_organization_id', '{$orgId}')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    }

    foreach ([
        'enterprise_memberships' => 'organization_id',
        'enterprise_farmer_profiles' => 'organization_id',
        'enterprise_opportunities' => 'organization_id',
    ] as $table => $col) {
        $res = @$db->query("SHOW TABLES LIKE '{$table}'");
        if ($res && $res->num_rows > 0 && !ep_org_column_exists($db, $table, $col)) {
            ep_org_exec($db, "ALTER TABLE `{$table}` ADD COLUMN `{$col}` BIGINT UNSIGNED NULL AFTER id, ADD KEY idx_{$table}_org ({$col})");
            if ($orgId > 0) {
                $db->query("UPDATE `{$table}` SET {$col} = {$orgId} WHERE {$col} IS NULL");
            }
        }
    }

    $db->commit();
    echo "OK: Organization foundation migrated.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
