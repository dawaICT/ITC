<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/assignment_storage.php';

$status = assignmentStorageConfigStatus();

echo "Assignment Storage Diagnostic\n";
echo "=============================\n";
echo 'Archive mode: ' . ($status['archive_mode'] ?? 'unknown') . PHP_EOL;
echo 'Archive ready: ' . ($status['drive_ready'] ? 'yes' : 'no') . PHP_EOL;
echo 'Message: ' . $status['drive_message'] . PHP_EOL;
if (!empty($status['local_archive_path'])) {
    echo 'Local archive path: ' . $status['local_archive_path'] . PHP_EOL;
}
if (!empty($status['online_archive_url'])) {
    echo 'Online archive provider: ' . ($status['online_archive_provider'] ?? 'Online archive') . PHP_EOL;
    echo 'Online archive URL: ' . $status['online_archive_url'] . PHP_EOL;
}
echo 'Service-account JSON: ' . ($status['service_account_json_path'] ?: '(not set)') . PHP_EOL;
echo 'Service-account email: ' . ($status['service_account_email'] ?: '(not available)') . PHP_EOL;
echo 'Root folder ID: ' . ($status['drive_root_folder_id'] ?: '(not set; service-account Drive root will be used)') . PHP_EOL;
echo 'PHP cURL: ' . (function_exists('curl_init') ? 'yes' : 'no') . PHP_EOL;
echo 'PHP OpenSSL: ' . (function_exists('openssl_sign') ? 'yes' : 'no') . PHP_EOL;
echo 'AI review provider: ' . $status['ai_provider'] . PHP_EOL;

exit($status['drive_ready'] ? 0 : 1);
