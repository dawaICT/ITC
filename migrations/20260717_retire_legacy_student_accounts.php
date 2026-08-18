<?php
/**
 * Retire the orphaned MyISAM/BIGINT student_accounts mirror.
 *
 * The original table is preserved verbatim as
 * student_accounts_legacy_archive_20260717. The public name becomes a
 * read-only aggregate view over authoritative student_fee_accounts.
 *
 * Usage:
 *   php migrations/20260717_retire_legacy_student_accounts.php --dry-run
 *   php migrations/20260717_retire_legacy_student_accounts.php --apply
 *
 * Manual rollback:
 *   DROP VIEW student_accounts;
 *   RENAME TABLE student_accounts_legacy_archive_20260717 TO student_accounts;
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../db/connect.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$apply = in_array('--apply', $argv ?? [], true);
if (!$dryRun && !$apply) {
    fwrite(STDERR, "Usage: php migrations/20260717_retire_legacy_student_accounts.php [--dry-run|--apply]\n");
    exit(1);
}

$archiveName = 'student_accounts_legacy_archive_20260717';
$databaseResult = $db->query('SELECT DATABASE() AS db_name');
$databaseName = (string)($databaseResult->fetch_assoc()['db_name'] ?? '');
$databaseResult->free();
if ($databaseName === '') {
    fwrite(STDERR, "No database is selected.\n");
    exit(1);
}

/** @return string|null BASE TABLE, VIEW, or null */
$objectType = static function (mysqli $db, string $database, string $name): ?string {
    $stmt = $db->prepare(
        'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $database, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row ? (string)$row['TABLE_TYPE'] : null;
};

$canonicalType = $objectType($db, $databaseName, 'student_fee_accounts');
if ($canonicalType !== 'BASE TABLE') {
    fwrite(STDERR, "student_fee_accounts is missing; refusing to create a compatibility view without its authoritative source.\n");
    exit(2);
}

$requiredColumns = ['id', 'student_id', 'balance', 'status', 'created_at', 'updated_at'];
$columnsResult = $db->query('SHOW COLUMNS FROM student_fee_accounts');
$columns = [];
while ($row = $columnsResult->fetch_assoc()) {
    $columns[] = (string)$row['Field'];
}
$columnsResult->free();
$missingColumns = array_values(array_diff($requiredColumns, $columns));
if ($missingColumns) {
    fwrite(STDERR, 'student_fee_accounts is missing required column(s): ' . implode(', ', $missingColumns) . "\n");
    exit(2);
}

$sourceType = $objectType($db, $databaseName, 'student_accounts');
$archiveType = $objectType($db, $databaseName, $archiveName);
$legacyRows = 0;
$orphanRows = 0;
if ($sourceType === 'BASE TABLE') {
    $rowResult = $db->query('SELECT COUNT(*) AS total FROM student_accounts');
    $legacyRows = (int)($rowResult->fetch_assoc()['total'] ?? 0);
    $rowResult->free();

    $orphanResult = $db->query(
        'SELECT COUNT(*) AS total
           FROM student_accounts sa
      LEFT JOIN students s ON CONVERT(sa.SID, CHAR) = s.SID
          WHERE s.SID IS NULL'
    );
    $orphanRows = (int)($orphanResult->fetch_assoc()['total'] ?? 0);
    $orphanResult->free();
}

$viewSql = "CREATE OR REPLACE VIEW student_accounts AS
            SELECT MIN(id) AS id,
                   student_id AS SID,
                   ROUND(SUM(balance), 2) AS balance,
                   MAX(updated_at) AS last_payment_date,
                   MIN(created_at) AS created_at,
                   MAX(updated_at) AS updated_at
              FROM student_fee_accounts
             WHERE status = 'active'
             GROUP BY student_id";

echo '[state] student_accounts=' . ($sourceType ?? 'missing')
    . '; archive=' . ($archiveType ?? 'missing')
    . '; legacy_rows=' . $legacyRows
    . '; orphan_rows=' . $orphanRows . PHP_EOL;

if ($dryRun) {
    if ($sourceType === 'BASE TABLE') {
        if ($archiveType !== null) {
            fwrite(STDERR, "[blocked] {$archiveName} already exists while student_accounts is still a base table. Reconcile the names manually.\n");
            exit(3);
        }
        echo "[dry-run] RENAME TABLE student_accounts TO {$archiveName}\n";
    }
    echo '[dry-run] ' . preg_replace('/\s+/', ' ', trim($viewSql)) . PHP_EOL;
    exit(0);
}

try {
    if ($sourceType === 'BASE TABLE') {
        if ($archiveType !== null) {
            throw new RuntimeException("{$archiveName} already exists while student_accounts is still a base table.");
        }
        $db->query("RENAME TABLE student_accounts TO {$archiveName}");
        echo "[applied] archived {$legacyRows} legacy row(s) as {$archiveName}\n";
    }

    $db->query($viewSql);
    echo "[applied] student_accounts compatibility view now reads student_fee_accounts\n";

    $finalType = $objectType($db, $databaseName, 'student_accounts');
    $finalArchiveType = $objectType($db, $databaseName, $archiveName);
    if ($finalType !== 'VIEW') {
        throw new RuntimeException('student_accounts was not created as a view.');
    }
    if ($sourceType === 'BASE TABLE' && $finalArchiveType !== 'BASE TABLE') {
        throw new RuntimeException('The legacy archive table could not be verified.');
    }

    $viewRowsResult = $db->query('SELECT COUNT(*) AS total FROM student_accounts');
    $viewRows = (int)($viewRowsResult->fetch_assoc()['total'] ?? 0);
    $viewRowsResult->free();
    echo "[verified] compatibility_view_rows={$viewRows}; archived_rows={$legacyRows}; archived_orphans={$orphanRows}\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[failed] ' . $e->getMessage() . PHP_EOL);
    exit(4);
}

