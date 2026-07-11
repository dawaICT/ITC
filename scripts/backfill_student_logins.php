<?php
/**
 * Repair script: create portal credentials for every student who has no
 * student_login row (initial password = NRC, must_change_password = 1).
 *
 * Safe to re-run: only inserts missing rows; existing accounts are never
 * touched (admissionsEnsureStudentLogin is strictly create-if-missing).
 *
 * Run:  E:\xampp\php\php.exe scripts\backfill_student_logins.php
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../admissions/includes/registration_handlers.php';

$res = $db->query(
    "SELECT s.SID, s.nrc_pass, s.email, s.Fname, s.Lname
     FROM students s
     LEFT JOIN student_login sl ON sl.Sid = s.SID
     WHERE sl.Sid IS NULL"
);

$created = 0;
$skipped = 0;
while ($row = $res->fetch_assoc()) {
    $sid = (string)$row['SID'];
    $nrc = trim((string)($row['nrc_pass'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));

    admissionsEnsureStudentLogin($db, $sid, $nrc, $email !== '' ? $email : null);

    // Confirm it landed
    $chk = $db->prepare('SELECT 1 FROM student_login WHERE Sid = ? LIMIT 1');
    $chk->bind_param('s', $sid);
    $chk->execute();
    $chk->store_result();
    $ok = $chk->num_rows > 0;
    $chk->close();

    if ($ok) {
        $created++;
        $pwSource = $nrc !== '' ? 'NRC' : 'SID (no NRC on file)';
        echo "[CREATED] {$sid} ({$row['Fname']} {$row['Lname']}) — initial password = {$pwSource}, forced change on first login\n";
    } else {
        $skipped++;
        echo "[SKIPPED] {$sid} — could not create login row\n";
    }
}

echo "\nDone: {$created} login(s) created, {$skipped} skipped.\n";
