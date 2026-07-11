<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$operationsConfig = getenv('WUC_OPERATIONS_CONFIG') ?: dirname(__DIR__, 3) . '/wucportal-var/config/operations.php';
if (is_file($operationsConfig)) {
    $operationValues = require $operationsConfig;
    if (is_array($operationValues)) {
        foreach ($operationValues as $name => $value) {
            if (getenv((string)$name) === false) putenv((string)$name . '=' . (string)$value);
        }
    }
}
$required = ['WUC_DB_HOST', 'WUC_DB_NAME', 'WUC_BACKUP_DB_USER', 'WUC_BACKUP_DB_PASSWORD', 'WUC_BACKUP_DIR'];
foreach ($required as $name) {
    if (trim((string)getenv($name)) === '') {
        fwrite(STDERR, "Missing {$name}\n");
        exit(1);
    }
}

$dir = rtrim((string)getenv('WUC_BACKUP_DIR'), '/\\');
if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    throw new RuntimeException("Cannot create backup directory: {$dir}");
}
$docRoot = realpath(dirname(__DIR__));
$realDir = realpath($dir);
if ($docRoot && $realDir && str_starts_with(strtolower($realDir), strtolower($docRoot))) {
    throw new RuntimeException('Backup directory must be outside the web document root.');
}

$binary = getenv('WUC_MYSQLDUMP') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump');
$database = (string)getenv('WUC_DB_NAME');
$target = $dir . DIRECTORY_SEPARATOR . $database . '_' . gmdate('Ymd_His') . '.sql';
$stream = fopen($target, 'xb');
if (!$stream) {
    throw new RuntimeException("Cannot create backup: {$target}");
}
$command = [$binary, '--protocol=TCP', '--single-transaction', '--skip-lock-tables', '--triggers', '--hex-blob', '--host=' . getenv('WUC_DB_HOST'), '--port=' . (getenv('WUC_DB_PORT') ?: '3306'), '--user=' . getenv('WUC_BACKUP_DB_USER'), $database];
// Let the child inherit the complete host environment (required by Winsock on
// Windows); MYSQL_PWD avoids exposing the secret in the process command line.
putenv('MYSQL_PWD=' . (string)getenv('WUC_BACKUP_DB_PASSWORD'));
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => $stream, 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    fclose($stream);
    @unlink($target);
    throw new RuntimeException('Could not start mysqldump.');
}
fclose($pipes[0]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$status = proc_close($process);
putenv('MYSQL_PWD');
fclose($stream);
if ($status !== 0 || filesize($target) === 0) {
    @unlink($target);
    throw new RuntimeException('Backup failed: ' . trim((string)$error));
}
@chmod($target, 0600);

$replicaDir = rtrim((string)(getenv('WUC_BACKUP_REPLICA_DIR') ?: ''), '/\\');
if ($replicaDir !== '') {
    if (!is_dir($replicaDir) && !mkdir($replicaDir, 0750, true) && !is_dir($replicaDir)) {
        throw new RuntimeException("Cannot create replica directory: {$replicaDir}");
    }
    $replica = $replicaDir . DIRECTORY_SEPARATOR . basename($target);
    if (!copy($target, $replica) || !hash_equals(hash_file('sha256', $target), hash_file('sha256', $replica))) {
        @unlink($replica);
        throw new RuntimeException('Backup replica copy or checksum verification failed.');
    }
    @chmod($replica, 0600);
}

$retention = max(1, (int)(getenv('WUC_BACKUP_RETENTION_DAYS') ?: 30));
$cutoff = time() - ($retention * 86400);
foreach (glob($dir . DIRECTORY_SEPARATOR . $database . '_*.sql') ?: [] as $old) {
    if (filemtime($old) < $cutoff) {
        @unlink($old);
    }
}
echo "BACKUP OK: {$target} (" . filesize($target) . " bytes)\n";
