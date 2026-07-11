<?php
require 'c:/xampp/htdocs/wucportal/db/connect.php';

$result = $db->query('SELECT pass FROM user_credentials WHERE staff_id = "WUC015"');
if ($result && $row = $result->fetch_assoc()) {
    echo 'Password hash: ' . $row['pass'] . PHP_EOL;
    echo 'Hash starts with $2y$: ' . (str_starts_with($row['pass'], '$2y$') ? 'YES' : 'NO') . PHP_EOL;
    echo 'Password verify Admin123!: ' . (password_verify('Admin123!', $row['pass']) ? 'YES' : 'NO') . PHP_EOL;
} else {
    echo 'Staff ID WUC015 not found or query failed' . PHP_EOL;
}
?>