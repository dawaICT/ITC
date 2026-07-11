<?php
/**
 * CLI-ONLY break-glass tool: grant a staff account full Super Admin (RBAC) access.
 *
 * SECURITY: This is a privilege-escalation tool and is intentionally restricted to
 * the command line so it can NEVER be triggered over HTTP. Run it from the server
 * console (where filesystem/shell access is the authorization), e.g.:
 *
 *     php admin/scripts/grant_me_superadmin.php <staff_id>
 *
 * Previously this was an HTTP endpoint whose permission guard was an empty no-op,
 * letting ANY logged-in staff member elevate themselves to Super Admin.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// The target account is supplied explicitly on the command line (there is no
// session in CLI), so this tool can only ever elevate a deliberately named user.
$me = isset($argv[1]) ? trim((string) $argv[1]) : '';
if ($me === '') {
    fwrite(STDERR, 'Usage: php ' . basename(__FILE__) . " <staff_id>\n");
    exit(1);
}

try {
    // Ensure the RBAC permission store exists (PosID matches positions.PosID = bigint unsigned)
    $db->query("CREATE TABLE IF NOT EXISTS role_permissions (
        id BIGINT NOT NULL AUTO_INCREMENT,
        PosID BIGINT(20) UNSIGNED NOT NULL,
        permission_name VARCHAR(100) NOT NULL,
        permission_description VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_pos_perm (PosID, permission_name),
        KEY idx_perm_name (permission_name),
        KEY idx_pos (PosID)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Resolve the admin position by name (prefer Administrator, else Systems Admin).
    // PosID is an auto_increment bigint, so never insert a string id like 'ADM009'.
    $posId = null;
    $res = $db->query("SELECT PosID FROM positions WHERE PosName IN ('Administrator','Systems Admin') ORDER BY (PosName='Administrator') DESC LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) { $posId = (int)$row['PosID']; }
    if ($posId === null) {
        $db->query("INSERT INTO positions (PosName) VALUES ('Administrator')");
        $posId = (int)$db->insert_id;
    }

    $db->begin_transaction();

    // Assign the admin position to the target user
    $stmt = $db->prepare("INSERT IGNORE INTO staff_positions (staff_id, PosID) VALUES (?, ?)");
    $stmt->bind_param('si', $me, $posId);
    $stmt->execute();
    $stmt->close();

    // Grant full permissions to the admin position (idempotent)
    $perms = [
        ['admin_all', 'Full system access'],
        ['manage_roles', 'Create, assign and remove user roles'],
        ['library_manage', 'Full library administration'],
        ['library_catalog', 'Add and maintain catalog records'],
        ['library_circulation', 'Checkout, returns, reservations'],
        ['library_fines', 'Assess and settle fines'],
        ['library_digital', 'Manage digital resources'],
    ];
    $stmt2 = $db->prepare("INSERT INTO role_permissions (PosID, permission_name, permission_description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE permission_description=VALUES(permission_description)");
    foreach ($perms as $p) {
        $stmt2->bind_param('iss', $posId, $p[0], $p[1]);
        $stmt2->execute();
    }
    $stmt2->close();

    $db->commit();
    fwrite(STDOUT, "Granted Super Admin access to staff_id={$me} (PosID={$posId}).\n");
} catch (Throwable $e) {
    if ($db->errno) { @$db->rollback(); }
    fwrite(STDERR, 'Failed to elevate: ' . $e->getMessage() . "\n");
    exit(1);
}
