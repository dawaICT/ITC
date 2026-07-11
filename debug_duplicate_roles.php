<?php
/**
 * Debug Duplicate Roles
 * Run from CLI: php debug_duplicate_roles.php
 */
require_once 'db/connect.php';

echo "\n=== DUPLICATE ROLES DEBUG ===\n\n";

// 1. Duplicate staff_id in access_right
echo "1. DUPLICATE staff_id IN access_right:\n";
echo str_repeat("-", 70) . "\n";
$result = $db->query("SELECT staff_id, COUNT(*) as cnt FROM access_right GROUP BY staff_id HAVING cnt > 1");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   {$row['staff_id']} appears {$row['cnt']} times\n";
        
        // Show the duplicate entries
        $detail = $db->query("SELECT id, staff_id, assigned_access, UserID FROM access_right WHERE staff_id = '{$row['staff_id']}'");
        while ($d = $detail->fetch_assoc()) {
            printf("      ID: %-5s | UserID: %-12s | Role: %s\n", $d['id'], $d['UserID'], $d['assigned_access']);
        }
        echo "\n";
    }
} else {
    echo "   None found.\n";
}

// 2. Duplicate UserID in access_right
echo "\n2. DUPLICATE UserID IN access_right:\n";
echo str_repeat("-", 70) . "\n";
$result = $db->query("SELECT UserID, COUNT(*) as cnt FROM access_right WHERE UserID != '' AND UserID IS NOT NULL GROUP BY UserID HAVING cnt > 1");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   '{$row['UserID']}' appears {$row['cnt']} times\n";
        
        // Show the duplicate entries
        $detail = $db->query("SELECT id, staff_id, assigned_access, UserID FROM access_right WHERE UserID = '".$db->real_escape_string($row['UserID'])."'");
        while ($d = $detail->fetch_assoc()) {
            printf("      ID: %-5s | staff_id: %-12s | Role: %s\n", $d['id'], $d['staff_id'], $d['assigned_access']);
        }
        echo "\n";
    }
} else {
    echo "   None found.\n";
}

// 3. Duplicate staff_id in user_credentials
echo "\n3. DUPLICATE staff_id IN user_credentials:\n";
echo str_repeat("-", 70) . "\n";
$result = $db->query("SELECT staff_id, COUNT(*) as cnt FROM user_credentials GROUP BY staff_id HAVING cnt > 1");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   {$row['staff_id']} appears {$row['cnt']} times\n";
        
        // Show the duplicate entries
        $detail = $db->query("SELECT id, staff_id FROM user_credentials WHERE staff_id = '".$db->real_escape_string($row['staff_id'])."'");
        while ($d = $detail->fetch_assoc()) {
            printf("      ID: %-5s\n", $d['id']);
        }
        echo "\n";
    }
} else {
    echo "   None found.\n";
}

// 4. Duplicate staff positions
echo "\n4. DUPLICATE STAFF POSITION ASSIGNMENTS:\n";
echo str_repeat("-", 70) . "\n";
$result = $db->query("SELECT staff_id, COUNT(*) as cnt FROM staff_positions GROUP BY staff_id HAVING cnt > 1");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   {$row['staff_id']} has {$row['cnt']} positions\n";
        
        // Show the positions
        $detail = $db->query("SELECT sp.*, p.PosName FROM staff_positions sp LEFT JOIN positions p ON sp.PosID = p.PosID WHERE sp.staff_id = '".$db->real_escape_string($row['staff_id'])."'");
        while ($d = $detail->fetch_assoc()) {
            printf("      PosID: %-5s | Position: %s\n", $d['PosID'], $d['PosName'] ?? 'Unknown');
        }
        echo "\n";
    }
} else {
    echo "   None found.\n";
}

// 5. All access_right records summary
echo "\n5. ALL access_right RECORDS:\n";
echo str_repeat("-", 80) . "\n";
$result = $db->query("SELECT * FROM access_right ORDER BY staff_id, id");
if ($result && $result->num_rows > 0) {
    printf("%-5s | %-12s | %-25s | %-15s\n", "ID", "staff_id", "assigned_access", "UserID");
    echo str_repeat("-", 80) . "\n";
    while ($row = $result->fetch_assoc()) {
        printf("%-5s | %-12s | %-25s | %-15s\n", 
            $row['id'], 
            $row['staff_id'], 
            $row['assigned_access'],
            $row['UserID'] ?: '(empty)'
        );
    }
    echo "\nTotal records: " . $result->num_rows . "\n";
}

// 6. Check table indexes/constraints
echo "\n6. TABLE CONSTRAINTS (access_right):\n";
echo str_repeat("-", 70) . "\n";
$result = $db->query("SHOW INDEX FROM access_right");
if ($result) {
    printf("%-15s | %-10s | %-15s | %-10s\n", "Key_name", "Non_unique", "Column_name", "Seq");
    echo str_repeat("-", 70) . "\n";
    while ($row = $result->fetch_assoc()) {
        printf("%-15s | %-10s | %-15s | %-10s\n", 
            $row['Key_name'], 
            $row['Non_unique'] ? 'Yes' : 'No (unique)',
            $row['Column_name'],
            $row['Seq_in_index']
        );
    }
}

echo "\n=== DEBUG COMPLETE ===\n";
