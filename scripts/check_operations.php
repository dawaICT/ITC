<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/portal_config.php';
$errors = [];
$backupDir = rtrim((string)(getenv('WUC_BACKUP_DIR') ?: ''), '/\\');
$logDir = rtrim((string)(getenv('WUC_LOG_DIR') ?: ''), '/\\');

$backups = $backupDir !== '' ? (glob($backupDir . DIRECTORY_SEPARATOR . '*.sql') ?: []) : [];
usort($backups, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
if (!$backups) {
    $errors[] = 'No database backup is available.';
} elseif (filemtime($backups[0]) < time() - 26 * 3600) {
    $errors[] = 'Latest database backup is older than 26 hours.';
}
foreach (['backup' => $backupDir, 'log' => $logDir] as $label => $dir) {
    if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
        $errors[] = ucfirst($label) . ' directory is missing or not writable.';
        continue;
    }
    $free = disk_free_space($dir);
    if ($free !== false && $free < 2 * 1024 * 1024 * 1024) {
        $errors[] = ucfirst($label) . ' volume has less than 2 GB free.';
    }
}
foreach ($errors as $error) fwrite(STDERR, "FAIL: {$error}\n");
if ($errors) exit(1);
echo 'OPERATIONS OK; latest_backup=' . basename($backups[0]) . '; age_minutes=' . (int)((time() - filemtime($backups[0])) / 60) . "\n";
