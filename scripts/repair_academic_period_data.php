<?php
declare(strict_types=1);
/**
 * Repair academic period consistency (term vs semester).
 *
 * Safe, idempotent data repair with CSV backup export first.
 *
 * Usage:
 *   php scripts/repair_academic_period_data.php [--dry-run] [--skip-backup]
 */

putenv('WUC_CONFIG_FILE=C:\xampp\wucportal-var\config\environment.php');
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_structure_helpers.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_period_helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$dryRun = in_array('--dry-run', $argv ?? [], true);
$skipBackup = in_array('--skip-backup', $argv ?? [], true);
$backupDir = dirname(__DIR__) . '/scratch/backups';
$stamp = date('Ymd_His');

echo "Academic period data repair" . ($dryRun ? ' (DRY RUN)' : '') . "\n";
echo str_repeat('-', 60) . "\n";

if (!$skipBackup && !$dryRun) {
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    foreach (['programs', 'program_courses', 'semester_registration', 'student_program'] as $table) {
        $file = "{$backupDir}/{$table}_{$stamp}.csv";
        $res = $db->query("SELECT * FROM {$table}");
        $fp = fopen($file, 'w');
        $header = true;
        while ($row = $res->fetch_assoc()) {
            if ($header) {
                fputcsv($fp, array_keys($row));
                $header = false;
            }
            fputcsv($fp, $row);
        }
        fclose($fp);
        echo "Backed up {$table} -> {$file}\n";
    }
}

$fixes = [];

// 1. Backfill structure_type from period_mode / flags when NULL.
$sql = "SELECT program_code, structure_type, period_mode, uses_terms, uses_semesters, is_short_course, academic_structure
          FROM programs";
$res = $db->query($sql);
while ($row = $res->fetch_assoc()) {
    $code = (string)$row['program_code'];
    $current = (string)($row['structure_type'] ?? '');
    $expected = wuc_program_structure_type($db, $code);
    if ($expected === '' || $current === $expected) {
        continue;
    }
    $fixes[] = ['table' => 'programs', 'key' => $code, 'field' => 'structure_type', 'from' => $current, 'to' => $expected];
    if (!$dryRun) {
        $stmt = $db->prepare('UPDATE programs SET structure_type = ? WHERE program_code = ?');
        $stmt->bind_param('ss', $expected, $code);
        $stmt->execute();
        $stmt->close();
    }
}
echo 'structure_type backfill: ' . count(array_filter($fixes, static fn($f) => $f['field'] === 'structure_type')) . " row(s)\n";

// 2. Align period_mode with structure_type when mismatched.
$res = $db->query("SELECT program_code, structure_type, period_mode FROM programs");
while ($row = $res->fetch_assoc()) {
    $code = (string)$row['program_code'];
    $structure = (string)($row['structure_type'] ?? '');
    $mode = (string)($row['period_mode'] ?? '');
    $expectedMode = 'semester';
    if ($structure === 'TERM_BASED' || $structure === 'TRADE_TEST_LEVEL') {
        $expectedMode = 'term';
    } elseif ($structure === 'SEMESTER_BASED') {
        $expectedMode = 'semester';
    } elseif ($mode === 'term' || $mode === 'semester') {
        continue;
    }
    if ($mode === $expectedMode) {
        continue;
    }
    $fixes[] = ['table' => 'programs', 'key' => $code, 'field' => 'period_mode', 'from' => $mode, 'to' => $expectedMode];
    if (!$dryRun) {
        $stmt = $db->prepare('UPDATE programs SET period_mode = ? WHERE program_code = ?');
        $stmt->bind_param('ss', $expectedMode, $code);
        $stmt->execute();
        $stmt->close();
    }
}
echo "period_mode alignment: " . count(array_filter($fixes, static fn($f) => $f['field'] === 'period_mode')) . " row(s)\n";

// 3. semester_registration.period_type must match programme structure.
if ($db->query("SHOW COLUMNS FROM semester_registration LIKE 'period_type'")->num_rows) {
    $res = $db->query(
        "SELECT sr.id, sr.student_id, sr.program_code, sr.period_type, sr.semester, sr.year_of_study
           FROM semester_registration sr
           WHERE sr.program_code IS NOT NULL AND sr.program_code <> ''"
    );
    $periodFixes = 0;
    while ($row = $res->fetch_assoc()) {
        $programCode = (string)$row['program_code'];
        $structure = wuc_program_structure_type($db, $programCode);
        $expected = 'semester';
        if ($structure === 'TERM_BASED') {
            $expected = 'term';
        } elseif ($structure === 'TRADE_TEST_LEVEL') {
            $expected = 'trade_test_level';
        } elseif ($structure === 'SHORT_COURSE') {
            $expected = 'short_course_cycle';
        }
        $current = (string)($row['period_type'] ?? 'semester');
        if ($current === $expected) {
            continue;
        }
        $periodFixes++;
        if (!$dryRun) {
            $id = (int)$row['id'];
            $stmt = $db->prepare('UPDATE semester_registration SET period_type = ? WHERE id = ?');
            $stmt->bind_param('si', $expected, $id);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo "semester_registration.period_type fixes: {$periodFixes}\n";
}

// 4. Report invalid program_courses period numbers (no destructive delete).
$res = $db->query(
    "SELECT pc.id, pc.program_code, pc.course_code, pc.year, pc.semester, p.structure_type
       FROM program_courses pc
       JOIN programs p ON p.program_code = pc.program_code"
);
$invalidPc = 0;
while ($row = $res->fetch_assoc()) {
    $structure = (string)($row['structure_type'] ?: wuc_program_structure_type($db, (string)$row['program_code']));
    if (!wuc_structure_validate_period($structure, (int)$row['semester'])) {
        $invalidPc++;
        echo "  INVALID program_courses id={$row['id']} {$row['program_code']}/{$row['course_code']} period={$row['semester']} structure={$structure}\n";
    }
}
echo "Invalid program_courses rows (manual review): {$invalidPc}\n";

echo str_repeat('-', 60) . "\n";
echo ($dryRun ? 'Dry run complete — no changes written.' : 'Repair complete.') . "\n";
