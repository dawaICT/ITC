<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
$sqlPath = $argv[1] ?? '';
if ($sqlPath === '') {
	fwrite(STDERR, "Usage: php scripts/apply_sql.php path/to/file.sql\n");
	exit(2);
}

// Normalize path relative to project root if needed
if (!preg_match('/^([A-Za-z]:\\\\|\\\\|\\/)/', $sqlPath)) {
	$sqlPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sqlPath);
}

if (!file_exists($sqlPath)) {
	fwrite(STDERR, "SQL file not found: {$sqlPath}\n");
	exit(3);
}

require_once $root . '/db/connect.php'; // provides $db (mysqli) and selects wucportal

// Read SQL
$sql = file_get_contents($sqlPath);
if ($sql === false) {
	fwrite(STDERR, "Failed to read SQL file: {$sqlPath}\n");
	exit(4);
}

// Execute with multi_query to support multiple statements
if (!$db->multi_query($sql)) {
	fwrite(STDERR, "SQL error: " . $db->error . "\n");
	exit(5);
}

do {
	if ($result = $db->store_result()) {
		$result->free();
	}
} while ($db->more_results() && $db->next_result());

echo "Applied: {$sqlPath}\n";
