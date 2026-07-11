<?php
/**
 * Apply repository schema on local XAMPP (run once as MySQL root).
 *
 * The portal connects as wucportal_app (DML only) — it cannot CREATE tables.
 * This script must be run with credentials that can execute DDL:
 *
 *   Get-Content migrations/20260703_digital_learning_repository.sql | mysql -u root wucportal
 *
 * Or on XAMPP Windows:
 *   Get-Content c:\xampp\htdocs\wucportal\migrations\20260703_digital_learning_repository.sql | c:\xampp\mysql\bin\mysql.exe -u root wucportal
 */
declare(strict_types=1);

$migration = __DIR__ . '/../migrations/20260703_digital_learning_repository.sql';
if (!is_file($migration)) {
    fwrite(STDERR, "Migration file not found.\n");
    exit(1);
}

$host = getenv('WUC_MIGRATOR_HOST') ?: '127.0.0.1';
$user = getenv('WUC_MIGRATOR_USER') ?: 'root';
$pass = getenv('WUC_MIGRATOR_PASSWORD') ?: '';
$dbName = getenv('WUC_DB_NAME') ?: 'wucportal';

$db = @new mysqli($host, $user, $pass, $dbName);
if ($db->connect_error) {
    fwrite(STDERR, 'Migrator connection failed: ' . $db->connect_error . PHP_EOL);
    exit(1);
}

$sql = file_get_contents($migration);
$statements = array_filter(array_map('trim', preg_split('/;\s*\n/', (string)$sql)));
$ok = 0;
$fail = 0;

foreach ($statements as $statement) {
    if ($statement === '' || str_starts_with($statement, '--')) {
        continue;
    }
    try {
        if ($db->query($statement)) {
            $ok++;
        } else {
            fwrite(STDERR, "Failed: {$db->error}\n");
            $fail++;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: {$e->getMessage()}\n");
        $fail++;
    }
}

$res = $db->query('SELECT COUNT(*) AS c FROM repository_categories');
$n = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;
echo "Done. OK={$ok} failed={$fail} repository_categories rows={$n}\n";
exit($fail > 0 ? 1 : 0);
