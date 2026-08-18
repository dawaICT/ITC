<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;

function check(bool $condition, string $label): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "[PASS] {$label}" . PHP_EOL;
        return;
    }

    $failed++;
    echo "[FAIL] {$label}" . PHP_EOL;
}

function source(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }

    return $contents;
}

function checkBefore(string $contents, string $first, string $second, string $label): void
{
    $firstPosition = strpos($contents, $first);
    $secondPosition = strpos($contents, $second);
    check(
        $firstPosition !== false && $secondPosition !== false && $firstPosition < $secondPosition,
        $label
    );
}

$legacyDpo = source($root . '/students/dpopay_callback.php');
$airtelCallback = source($root . '/students/airtel_callback.php');
$airtelProcessor = source($root . '/students/process_airtel_payment.php');
$airtelExample = source($root . '/students/example_airtel_payment.php');
$airtelAdmin = source($root . '/admin/airtel_config.php');
$airtelGateway = source($root . '/includes/AirtelMoneyGateway.php');
$dpoReturn = source($root . '/students/dpo_callback.php');
$paygateReturn = source($root . '/students/payments/paygate_return.php');

check(strpos($legacyDpo, 'http_response_code(410)') !== false, 'Legacy DPO callback returns Gone');
check(strpos($legacyDpo, 'ENDPOINT_RETIRED') !== false, 'Legacy DPO callback identifies retirement');
check(strpos($legacyDpo, 'db/connect.php') === false, 'Legacy DPO callback cannot connect to the database');
check(stripos($legacyDpo, 'UPDATE invoices') === false, 'Legacy DPO callback cannot update invoices');
check(stripos($legacyDpo, 'INSERT INTO student_payments') === false, 'Legacy DPO callback cannot post payments');
check(strpos($legacyDpo, 'file_put_contents') === false, 'Legacy DPO callback does not log raw payloads');

check(strpos($airtelCallback, 'http_response_code(503)') !== false, 'Airtel callback fails closed');
check(strpos($airtelCallback, 'CALLBACK_VERIFICATION_REQUIRED') !== false, 'Airtel callback reports verification requirement');
check(strpos($airtelCallback, 'db/connect.php') === false, 'Airtel callback cannot connect to the database');
check(stripos($airtelCallback, 'UPDATE transactions') === false, 'Airtel callback cannot update transaction status');
check(stripos($airtelCallback, 'INSERT INTO student_payments') === false, 'Airtel callback cannot post payments');
check(strpos($airtelCallback, 'file_put_contents') === false, 'Airtel callback does not log raw payloads');

check(strpos($airtelProcessor, 'http_response_code(503)') !== false, 'Airtel payment processor fails closed');
check(strpos($airtelProcessor, 'INTEGRATION_NOT_READY') !== false, 'Airtel processor reports integration state');
check(strpos($airtelProcessor, 'db/connect.php') === false, 'Airtel processor cannot mutate the database');
check(strpos($airtelProcessor, 'AirtelMoneyGateway') === false, 'Airtel processor cannot initiate provider charges');
check(strpos($airtelProcessor, '$_POST') === false, 'Airtel processor ignores caller-supplied payment identity and amounts');

check(strpos($airtelExample, '/wucportal/students/fees.php') !== false, 'Retired Airtel demo routes to the real fee ledger');
check(strpos($airtelExample, '2500.00') === false, 'Retired Airtel demo has no hard-coded balance');
check(strpos($airtelExample, 'payment_success') === false, 'Query parameters cannot fabricate Airtel payment success');

check(strpos($airtelAdmin, '/wucportal/admin/payment_gateway_settings.php') !== false, 'Legacy Airtel admin page routes to active gateway settings');
check(strpos($airtelAdmin, 'client_secret') === false, 'Legacy Airtel admin page cannot collect merchant secrets');
check(stripos($airtelAdmin, 'CREATE TABLE') === false, 'Legacy Airtel admin page cannot create configuration storage');

check(strpos($airtelGateway, 'CURLOPT_SSL_VERIFYPEER, false') === false, 'Airtel gateway never disables peer verification');
check(strpos($airtelGateway, 'CURLOPT_SSL_VERIFYHOST, false') === false, 'Airtel gateway never disables host verification');
check(strpos($airtelGateway, 'CALLBACK_VERIFICATION_REQUIRED') !== false, 'Dormant gateway callback parser fails closed');

$airtelDocs = [
    'AIRTEL_INTEGRATION_GUIDE.md',
    'AIRTEL_DOCUMENTATION_INDEX.md',
    'AIRTEL_DELIVERY_SUMMARY.md',
    'AIRTEL_MONEY_SETUP.md',
    'AIRTEL_MONEY_IMPLEMENTATION_SUMMARY.md',
    'AIRTEL_README.md',
    'AIRTEL_SETUP_CHECKLIST.md',
];
foreach ($airtelDocs as $doc) {
    check(strpos(source($root . '/' . $doc), '**Quarantined') !== false, "{$doc} warns against deployment");
}

checkBefore($dpoReturn, 'verifyToken(', 'payment_apply_completed_payment(', 'DPO callback verifies token before ledger posting');
checkBefore($paygateReturn, 'verifyToken(', 'payment_apply_completed_payment(', 'DPO PayGate return verifies token before ledger posting');

putenv('AIRTEL_MONEY_ENABLED=false');
putenv('AIRTEL_DEBUG_MODE=off');
require $root . '/includes/airtel_config.php';
require $root . '/includes/AirtelMoneyGateway.php';

check(AIRTEL_MONEY_ENABLED === false, 'String false does not enable Airtel Money');
check(AIRTEL_DEBUG_MODE === false, 'String off does not enable Airtel debug logging');
check(wuc_airtel_config_flag('true') === true, 'Explicit true enables an Airtel flag');
check(wuc_airtel_config_flag('1') === true, 'Explicit one enables an Airtel flag');
check(wuc_airtel_config_flag('no') === false, 'Unrecognized flag values fail closed');

$gateway = new AirtelMoneyGateway();
$forgedResult = $gateway->handleCallback([
    'reference' => 'FORGED-REFERENCE',
    'status' => 'success',
    'amount' => 999999,
]);
check(($forgedResult['success'] ?? true) === false, 'Dormant gateway rejects a forged success callback');
check(($forgedResult['code'] ?? '') === 'CALLBACK_VERIFICATION_REQUIRED', 'Forged callback requires provider verification');

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
