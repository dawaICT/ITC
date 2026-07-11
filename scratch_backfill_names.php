<?php
/**
 * One-time backfill: populate user_name for existing login_activity records
 * where it was logged as empty due to the previous bug in staffLogin.php.
 */
require __DIR__ . '/db/connect.php';

echo "=== Backfilling empty user_name in login_activity ===\n";

// Fix staff entries
$sql1 = "UPDATE login_activity la
         INNER JOIN staff sf ON la.user_id = sf.staff_id
         SET la.user_name = TRIM(CONCAT(COALESCE(sf.Fname,''), ' ', COALESCE(sf.Lname,'')))
         WHERE la.user_type = 'staff' AND (la.user_name IS NULL OR la.user_name = '')";
$db->query($sql1);
echo "  Staff records fixed: " . $db->affected_rows . "\n";

// Fix student entries
$sql2 = "UPDATE login_activity la
         INNER JOIN students st ON la.user_id = st.SID
         SET la.user_name = TRIM(CONCAT(COALESCE(st.Fname,''), ' ', COALESCE(st.Lname,'')))
         WHERE la.user_type = 'student' AND (la.user_name IS NULL OR la.user_name = '')";
$db->query($sql2);
echo "  Student records fixed: " . $db->affected_rows . "\n";

// Verify
echo "\n=== Verification ===\n";
$r = $db->query("SELECT id, user_id, user_type, user_name, ip_address FROM login_activity ORDER BY id DESC LIMIT 10");
while ($row = $r->fetch_assoc()) {
    echo "  ID={$row['id']} | {$row['user_id']} | {$row['user_type']} | name='{$row['user_name']}' | ip='{$row['ip_address']}'\n";
}

echo "\nDone!\n";
