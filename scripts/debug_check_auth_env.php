<?php
require_once __DIR__ . '/../db/connect.php';

function println($msg){ echo $msg, "\n"; }

println('== Student login check ==');
$sid = 'test123';
try {
	$exists = false;
	if ($res = $db->query("SHOW TABLES LIKE 'student_login'")) {
		$exists = $res->num_rows > 0; $res->free();
	}
	println('student_login table: ' . ($exists ? 'YES' : 'NO'));
	if ($exists) {
		$stmt = $db->prepare('SELECT Sid FROM student_login WHERE Sid=? LIMIT 1');
		$stmt->bind_param('s', $sid);
		$stmt->execute();
		$stmt->store_result();
		println('test123 in student_login: ' . ($stmt->num_rows === 1 ? 'YES' : 'NO'));
		$stmt->close();
	}
} catch (Throwable $e) { println('Error (student): ' . $e->getMessage()); }

println("\n== Staff login check ==");
$staffId = 'LVTC23';
try {
	// Check staff table
	$staffExists = false;
	if ($res = $db->prepare('SELECT staff_id FROM staff WHERE staff_id=? LIMIT 1')) {
		$res->bind_param('s', $staffId); $res->execute(); $r = $res->get_result();
		$staffExists = $r && $r->num_rows === 1; $res->close();
	}
	println('staff LVTC23 exists: ' . ($staffExists ? 'YES' : 'NO'));

	// Check user_credentials
	$credExists = false;
	if ($res = $db->prepare('SELECT staff_id FROM user_credentials WHERE staff_id=? LIMIT 1')) {
		$res->bind_param('s', $staffId); $res->execute(); $r = $res->get_result();
		$credExists = $r && $r->num_rows === 1; $res->close();
	}
	println('user_credentials LVTC23 exists: ' . ($credExists ? 'YES' : 'NO'));

	// Check staff_positions for ADM009
	$roleExists = false;
	if ($res = $db->prepare('SELECT 1 FROM staff_positions WHERE staff_id=? AND PosID="ADM009" LIMIT 1')) {
		$res->bind_param('s', $staffId); $res->execute(); $r = $res->get_result();
		$roleExists = $r && $r->num_rows === 1; $res->close();
	}
	println('staff_positions LVTC23/ADM009 exists: ' . ($roleExists ? 'YES' : 'NO'));
} catch (Throwable $e) { println('Error (staff): ' . $e->getMessage()); }

println("\nDone.");


