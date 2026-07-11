<?php
$module = (string) ($argv[1] ?? '');
if (!in_array($module, ['accounts', 'dean', 'hod', 'registrar', 'transport', 'vc'], true)) {
    fwrite(STDERR, "Usage: php test_unified_module_dashboard.php accounts|dean|hod|registrar|transport|vc\n");
    exit(2);
}
$requestedModule = $module;
$entryFile = $requestedModule === 'transport' ? 'transport_management.php' : 'index.php';

$sessionPath = dirname(__DIR__) . '/tmp/session-tests';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}
session_save_path($sessionPath);
session_start();
$_SESSION = [
    'staff_id' => 'WUC900',
    'user_id' => 'WUC900',
    'user_role' => 'staff',
    'last_activity' => time(),
];
$_SERVER['PHP_SELF'] = "/wucportal/{$module}/{$entryFile}";
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'];
$_SERVER['REQUEST_URI'] = $_SERVER['PHP_SELF'];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require dirname(__DIR__) . "/{$requestedModule}/{$entryFile}";
$html = ob_get_clean();

foreach (['<!DOCTYPE html>', 'class="sidebar', 'class="main-wrapper', 'portal-dashboard'] as $needle) {
    if (stripos($html, $needle) === false) {
        fwrite(STDERR, "FAIL: {$requestedModule} dashboard missing {$needle}.\n");
        exit(1);
    }
}
if (substr_count(strtolower($html), '<!doctype html>') !== 1
    || substr_count(strtolower($html), '<html') !== 1
    || substr_count(strtolower($html), '<body') !== 1) {
    fwrite(STDERR, "FAIL: {$requestedModule} dashboard has an invalid document shell.\n");
    exit(1);
}

echo "PASS: {$requestedModule} dashboard renders with unified chrome and one document shell.\n";
