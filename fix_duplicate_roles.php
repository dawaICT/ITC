<?php
/**
 * Fix Duplicate Roles
 * Run from CLI: php fix_duplicate_roles.php
 */
require_once 'db/connect.php';

echo "\n=== FIXING DUPLICATE ROLES ===\n\n";

// 1. Fix duplicate user_credentials - keep only the latest (highest ID)
echo "1. FIXING DUPLICATE user_credentials:\n";
echo str_repeat("-", 70) . "\n";

$result = $db->query("SELECT staff_id, COUNT(*) as cnt, MAX(id) as keep_id FROM user_credentials GROUP BY staff_id HAVING cnt > 1");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   {$row['staff_id']}: {$row['cnt']} entries, keeping ID {$row['keep_id']}\n";
        
        // Delete all except the one to keep
        $stmt = $db->prepare("DELETE FROM user_credentials WHERE staff_id = ? AND id != ?");
        $stmt->bind_param('si', $row['staff_id'], $row['keep_id']);
        if ($stmt->execute()) {
            echo "   ✓ Deleted " . $stmt->affected_rows . " duplicate(s)\n";
        } else {
            echo "   ✗ Error: " . $stmt->error . "\n";
        }
        $stmt->close();
    }
} else {
    echo "   No duplicates found.\n";
}

// 2. Fix duplicate staff_positions - remove exact duplicates
echo "\n2. FIXING DUPLICATE staff_positions:\n";
echo str_repeat("-", 70) . "\n";

// First show what we have
$result = $db->query("SELECT staff_id, PosID, COUNT(*) as cnt FROM staff_positions GROUP BY staff_id, PosID HAVING cnt > 1");
if ($result && $result->num_rows > 0) {
    echo "   Found exact duplicate position assignments:\n";
    $result->data_seek(0);
    while ($row = $result->fetch_assoc()) {
        echo "   {$row['staff_id']} + {$row['PosID']}: {$row['cnt']} times\n";
    }
    
    // Delete exact duplicates (keep one of each staff_id + PosID combo using St_id)
    $db->query("CREATE TEMPORARY TABLE temp_sp AS 
                SELECT MIN(St_id) as keep_id FROM staff_positions GROUP BY staff_id, PosID");
    
    $delResult = $db->query("DELETE FROM staff_positions WHERE St_id NOT IN (SELECT keep_id FROM temp_sp)");
    if ($delResult) {
        echo "   ✓ Removed exact duplicates (" . $db->affected_rows . " rows)\n";
    }
    $db->query("DROP TEMPORARY TABLE IF EXISTS temp_sp");
} else {
    echo "   No exact duplicates found.\n";
}

// 3. Show staff with multiple different positions (for review, not auto-fix)
echo "\n3. STAFF WITH MULTIPLE POSITIONS (review needed):\n";
echo str_repeat("-", 70) . "\n";

$result = $db->query("SELECT sp.staff_id, s.Fname, s.Lname, COUNT(DISTINCT sp.PosID) as pos_count 
                      FROM staff_positions sp 
                      LEFT JOIN staff s ON sp.staff_id = s.staff_id
                      GROUP BY sp.staff_id 
                      HAVING pos_count > 1");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   {$row['staff_id']} ({$row['Fname']} {$row['Lname']}): {$row['pos_count']} positions\n";
        
        // Show positions
        $detail = $db->query("SELECT p.PosName FROM staff_positions sp 
                              LEFT JOIN positions p ON sp.PosID = p.PosID 
                              WHERE sp.staff_id = '".$db->real_escape_string($row['staff_id'])."'");
        while ($d = $detail->fetch_assoc()) {
            echo "      - {$d['PosName']}\n";
        }
    }
    echo "\n   NOTE: Multiple positions may be intentional. Review and use fix_staff_positions.php to clean.\n";
} else {
    echo "   All staff have single positions.\n";
}

// 4. Verify fixes
echo "\n4. VERIFICATION:\n";
echo str_repeat("-", 70) . "\n";

$result = $db->query("SELECT staff_id, COUNT(*) as cnt FROM user_credentials GROUP BY staff_id HAVING cnt > 1");
echo "   Duplicate user_credentials remaining: " . ($result ? $result->num_rows : 0) . "\n";

$result = $db->query("SELECT staff_id, PosID, COUNT(*) as cnt FROM staff_positions GROUP BY staff_id, PosID HAVING cnt > 1");
echo "   Exact duplicate staff_positions remaining: " . ($result ? $result->num_rows : 0) . "\n";

echo "\n=== FIX COMPLETE ===\n";
