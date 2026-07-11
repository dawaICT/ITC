<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbname = 'wucportal';

$db = @new mysqli($host, $user, $pass, $dbname);
if ($db->connect_error) {
	echo "Connect error: {$db->connect_error}\n";
	exit(1);
}

echo "Connected to {$dbname} as {$user}@{$host}\n";

$sql = "CREATE TABLE IF NOT EXISTS el_course_modules (
	id INT AUTO_INCREMENT PRIMARY KEY,
	course_code VARCHAR(64) NOT NULL,
	title VARCHAR(255) NOT NULL,
	description TEXT NULL,
	release_at DATETIME NULL,
	close_at DATETIME NULL,
	position INT DEFAULT 0,
	created_by VARCHAR(64) NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	INDEX idx_el_modules_course_pos (course_code, position),
	INDEX idx_el_modules_release (release_at),
	INDEX idx_el_modules_close (close_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!$db->query($sql)) {
	echo "Error creating el_course_modules: {$db->error}\n";
	exit(2);
}

echo "CREATE TABLE executed.\n";

$exists = false;
if ($res = $db->query("SHOW TABLES LIKE 'el_course_modules'")) {
	$exists = $res->num_rows > 0;
	$res->free();
}

echo "el_course_modules exists: " . ($exists ? 'yes' : 'no') . "\n";

if ($exists) {
	if ($res = $db->query("SELECT COUNT(*) AS c FROM el_course_modules")) {
		$row = $res->fetch_assoc();
		echo "row_count=" . ($row['c'] ?? '0') . "\n";
		$res->free();
	}
}

$db->close();
