<?php
/**
 * Migration: create and seed the role_permissions table (RBAC).
 *
 * The whole permission system (includes/permissions.php hasPermission/getUserPermissions,
 * admin/user_role_mgmt.php, the grant_* scripts, library + e-learning guards) reads
 * role_permissions, but the table was never created in the live DB. Because
 * hasPermission() returns false whenever the table is missing, EVERY user is denied
 * access to permission-gated pages (e.g. User & Role Management).
 *
 * PosID is bigint(20) unsigned to match positions.PosID / staff_positions.PosID so
 * the JOINs in hasPermission() and user_role_mgmt.php work.
 *
 * Seed scope: admin roles only -> admin_all + manage_roles for
 *   Systems Admin (PosID 1) and Administrator (PosID 9).
 * Other role->permission mappings can be added through the now-working UI.
 *
 * Re-runnable: CREATE TABLE IF NOT EXISTS + INSERT ... ON DUPLICATE KEY UPDATE.
 *
 * Run:  E:\xampp\php\php.exe db\create_role_permissions_table.php
 */

require_once __DIR__ . '/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ok = true;

try {
    $db->query(<<<SQL
CREATE TABLE IF NOT EXISTS role_permissions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  PosID BIGINT(20) UNSIGNED NOT NULL,
  permission_name VARCHAR(100) NOT NULL,
  permission_description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_pos_perm (PosID, permission_name),
  KEY idx_perm_name (permission_name),
  KEY idx_pos (PosID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "[OK]   role_permissions table\n";
} catch (Throwable $e) {
    echo "[FAIL] role_permissions table -> " . $e->getMessage() . "\n";
    $ok = false;
}

// Seed admin roles only. PosID 1 = Systems Admin, PosID 9 = Administrator.
$seed = [
    [1, 'admin_all',    'Full system access'],
    [1, 'manage_roles', 'Create, assign and remove user roles'],
    [9, 'admin_all',    'Full system access'],
    [9, 'manage_roles', 'Create, assign and remove user roles'],
];

if ($ok) {
    try {
        $stmt = $db->prepare("INSERT INTO role_permissions (PosID, permission_name, permission_description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permission_description=VALUES(permission_description)");
        foreach ($seed as $row) {
            $stmt->bind_param('iss', $row[0], $row[1], $row[2]);
            $stmt->execute();
            echo "[SEED] PosID {$row[0]} -> {$row[1]}\n";
        }
        $stmt->close();
    } catch (Throwable $e) {
        echo "[FAIL] seeding -> " . $e->getMessage() . "\n";
        $ok = false;
    }
}

echo $ok ? "\nMigration complete.\n" : "\nMigration finished with errors.\n";
exit($ok ? 0 : 1);
