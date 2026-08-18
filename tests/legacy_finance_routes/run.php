<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db/connect.php';

$passed = 0;
$failed = 0;
$sessionId = 'codexlegacy' . strtolower(bin2hex(random_bytes(8)));
$forgedReference = 'FORGED-LEGACY-' . strtoupper(bin2hex(random_bytes(6)));

function check(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo '[PASS] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
        return;
    }

    $failed++;
    echo '[FAIL] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
}

function source(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $contents;
}

function httpRequest(string $url, string $method = 'GET', ?string $sessionId = null, array $fields = []): array
{
    $headers = [];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($sessionId !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sessionId);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$headers): int {
        $length = strlen($line);
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $length;
    });
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => $error];
}

function countReference(mysqli $db, string $reference): int
{
    $stmt = $db->prepare(
        "SELECT
            (SELECT COUNT(*) FROM payments WHERE receipt_no = ?) +
            (SELECT COUNT(*) FROM student_payments WHERE reference_number = ? OR receipt_number = ?) +
            (SELECT COUNT(*) FROM transactions WHERE referenceID = ?) AS total"
    );
    $stmt->bind_param('ssss', $reference, $reference, $reference, $reference);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_id($sessionId);
session_start();
$_SESSION['user_id'] = 'ITC900';
$_SESSION['staff_id'] = 'ITC900';
$_SESSION['last_activity'] = time();
session_write_close();

try {
    $routes = [
        'updateBank_payment.php' => '/wucportal/accounts/pendingPayments.php',
        'bankPayment.php' => '/wucportal/accounts/pendingPayments.php',
        'payments2.php' => '/wucportal/accounts/fees_student_payments.php',
        'scholarshipPayments2.php' => '/wucportal/accounts/fees_student_payments.php',
    ];

    foreach ($routes as $file => $destination) {
        $contents = source($root . '/accounts/' . $file);
        check(strpos($contents, "require __DIR__ . '/includes/nav.php'") !== false, $file . ' keeps finance authorization');
        check(strpos($contents, "wuc_safe_redirect('" . $destination . "', 303)") !== false, $file . ' redirects to the supported workflow');
        check(strpos($contents, '$_POST') === false, $file . ' ignores stale POST payloads');
        check(preg_match('/\b(?:INSERT|UPDATE|DELETE)\s+(?:INTO|FROM|`?[A-Za-z_])/i', $contents) !== 1, $file . ' contains no database write statements');
    }

    $nav = source($root . '/accounts/includes/nav.php');
    check(strpos($nav, "'href' => 'pendingPayments.php'") !== false, 'finance navigation exposes verified bank review');
    check(strpos($nav, "'href' => 'fees_student_payments.php'") !== false, 'finance navigation exposes supported manual payments');
    check(strpos($nav, "'href' => 'updateBank_payment.php'") === false, 'finance navigation hides the retired bank poster');
    check(strpos($nav, "'href' => 'payments2.php'") === false, 'finance navigation hides the retired direct ledger poster');

    $baseUrl = rtrim((string)(getenv('WUC_TEST_BASE_URL') ?: 'http://localhost/wucportal'), '/');
    $before = countReference($db, $forgedReference);
    check($before === 0, 'forged regression reference starts absent');

    foreach ($routes as $file => $destination) {
        $get = httpRequest($baseUrl . '/accounts/' . $file, 'GET', $sessionId);
        check($get['status'] === 303, $file . ' authenticated GET returns 303', 'status=' . $get['status']);
        check(($get['headers']['location'] ?? '') === $destination, $file . ' authenticated GET has the safe destination');

        $post = httpRequest($baseUrl . '/accounts/' . $file, 'POST', $sessionId, [
            'Sid' => 'CSE001',
            'studentID' => 'CSE001',
            'amount_paid' => '99999.99',
            'amount' => '99999.99',
            'referenceID' => $forgedReference,
            'receipt_number' => $forgedReference,
            'post_transaction' => '1',
        ]);
        check($post['status'] === 303, $file . ' forged POST returns 303', 'status=' . $post['status']);
        check(($post['headers']['location'] ?? '') === $destination, $file . ' forged POST cannot select another destination');
    }

    $after = countReference($db, $forgedReference);
    check($after === 0, 'forged legacy POSTs create no finance rows', 'rows=' . $after);

    $unauthenticated = httpRequest($baseUrl . '/accounts/updateBank_payment.php');
    check($unauthenticated['status'] === 302, 'retired route still requires authentication', 'status=' . $unauthenticated['status']);
    check(($unauthenticated['headers']['location'] ?? '') === '/wucportal/staff_login.php', 'unauthenticated request returns to staff login');
} finally {
    session_id($sessionId);
    session_start();
    session_destroy();
}

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

