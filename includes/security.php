<?php
/**
 * Shared security helpers for portal pages.
 *
 * Keep these helpers framework-free so legacy modules can include them before
 * sessions, redirects, password reset links, and payment callbacks are built.
 */

if (!defined('WUC_APP_BASE_PATH')) {
    define('WUC_APP_BASE_PATH', '/wucportal');
}

if (!function_exists('wuc_is_https_request')) {
    function wuc_is_https_request(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}

if (!function_exists('wuc_configure_session_cookie')) {
    function wuc_configure_session_cookie(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', wuc_is_https_request() ? '1' : '0');

        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $params['lifetime'] ?? 0,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => wuc_is_https_request(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('wuc_apply_security_headers')) {
    function wuc_apply_security_headers(bool $noStore = false): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), payment=(), usb=(), serial=()');

        if (wuc_is_https_request()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        if ($noStore) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Fri, 01 Jan 1990 00:00:00 GMT');
        }
    }
}

if (!function_exists('wuc_normalize_local_url')) {
    function wuc_normalize_local_url(string $url, string $fallback = WUC_APP_BASE_PATH . '/index.php'): string
    {
        $url = trim(str_replace(["\r", "\n", "\0"], '', $url));
        $fallback = trim(str_replace(["\r", "\n", "\0"], '', $fallback));
        if ($fallback === '') {
            $fallback = WUC_APP_BASE_PATH . '/index.php';
        }

        if ($url === '') {
            return $fallback;
        }

        $url = str_replace('\\', '/', $url);
        $pathOnly = preg_split('/[?#]/', $url, 2)[0] ?? '';
        $decodedPath = rawurldecode($pathOnly);
        if (preg_match('#(^|/)\.\.?(/|$)#', $decodedPath)) {
            return $fallback;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) || strpos($url, '//') === 0) {
            return $fallback;
        }

        if ($url[0] !== '/') {
            // Relative URL: resolve against the directory of the CURRENT request,
            // not the app root. A bare "page.php" issued from
            // /wucportal/lecturers/x.php must resolve to
            // /wucportal/lecturers/page.php — resolving against the root dropped
            // the subdirectory and produced 404s (e.g. the lecturer upload/CA
            // post/redirect/get flows). Root-level pages are unaffected: their
            // request directory IS the app root, so the result is identical to
            // the previous behaviour.
            $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
            $baseDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
            if ($baseDir === '' || strpos($baseDir . '/', WUC_APP_BASE_PATH . '/') !== 0) {
                $baseDir = WUC_APP_BASE_PATH;
            }
            $url = $baseDir . '/' . ltrim($url, '/');
        }

        if ($url === WUC_APP_BASE_PATH) {
            return WUC_APP_BASE_PATH . '/index.php';
        }

        if (strpos($url, WUC_APP_BASE_PATH . '/') !== 0) {
            return $fallback;
        }

        return $url;
    }
}

if (!function_exists('wuc_safe_redirect')) {
    function wuc_safe_redirect(string $url, int $status = 302, string $fallback = WUC_APP_BASE_PATH . '/index.php'): void
    {
        $target = wuc_normalize_local_url($url, $fallback);

        if (!headers_sent()) {
            header('Location: ' . $target, true, $status);
            exit;
        }

        echo '<script>window.location.href=' . json_encode($target) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}

if (!function_exists('wuc_public_base_url')) {
    function wuc_public_base_url(): string
    {
        $scheme = wuc_is_https_request() ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

        if ($host === '' || preg_match('/[\r\n\\\\\/]/', $host) || !preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
            $host = 'localhost';
        }

        return $scheme . '://' . $host;
    }
}

if (!function_exists('wuc_validate_external_http_url')) {
    function wuc_validate_external_http_url(string $url): bool
    {
        $url = trim(str_replace(["\r", "\n", "\0"], '', $url));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || substr($host, -6) === '.local') {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }
}

/**
 * Opaque, tamper-proof identifiers for URLs.
 *
 * Raw record identifiers (student SIDs, etc.) should NOT travel in query
 * strings: they leak into browser history, server access logs and Referer
 * headers, and they invite IDOR by letting anyone edit the value to point at
 * another record. These helpers turn an id into an opaque token that is
 * confidential (AES-256-GCM) and integrity-protected (the GCM tag plus a
 * per-context key), so a token minted for one kind of link cannot be tampered
 * with or replayed against a different endpoint.
 *
 *   $token = wuc_encode_id($sid, 'student');   // put in the URL
 *   $sid   = wuc_decode_id($_GET['id'], 'student'); // null if invalid/tampered
 */

if (!function_exists('wuc_app_secret')) {
    function wuc_app_secret(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }

        // Production deployments can pin the key via the environment.
        $env = getenv('WUC_APP_SECRET');
        if (is_string($env) && strlen($env) >= 32) {
            return $secret = $env;
        }

        // Otherwise read (or lazily create) a private per-install key file.
        $file = __DIR__ . '/app_secret.php';
        if (is_file($file)) {
            $val = include $file;
            if (is_string($val) && strlen($val) >= 32) {
                return $secret = $val;
            }
        }

        $key = bin2hex(random_bytes(32));
        $php = "<?php\n"
             . "// Auto-generated application secret used to encrypt/sign URL identifiers.\n"
             . "// Keep this file PRIVATE. Deleting it invalidates every token already issued.\n"
             . "return '" . $key . "';\n";
        @file_put_contents($file, $php, LOCK_EX);
        return $secret = $key;
    }
}

if (!function_exists('wuc_id_token_key')) {
    function wuc_id_token_key(string $context): string
    {
        // Derive a distinct 256-bit key per context so a 'student' token can
        // never be decoded — nor forged — under a different context.
        return hash_hmac('sha256', 'wuc-id-token:' . $context, wuc_app_secret(), true);
    }
}

if (!function_exists('wuc_encode_id')) {
    function wuc_encode_id($id, string $context = ''): string
    {
        $id = (string) $id;
        $key = wuc_id_token_key($context);

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($id, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context, 16);
            if ($cipher !== false) {
                return 'A' . rtrim(strtr(base64_encode($iv . $tag . $cipher), '+/', '-_'), '=');
            }
        }

        // Fallback (no openssl): HMAC-signed token — still tamper-proof.
        $sig = substr(hash_hmac('sha256', $id, $key, true), 0, 16);
        return 'S' . rtrim(strtr(base64_encode($sig . $id), '+/', '-_'), '=');
    }
}

if (!function_exists('wuc_decode_id')) {
    function wuc_decode_id($token, string $context = ''): ?string
    {
        if (!is_string($token) || strlen($token) < 2) {
            return null;
        }

        $version = $token[0];
        $b64 = strtr(substr($token, 1), '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $packed = base64_decode($b64, true);
        if ($packed === false) {
            return null;
        }

        $key = wuc_id_token_key($context);

        if ($version === 'A') {
            if (strlen($packed) < 12 + 16 + 1 || !function_exists('openssl_decrypt')) {
                return null;
            }
            $iv = substr($packed, 0, 12);
            $tag = substr($packed, 12, 16);
            $cipher = substr($packed, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context);
            return $plain === false ? null : $plain;
        }

        if ($version === 'S') {
            if (strlen($packed) < 16 + 1) {
                return null;
            }
            $sig = substr($packed, 0, 16);
            $id = substr($packed, 16);
            $expected = substr(hash_hmac('sha256', $id, $key, true), 0, 16);
            return hash_equals($expected, $sig) ? $id : null;
        }

        return null;
    }
}

if (!function_exists('wuc_resolve_id')) {
    /**
     * Receiver-side counterpart to wuc_encode_id(). Returns the real id from an
     * incoming URL value: decodes the opaque token when present, and otherwise
     * returns the trimmed raw value so links not yet migrated keep working.
     */
    function wuc_resolve_id($value, string $context = 'student'): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $decoded = wuc_decode_id($value, $context);
        return $decoded !== null ? $decoded : $value;
    }
}

/**
 * Password security policy helpers.
 *
 * Backed by the security_policies table (see db/create_security_tables.php); falls
 * back to secure defaults when that table is missing or empty so callers never fatal.
 */

if (!function_exists('get_security_policy')) {
    function get_security_policy($db = null): array
    {
        // Secure defaults used when the security_policies table is absent/empty.
        // Keys mirror the security_policies columns so the validator AND the
        // resetPassword.php display read the same names.
        $policy = [
            'min_length'        => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_digit'     => true,
            'require_special'   => false,
            'history_count'     => 5,
        ];

        if ($db instanceof mysqli) {
            try {
                $check = $db->query("SHOW TABLES LIKE 'security_policies'");
                if ($check && $check->num_rows > 0) {
                    // SELECT * so a table created without history_count (or other extras) won't error.
                    $res = $db->query("SELECT * FROM security_policies ORDER BY id LIMIT 1");
                    if ($res && ($row = $res->fetch_assoc())) {
                        if (isset($row['min_length']))        { $policy['min_length']        = (int)$row['min_length']; }
                        if (isset($row['require_uppercase'])) { $policy['require_uppercase'] = (bool)$row['require_uppercase']; }
                        if (isset($row['require_lowercase'])) { $policy['require_lowercase'] = (bool)$row['require_lowercase']; }
                        if (isset($row['require_digit']))     { $policy['require_digit']     = (bool)$row['require_digit']; }
                        if (isset($row['require_special']))   { $policy['require_special']   = (bool)$row['require_special']; }
                        if (isset($row['history_count']))     { $policy['history_count']     = (int)$row['history_count']; }
                    }
                }
            } catch (Throwable $e) {
                error_log('get_security_policy fell back to defaults: ' . $e->getMessage());
            }
        }

        return $policy;
    }
}

if (!function_exists('password_meets_policy')) {
    function password_meets_policy(string $password, array $policy, &$err = null): bool
    {
        $err = '';
        $min = (int)($policy['min_length'] ?? 8);

        if (strlen($password) < $min) {
            $err = "Password must be at least {$min} characters long.";
            return false;
        }
        if (!empty($policy['require_uppercase']) && !preg_match('/[A-Z]/', $password)) {
            $err = 'Password must contain at least one uppercase letter.';
            return false;
        }
        if (!empty($policy['require_lowercase']) && !preg_match('/[a-z]/', $password)) {
            $err = 'Password must contain at least one lowercase letter.';
            return false;
        }
        if (!empty($policy['require_digit']) && !preg_match('/[0-9]/', $password)) {
            $err = 'Password must contain at least one number.';
            return false;
        }
        if (!empty($policy['require_special']) && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $err = 'Password must contain at least one special character.';
            return false;
        }

        return true;
    }
}

if (!function_exists('record_password_history')) {
    function record_password_history($db, string $staff_id, string $password_hash): bool
    {
        if (!$db instanceof mysqli) {
            return false;
        }

        // Errors are swallowed on purpose: failing to record history must never break
        // account creation or a password reset (resetPassword.php calls this mid-transaction).
        try {
            $stmt = $db->prepare("INSERT INTO password_history (staff_id, password_hash) VALUES (?, ?)");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $staff_id, $password_hash);
            $ok = $stmt->execute();
            $stmt->close();
            return (bool)$ok;
        } catch (Throwable $e) {
            error_log('record_password_history failed: ' . $e->getMessage());
            return false;
        }
    }
}
