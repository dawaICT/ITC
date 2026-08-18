<?php
/** HTTP smoke test for qualification-specific academic portal routing. */
declare(strict_types=1);

$base = 'http://localhost/wucportal';
$sid = getenv('WUC_TEST_STUDENT_ID') ?: 'CSE26456789';
$password = getenv('WUC_TEST_STUDENT_PASSWORD') ?: 'Student@12345';
$jar = tempnam(sys_get_temp_dir(), 'portal_sep_');
$failures = 0;

function portal_http(string $url, ?array $post, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $headerSize);
    preg_match('/^Location:\s*(.+)$/mi', $headers, $match);
    return [
        'code' => $code,
        'location' => trim((string)($match[1] ?? '')),
        'body' => substr($raw, $headerSize),
    ];
}

function portal_csrf(string $html): string
{
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match)
        ? html_entity_decode($match[1], ENT_QUOTES, 'UTF-8')
        : '';
}

function portal_ok(bool $condition, string $label, string $detail = ''): void
{
    global $failures;
    if (!$condition) {
        $failures++;
    }
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . ($detail !== '' ? " | {$detail}" : '') . "\n";
}

$loginPage = portal_http("{$base}/student_login.php", null, $jar);
$login = portal_http("{$base}/studentLogin.php", [
    'csrf_token' => portal_csrf($loginPage['body']),
    'login' => '1',
    'Sid' => $sid,
    'Password' => $password,
], $jar);
portal_ok(in_array($login['code'], [302, 303], true), 'login.redirects', $login['location']);

$landing = $login['location'];
if (str_contains($landing, 'portal_selection.php')) {
    $picker = portal_http("{$base}/portal_selection.php", null, $jar);
    $selected = portal_http("{$base}/portal_selection.php", [
        'csrf_token' => portal_csrf($picker['body']),
        'portal' => 'academic',
    ], $jar);
    $landing = $selected['location'];
}

portal_ok(str_contains($landing, '/students/diploma_portal.php'), 'login.diploma_landing', $landing);
$diploma = portal_http("{$base}/students/diploma_portal.php", null, $jar);
portal_ok($diploma['code'] === 200, 'diploma.page_200', (string)$diploma['code']);
portal_ok(str_contains($diploma['body'], 'Diploma Dashboard'), 'diploma.label_rendered');

$wrongPortal = portal_http("{$base}/students/certificate_portal.php", null, $jar);
portal_ok(
    in_array($wrongPortal['code'], [302, 303], true)
        && str_contains($wrongPortal['location'], '/students/diploma_portal.php'),
    'cross_portal.redirected',
    $wrongPortal['location']
);

@unlink($jar);
echo $failures === 0 ? "RESULT=OK\n" : "RESULT=FAIL failures={$failures}\n";
exit($failures === 0 ? 0 : 1);
