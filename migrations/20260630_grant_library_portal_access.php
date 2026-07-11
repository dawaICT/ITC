<?php
/**
 * Phase 2b (Multi-Portal Redesign) — light up the Library portal.
 *
 * The `library` portal existed in `portals` but had ZERO rows in
 * `user_portal_access`, so once library/index.php adopts
 * wuc_require_portal_access('library') nobody could enter. This seed grants
 * library portal access to exactly the users who already hold library access
 * today: every systems_admin and librarian, plus anyone whose role carries a
 * library-module permission. Idempotent (wuc_grant_user_portal_access uses
 * INSERT ... ON DUPLICATE KEY UPDATE).
 *
 * Run:  php migrations/20260630_grant_library_portal_access.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/portal_access.php';

/** @var mysqli $db */

$sql = "
    SELECT DISTINCT u.user_id, u.username
      FROM users u
      JOIN user_roles ur ON ur.user_id = u.user_id AND ur.status = 'active'
      JOIN roles r       ON r.role_id = ur.role_id AND r.status = 'active'
     WHERE r.role_name IN ('systems_admin', 'librarian')
        OR EXISTS (
            SELECT 1
              FROM role_permissions rp
              JOIN modules m ON m.module_id = rp.module_id
             WHERE rp.role_id = ur.role_id
               AND m.module_key = 'library'
               AND rp.status = 'active'
        )
     ORDER BY u.user_id";

$res = $db->query($sql);
$granted = 0;
while ($res && ($row = $res->fetch_assoc())) {
    $userId = (int)$row['user_id'];
    wuc_grant_user_portal_access($db, $userId, ['library'], 'phase2b_migration');
    echo "  granted library portal access to user {$userId} ({$row['username']})\n";
    $granted++;
}
if ($res) { $res->free(); }

echo "Done. Ensured library portal access for {$granted} user(s).\n";
