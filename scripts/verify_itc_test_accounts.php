<?php
/**
 * Verify that legacy STU900/WUC900 demo accounts have been replaced by the
 * ITC/CSE test accounts and that the login passwords verify.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts\verify_itc_test_accounts.php
 */

require_once __DIR__ . '/../db/connect.php';

if (php_sapi_name() !== 'cli') {
    echo "Run this script from the command line.\n";
    exit(1);
}

$studentId = 'CSE26456789';
$studentPassword = 'Student@12345';
$staffId = 'ITC900';
$staffPassword = 'Test@12345';
$legacyStudentId = 'STU900';
$ok = true;

function fail_line(string $message): void
{
    echo "FAIL: {$message}\n";
}

function pass_line(string $message): void
{
    echo "PASS: {$message}\n";
}

function table_has_column(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

// 1. Old IDs must not remain in text columns.
$hits = [];
$cols = $db->query(
    "SELECT TABLE_NAME, COLUMN_NAME
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext')
     ORDER BY TABLE_NAME, COLUMN_NAME"
);
while ($col = $cols->fetch_assoc()) {
    $table = str_replace('`', '``', (string)$col['TABLE_NAME']);
    $column = str_replace('`', '``', (string)$col['COLUMN_NAME']);
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$column}` = ? OR `{$column}` LIKE 'WUC%'");
    if (!$stmt) {
        continue;
    }
    $legacyStudent = $legacyStudentId;
    $stmt->bind_param('s', $legacyStudent);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)($row['c'] ?? 0) > 0) {
        $hits[] = "{$table}.{$column}={$row['c']}";
    }
}
if ($hits) {
    $ok = false;
    fail_line('legacy IDs still found: ' . implode(', ', $hits));
} else {
    pass_line('legacy STU900/WUC### IDs are absent from text columns.');
}

// 2. Student login chain must resolve through students -> student_program -> programs.
$stmt = $db->prepare(
    'SELECT s.SID, s.program, sp.program_code, p.program_name
     FROM students s
     JOIN student_program sp ON sp.Sid = s.SID
     JOIN programs p ON p.program_code = sp.program_code
     WHERE s.SID = ?
     LIMIT 1'
);
$stmt->bind_param('s', $studentId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$student || $student['program_code'] !== 'CSE') {
    $ok = false;
    fail_line("student {$studentId} is missing or not linked to CSE.");
} else {
    pass_line("student {$studentId} links to {$student['program_code']} ({$student['program_name']}).");
}

$stmt = $db->prepare('SELECT Password FROM student_login WHERE Sid = ? LIMIT 1');
$stmt->bind_param('s', $studentId);
$stmt->execute();
$studentLogin = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$studentLogin || !password_verify($studentPassword, (string)$studentLogin['Password'])) {
    $ok = false;
    fail_line("student password for {$studentId} does not verify.");
} else {
    pass_line("student password verifies for {$studentId}.");
}

// 3. Staff login chain must resolve through staff -> user_credentials -> staff_positions.
$stmt = $db->prepare(
    'SELECT s.staff_id, s.role, s.status, s.password, uc.pass
     FROM staff s
     JOIN user_credentials uc ON uc.staff_id = s.staff_id
     WHERE s.staff_id = ?
     LIMIT 1'
);
$stmt->bind_param('s', $staffId);
$stmt->execute();
$staff = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$staff || strtolower((string)$staff['status']) !== 'active') {
    $ok = false;
    fail_line("staff {$staffId} is missing, lacks credentials, or is inactive.");
} elseif (!password_verify($staffPassword, (string)$staff['password'])) {
    $ok = false;
    fail_line("staff login password for {$staffId} does not verify against staff.password.");
} elseif (!password_verify($staffPassword, (string)$staff['pass'])) {
    $ok = false;
    fail_line("staff credential password for {$staffId} does not verify against user_credentials.pass.");
} else {
    pass_line("staff password verifies for {$staffId} in staff.password and user_credentials.pass ({$staff['role']}).");
}

$stmt = $db->prepare('SELECT COUNT(*) AS c FROM staff_positions WHERE staff_id = ?');
$stmt->bind_param('s', $staffId);
$stmt->execute();
$positionCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
if ($positionCount < 1) {
    $ok = false;
    fail_line("staff {$staffId} has no staff_positions rows.");
} else {
    pass_line("staff {$staffId} has {$positionCount} staff_positions rows.");
}

// 4. Confirm the main login tables no longer have the legacy rows.
foreach ([
    ['students', 'SID', 'STU900'],
    ['student_login', 'Sid', 'STU900'],
    ['student_program', 'Sid', 'STU900'],
    ['staff', 'staff_id', 'WUC900'],
    ['user_credentials', 'staff_id', 'WUC900'],
    ['staff_positions', 'staff_id', 'WUC900'],
    ['access_right', 'staff_id', 'WUC900'],
] as $target) {
    [$table, $column, $value] = $target;
    if (!table_has_column($db, $table, $column)) {
        continue;
    }
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$column}` = ?");
    $stmt->bind_param('s', $value);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($count > 0) {
        $ok = false;
        fail_line("{$table}.{$column} still has {$count} row(s) for {$value}.");
    }
}

if (!$ok) {
    exit(1);
}

echo "\nAll ITC test account checks passed.\n";
