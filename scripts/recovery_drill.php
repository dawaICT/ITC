<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array('--confirm-drill', $argv, true)) {
    fwrite(STDERR, "Usage: php scripts/recovery_drill.php <backup.sql> --confirm-drill\n");
    exit(2);
}
$backup = $argv[1] ?? '';
if (!is_file($backup)) {
    throw new RuntimeException('Backup file was not found.');
}
$configFile = getenv('WUC_OPERATIONS_CONFIG') ?: dirname(__DIR__, 3) . '/wucportal-var/config/operations.php';
$values = is_file($configFile) ? require $configFile : [];
foreach ($values as $name => $value) {
    if (getenv((string)$name) === false) putenv((string)$name . '=' . (string)$value);
}

$sourceName = (string)getenv('WUC_DB_NAME');
$drillName = preg_replace('/[^A-Za-z0-9_]/', '', $sourceName) . '_restore_drill';
$admin = new mysqli(
    (string)(getenv('WUC_ADMIN_DB_HOST') ?: '127.0.0.1'),
    (string)(getenv('WUC_ADMIN_DB_USER') ?: 'root'),
    (string)(getenv('WUC_ADMIN_DB_PASSWORD') ?: ''),
    '',
    (int)(getenv('WUC_ADMIN_DB_PORT') ?: 3306)
);
$admin->query("DROP DATABASE IF EXISTS `{$drillName}`");
$admin->query("CREATE DATABASE `{$drillName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$migrationUser = $admin->real_escape_string((string)getenv('WUC_MIGRATION_DB_USER'));
$admin->query("GRANT ALL PRIVILEGES ON `{$drillName}`.* TO '{$migrationUser}'@'127.0.0.1'");

$started = microtime(true);
try {
    putenv('WUC_RESTORE_DB_NAME=' . $drillName);
    $command = [PHP_BINARY, __DIR__ . '/restore_database.php', realpath($backup), '--confirm=' . $drillName];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('Restore process failed: ' . trim($error));
    }

    $source = new mysqli((string)getenv('WUC_DB_HOST'), (string)getenv('WUC_MIGRATION_DB_USER'), (string)getenv('WUC_MIGRATION_DB_PASSWORD'), $sourceName, (int)(getenv('WUC_DB_PORT') ?: 3306));
    $restored = new mysqli((string)getenv('WUC_DB_HOST'), (string)getenv('WUC_MIGRATION_DB_USER'), (string)getenv('WUC_MIGRATION_DB_PASSWORD'), $drillName, (int)(getenv('WUC_DB_PORT') ?: 3306));
    $tables = ['students', 'student_login', 'student_program', 'programs', 'courses', 'payments', 'fee_structure', 'semester_registration'];
    foreach ($tables as $table) {
        $sourceCount = (int)$source->query("SELECT COUNT(*) c FROM `{$table}`")->fetch_assoc()['c'];
        $restoredCount = (int)$restored->query("SELECT COUNT(*) c FROM `{$table}`")->fetch_assoc()['c'];
        if ($sourceCount !== $restoredCount) {
            throw new RuntimeException("Row count mismatch for {$table}: source={$sourceCount}, restored={$restoredCount}");
        }
        echo "MATCH {$table}: {$sourceCount}\n";
    }
    echo 'RECOVERY DRILL PASSED in ' . number_format(microtime(true) - $started, 2) . " seconds\n";
} finally {
    $admin->query("DROP DATABASE IF EXISTS `{$drillName}`");
    try {
        $admin->query("REVOKE ALL PRIVILEGES ON `{$drillName}`.* FROM '{$migrationUser}'@'127.0.0.1'");
    } catch (Throwable $ignored) {
    }
}
