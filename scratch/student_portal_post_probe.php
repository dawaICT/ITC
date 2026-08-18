<?php
/**
 * Student portal_selection POST probe — reports Location header per portal.
 * Run: C:\xampp\php\php.exe scratch/student_portal_post_probe.php
 */
const BASE = 'http://localhost/wucportal';
const OUT_FILE = __DIR__ . '/_student_portal_post.txt';
const SID = 'CSE26456789';
const PASS = 'Student@12345';
const PORTALS = ['academic', 'elearning', 'alumni'];

$lines = [];
$log = static function (string $line) use (&$lines): void {
    $lines[] = $line;
};

function resolve_url(string $base, string $location): string
{
    $location = trim($location);
    if ($location === '') {
        return $base;
    }
    if (preg_match('#^https?://#i', $location)) {
        return $location;
    }
    $parts = parse_url($base);
    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'] ?? 'localhost';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '/';
    if ($location[0] === '/') {
        return $scheme . '://' . $host . $port . $location;
    }
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    return $scheme . '://' . $host . $port . $dir . $location;
}

function http_request(string $url, string $method = 'GET', array $postFields = [], string $cookieJar = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => 'WUC-Student-Portal-POST-Probe/1.0',
        CURLOPT_TIMEOUT => 60,
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $parsed = [];
    foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$k, $v] = explode(':', $line, 2);
        $parsed[strtolower(trim($k))] = trim($v);
    }
    return [
        'status' => $status,
        'headers' => $parsed,
        'body' => $body,
        'effective_url' => $effective,
        'location' => $parsed['location'] ?? null,
        'raw_headers' => $rawHeaders,
    ];
}

function extract_csrf(string $html): ?string
{
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }
    return null;
}

function reset_cookies(string $cookieJar): void
{
    if (is_file($cookieJar)) {
        @unlink($cookieJar);
    }
}

function student_login(string $cookieJar, callable $log): void
{
    $loginPageUrl = BASE . '/student_login.php';
    $log('  GET ' . $loginPageUrl);
    $get = http_request($loginPageUrl, 'GET', [], $cookieJar);
    $log('    -> HTTP ' . $get['status']);
    $csrf = extract_csrf($get['body']);
    if ($csrf === null) {
        throw new RuntimeException('Student CSRF token not found on login page');
    }
    $url = BASE . '/student_login.php';
    $post = ['csrf_token' => $csrf, 'login' => '1', 'Sid' => SID, 'Password' => PASS];
    for ($i = 0; $i < 15; $i++) {
        $log('  POST student_login.php (attempt chain step ' . ($i + 1) . ')');
        $resp = http_request($url, $i === 0 ? 'POST' : 'GET', $i === 0 ? $post : [], $cookieJar);
        $log('    -> HTTP ' . $resp['status'] . ($resp['location'] ? ' Location: ' . $resp['location'] : ''));
        if ($resp['status'] >= 300 && $resp['status'] < 400 && !empty($resp['location'])) {
            $next = resolve_url($url, (string) $resp['location']);
            if (stripos($next, 'portal_selection.php') !== false) {
                $ps = http_request($next, 'GET', [], $cookieJar);
                $log('  GET ' . $next);
                $log('    -> HTTP ' . $ps['status'] . ($ps['location'] ? ' Location: ' . $ps['location'] : ''));
                if ($ps['status'] === 200) {
                    return;
                }
                if ($ps['status'] >= 300 && $ps['status'] < 400 && !empty($ps['location'])) {
                    $log('    (stopped before leaving portal_selection chooser)');
                    return;
                }
            }
            $url = $next;
            continue;
        }
        return;
    }
}

function portal_post_location(string $cookieJar, callable $log, string $portalCode): ?string
{
    $psUrl = BASE . '/portal_selection.php';
    $log('  GET ' . $psUrl . ' (CSRF for POST portal=' . $portalCode . ')');
    $get = http_request($psUrl, 'GET', [], $cookieJar);
    $log('    -> HTTP ' . $get['status'] . ($get['location'] ? ' Location: ' . $get['location'] : ''));
    if ($get['status'] >= 300 && $get['status'] < 400 && !empty($get['location'])) {
        $log('  NOTE: portal_selection auto-redirected on GET; POST skipped.');
        return (string) $get['location'];
    }
    $csrf = extract_csrf($get['body']);
    if ($csrf === null) {
        throw new RuntimeException('CSRF missing on portal_selection for portal=' . $portalCode);
    }
    $log('  POST portal_selection.php portal=' . $portalCode);
    $resp = http_request($psUrl, 'POST', ['csrf_token' => $csrf, 'portal' => $portalCode], $cookieJar);
    $log('    -> HTTP ' . $resp['status'] . ($resp['location'] ? ' Location: ' . $resp['location'] : ''));
    return $resp['location'];
}

$cookieJar = __DIR__ . '/._student_portal_post_cookies.txt';
reset_cookies($cookieJar);

$log('=== Student portal_selection POST probe ===');
$log('Base: ' . BASE);
$log('Student: ' . SID);
$log('Timestamp: ' . date('c'));
$log('');

try {
    foreach (PORTALS as $idx => $portalCode) {
        $log('--- Scenario ' . ($idx + 1) . ': portal=' . $portalCode . ' ---');
        if ($idx > 0) {
            reset_cookies($cookieJar);
            $log('  (fresh cookie jar)');
        }
        student_login($cookieJar, $log);
        $location = portal_post_location($cookieJar, $log, $portalCode);
        $log('  RESULT Location: ' . ($location ?? '(none)'));
        $log('');
    }
} catch (Throwable $e) {
    $log('ERROR: ' . $e->getMessage());
    $log($e->getFile() . ':' . $e->getLine());
}

$output = implode("\n", $lines) . "\n";
file_put_contents(OUT_FILE, $output);
echo $output;
