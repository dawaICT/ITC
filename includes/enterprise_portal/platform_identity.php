<?php
declare(strict_types=1);

/**
 * Product branding for integrated host vs standalone Skills and Enterprise Network.
 */

if (!function_exists('ep_platform_product_name')) {
    function ep_platform_product_name(): string
    {
        $custom = function_exists('wuc_portal_env')
            ? trim((string)wuc_portal_env('ENTERPRISE_PLATFORM_NAME', ''))
            : trim((string)(getenv('ENTERPRISE_PLATFORM_NAME') ?: ''));
        if ($custom !== '') {
            return $custom;
        }
        if (function_exists('ep_is_standalone_mode') && ep_is_standalone_mode()) {
            return 'Skills and Enterprise Network';
        }
        return 'Skills and Enterprise Portal';
    }
}

if (!function_exists('ep_platform_tagline')) {
    function ep_platform_tagline(): string
    {
        $custom = function_exists('wuc_portal_env')
            ? trim((string)wuc_portal_env('ENTERPRISE_PLATFORM_TAGLINE', ''))
            : trim((string)(getenv('ENTERPRISE_PLATFORM_TAGLINE') ?: ''));
        if ($custom !== '') {
            return $custom;
        }
        return 'Connecting Skills, Farmers, Businesses and Markets';
    }
}

if (!function_exists('ep_platform_service_pillars')) {
    /**
     * Three connected services (skills, enterprise marketplace, agriculture).
     *
     * @return list<array{key:string,label:string,description:string}>
     */
    function ep_platform_service_pillars(): array
    {
        return [
            [
                'key' => 'skills_careers',
                'label' => 'Skills and Careers',
                'description' => 'Connect students and graduates to employers',
            ],
            [
                'key' => 'enterprise_marketplace',
                'label' => 'Enterprise Marketplace',
                'description' => 'Connect producers and service providers to customers',
            ],
            [
                'key' => 'agriculture_market_access',
                'label' => 'Agriculture Market Access',
                'description' => 'Connect small-scale farmers to buyers and verified price information',
            ],
        ];
    }
}

if (!function_exists('ep_platform_access_channels')) {
    /**
     * Supported or planned access channels (same business engine).
     *
     * @return list<array{key:string,label:string,status:string}>
     */
    function ep_platform_access_channels(): array
    {
        return [
            ['key' => 'web', 'label' => 'Web portal', 'status' => 'available'],
            ['key' => 'mobile_web', 'label' => 'Mobile web / PWA', 'status' => 'partial'],
            ['key' => 'public_directory', 'label' => 'Public directory', 'status' => 'available'],
            ['key' => 'ussd', 'label' => 'USSD', 'status' => 'simulator'],
            ['key' => 'sms', 'label' => 'SMS', 'status' => 'mock_queue'],
            ['key' => 'agent', 'label' => 'Agent portal', 'status' => 'available'],
            ['key' => 'call_centre', 'label' => 'Call centre', 'status' => 'manual'],
            ['key' => 'ivr', 'label' => 'Voice / IVR', 'status' => 'planned'],
            ['key' => 'api', 'label' => 'External API', 'status' => 'partial'],
        ];
    }
}
