<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/portal_config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/session_guard.php';

wuc_enforce_session_guard([
    'context' => 'finance payment proof',
    'session_keys' => ['user_id', 'staff_id'],
    'activity_keys' => ['last_activity'],
    'timeout' => 1800,
    'login_path' => WUC_APP_BASE_PATH . '/staff_login.php',
    'flash_key' => 'errorMessage',
    'login_message' => 'Please log in to review payment proofs.',
]);

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/portal_access.php';
require_once __DIR__ . '/../includes/staff_role_helpers.php';
require_once __DIR__ . '/../includes/role_helpers.php';
require_once __DIR__ . '/../includes/payment_helpers.php';

wuc_require_portal_access($db, 'academic');

$staffId = trim((string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? ''));
if ($staffId !== '') {
    wuc_hydrate_staff_roles($db, $staffId);
}

$allowed = (function_exists('canAccessFinance') && canAccessFinance())
    || (function_exists('isSystemsAdmin') && isSystemsAdmin())
    || hasAnyRole([ROLE_ACCOUNTANT, ROLE_SYSTEMS_ADMIN]);

if (!$allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Access denied.';
    exit;
}

$transactionId = filter_input(INPUT_GET, 'transaction_id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$transaction = $transactionId ? payment_find_gateway_transaction_by_id($db, (int)$transactionId) : null;

if (!$transaction || strtoupper((string)($transaction['provider'] ?? '')) !== 'BANK_TRANSFER') {
    http_response_code(404);
    exit;
}

$storedName = trim((string)($transaction['proof_file'] ?? ''));
if ($storedName === '' || !hash_equals(basename($storedName), $storedName)) {
    http_response_code(404);
    exit;
}

$proofDirectory = realpath(__DIR__ . '/../uploads/payment_proofs');
$proofPath = $proofDirectory !== false ? realpath($proofDirectory . DIRECTORY_SEPARATOR . $storedName) : false;
$directoryPrefix = $proofDirectory !== false ? rtrim($proofDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';

if (
    $proofDirectory === false
    || $proofPath === false
    || strncasecmp($proofPath, $directoryPrefix, strlen($directoryPrefix)) !== 0
    || !is_file($proofPath)
) {
    http_response_code(404);
    exit;
}

$allowedMimes = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = $finfo ? (string)finfo_file($finfo, $proofPath) : '';
if ($finfo) {
    finfo_close($finfo);
}

if (!isset($allowedMimes[$detectedMime])) {
    http_response_code(404);
    exit;
}

$downloadName = 'bank-transfer-proof-' . (int)$transaction['id'] . '.' . $allowedMimes[$detectedMime];
header('Content-Type: ' . $detectedMime);
header('Content-Length: ' . (string)filesize($proofPath));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
readfile($proofPath);
exit;
