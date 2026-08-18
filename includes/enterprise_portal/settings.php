<?php
declare(strict_types=1);

if (!function_exists('ep_setting')) {
    function ep_setting(mysqli $db, string $key, ?string $default = null): ?string
    {
        static $cache = [];
        $envMap = [
            'portal_enabled' => 'ENTERPRISE_PORTAL_ENABLED',
            'membership_approval_mode' => 'ENTERPRISE_MEMBERSHIP_APPROVAL_MODE',
            'allow_current_students' => 'ENTERPRISE_ALLOW_CURRENT_STUDENTS',
            'allow_graduates' => 'ENTERPRISE_ALLOW_GRADUATES',
            'public_directory' => 'ENTERPRISE_PUBLIC_DIRECTORY',
            'ai_enabled' => 'ENTERPRISE_AI_ENABLED',
        ];
        if (isset($envMap[$key])) {
            $env = getenv($envMap[$key]);
            if ($env !== false && $env !== '') {
                return $cache[$key] = $env;
            }
        }
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $db->prepare('SELECT setting_value FROM enterprise_portal_settings WHERE setting_key = ? LIMIT 1');
        if (!$stmt) {
            return $default;
        }
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $cache[$key] = isset($row['setting_value']) ? (string)$row['setting_value'] : $default;
    }
}

if (!function_exists('ep_setting_bool')) {
    function ep_setting_bool(mysqli $db, string $key, bool $default = false): bool
    {
        $v = ep_setting($db, $key, $default ? 'true' : 'false');
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('ep_set_setting')) {
    function ep_set_setting(mysqli $db, string $key, string $value): void
    {
        $stmt = $db->prepare('INSERT INTO enterprise_portal_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('ep_portal_enabled')) {
    function ep_portal_enabled(mysqli $db): bool
    {
        return ep_setting_bool($db, 'portal_enabled', true);
    }
}

if (!function_exists('ep_ai_enabled')) {
    function ep_ai_enabled(mysqli $db): bool
    {
        return ep_setting_bool($db, 'ai_enabled', false);
    }
}
