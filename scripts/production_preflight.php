<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$skipDb = in_array('--skip-db', $argv, true);
$errors = [];
$warnings = [];

require_once dirname(__DIR__) . '/includes/portal_config.php';

if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    $errors[] = 'PHP 8.2 or newer is required; found ' . PHP_VERSION;
}

foreach (['mysqli', 'mbstring', 'json', 'openssl', 'fileinfo'] as $extension) {
    if (!extension_loaded($extension)) {
        $errors[] = "Required PHP extension is missing: {$extension}";
    }
}

if ((getenv('APP_ENV') ?: '') !== 'production') {
    $errors[] = 'APP_ENV must be production.';
}

$publicUrl = (string)(getenv('WUC_PUBLIC_URL') ?: '');
$publicHost = strtolower((string)parse_url($publicUrl, PHP_URL_HOST));
if (strtolower((string)parse_url($publicUrl, PHP_URL_SCHEME)) !== 'https'
    || $publicHost === ''
    || in_array($publicHost, ['localhost', '127.0.0.1', '::1'], true)) {
    $errors[] = 'WUC_PUBLIC_URL must be the externally verified HTTPS production URL.';
}

foreach (['WUC_DB_HOST', 'WUC_DB_NAME', 'WUC_DB_USER', 'WUC_DB_PASSWORD', 'WUC_LOG_DIR', 'WUC_BACKUP_DIR'] as $name) {
    if (trim((string)getenv($name)) === '') {
        $errors[] = "Required environment variable is missing: {$name}";
    }
}

if (strtolower((string)getenv('WUC_DB_USER')) === 'root') {
    $errors[] = 'WUC_DB_USER must be a least-privilege account, not root.';
}

if ((string)getenv('WUC_BACKUP_ENCRYPTED_AT_REST') !== '1') {
    $errors[] = 'Backups must be stored on an encrypted volume (set WUC_BACKUP_ENCRYPTED_AT_REST=1 after verification).';
}

foreach (['WUC_LOG_DIR', 'WUC_BACKUP_DIR'] as $name) {
    $path = trim((string)getenv($name));
    if ($path !== '') {
        $realParent = realpath(is_dir($path) ? $path : dirname($path));
        $docRoot = realpath(dirname(__DIR__));
        if ($realParent !== false && $docRoot !== false && str_starts_with(strtolower($realParent), strtolower($docRoot))) {
            $errors[] = "{$name} must be outside the web document root.";
        }
    }
}

if (!$skipDb && !$errors) {
    try {
        require dirname(__DIR__) . '/db/connect.php';
        $required = ['students', 'student_login', 'student_program', 'programs', 'courses', 'payments', 'fee_structure', 'semester_registration'];
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        foreach ($required as $table) {
            $stmt->bind_param('s', $table);
            $stmt->execute();
            if ((int)$stmt->get_result()->fetch_assoc()['c'] !== 1) {
                $errors[] = "Required database table is missing: {$table}";
            }
        }
        $stmt->close();
        $pending = 0;
        $ledgerExists = $db->query("SHOW TABLES LIKE 'schema_migrations'")->num_rows > 0;
        if (!$ledgerExists) {
            $errors[] = 'Migration ledger is missing; run scripts/migrate.php.';
        } else {
            foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $file) {
                $name = basename($file);
                $check = $db->prepare('SELECT checksum FROM schema_migrations WHERE migration = ?');
                $check->bind_param('s', $name);
                $check->execute();
                $row = $check->get_result()->fetch_assoc();
                if (!$row) {
                    $pending++;
                } elseif (!hash_equals($row['checksum'], hash_file('sha256', $file))) {
                    $errors[] = "Applied migration was modified: {$name}";
                }
                $check->close();
            }
            if ($pending > 0) {
                $errors[] = "{$pending} SQL migration(s) are pending.";
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Database readiness check failed: ' . $e->getMessage();
    }
}

foreach ($warnings as $message) {
    fwrite(STDOUT, "WARN: {$message}\n");
}
foreach ($errors as $message) {
    fwrite(STDERR, "FAIL: {$message}\n");
}
if ($errors) {
    fwrite(STDERR, 'PREFLIGHT FAILED (' . count($errors) . " issue(s))\n");
    exit(1);
}
fwrite(STDOUT, "PREFLIGHT PASSED\n");
