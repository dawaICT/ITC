<?php
/**
 * Fix Access Rights Data Issues
 * Run from CLI: php fix_access_rights.php
 */
require_once 'db/connect.php';

echo "\n=== FIXING ACCESS RIGHTS DATA ===\n\n";

// 1. Fix empty UserID - set it to match staff_id
echo "1. Fixing empty UserID records...\n";
$result = $db->query("SELECT id, staff_id, UserID FROM access_right WHERE UserID = '' OR UserID IS NULL");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   Fixing ID {$row['id']}: staff_id={$row['staff_id']}, setting UserID to staff_id...\n";
        $stmt = $db->prepare("UPDATE access_right SET UserID = ? WHERE id = ?");
        $stmt->bind_param('si', $row['staff_id'], $row['id']);
        if ($stmt->execute()) {
            echo "   ✓ Updated successfully\n";
        } else {
            echo "   ✗ Error: " . $stmt->error . "\n";
        }
        $stmt->close();
    }
} else {
    echo "   No records with empty UserID.\n";
}

// 2. Normalize role names (fix 'System Administrator' -> 'Systems Admin')
echo "\n2. Normalizing role names...\n";
$normalizations = [
    'System Administrator' => 'Systems Admin',
    'system administrator' => 'Systems Admin',
    'SuperAdmin' => 'Systems Admin',
    'superadmin' => 'Systems Admin',
];

foreach ($normalizations as $old => $new) {
    $stmt = $db->prepare("UPDATE access_right SET assigned_access = ? WHERE assigned_access = ?");
    $stmt->bind_param('ss', $new, $old);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo "   ✓ Normalized '$old' to '$new' ({$stmt->affected_rows} records)\n";
        }
    }
    $stmt->close();
}

// 3. Show updated records
echo "\n3. Updated access_right records:\n";
echo str_repeat("-", 90) . "\n";
$result = $db->query("SELECT id, staff_id, assigned_access, UserID, pass FROM access_right ORDER BY id");
if ($result && $result->num_rows > 0) {
    printf("%-5s | %-12s | %-25s | %-15s | %-10s\n", "ID", "staff_id", "assigned_access", "UserID", "pass?");
    echo str_repeat("-", 90) . "\n";
    while ($row = $result->fetch_assoc()) {
        printf("%-5s | %-12s | %-25s | %-15s | %-10s\n", 
            $row['id'], 
            $row['staff_id'], 
            $row['assigned_access'],
            $row['UserID'] ?: '(empty)',
            !empty($row['pass']) ? 'yes' : 'no'
        );
    }
}

// 4. Check user_credentials table for these staff
echo "\n4. Checking user_credentials for access_right users:\n";
echo str_repeat("-", 70) . "\n";
$result = $db->query("SELECT ar.staff_id, ar.assigned_access, uc.pass IS NOT NULL as has_credential 
                      FROM access_right ar 
                      LEFT JOIN user_credentials uc ON ar.staff_id = uc.staff_id");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $status = $row['has_credential'] ? '✓ Has credentials' : '✗ NO credentials';
        printf("   %s (%s): %s\n", $row['staff_id'], $row['assigned_access'], $status);
    }
}

echo "\n=== FIX COMPLETE ===\n";
echo "\nNOTE: Staff without user_credentials cannot log in via staffLogin.php.\n";
echo "Use grant_access.php to create login credentials for staff members.\n";
