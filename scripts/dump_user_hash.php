<?php
require_once __DIR__ . '/../db/connect.php';
$user = $argv[1] ?? 'WUC026';
$res = $db->query("SELECT pass, LENGTH(pass) AS len FROM user_credentials WHERE staff_id='".$db->real_escape_string($user)."' LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
  echo "len=".$row['len']."\n";
  echo "pass=".$row['pass']."\n";
} else {
  echo "No row for {$user}\n";
}

$res2 = $db->query("SHOW CREATE TABLE user_credentials");
if ($res2 && $row2 = $res2->fetch_array(MYSQLI_NUM)) {
  echo "\nCREATE TABLE user_credentials:\n".$row2[1]."\n";
}


