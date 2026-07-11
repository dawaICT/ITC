<?php
// Simulate Apache: no putenv, no external config override
$_SERVER = $_SERVER ?? [];
require_once __DIR__ . '/../includes/portal_config.php';
require_once __DIR__ . '/../db/connect.php';
echo "Connected as user via connect.php\n";
$r = $db->query('SELECT SID FROM students LIMIT 1');
echo "Student: " . ($r->fetch_assoc()['SID'] ?? 'none') . "\n";
