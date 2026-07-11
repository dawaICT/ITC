<?php
/**
 * Apply optional UNIQUE indexes to harden manual-entry duplicate prevention.
 *
 * Run AFTER deduplicating existing rows:
 *   php migrations/manual_entry_unique_indexes.php --dry-run
 *   php migrations/manual_entry_unique_indexes.php --apply
 *
 * Does not run automatically — inspect dry-run output first.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$apply = in_array('--apply', $argv ?? [], true);

if (!$dryRun && !$apply) {
    echo "Usage: php migrations/manual_entry_unique_indexes.php [--dry-run|--apply]\n";
    exit(1);
}

$indexes = [
    [
        'table' => 'semester_registration',
        'name' => 'uq_sem_reg_period',
        'sql' => 'ALTER TABLE semester_registration ADD UNIQUE KEY uq_sem_reg_period (student_id, program_code, semester, period_type, year_of_study, academic_year)',
    ],
    [
        'table' => 'students',
        'name' => 'uq_students_email',
        'sql' => 'ALTER TABLE students ADD UNIQUE KEY uq_students_email (email)',
    ],
    [
        'table' => 'students',
        'name' => 'uq_students_nrc',
        'sql' => 'ALTER TABLE students ADD UNIQUE KEY uq_students_nrc (nrc_pass)',
    ],
    [
        'table' => 'payments',
        'name' => 'uq_payments_student_receipt',
        'sql' => 'ALTER TABLE payments ADD UNIQUE KEY uq_payments_student_receipt (student_id, receipt_no)',
    ],
    [
        'table' => 'staff',
        'name' => 'uq_staff_email',
        'sql' => 'ALTER TABLE staff ADD UNIQUE KEY uq_staff_email (email)',
    ],
];

function index_exists(mysqli $db, string $table, string $indexName): bool
{
    $safeTable = $db->real_escape_string($table);
    $safeIndex = $db->real_escape_string($indexName);
    $res = $db->query("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'");
    $exists = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $exists;
}

function table_exists(mysqli $db, string $table): bool
{
    $safeTable = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safeTable}'");
    $exists = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $exists;
}

foreach ($indexes as $idx) {
    $table = $idx['table'];
    $name = $idx['name'];
    if (!table_exists($db, $table)) {
        echo "[skip] Table {$table} not found\n";
        continue;
    }
    if (index_exists($db, $table, $name)) {
        echo "[ok] {$table}.{$name} already exists\n";
        continue;
    }
    if ($dryRun) {
        echo "[dry-run] Would run: {$idx['sql']}\n";
        continue;
    }
    try {
        if ($db->query($idx['sql'])) {
            echo "[applied] {$table}.{$name}\n";
        } else {
            echo "[fail] {$table}.{$name}: {$db->error}\n";
        }
    } catch (Throwable $e) {
        echo "[fail] {$table}.{$name}: {$e->getMessage()}\n";
    }
}

echo "Done.\n";
