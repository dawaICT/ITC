<?php
// One-time schema fix: widen user_credentials.pass to store full bcrypt hashes
require_once __DIR__ . '/../db/connect.php';

// Check current DDL
$res = $db->query("SHOW CREATE TABLE user_credentials");
if (!$res || !($row = $res->fetch_array(MYSQLI_NUM))) {
  echo "user_credentials table not found.\n";
  exit(1);
}
echo "Before:\n".$row[1]."\n\n";

// Alter pass column to VARCHAR(255)
$ok = $db->query("ALTER TABLE user_credentials MODIFY pass VARCHAR(255) NOT NULL");
if (!$ok) { echo "ALTER failed: ".$db->error."\n"; exit(1); }

// Ensure index on staff_id for fast lookups
$db->query("CREATE INDEX IF NOT EXISTS idx_user_credentials_staff ON user_credentials (staff_id)");

// Show after
$res2 = $db->query("SHOW CREATE TABLE user_credentials");
$row2 = $res2->fetch_array(MYSQLI_NUM);
echo "After:\n".$row2[1]."\n";


