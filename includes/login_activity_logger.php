<?php
/**
 * Login Activity Logger
 * 
 * Shared helper to record login events in the login_activity table.
 * Include this file and call wuc_log_login() after a successful authentication.
 */

if (!function_exists('wuc_log_login')) {
    /**
     * Record a successful login event.
     *
     * @param mysqli $db       Active database connection
     * @param string $userId   The user's ID (SID for students, staff_id for staff)
     * @param string $userType Either 'student' or 'staff'
     * @param string $userName Full name of the user (optional snapshot)
     */
    function wuc_log_login(mysqli $db, string $userId, string $userType = 'student', string $userName = ''): void
    {
        // Schema creation belongs to the migration runner, never a web request.
        try {
            static $tableReady = null;
            if ($tableReady === null) {
                $tableReady = $db->query("SHOW TABLES LIKE 'login_activity'")->num_rows === 1;
            }
            if (!$tableReady) {
                error_log('login_activity table is missing; run migrations.');
                return;
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
            }
            $ip = filter_var(trim($ip), FILTER_VALIDATE_IP) ?: '0.0.0.0';

            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

            $stmt = $db->prepare(
                "INSERT INTO login_activity (user_id, user_type, user_name, ip_address, user_agent, login_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param('sssss', $userId, $userType, $userName, $ip, $ua);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            // Log but never block the login flow
            error_log('wuc_log_login error: ' . $e->getMessage());
        }
    }
}
