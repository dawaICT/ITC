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
            if (getenv((string)$name) === false) {
                putenv((string)$name . '=' . (string)$value);
            }
        }
    }
}
$migrationUser = (string)(getenv('WUC_MIGRATION_DB_USER') ?: '');
$migrationPassword = (string)(getenv('WUC_MIGRATION_DB_PASSWORD') ?: '');
if ($migrationUser === '' || $migrationPassword === '') {
    throw new RuntimeException('Dedicated migration database credentials are required.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(
    (string)(getenv('WUC_DB_HOST') ?: '127.0.0.1'),
    $migrationUser,
    $migrationPassword,
    (string)(getenv('WUC_DB_NAME') ?: 'wucportal'),
    (int)(getenv('WUC_DB_PORT') ?: 3306)
);
$db->set_charset('utf8mb4');

$only = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--only=')) {
        $only = basename(substr($argument, 7));
    }
}

if (!$db->query("SELECT GET_LOCK('wucportal_migrations', 10)")->fetch_row()[0]) {
    fwrite(STDERR, "Could not acquire migration lock.\n");
    exit(1);
}

try {
    $db->query("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(255) PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        applied_by VARCHAR(128) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    if ($only !== null) {
        $files = array_values(array_filter($files, static fn(string $file): bool => basename($file) === $only));
        if (!$files) {
            throw new RuntimeException("Migration not found: {$only}");
        }
    }
    $applied = 0;
    foreach ($files as $file) {
        $name = basename($file);
        $checksum = hash_file('sha256', $file);
        $stmt = $db->prepare('SELECT checksum FROM schema_migrations WHERE migration = ?');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            if (!hash_equals($row['checksum'], $checksum)) {
                throw new RuntimeException("Applied migration changed on disk: {$name}");
            }
            echo "SKIP {$name}\n";
            continue;
        }

        $sql = trim((string)file_get_contents($file));
        if ($sql === '') {
            throw new RuntimeException("Migration is empty: {$name}");
        }
        if (!$db->multi_query($sql)) {
            throw new RuntimeException("Migration failed: {$name}: {$db->error}");
        }
        do {
            if ($result = $db->store_result()) {
                $result->free();
            }
        } while ($db->more_results() && $db->next_result());
        if ($db->errno) {
            throw new RuntimeException("Migration failed: {$name}: {$db->error}");
        }

        $actor = get_current_user() ?: 'deployment';
        $insert = $db->prepare('INSERT INTO schema_migrations (migration, checksum, applied_by) VALUES (?, ?, ?)');
        $insert->bind_param('sss', $name, $checksum, $actor);
        $insert->execute();
        $insert->close();
        echo "APPLIED {$name}\n";
        $applied++;
    }
    echo "MIGRATIONS COMPLETE: {$applied} applied\n";
} finally {
    $db->query("SELECT RELEASE_LOCK('wucportal_migrations')");
}
