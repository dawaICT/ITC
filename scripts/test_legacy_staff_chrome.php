<?php
$module = (string) ($argv[1] ?? '');
if (!in_array($module, ['registrar', 'vc'], true)) {
    fwrite(STDERR, "Usage: php test_legacy_staff_chrome.php registrar|vc\n");
    exit(2);
}

$sessionPath = dirname(__DIR__) . '/tmp/session-tests';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}
session_save_path($sessionPath);
session_start();
$_SESSION = [
    'staff_id' => 'WUC900',
    'user_id' => 'WUC900',
    'last_activity' => time(),
];
$_SERVER['PHP_SELF'] = "/wucportal/{$module}/index.php";
$_SERVER['REQUEST_URI'] = $_SERVER['PHP_SELF'];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require dirname(__DIR__) . "/{$module}/includes/admin.php";
echo '<!DOCTYPE html><html><head><title>Legacy page</title></head><body><main>Content</main></body></html>';
ob_end_flush();
$html = ob_get_clean();

$checks = [
    'shared responsive navigation' => 'legacy-portal-nav',
    'canonical Bootstrap version' => 'bootstrap@5.3.2',
    'canonical Font Awesome version' => 'font-awesome/6.4.0',
    'secure logout form' => 'action="/wucportal/logout.php"',
    'ITC logo' => '/wucportal/images/itc_logo.png',
];
foreach ($checks as $label => $needle) {
    if (strpos($html, $needle) === false) {
        fwrite(STDERR, "FAIL: {$module} chrome missing {$label}.\n");
        exit(1);
    }
    echo "PASS: {$module} chrome has {$label}.\n";
}

if (substr_count(strtolower($html), '<!doctype html>') !== 1
    || substr_count(strtolower($html), '<html') !== 1
    || substr_count(strtolower($html), '<body') !== 1) {
    fwrite(STDERR, "FAIL: {$module} chrome produced a nested document shell.\n");
    exit(1);
}
if (strpos($html, '<nav class="navbar navbar-expand-lg legacy-portal-nav') < strpos($html, '<body')) {
    fwrite(STDERR, "FAIL: {$module} navigation was not injected inside the body.\n");
    exit(1);
}
echo "PASS: {$module} chrome preserves one valid document shell.\n";

echo ucfirst($module) . " shared chrome checks passed.\n";
