<?php
ob_start();
require_once dirname(__DIR__) . '/includes/session_guard.php';

$sessionTestPath = dirname(__DIR__) . '/tmp/session-tests';
if (!is_dir($sessionTestPath)) {
    mkdir($sessionTestPath, 0775, true);
}
session_save_path($sessionTestPath);

function guard_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wucportal/scripts/test_session_guard.php';

wuc_configure_session_cookie();
session_start();
$_SESSION = [
    'user_id' => 'WUC-TEST',
    'last_active_time' => time() - 10,
];

$ok = wuc_enforce_session_guard([
    'context' => 'guard-test-active',
    'is_script' => true,
    'session_keys' => ['staff_id', 'user_id'],
    'activity_keys' => ['last_activity', 'last_active_time'],
    'timeout' => 1800,
]);

guard_check($ok, 'active script session is accepted');
guard_check(($_SESSION['staff_id'] ?? '') === 'WUC-TEST', 'staff session aliases are synchronized');
guard_check(isset($_SESSION['last_activity'], $_SESSION['last_active_time']), 'activity timestamps are unified');

$_SESSION['last_activity'] = time() - 1900;
$_SESSION['last_active_time'] = time() - 1900;
$ok = wuc_enforce_session_guard([
    'context' => 'guard-test-expired',
    'is_script' => true,
    'session_keys' => ['staff_id', 'user_id'],
    'activity_keys' => ['last_activity', 'last_active_time'],
    'timeout' => 1800,
]);

guard_check(!$ok, 'expired script session is rejected without redirecting');
guard_check(empty($_SESSION), 'expired session data is cleared');

echo "Session guard regression checks passed.\n";
ob_end_flush();
