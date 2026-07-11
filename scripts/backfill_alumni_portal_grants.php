<?php
/**
 * 2026-07-06 — Backfill alumni portal grants for students whose graduation
 * clearance is already Approved/Graduated (the automatic grant in
 * campus_services_admin.php only fires on new clearance saves).
 *
 * DML only (user_portal_access inserts), so the app connection is fine.
 * Idempotent: wuc_grant_user_portal_access upserts on (user_id, portal_id).
 * Run: php scripts/backfill_alumni_portal_grants.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
define('IS_SCRIPT', true);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/alumni_access_helpers.php';

$res = $db->query("SELECT student_id, graduation_status FROM student_clearance
                   WHERE graduation_status IN ('Approved','Graduated')");
$granted = 0;
$noAccount = 0;
while ($row = $res->fetch_assoc()) {
    $sid = (string)$row['student_id'];
    $check = $db->prepare('SELECT user_id FROM users WHERE student_id = ? LIMIT 1');
    $check->bind_param('s', $sid);
    $check->execute();
    $hasAccount = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$hasAccount) {
        echo "skip  $sid — no login account (users row missing)\n";
        $noAccount++;
        continue;
    }
    wuc_sync_alumni_portal_access($db, $sid, (string)$row['graduation_status']);
    echo "grant $sid ({$row['graduation_status']})\n";
    $granted++;
}
echo "\nDone: $granted granted/refreshed, $noAccount without accounts.\n";
