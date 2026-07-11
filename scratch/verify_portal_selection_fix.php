<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/portal_access.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== PORTAL SELECTION VERIFY ===\n\n";

$userId = 25;
$sid = 'CSE26456789';

echo "User {$userId} ({$sid})\n";
echo 'is_fully_registered_student: ' . (wuc_user_is_fully_registered_student($db, $userId) ? 'yes' : 'no') . "\n";
echo 'is_staff_account: ' . (wuc_user_is_staff_account($db, $userId) ? 'yes' : 'no') . "\n";
echo 'applicant_stage: ' . (wuc_user_applicant_stage($db, $userId) ?? 'null') . "\n";

$portals = wuc_user_active_portals($db, $userId);
echo 'active_portals: ' . implode(', ', array_column($portals, 'portal_code')) . "\n";
echo 'has applicant access: ' . (wuc_user_has_portal_access($db, $userId, 'applicant') ? 'yes' : 'no') . "\n";
echo 'has academic access: ' . (wuc_user_has_portal_access($db, $userId, 'academic') ? 'yes' : 'no') . "\n";
echo 'after_login_url kind=student: ' . wuc_after_login_portal_url($db, $userId, 'student') . "\n";

echo "\nDB grants for user {$userId}:\n";
$r = $db->query("SELECT p.portal_code, upa.access_status FROM user_portal_access upa JOIN portals p ON p.id=upa.portal_id WHERE upa.user_id={$userId}");
while ($row = $r->fetch_assoc()) {
    echo "  {$row['portal_code']}: {$row['access_status']}\n";
}

echo "\n=== Student with academic only (user 26) ===\n";
$uid = 26;
$portals = wuc_user_active_portals($db, $uid);
echo 'active_portals: ' . implode(', ', array_column($portals, 'portal_code')) . "\n";
echo 'after_login_url: ' . wuc_after_login_portal_url($db, $uid, 'student') . "\n";

echo "\nDone.\n";
