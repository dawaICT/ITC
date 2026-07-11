<?php
// Seeds a test staff user with access to ALL roles (all entries in positions)
// Usage: php scripts/seed_all_roles_test_user.php

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/id_helpers.php';

if (php_sapi_name() !== 'cli') {
    echo "Run this script via CLI: php scripts/seed_all_roles_test_user.php\n";
}

$desiredId = 'auto';
$passwordPlain = 'P@ssw0rd1!'; // Meets policy: 8-30 chars, upper, lower, digit, special

// CLI overrides: --id=ITC### or --id=auto, --password=YourPass
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--id=') === 0) {
            $desiredId = substr($arg, 5);
        } elseif (strpos($arg, '--password=') === 0) {
            $passwordPlain = substr($arg, 11);
        }
    }
}

if ($desiredId === 'auto') {
    $desiredId = generateNextStaffId($db);
}

if (!validateStaffId($desiredId)) {
    fwrite(STDERR, "Invalid staff ID. Use ITC followed by 3 digits, e.g., ITC001.\n");
    exit(1);
}

if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[^A-Za-z0-9]).{8,30}$/', $passwordPlain)) {
    fwrite(STDERR, "Password does not meet complexity requirements.\n");
    exit(1);
}
$passwordHash = password_hash($passwordPlain, PASSWORD_BCRYPT);

// Create basic tables if missing (non-destructive)
$db->query("CREATE TABLE IF NOT EXISTS user_credentials (
    staff_id VARCHAR(64) PRIMARY KEY,
    pass VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS positions (
    PosID VARCHAR(20) PRIMARY KEY,
    PosName VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS staff_positions (
    staff_id VARCHAR(64) NOT NULL,
    PosID VARCHAR(20) NOT NULL,
    UNIQUE KEY uniq_staff_pos (staff_id, PosID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure staff exists (minimal required columns based on existing code paths)
$exists = $db->prepare('SELECT staff_id FROM staff WHERE staff_id=? LIMIT 1');
$exists->bind_param('s', $desiredId);
$exists->execute();
$exists->store_result();
$staffId = $desiredId;
if ($exists->num_rows === 0) {
    // Insert a staff record (columns allowed by admin/add_lecturer.php)
    $title = 'Mr.';
    $fname = 'Test';
    $lname = 'Superuser';
    $sex = 'M';
    $email = 'superuser@example.com';
    $insertStaff = $db->prepare("INSERT INTO staff (staff_id, title, Fname, Lname, sex, email) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$insertStaff) { die('Prepare staff failed: '.$db->error."\n"); }
    $insertStaff->bind_param('ssssss', $staffId, $title, $fname, $lname, $sex, $email);
    if (!$insertStaff->execute()) { die('Insert staff failed: '.$insertStaff->error."\n"); }
    $insertStaff->close();
}
$exists->close();

// Upsert credentials with bcrypt hash
$insCred = $db->prepare('INSERT INTO user_credentials (staff_id, pass) VALUES (?, ?) ON DUPLICATE KEY UPDATE pass=VALUES(pass)');
if (!$insCred) { die('Prepare creds failed: '.$db->error."\n"); }
$insCred->bind_param('ss', $staffId, $passwordHash);
if (!$insCred->execute()) { die('Insert creds failed: '.$insCred->error."\n"); }
$insCred->close();

// Assign ALL roles that exist in positions
$res = $db->query('SELECT PosID FROM positions');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $posId = $row['PosID'];
        $insRole = $db->prepare('INSERT IGNORE INTO staff_positions (staff_id, PosID) VALUES (?, ?)');
        $insRole->bind_param('ss', $staffId, $posId);
        $insRole->execute();
        $insRole->close();
    }
}

echo "Test user created/updated successfully.\n";
echo "User ID: {$staffId}\n";
echo "Password: {$passwordPlain}\n";
echo "Assigned to all roles in positions table.\n";

