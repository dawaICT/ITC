<?php
declare(strict_types=1);

if (!function_exists('wuc_exhibition_mode_enabled')) {
    function wuc_exhibition_mode_enabled(?mysqli $db = null): bool
    {
        $env = getenv('WUC_EXHIBITION_MODE');
        if ($env !== false && $env !== '') {
            return in_array(strtolower(trim($env)), ['1', 'true', 'yes', 'on'], true);
        }
        if (!$db) {
            return false;
        }
        try {
            $stmt = $db->prepare("SELECT setting_value FROM portal_settings WHERE setting_key = 'exhibition_mode' LIMIT 1");
            if (!$stmt) {
                return false;
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return in_array(strtolower(trim((string)($row['setting_value'] ?? '0'))), ['1', 'true', 'yes', 'on'], true);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wuc_exhibition_is_demo_identifier')) {
    function wuc_exhibition_is_demo_identifier(string $identifier): bool
    {
        $value = strtolower(trim($identifier));
        return str_starts_with($value, 'exh-')
            || str_starts_with($value, 'exh_')
            || str_ends_with($value, '@exhibition.test');
    }
}

if (!function_exists('wuc_exhibition_assert_destructive_target')) {
    /**
     * In Exhibition Mode, destructive tools may mutate only explicitly seeded
     * EXH-* identities. Web handlers can call this before delete/reset actions.
     */
    function wuc_exhibition_assert_destructive_target(mysqli $db, string $identifier): void
    {
        if (wuc_exhibition_mode_enabled($db) && !wuc_exhibition_is_demo_identifier($identifier)) {
            throw new DomainException('Exhibition Mode protects non-demo records from destructive operations.');
        }
    }
}
