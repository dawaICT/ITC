<?php
/**
 * CLI verification for login/password-reset store alignment.
 * Usage:
 *   set WUC_CONFIG_FILE=C:\xampp\wucportal-var\config\environment.php
 *   php scratch/verify_auth_pages.php
 */
require_once __DIR__ . '/../includes/auth_helpers.php';
require_once __DIR__ . '/../db/connect.php';

$staffId = 'ITC900';
$staffPass = 'Test@12345';
$staffNrc = '900000/00/1';
$studentId = 'CSE26456789';
$studentPass = 'Student@12345';
$studentNrc = '123456/78/9';

$ok = true;
function pass(string $msg): void { echo "PASS: {$msg}\n"; }
function fail(string $msg): void { global $ok; $ok = false; echo "FAIL: {$msg}\n"; }

// Staff: users.password must verify for login.
$stmt = $db->prepare(
    'SELECT u.password AS users_pw, s.password AS staff_pw, s.nrc_pass
     FROM users u
     JOIN staff s ON s.staff_id = u.staff_id
     WHERE u.username = ?
     LIMIT 1'
);
$stmt->bind_param('s', $staffId);
$stmt->execute();
$staffRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$staffRow) {
    fail("no users+staff row for {$staffId}");
} elseif (!password_verify($staffPass, (string) $staffRow['users_pw'])) {
    fail("users.password does not verify for {$staffId}");
} elseif (!password_verify($staffPass, (string) $staffRow['staff_pw'])) {
    fail("staff.password does not verify for {$staffId}");
} elseif (trim((string) ($staffRow['nrc_pass'] ?? '')) !== $staffNrc) {
    fail("staff nrc_pass for {$staffId} is '{$staffRow['nrc_pass']}', expected {$staffNrc}");
} else {
    pass("staff {$staffId} passwords aligned in users + staff; nrc_pass set.");
}

// Student: users.password must verify for login.
$stmt = $db->prepare(
    'SELECT u.password AS users_pw, sl.Password AS login_pw, s.nrc_pass
     FROM users u
     JOIN student_login sl ON sl.Sid = u.student_id
     JOIN students s ON s.SID = u.student_id
     WHERE u.username = ?
     LIMIT 1'
);
$stmt->bind_param('s', $studentId);
$stmt->execute();
$stuRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$stuRow) {
    fail("no users+student_login row for {$studentId}");
} elseif (!password_verify($studentPass, (string) $stuRow['users_pw'])) {
    fail("users.password does not verify for {$studentId}");
} elseif (!password_verify($studentPass, (string) $stuRow['login_pw'])) {
    fail("student_login.Password does not verify for {$studentId}");
} elseif (trim((string) ($stuRow['nrc_pass'] ?? '')) !== $studentNrc) {
    fail("student nrc_pass mismatch for {$studentId}");
} else {
    pass("student {$studentId} passwords aligned in users + student_login; nrc_pass set.");
}

// Simulate staff reset sync (does not change password — uses existing hash).
$testHash = password_hash('SyncProbeStaff1!', PASSWORD_DEFAULT);
wuc_sync_staff_password($db, $staffId, $testHash);
$chk = $db->prepare('SELECT password FROM users WHERE username = ? LIMIT 1');
$chk->bind_param('s', $staffId);
$chk->execute();
$chk->bind_result($synced);
$chk->fetch();
$chk->close();
if (!password_verify('SyncProbeStaff1!', (string) $synced)) {
    fail('wuc_sync_staff_password did not update users.password');
} else {
    pass('wuc_sync_staff_password updates users.password');
}
// Restore staff test password across stores.
$restore = password_hash($staffPass, PASSWORD_DEFAULT);
$db->prepare('UPDATE staff SET password = ? WHERE staff_id = ?')->bind_param('ss', $restore, $staffId) && false;
$updStaff = $db->prepare('UPDATE staff SET password = ? WHERE staff_id = ?');
$updStaff->bind_param('ss', $restore, $staffId);
$updStaff->execute();
$updStaff->close();
wuc_sync_staff_password($db, $staffId, $restore);

$testHash2 = password_hash('SyncProbeStudent1!', PASSWORD_DEFAULT);
wuc_sync_student_password($db, $studentId, $testHash2);
$chk2 = $db->prepare('SELECT password FROM users WHERE username = ? LIMIT 1');
$chk2->bind_param('s', $studentId);
$chk2->execute();
$chk2->bind_result($synced2);
$chk2->fetch();
$chk2->close();
if (!password_verify('SyncProbeStudent1!', (string) $synced2)) {
    fail('wuc_sync_student_password did not update users.password');
} else {
    pass('wuc_sync_student_password updates users.password');
}
$restore2 = password_hash($studentPass, PASSWORD_DEFAULT);
$updSl = $db->prepare('UPDATE student_login SET Password = ? WHERE Sid = ?');
$updSl->bind_param('ss', $restore2, $studentId);
$updSl->execute();
$updSl->close();
wuc_sync_student_password($db, $studentId, $restore2);

if (!$ok) {
    exit(1);
}
echo "\nAll auth page store checks passed.\n";
