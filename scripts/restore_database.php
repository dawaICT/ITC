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
$file = $argv[1] ?? '';
$database = (string)(getenv('WUC_RESTORE_DB_NAME') ?: getenv('WUC_DB_NAME'));
$confirmed = in_array('--confirm=' . $database, $argv, true);
if (!$confirmed || !is_file($file)) {
    fwrite(STDERR, "Usage: php scripts/restore_database.php <backup.sql> --confirm={$database}\n");
    exit(2);
}
foreach (['WUC_DB_HOST', 'WUC_MIGRATION_DB_USER', 'WUC_MIGRATION_DB_PASSWORD'] as $name) {
    if (trim((string)getenv($name)) === '') {
        throw new RuntimeException("Missing {$name}");
    }
}
$binary = getenv('WUC_MYSQL') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysql.exe' : 'mysql');
$command = [$binary, '--protocol=TCP', '--host=' . getenv('WUC_DB_HOST'), '--port=' . (getenv('WUC_DB_PORT') ?: '3306'), '--user=' . getenv('WUC_MIGRATION_DB_USER'), $database];
$input = fopen($file, 'rb');
putenv('MYSQL_PWD=' . (string)getenv('WUC_MIGRATION_DB_PASSWORD'));
$process = proc_open($command, [0 => $input, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start mysql client.');
}
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);
putenv('MYSQL_PWD');
fclose($input);
if ($status !== 0) {
    throw new RuntimeException('Restore failed: ' . trim($error));
}
echo "RESTORE OK: {$file}\n{$output}";
