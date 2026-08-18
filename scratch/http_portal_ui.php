<?php
/**
 * HTTP portal UI probe (CLI) — cookie jar + manual redirect handling.
 */

const BASE = 'http://localhost/wucportal';
const OUT_FILE = __DIR__ . '/_http_portal_ui.txt';

$lines = [];
$log = static function (string $line) use (&$lines): void {
    $lines[] = $line;
};

$cookieJar = __DIR__ . '/._http_portal_cookies.txt';
if (is_file($cookieJar)) {
    @unlink($cookieJar);
}

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

/**
 * @param array<string,string> $postFields
 * @return array{status:int,headers:array<string,string>,body:string,effective_url:string,location:?string,raw_headers:string}
 */
function http_request(string $url, string $method = 'GET', array $postFields = [], string $cookieJar = ''): array
{
    $ch = curl_init($url);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => 'WUC-Portal-HTTP-Probe/1.0',
        CURLOPT_TIMEOUT => 60,
    ]);
    $method = strtoupper($method);
    if ($method === 'POST') {
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
    $location = $parsed['location'] ?? null;

    return [
        'status' => $status,
        'headers' => $parsed,
        'body' => $body,
        'effective_url' => $effective,
        'location' => $location,
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

function parse_portal_selection(string $html): array
{
    $welcome = null;
    if (preg_match('/Welcome,\s*([^<]+?)\.\s*Select/i', $html, $m)) {
        $welcome = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
    }
    $cards = [];
    if (preg_match_all(
        '/class="portal-name"[^>]*>([^<]+)<.*?class="portal-desc"[^>]*>([^<]+)</s',
        $html,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $match) {
            $cards[] = [
                'name' => trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5)),
                'description' => trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5)),
            ];
        }
    }
    return ['welcome' => $welcome, 'cards' => $cards, 'is_portal_selection' => stripos($html, 'Choose Portal') !== false];
}

/**
 * @param callable(string):void $log
 * @param array<string,string> $postFields
 * @return array{final:array,redirects:array<int,array<string,mixed>>,portal_page:?array}
 */
function follow_redirects(
    string $startUrl,
    string $method,
    array $postFields,
    string $cookieJar,
    callable $log,
    bool $stopAtPortalSelectionGet = false,
    bool $followPastPortalSelection = false
): array {
    $redirects = [];
    $url = $startUrl;
    $currentMethod = $method;
    $currentPost = $postFields;
    $portalPage = null;

    for ($i = 0; $i < 20; $i++) {
        $resp = http_request($url, $currentMethod, $currentPost, $cookieJar);
        $entry = [
            'step' => $i + 1,
            'method' => $currentMethod,
            'url' => $url,
            'status' => $resp['status'],
            'location' => $resp['location'],
        ];
        $redirects[] = $entry;
        $log(sprintf('  [%d] %s %s -> HTTP %d%s', $i + 1, $currentMethod, $url, $resp['status'], $resp['location'] ? ' Location: ' . $resp['location'] : ''));

        $currentMethod = 'GET';
        $currentPost = [];

        if ($resp['status'] >= 300 && $resp['status'] < 400 && !empty($resp['location'])) {
            $next = resolve_url($url, (string) $resp['location']);
            if (!$followPastPortalSelection && $stopAtPortalSelectionGet && stripos($next, 'portal_selection.php') !== false) {
                $ps = http_request($next, 'GET', [], $cookieJar);
                $redirects[] = [
                    'step' => $i + 2,
                    'method' => 'GET',
                    'url' => $next,
                    'status' => $ps['status'],
                    'location' => $ps['location'],
                ];
                $log(sprintf('  [%d] GET %s -> HTTP %d%s', $i + 2, $next, $ps['status'], $ps['location'] ? ' Location: ' . $ps['location'] : ''));
                if ($ps['status'] >= 300 && $ps['status'] < 400 && !empty($ps['location'])) {
                    $log('  (stopped before following redirect away from portal_selection.php)');
                    return ['final' => $ps, 'redirects' => $redirects, 'portal_page' => null];
                }
                $parsed = parse_portal_selection($ps['body']);
                return ['final' => $ps, 'redirects' => $redirects, 'portal_page' => $parsed];
            }
            $url = $next;
            continue;
        }

        if ($resp['status'] === 200 && stripos($resp['effective_url'], 'portal_selection.php') !== false) {
            $portalPage = parse_portal_selection($resp['body']);
            return ['final' => $resp, 'redirects' => $redirects, 'portal_page' => $portalPage];
        }

        return ['final' => $resp, 'redirects' => $redirects, 'portal_page' => $portalPage];
    }

    throw new RuntimeException('Too many redirects');
}

function reset_cookies(string $cookieJar): void
{
    if (is_file($cookieJar)) {
        @unlink($cookieJar);
    }
}

function log_portal_page(callable $log, ?array $portalPage, string $label): void
{
    $log('');
    $log($label);
    if ($portalPage === null) {
        $log('  portal_selection.php: not rendered (auto-redirect or other landing)');
        return;
    }
    if (!$portalPage['is_portal_selection']) {
        $log('  portal_selection.php: response was not the chooser page');
        return;
    }
    $log('  Welcome name: ' . ($portalPage['welcome'] ?? '(not found)'));
    $log('  Portal cards (' . count($portalPage['cards']) . '):');
    foreach ($portalPage['cards'] as $idx => $card) {
        $log('    ' . ($idx + 1) . '. ' . $card['name'] . ' — ' . $card['description']);
    }
}

function staff_login_flow(string $cookieJar, callable $log, string $user, string $pass): array
{
    $loginPageUrl = BASE . '/staff_login.php';
    $log('GET ' . $loginPageUrl);
    $get = http_request($loginPageUrl, 'GET', [], $cookieJar);
    $log('  -> HTTP ' . $get['status']);
    $csrf = extract_csrf($get['body']);
    if ($csrf === null) {
        throw new RuntimeException('Staff CSRF token not found');
    }
    $log('POST staff_login.php (Staff ID ' . $user . ')');
    return follow_redirects(
        BASE . '/staff_login.php',
        'POST',
        ['csrf_token' => $csrf, 'user_id' => $user, 'password' => $pass],
        $cookieJar,
        $log,
        true,
        false
    );
}

function student_login_flow(string $cookieJar, callable $log, string $sid, string $pass): array
{
    $loginPageUrl = BASE . '/student_login.php';
    $log('GET ' . $loginPageUrl);
    $get = http_request($loginPageUrl, 'GET', [], $cookieJar);
    $log('  -> HTTP ' . $get['status']);
    $csrf = extract_csrf($get['body']);
    if ($csrf === null) {
        throw new RuntimeException('Student CSRF token not found');
    }
    $log('POST student_login.php (Sid ' . $sid . ')');
    return follow_redirects(
        BASE . '/student_login.php',
        'POST',
        ['csrf_token' => $csrf, 'login' => '1', 'Sid' => $sid, 'Password' => $pass],
        $cookieJar,
        $log,
        true,
        false
    );
}

function logout(string $cookieJar, callable $log, string $to): void
{
    $url = BASE . '/logout.php?to=' . rawurlencode($to);
    $log('GET ' . $url);
    $chain = follow_redirects($url, 'GET', [], $cookieJar, $log, false, true);
    $finalLoc = $chain['final']['location'] ?? $chain['final']['effective_url'];
    $log('  Logout landed at: ' . ($finalLoc ?? '(unknown)'));
}

function portal_post_select(string $cookieJar, callable $log, string $portalCode): array
{
    $psUrl = BASE . '/portal_selection.php';
    $log('GET ' . $psUrl . ' (for CSRF before POST portal=' . $portalCode . ')');
    $get = http_request($psUrl, 'GET', [], $cookieJar);
    $log('  -> HTTP ' . $get['status'] . ($get['location'] ? ' Location: ' . $get['location'] : ''));
    if ($get['status'] >= 300 && $get['status'] < 400 && !empty($get['location'])) {
        $log('  Cannot POST — portal_selection auto-redirected without showing chooser.');
        return ['redirects' => [], 'landing' => resolve_url($psUrl, (string) $get['location'])];
    }
    $csrf = extract_csrf($get['body']);
    if ($csrf === null) {
        throw new RuntimeException('CSRF missing on portal_selection for POST ' . $portalCode);
    }
    $log('POST portal_selection.php portal=' . $portalCode);
    $locations = [];
    $url = $psUrl;
    $method = 'POST';
    $post = ['csrf_token' => $csrf, 'portal' => $portalCode];
    for ($i = 0; $i < 20; $i++) {
        $resp = http_request($url, $method, $post, $cookieJar);
        $log(sprintf('  [%d] %s %s -> HTTP %d%s', $i + 1, $method, $url, $resp['status'], $resp['location'] ? ' Location: ' . $resp['location'] : ''));
        if (!empty($resp['location'])) {
            $locations[] = $resp['location'];
        }
        $method = 'GET';
        $post = [];
        if ($resp['status'] >= 300 && $resp['status'] < 400 && !empty($resp['location'])) {
            $url = resolve_url($url, (string) $resp['location']);
            continue;
        }
        $landing = $resp['effective_url'];
        if (!empty($resp['location'])) {
            $landing = resolve_url($url, (string) $resp['location']);
        }
        return ['redirects' => $locations, 'landing' => $landing, 'status' => $resp['status']];
    }
    return ['redirects' => $locations, 'landing' => null];
}

// --- Run ---

$log('=== WUC Portal HTTP UI Probe ===');
$log('Base URL: ' . BASE);
$log('Cookie jar: ' . $cookieJar);
$log('Timestamp: ' . date('c'));
$log('');

try {
    $log('--- 1) Lecturer login (ITC907) ---');
    $lecturer = staff_login_flow($cookieJar, $log, 'ITC907', 'Test@12345');
    log_portal_page($log, $lecturer['portal_page'], '--- 2) portal_selection extraction (lecturer) ---');
    if ($lecturer['portal_page'] === null && !empty($lecturer['redirects'])) {
        $last = end($lecturer['redirects']);
        if (!empty($last['location'])) {
            $log('  Last Location header: ' . $last['location']);
        }
    }

    $log('');
    $log('--- 3) Logout (staff) ---');
    logout($cookieJar, $log, 'staff');

    reset_cookies($cookieJar);
    $log('');
    $log('--- 4) Student login (CSE26456789) ---');
    $student = student_login_flow($cookieJar, $log, 'CSE26456789', 'Student@12345');
    log_portal_page($log, $student['portal_page'], '--- 5) portal_selection extraction (student) ---');
    if ($student['portal_page'] === null && !empty($student['redirects'])) {
        $last = end($student['redirects']);
        if (!empty($last['location'])) {
            $log('  Last Location header: ' . $last['location']);
        }
    }

    $log('');
    $log('--- 6) Lecturer portal POST simulations ---');

    foreach (['academic', 'elearning'] as $portalCode) {
        reset_cookies($cookieJar);
        $log('');
        $log('>> Fresh lecturer login for portal=' . $portalCode);
        staff_login_flow($cookieJar, $log, 'ITC907', 'Test@12345');
        $result = portal_post_select($cookieJar, $log, $portalCode);
        $log('>> Location chain for portal=' . $portalCode . ':');
        if ($result['redirects'] === []) {
            $log('   (no redirect chain — see above)');
        } else {
            foreach ($result['redirects'] as $idx => $loc) {
                $log('   ' . ($idx + 1) . '. ' . $loc);
            }
        }
        $log('>> Final landing URL: ' . ($result['landing'] ?? '(none)'));
    }
} catch (Throwable $e) {
    $log('');
    $log('ERROR: ' . $e->getMessage());
    $log($e->getFile() . ':' . $e->getLine());
}

$output = implode("\n", $lines) . "\n";
file_put_contents(OUT_FILE, $output);
echo $output;
