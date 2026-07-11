<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
require __DIR__ . '/db/connect.php';

echo "=== LOGIN ACTIVITY TABLE DATA ===\n";
$r = $db->query("SELECT * FROM login_activity ORDER BY id DESC LIMIT 10");
if ($r && $r->num_rows > 0) {
    while ($row = $r->fetch_assoc()) {
        echo "  ID={$row['id']} | user_id={$row['user_id']} | type={$row['user_type']} | name='" . ($row['user_name'] ?? 'NULL') . "' | ip='" . ($row['ip_address'] ?? 'NULL') . "' | ua='" . substr($row['user_agent'] ?? '', 0, 50) . "' | at={$row['login_at']}\n";
    }
} else {
    echo "  No rows found\n";
}

echo "\n=== LOGIN ACTIVITY TABLE SCHEMA ===\n";
$r2 = $db->query("DESCRIBE login_activity");
while ($row = $r2->fetch_assoc()) {
    echo "  {$row['Field']} | {$row['Type']} | Null={$row['Null']} | Default={$row['Default']}\n";
}

echo "\n=== SIMULATING wuc_log_login() ===\n";
require_once __DIR__ . '/includes/login_activity_logger.php';
$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120';
wuc_log_login($db, 'DEBUG_TEST', 'student', 'Debug Student');

$r3 = $db->query("SELECT * FROM login_activity WHERE user_id = 'DEBUG_TEST' ORDER BY id DESC LIMIT 1");
$row = $r3->fetch_assoc();
echo "  Inserted: name='{$row['user_name']}' | ip='{$row['ip_address']}' | ua='{$row['user_agent']}'\n";

// Clean up
$db->query("DELETE FROM login_activity WHERE user_id = 'DEBUG_TEST'");
echo "  Cleaned up\n";

echo "\n=== CHECK staffLogin.php SELECT ===\n";
// Verify the fixed query includes Fname/Lname
$file = file_get_contents(__DIR__ . '/staffLogin.php');
if (preg_match('/SELECT\s+(.+?)\s+FROM\s+staff/i', $file, $m)) {
    echo "  SELECT columns: {$m[1]}\n";
    echo "  Has Fname: " . (stripos($m[1], 'Fname') !== false ? 'YES' : 'NO') . "\n";
    echo "  Has Lname: " . (stripos($m[1], 'Lname') !== false ? 'YES' : 'NO') . "\n";
}

echo "\n=== CHECK studentLogin.php name fetch ===\n";
$file2 = file_get_contents(__DIR__ . '/studentLogin.php');
if (strpos($file2, 'wuc_log_login') !== false) {
    echo "  wuc_log_login call found\n";
    // Check the name lookup query
    if (preg_match('/SELECT\s+Fname,\s*Lname\s+FROM\s+students/i', $file2)) {
        echo "  Name lookup query: EXISTS\n";
    } else {
        echo "  Name lookup query: CUSTOM CHECK NEEDED\n";
    }
} else {
    echo "  wuc_log_login NOT CALLED - BUG!\n";
}

echo "\n=== students TABLE — sample row column check ===\n";
$r4 = $db->query("SELECT SID, Fname, Lname FROM students LIMIT 3");
if ($r4 && $r4->num_rows > 0) {
    while ($row = $r4->fetch_assoc()) {
        echo "  SID={$row['SID']} | Fname='{$row['Fname']}' | Lname='{$row['Lname']}'\n";
    }
} else {
    echo "  No students found or table missing\n";
}
