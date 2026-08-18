<?php
require_once __DIR__ . '/staff_role_helpers.php';

if (!function_exists('wuc_secure_session_start')) {
    function wuc_secure_session_start(int $timeoutSeconds = 1800): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
            ini_set('session.cookie_samesite', 'Lax');
            ini_set('session.gc_maxlifetime', (string) $timeoutSeconds);
            session_start();
        }

        $now = time();
        if (!empty($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $timeoutSeconds) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            session_start();
        }

        $_SESSION['last_activity'] = $now;

        if (empty($_SESSION['created_at'])) {
            $_SESSION['created_at'] = $now;
        } elseif (($now - (int) $_SESSION['created_at']) > 600) {
            session_regenerate_id(true);
            $_SESSION['created_at'] = $now;
        }
    }
}

if (!function_exists('wuc_session_release_lock')) {
    /**
     * Close the session early on read-mostly pages so concurrent requests from
     * the same browser (tabs, AJAX) are not blocked on the session file lock.
     * Session data remains readable in $_SESSION for the rest of the request;
     * further writes are discarded until the next session_start().
     */
    function wuc_session_release_lock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}

if (!function_exists('wuc_security_headers')) {
    function wuc_security_headers(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }
}

if (!function_exists('wuc_csrf_token')) {
    function wuc_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('wuc_validate_csrf')) {
    function wuc_validate_csrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('wuc_get_client_ip')) {
    function wuc_get_client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }
}

if (!function_exists('wuc_ensure_login_attempts_table')) {
    function wuc_ensure_login_attempts_table(mysqli $db): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        try {
            $ready = $db->query("SHOW TABLES LIKE 'login_attempts'")->num_rows === 1;
            if (!$ready) {
                error_log('login_attempts table is missing; run the production migrations.');
            }
        } catch (Throwable $e) {
            error_log('login_attempts unavailable: ' . $e->getMessage());
            $ready = false;
        }

        return $ready;
    }
}

if (!function_exists('wuc_login_key')) {
    function wuc_login_key(string $scope, string $identifier): string
    {
        return substr($scope . ':' . trim($identifier), 0, 80);
    }
}

if (!function_exists('wuc_is_login_locked')) {
    function wuc_is_login_locked(mysqli $db, string $scope, string $identifier, int $maxAttempts = 5, int $windowSeconds = 900): bool
    {
        if (!wuc_ensure_login_attempts_table($db)) {
            return false;
        }

        $ip = wuc_get_client_ip();
        $key = wuc_login_key($scope, $identifier);
        $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);

        // On loopback (localhost), every local user shares one IP, so an IP-based
        // lock would lock out ALL staff after a few failed attempts. Lock per-user
        // only for loopback; keep full IP+user protection for real client IPs.
        $isLoopback = in_array($ip, ['::1', '127.0.0.1', '0.0.0.0', ''], true) || strncmp($ip, '127.', 4) === 0;

        if ($isLoopback) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM login_attempts WHERE attempt_time > ? AND user_id = ?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $cutoff, $key);
        } else {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM login_attempts WHERE attempt_time > ? AND (ip_address = ? OR user_id = ?)'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sss', $cutoff, $ip, $key);
        }
        $stmt->execute();
        $stmt->bind_result($attempts);
        $stmt->fetch();
        $stmt->close();

        return (int) $attempts >= $maxAttempts;
    }
}

if (!function_exists('wuc_record_failed_login')) {
    function wuc_record_failed_login(mysqli $db, string $scope, string $identifier): void
    {
        if (!wuc_ensure_login_attempts_table($db)) {
            return;
        }

        $key = wuc_login_key($scope, $identifier);
        $ip = wuc_get_client_ip();
        $stmt = $db->prepare('INSERT INTO login_attempts (user_id, ip_address, attempt_time) VALUES (?, ?, NOW())');
        if ($stmt) {
            $stmt->bind_param('ss', $key, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('wuc_clear_login_attempts')) {
    function wuc_clear_login_attempts(mysqli $db, string $scope, string $identifier): void
    {
        if (!wuc_ensure_login_attempts_table($db)) {
            return;
        }

        $key = wuc_login_key($scope, $identifier);
        $ip = wuc_get_client_ip();
        $stmt = $db->prepare('DELETE FROM login_attempts WHERE user_id = ? OR ip_address = ?');
        if ($stmt) {
            $stmt->bind_param('ss', $key, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('wuc_password_verify_legacy')) {
    function wuc_password_verify_legacy(string $plain, string $stored): array
    {
        $stored = trim($stored);
        if ($stored === '') {
            password_verify($plain, password_hash('dummy-password', PASSWORD_DEFAULT));
            return [false, false];
        }

        $algo = password_get_info($stored)['algo'];
        if ($algo !== null && $algo !== 0) {
            return [password_verify($plain, $stored), password_needs_rehash($stored, PASSWORD_DEFAULT)];
        }

        if (preg_match('/^[a-f0-9]{32}$/i', $stored) && hash_equals(strtolower($stored), md5($plain))) {
            return [true, true];
        }

        if (hash_equals($stored, $plain)) {
            return [true, true];
        }

        return [false, false];
    }
}

if (!function_exists('wuc_redirect')) {
    function wuc_redirect(string $location, int $status = 303): void
    {
        header('Location: ' . $location, true, $status);
        exit;
    }
}

if (!function_exists('wuc_auth_table_exists')) {
    function wuc_auth_table_exists(mysqli $db, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            if (!$stmt) {
                $cache[$table] = false;
                return false;
            }
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $cache[$table] = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}

if (!function_exists('wuc_sync_staff_password')) {
    /**
     * Align password hashes across login stores. staffLogin.php verifies users.password.
     */
    function wuc_sync_staff_password(
        mysqli $db,
        string $staffId,
        string $hashedPassword,
        ?string $primaryRole = null,
        string $status = 'active'
    ): void {
        $staffId = trim($staffId);
        if ($staffId === '' || $hashedPassword === '' || !wuc_auth_table_exists($db, 'users')) {
            return;
        }

        $role = ($primaryRole !== null && $primaryRole !== '') ? $primaryRole : 'staff';
        if ($role === 'staff') {
            $roleStmt = $db->prepare('SELECT role FROM staff WHERE staff_id = ? LIMIT 1');
            if ($roleStmt) {
                $roleStmt->bind_param('s', $staffId);
                $roleStmt->execute();
                $roleStmt->bind_result($staffRole);
                if ($roleStmt->fetch() && trim((string) $staffRole) !== '') {
                    $role = wuc_normalize_staff_role((string) $staffRole);
                }
                $roleStmt->close();
            }
        }

        $userId = null;
        $lookup = $db->prepare('SELECT user_id FROM users WHERE staff_id = ? OR username = ? LIMIT 1');
        if ($lookup) {
            $lookup->bind_param('ss', $staffId, $staffId);
            $lookup->execute();
            $lookup->bind_result($userId);
            $lookup->fetch();
            $lookup->close();
        }

        if ($userId) {
            $upd = $db->prepare('UPDATE users SET password = ? WHERE user_id = ?');
            if ($upd) {
                $uid = (int) $userId;
                $upd->bind_param('si', $hashedPassword, $uid);
                $upd->execute();
                $upd->close();
            }
            return;
        }

        $ins = $db->prepare(
            'INSERT INTO users (username, password, primary_role, staff_id, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        if ($ins) {
            $ins->bind_param('sssss', $staffId, $hashedPassword, $role, $staffId, $status);
            $ins->execute();
            $ins->close();
        }
    }
}

if (!function_exists('wuc_sync_student_password')) {
    /**
     * Align password hashes across login stores. studentLogin.php verifies users.password.
     */
    function wuc_sync_student_password(mysqli $db, string $sid, string $hashedPassword): void
    {
        $sid = trim($sid);
        if ($sid === '' || $hashedPassword === '' || !wuc_auth_table_exists($db, 'users')) {
            return;
        }

        $userId = null;
        $lookup = $db->prepare('SELECT user_id FROM users WHERE student_id = ? OR username = ? LIMIT 1');
        if ($lookup) {
            $lookup->bind_param('ss', $sid, $sid);
            $lookup->execute();
            $lookup->bind_result($userId);
            $lookup->fetch();
            $lookup->close();
        }

        if ($userId) {
            $upd = $db->prepare('UPDATE users SET password = ? WHERE user_id = ?');
            if ($upd) {
                $uid = (int) $userId;
                $upd->bind_param('si', $hashedPassword, $uid);
                $upd->execute();
                $upd->close();
            }
        } else {
            $ins = $db->prepare(
                "INSERT INTO users (username, password, primary_role, student_id, status)
                 VALUES (?, ?, 'student', ?, 'active')"
            );
            if ($ins) {
                $ins->bind_param('sss', $sid, $hashedPassword, $sid);
                $ins->execute();
                $userId = (int) $ins->insert_id;
                $ins->close();
            }
        }

        if (!$userId || !wuc_auth_table_exists($db, 'user_roles') || !wuc_auth_table_exists($db, 'roles')) {
            return;
        }

        $roleId = null;
        $roleStmt = $db->prepare("SELECT role_id FROM roles WHERE role_name = 'student' LIMIT 1");
        if ($roleStmt) {
            $roleStmt->execute();
            $roleStmt->bind_result($roleId);
            $roleStmt->fetch();
            $roleStmt->close();
        }
        if (!$roleId) {
            return;
        }

        $uid = (int) $userId;
        $rid = (int) $roleId;
        $ur = $db->prepare(
            "INSERT INTO user_roles (user_id, role_id, status) VALUES (?, ?, 'active')
             ON DUPLICATE KEY UPDATE status = 'active'"
        );
        if ($ur) {
            $ur->bind_param('ii', $uid, $rid);
            $ur->execute();
            $ur->close();
        }
    }
}
