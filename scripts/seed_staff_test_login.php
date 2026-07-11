<?php
// Seed a staff test login for the actual staff login flow (staff_login.php)
// This seeds user_credentials (MD5 password), ensures positions and staff_positions entries.

require_once __DIR__ . '/../db/connect.php';

// Choose an existing staff_id commonly used as admin in this project
$staffId = 'LVTC23';
$plainPassword = 'admin123';
$md5Password = md5($plainPassword);

try {
	// Ensure user_credentials exists
	$db->query("CREATE TABLE IF NOT EXISTS user_credentials (
		staff_id VARCHAR(64) PRIMARY KEY,
		pass VARCHAR(255) NOT NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	// Ensure positions exists and has Administrator role ADM009
    $db->query("CREATE TABLE IF NOT EXISTS positions (
        PosID VARCHAR(20) PRIMARY KEY,
        PosName VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Role IDs stay on their legacy role-code format; staff IDs use ITC###.
    require_once __DIR__ . '/../includes/id_helpers.php';
    $adminPos = 'ADM009';
    $admPos = $db->query("SELECT PosID FROM positions WHERE PosID='ADM009'");
    if (!$admPos || $admPos->num_rows === 0) {
        $ins = $db->prepare("INSERT INTO positions (PosID, PosName) VALUES ('ADM009','Administrator')");
        $ins->execute();
    }
    $admissionsPos = $db->query("SELECT PosID FROM positions WHERE PosID='ADM010'");
    if (!$admissionsPos || $admissionsPos->num_rows === 0) {
        $ins = $db->prepare("INSERT INTO positions (PosID, PosName) VALUES ('ADM010','Admissions')");
        $ins->execute();
    }

	// Ensure staff_positions exists
	$db->query("CREATE TABLE IF NOT EXISTS staff_positions (
		staff_id VARCHAR(64) NOT NULL,
		PosID VARCHAR(10) NOT NULL,
		UNIQUE KEY uniq_staff_pos (staff_id, PosID)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	// Upsert credentials for the chosen staff id
	$stmt = $db->prepare('INSERT INTO user_credentials (staff_id, pass) VALUES (?, ?) ON DUPLICATE KEY UPDATE pass = VALUES(pass)');
	$stmt->bind_param('ss', $staffId, $md5Password);
	$stmt->execute();
	$stmt->close();

// Assign Admin and Admissions roles
$stmt2 = $db->prepare('INSERT IGNORE INTO staff_positions (staff_id, PosID) VALUES (?, "ADM009")');
$stmt2->bind_param('s', $staffId);
$stmt2->execute();
$stmt2->close();

$stmt3 = $db->prepare('INSERT IGNORE INTO staff_positions (staff_id, PosID) VALUES (?, "ADM010")');
$stmt3->bind_param('s', $staffId);
$stmt3->execute();
$stmt3->close();

	echo "Seeded staff login for staff_id: {$staffId} with password: {$plainPassword}\n";
echo "Roles assigned: ADM009 (Administrator), ADM010 (Admissions)\n";
	echo "Use on Staff Login page: staff_login.php\n";
} catch (Throwable $e) {
	echo 'Error seeding staff login: ' . $e->getMessage() . "\n";
}

