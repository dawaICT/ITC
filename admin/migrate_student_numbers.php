<?php
/**
 * One-off migration: convert every legacy student number (e.g. 26Y34296601) to
 * the approved programme format (e.g. CSE26456789), updating EVERY table that
 * references the old number so nothing is orphaned.
 *
 * The new number is derived from data already on the record:
 *   year   ← students.academic_year (or the admission date)
 *   period ← student_program.term   (falls back to 1)
 *   NRC4   ← students.nrc_pass       (last 4 of the personal serial)
 *
 * A student whose NRC cannot yield four digits is LEFT UNCHANGED and reported,
 * because the rules forbid minting a number without a valid NRC.
 *
 * SAFETY: dry run by default (prints the old→new map, writes nothing). Pass
 * --commit to apply. Idempotent: numbers already in ITC format are skipped, so
 * re-running is safe.
 *
 *   php admin/migrate_student_numbers.php            # preview
 *   php admin/migrate_student_numbers.php --commit   # apply
 */

require __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/student_id_generator.php';

$COMMIT = in_array('--commit', $argv, true);
function out(string $s = ''): void { echo $s . "\n"; }

// Every (table, column) that stores a student number, discovered live so the
// migration stays correct as the schema evolves.
function sidColumns(mysqli $db): array
{
    $sql = "SELECT TABLE_NAME, COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_NAME IN ('SID','Sid','sid','student_id','studentId','student_number')";
    $cols = [];
    $res = $db->query($sql);
    while ($row = $res->fetch_assoc()) {
        // Skip the students PK column here; it's updated explicitly last.
        if ($row['TABLE_NAME'] === 'students' && $row['COLUMN_NAME'] === 'SID') {
            continue;
        }
        $cols[] = [$row['TABLE_NAME'], $row['COLUMN_NAME']];
    }
    return $cols;
}

// Academic period for a student (1–3): prefer the enrolment's term.
function derivePeriod(mysqli $db, string $oldSid, ?string $intake): int
{
    $stmt = $db->prepare('SELECT term FROM student_program WHERE Sid = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('s', $oldSid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $term = $row['term'] ?? '';
    $digits = preg_replace('/\D+/', '', (string)$term);
    if ($digits !== '' && (int)$digits >= 1 && (int)$digits <= 3) {
        return (int)$digits;
    }
    // Fall back to the intake month name → period.
    $months = ['january'=>1,'february'=>1,'march'=>1,'april'=>1,'may'=>2,'june'=>2,
               'july'=>2,'august'=>2,'september'=>3,'october'=>3,'november'=>3,'december'=>3];
    foreach ($months as $name => $p) {
        if (stripos((string)$intake, $name) !== false) {
            return $p;
        }
    }
    return 1;
}

out('Student-number migration — ' . ($COMMIT ? 'COMMIT (writing)' : 'DRY RUN (no writes)'));
out(str_repeat('-', 72));

$cols = sidColumns($db);
out('Linked tables to update per student: ' . count($cols));

// Load every student whose number isn't already in the new format.
$students = [];
$res = $db->query("SELECT SID, nrc_pass, academic_year, intake, dte_adm, program FROM students ORDER BY SID");
while ($row = $res->fetch_assoc()) {
    if (wuc_validate_student_number($row['SID'])) {
        continue; // already ITC format
    }
    $students[] = $row;
}
out('Students needing migration: ' . count($students));
out(str_repeat('-', 72));

$map = [];        // oldSid => newSid
$skipped = [];    // oldSid => reason

foreach ($students as $row) {
    $oldSid = $row['SID'];
    $year   = preg_match('/^\d{4}$/', (string)$row['academic_year'])
        ? (int)$row['academic_year']
        : (int)date('Y', strtotime((string)($row['dte_adm'] ?: 'now')));
    $period = derivePeriod($db, $oldSid, $row['intake']);

    try {
        $programCode = trim((string)($row['program'] ?? '')) ?: 'GENERAL';
        $newSid = wuc_make_student_number($db, $programCode, $row['nrc_pass'], $year);
    } catch (Throwable $e) {
        $skipped[$oldSid] = $e->getMessage();
        continue;
    }
    if ($newSid === $oldSid) {
        continue;
    }
    $map[$oldSid] = $newSid;

    if ($COMMIT) {
        // Update children first, then the students PK, all with FK checks off.
        // Lift the identity-protection trigger guard for this system migration.
        $db->query('SET @allow_identity_change = 1');
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($cols as [$tbl, $col]) {
            $stmt = $db->prepare("UPDATE `{$tbl}` SET `{$col}` = ? WHERE `{$col}` = ?");
            $stmt->bind_param('ss', $newSid, $oldSid);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $db->prepare('UPDATE students SET SID = ? WHERE SID = ?');
        $stmt->bind_param('ss', $newSid, $oldSid);
        $stmt->execute();
        $stmt->close();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->query('SET @allow_identity_change = NULL');
    }
    out(sprintf('  %s  →  %s', $oldSid, $newSid));
}

out(str_repeat('-', 72));
out('Summary:');
out('   ' . ($COMMIT ? 'Migrated' : 'Would migrate') . ' : ' . count($map));
out('   Skipped (no valid NRC) : ' . count($skipped));
foreach ($skipped as $sid => $why) {
    out("      {$sid} — {$why}");
}
if (!$COMMIT) {
    out('');
    out('DRY RUN only. Re-run with --commit to apply.');
}
