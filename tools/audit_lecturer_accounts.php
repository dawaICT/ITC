<?php
declare(strict_types=1);
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/staff_role_helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$compare = ['ITC900', 'ITC907'];
$res = $db->query("SELECT staff_id, role, status FROM staff WHERE role IN ('lecturer','staff') AND status = 'active' ORDER BY staff_id");
while ($row = $res->fetch_assoc()) {
    $compare[] = (string)$row['staff_id'];
}
$compare = array_values(array_unique($compare));

foreach ($compare as $sid) {
    echo "\n=== {$sid} ===\n";
    $stmt = $db->prepare('SELECT user_id, primary_role, status FROM users WHERE staff_id = ? LIMIT 1');
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo 'users: ' . json_encode($user) . "\n";

    $uid = (int)($user['user_id'] ?? 0);
    if ($uid > 0) {
        $roles = getUserRoles($uid);
        echo 'user_roles: ' . json_encode(array_column($roles, 'role_name')) . "\n";
        $mods = getUserModules($uid);
        echo 'modules: ' . json_encode(array_values(array_unique($mods))) . "\n";
    }

    $resolved = wuc_resolve_staff_roles($db, $sid);
    echo 'resolved: ' . json_encode($resolved) . "\n";

    $stmt = $db->prepare('SELECT COUNT(*) c FROM staff_positions WHERE staff_id = ?');
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    echo 'staff_positions: ' . (int)$stmt->get_result()->fetch_assoc()['c'] . "\n";
    $stmt->close();

    $stmt = $db->prepare('SELECT COUNT(DISTINCT course_code) c FROM course_lecturer WHERE staff_id = ? AND status <> "inactive"');
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    echo 'course_lecturer: ' . (int)$stmt->get_result()->fetch_assoc()['c'] . "\n";
    $stmt->close();
}
