<?php
declare(strict_types=1);
/**
 * One-time repair: revoke stale applicant portal grants for fully registered students.
 *
 * Run: C:\xampp\php\php.exe migrations/20260706_revoke_applicant_portal_for_students.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/portal_access.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$stmt = $db->prepare(
    "SELECT u.user_id, u.student_id
       FROM users u
      WHERE u.student_id IS NOT NULL
        AND u.student_id <> ''"
);
if (!$stmt) {
    fwrite(STDERR, "Unable to load student users.\n");
    exit(1);
}
$stmt->execute();
$res = $stmt->get_result();
$repaired = 0;
while ($row = $res->fetch_assoc()) {
    $userId = (int)($row['user_id'] ?? 0);
    if ($userId <= 0) {
        continue;
    }
    if (!wuc_user_is_fully_registered_student($db, $userId)) {
        continue;
    }
    wuc_sync_student_portal_access_after_registration($db, $userId);
    $repaired++;
    echo "Synced portal access for user_id={$userId} sid={$row['student_id']}\n";
}
$stmt->close();

echo "Repaired {$repaired} registered student account(s).\n";
