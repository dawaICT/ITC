<?php
// Seeds/updates test logins in user_portals for quick local testing.
// Plain-text passwords to match current admissions/user_login.php checks.

require_once __DIR__ . '/../db/connect.php';

$testUsers = [
	[
		'user_name' => 'admin_test',
		'pass' => 'admin123',
		'designation' => 'admin',
	],
	[
		'user_name' => 'student_test',
		'pass' => 'student123',
		'designation' => 'student',
	],
];

try {
	// Prefer upsert to keep script idempotent
	$sql = 'INSERT INTO user_portals (user_name, pass, designation)
			VALUES (?, ?, ?)
			ON DUPLICATE KEY UPDATE pass = VALUES(pass), designation = VALUES(designation)';
	$stmt = $db->prepare($sql);
	if (!$stmt) {
		throw new Exception('Prepare failed: ' . $db->error);
	}

	foreach ($testUsers as $u) {
		$stmt->bind_param('sss', $u['user_name'], $u['pass'], $u['designation']);
		if (!$stmt->execute()) {
			throw new Exception('Execute failed for ' . $u['user_name'] . ': ' . $stmt->error);
		}
		echo 'Upserted user_portals: ' . $u['user_name'] . ' (' . $u['designation'] . ')' . "\n";
	}
	$stmt->close();

	echo "\nTest logins ready:\n";
	echo "- admin_test / admin123 (designation: admin)\n";
	echo "- student_test / student123 (designation: student)\n";
	echo "\nUse these on the admissions login form.\n";
} catch (Throwable $e) {
	echo 'Error seeding user_portals: ' . $e->getMessage() . "\n";
}


