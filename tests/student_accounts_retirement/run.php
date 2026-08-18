<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db/connect.php';

$passed = 0;
$failed = 0;

function check(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo '[PASS] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
        return;
    }
    $failed++;
    echo '[FAIL] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
}

function source(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $contents;
}

function objectType(mysqli $db, string $name): ?string
{
    $stmt = $db->prepare(
        'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row ? (string)$row['TABLE_TYPE'] : null;
}

$feeHelpers = source($root . '/includes/fees_helpers.php');
$oldSetup = source($root . '/admin/setup_database.sql');
$oldFix = source($root . '/fix_tables.sql');
$migration = source($root . '/migrations/20260717_retire_legacy_student_accounts.php');

check(strpos($feeHelpers, 'UPDATE student_accounts') === false, 'runtime no longer updates the legacy mirror');
check(strpos($feeHelpers, 'INSERT INTO student_accounts') === false, 'runtime no longer inserts legacy mirror rows');
check(stripos($oldSetup, 'DROP TABLE') === false, 'retired setup script cannot drop tables');
check(stripos($oldSetup, 'CREATE TABLE') === false, 'retired setup script cannot recreate MyISAM/BIGINT schemas');
check(stripos($oldFix, 'DROP TABLE') === false, 'retired emergency fix cannot drop student or payment tables');
check(strpos($migration, 'RENAME TABLE student_accounts TO {$archiveName}') !== false, 'migration preserves the original table verbatim');
check(strpos($migration, 'CREATE OR REPLACE VIEW student_accounts') !== false, 'migration provides a read-compatible canonical view');
check(strpos($migration, "WHERE status = 'active'") !== false, 'compatibility view excludes inactive fee accounts');

check(objectType($db, 'student_accounts') === 'VIEW', 'student_accounts is a read-only compatibility view');
check(objectType($db, 'student_accounts_legacy_archive_20260717') === 'BASE TABLE', 'legacy rows are preserved in an archive table');

$archive = $db->query(
    'SELECT COUNT(*) AS total,
            SUM(CASE WHEN SID = 0 THEN 1 ELSE 0 END) AS zero_rows
       FROM student_accounts_legacy_archive_20260717'
)->fetch_assoc();
check((int)($archive['total'] ?? 0) === 6, 'all six legacy rows were archived', 'rows=' . (string)($archive['total'] ?? ''));
check((int)($archive['zero_rows'] ?? 0) === 1, 'the historical SID=0 anomaly remains preserved for audit');

$viewRows = $db->query('SELECT SID, balance FROM student_accounts ORDER BY SID')->fetch_all(MYSQLI_ASSOC);
$canonicalRows = $db->query(
    "SELECT student_id AS SID, ROUND(SUM(balance), 2) AS balance
       FROM student_fee_accounts
      WHERE status = 'active'
      GROUP BY student_id
      ORDER BY student_id"
)->fetch_all(MYSQLI_ASSOC);
check($viewRows === $canonicalRows, 'compatibility view exactly matches active canonical account balances', json_encode($viewRows));
check(count(array_filter($viewRows, static fn(array $row): bool => (string)$row['SID'] === '0')) === 0, 'orphan SID=0 is absent from current account reads');

$writeRejected = false;
try {
    $db->query("INSERT INTO student_accounts (SID, balance) VALUES ('SHOULD-NOT-WRITE', 1.00)");
} catch (Throwable $e) {
    $writeRejected = true;
}
check($writeRejected, 'compatibility view rejects direct legacy writes');
$residue = $db->query("SELECT COUNT(*) AS total FROM student_fee_accounts WHERE student_id = 'SHOULD-NOT-WRITE'")->fetch_assoc();
check((int)($residue['total'] ?? 0) === 0, 'rejected compatibility write leaves no canonical residue');

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
