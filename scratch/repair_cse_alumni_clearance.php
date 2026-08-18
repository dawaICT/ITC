<?php
/**
 * One-shot repair: CSE26456789 was incorrectly marked Graduated while still
 * an active enrolled student, which left an alumni portal grant visible on
 * portal_selection.php.
 *
 * Run: php scratch/repair_cse_alumni_clearance.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/alumni_access_helpers.php';

$sid = 'CSE26456789';

$before = $db->prepare('SELECT graduation_status, admin_cleared FROM student_clearance WHERE student_id = ? LIMIT 1');
$before->bind_param('s', $sid);
$before->execute();
$row = $before->get_result()->fetch_assoc();
$before->close();
echo 'Before clearance: ' . json_encode($row) . PHP_EOL;

$upd = $db->prepare(
    "UPDATE student_clearance
        SET graduation_status = 'Not Eligible',
            admin_cleared = 0,
            updated_at = NOW()
      WHERE student_id = ?"
);
$upd->bind_param('s', $sid);
$upd->execute();
echo 'Clearance rows updated: ' . $upd->affected_rows . PHP_EOL;
$upd->close();

wuc_sync_alumni_portal_access($db, $sid, 'Not Eligible');
echo "Alumni grant sync invoked with status Not Eligible\n";

require_once dirname(__DIR__) . '/includes/portal_access.php';
$userId = 0;
$u = $db->prepare('SELECT user_id FROM users WHERE student_id = ? LIMIT 1');
$u->bind_param('s', $sid);
$u->execute();
$u->bind_result($userId);
$u->fetch();
$u->close();

$portals = wuc_user_active_portals($db, (int)$userId);
echo 'Active portals after repair: ' . implode(', ', array_column($portals, 'portal_code')) . PHP_EOL;
echo 'Alumni eligible: ' . (wuc_student_is_alumni_eligible($db, $sid) ? 'yes' : 'no') . PHP_EOL;
