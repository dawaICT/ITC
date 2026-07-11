<?php
require_once __DIR__ . '/../db/connect.php';

$user = $argv[1] ?? 'WUC026';
$plain = $argv[2] ?? 'Adm1n@2025';

echo "Checking user: {$user}\n";

// Check staff
$stmt = $db->prepare('SELECT staff_id, Fname, Lname, email FROM staff WHERE staff_id=? LIMIT 1');
$stmt->bind_param('s', $user);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
  $row = $res->fetch_assoc();
  echo "staff row: ".json_encode($row)."\n";
} else {
  echo "staff row: NOT FOUND\n";
}
$stmt->close();

// Check creds
$stmt = $db->prepare('SELECT pass FROM user_credentials WHERE staff_id=? LIMIT 1');
$stmt->bind_param('s', $user);
$stmt->execute();
$res2 = $stmt->get_result();
if ($res2 && $row2 = $res2->fetch_assoc()) {
  $hash = $row2['pass'];
  echo "creds: FOUND (len=".(strlen($hash)).")\n";
  echo "hash: ".$hash."\n";
  $isBcrypt = strpos($hash, '$2y$') === 0;
  echo "creds is bcrypt? ".($isBcrypt ? 'yes' : 'no')."\n";
  $ok = $isBcrypt ? password_verify($plain, $hash) : (hash_equals($hash, md5($plain)));
  echo "password_verify: ".($ok ? 'MATCH' : 'NO_MATCH')."\n";
} else {
  echo "creds: NOT FOUND\n";
}
$stmt->close();

// Sanity test: local hash and verify
$testHash = password_hash($plain, PASSWORD_BCRYPT);
echo "local bcrypt verify: ".(password_verify($plain, $testHash) ? 'MATCH' : 'NO_MATCH')." (hash: $testHash)\n";

// Check staff_positions entries count
if ($db->query("SHOW TABLES LIKE 'staff_positions'")) {
  $stmt = $db->prepare('SELECT COUNT(*) FROM staff_positions WHERE staff_id=?');
  $stmt->bind_param('s', $user);
  $stmt->execute();
  $stmt->bind_result($cnt);
  $stmt->fetch();
  echo "staff_positions count: ".$cnt."\n";
  $stmt->close();
}


