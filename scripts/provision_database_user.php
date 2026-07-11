<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$password = (string)(getenv('WUC_PROVISION_APP_PASSWORD') ?: '');
$user = (string)(getenv('WUC_PROVISION_APP_USER') ?: 'wucportal_app');
$host = (string)(getenv('WUC_PROVISION_APP_HOST') ?: 'localhost');
$database = (string)(getenv('WUC_DB_NAME') ?: 'wucportal');
$role = strtolower((string)(getenv('WUC_PROVISION_ROLE') ?: 'application'));
if (!in_array($role, ['application', 'migration', 'backup'], true)) {
    throw new RuntimeException('WUC_PROVISION_ROLE must be application, migration, or backup.');
}
if (strlen($password) < 20) {
    throw new RuntimeException('WUC_PROVISION_APP_PASSWORD must contain at least 20 characters.');
}
foreach (['user' => $user, 'host' => $host, 'database' => $database] as $label => $value) {
    if (!preg_match('/^[A-Za-z0-9_.%-]+$/', $value)) {
        throw new RuntimeException("Invalid {$label} value.");
    }
}

// This script intentionally requires a separately supplied administrative
// connection. Never grant schema-change privileges to the application user.
$adminHost = (string)(getenv('WUC_ADMIN_DB_HOST') ?: '127.0.0.1');
$adminPort = (int)(getenv('WUC_ADMIN_DB_PORT') ?: 3306);
$adminUser = (string)(getenv('WUC_ADMIN_DB_USER') ?: 'root');
$adminPassword = (string)(getenv('WUC_ADMIN_DB_PASSWORD') ?: '');
$db = new mysqli($adminHost, $adminUser, $adminPassword, '', $adminPort);
$db->set_charset('utf8mb4');

$qUser = "'" . $db->real_escape_string($user) . "'";
$qHost = "'" . $db->real_escape_string($host) . "'";
$qPassword = "'" . $db->real_escape_string($password) . "'";
$qDatabase = '`' . str_replace('`', '``', $database) . '`';
$db->query("CREATE USER IF NOT EXISTS {$qUser}@{$qHost} IDENTIFIED BY {$qPassword}");
$db->query("ALTER USER {$qUser}@{$qHost} IDENTIFIED BY {$qPassword}");
$grants = [
    'application' => 'SELECT, INSERT, UPDATE, DELETE, EXECUTE',
    'migration' => 'ALL PRIVILEGES',
    'backup' => 'SELECT, SHOW VIEW, TRIGGER, EVENT',
][$role];
$db->query("GRANT {$grants} ON {$qDatabase}.* TO {$qUser}@{$qHost}");
$db->query("FLUSH PRIVILEGES");
echo ucfirst($role) . " database user provisioned.\n";
