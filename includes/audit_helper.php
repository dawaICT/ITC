<?php
/**
 * System Audit Logging Helper
 */

if (!function_exists('wuc_log_audit')) {
    function wuc_log_audit(mysqli $db, string $action, string $details = ''): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $userId = (string)($_SESSION['staff_id'] ?? $_SESSION['Sid'] ?? $_SESSION['user_id'] ?? 'guest');
        $userRole = (string)($_SESSION['role'] ?? 'guest');
        $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI');

        // Truncate user agent if needed to fit schema limits
        if (strlen($userAgent) > 255) {
            $userAgent = substr($userAgent, 0, 252) . '...';
        }

        try {
            $stmt = $db->prepare("INSERT INTO system_audit_logs (user_id, user_role, action, ip_address, user_agent, details) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('ssssss', $userId, $userRole, $action, $ipAddress, $userAgent, $details);
                $ok = $stmt->execute();
                $stmt->close();
                return $ok;
            }
        } catch (Throwable $e) {
            error_log('wuc_log_audit failed: ' . $e->getMessage());
        }

        return false;
    }
}
?>
