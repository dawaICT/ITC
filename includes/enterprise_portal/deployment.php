<?php
declare(strict_types=1);

/**
 * Enterprise deployment mode and agriculture configuration.
 *
 * ENTERPRISE_DEPLOYMENT_MODE=integrated (default) | standalone
 */

if (!function_exists('ep_deployment_mode')) {
    function ep_deployment_mode(): string
    {
        $mode = strtolower(trim((string)(function_exists('wuc_portal_env')
            ? wuc_portal_env('ENTERPRISE_DEPLOYMENT_MODE', 'integrated')
            : (getenv('ENTERPRISE_DEPLOYMENT_MODE') ?: 'integrated'))));
        return $mode === 'standalone' ? 'standalone' : 'integrated';
    }
}

if (!function_exists('ep_is_standalone_mode')) {
    function ep_is_standalone_mode(): bool
    {
        return ep_deployment_mode() === 'standalone';
    }
}

if (!function_exists('ep_agriculture_enabled')) {
    function ep_agriculture_enabled(mysqli $db): bool
    {
        $env = function_exists('wuc_portal_env')
            ? wuc_portal_env('ENTERPRISE_AGRICULTURE_ENABLED', null)
            : getenv('ENTERPRISE_AGRICULTURE_ENABLED');
        if ($env !== false && $env !== null && $env !== '') {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }
        if (function_exists('ep_setting_bool')) {
            return ep_setting_bool($db, 'agriculture_enabled', true);
        }
        return true;
    }
}

if (!function_exists('ep_official_price_approval_mode')) {
    /** @return 'single'|'dual' */
    function ep_official_price_approval_mode(): string
    {
        $mode = strtolower(trim((string)(function_exists('wuc_portal_env')
            ? wuc_portal_env('AGRICULTURE_OFFICIAL_PRICE_APPROVAL_MODE', 'dual')
            : (getenv('AGRICULTURE_OFFICIAL_PRICE_APPROVAL_MODE') ?: 'dual'))));
        return $mode === 'single' ? 'single' : 'dual';
    }
}

if (!function_exists('ep_normalize_msisdn')) {
    /**
     * Normalize Zambian-style numbers to digits with country code when possible.
     * Never use the result as a primary key.
     */
    function ep_normalize_msisdn(string $raw, string $defaultCountry = '260'): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return $defaultCountry . substr($digits, 1);
        }
        if (strlen($digits) === 9 && str_starts_with($digits, '9')) {
            return $defaultCountry . $digits;
        }
        return $digits;
    }
}
