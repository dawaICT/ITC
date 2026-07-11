<?php
require_once __DIR__ . '/../db/connect.php';

$user = $argv[1] ?? '';
$plain = $argv[2] ?? '';

if ($user === '' || $plain === '') {
    echo "Usage: php scripts/force_update_password.php <STAFF_ID> <NEW_PASSWORD>\n";
    exit(1);
}

if (!preg_match('/^ITC\d{3}$/', $user)) {
    echo "Staff ID must be ITC followed by 3 digits (e.g., ITC001). Given: {$user}\n";
    exit(1);
}

if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,30}$/', $plain)) {
    echo "Password does not meet complexity requirements.\n";
    exit(1);
}

$hash = password_hash($plain, PASSWORD_BCRYPT);
$upd = $db->prepare('UPDATE user_credentials SET pass=? WHERE staff_id=?');
if (!$upd) { echo 'Prepare failed: '.$db->error."\n"; exit(1); }
$upd->bind_param('ss', $hash, $user);
if (!$upd->execute()) { echo 'Update failed: '.$upd->error."\n"; exit(1); }
echo "Updated password for {$user}.\nHash: {$hash}\n";


