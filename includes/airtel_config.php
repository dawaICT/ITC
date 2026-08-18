<?php
/**
 * Airtel Money Gateway Configuration
 * 
 * Set your Airtel Money API credentials here
 * Get credentials from: https://merchant.airtel.africa/
 */

// ===== AIRTEL MONEY CONFIGURATION =====

/**
 * Parse an environment flag without treating the string "false" as true.
 */
function wuc_airtel_config_flag($value): bool
{
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

// Environment: 'sandbox' for testing, 'production' for live
define('AIRTEL_ENV', getenv('AIRTEL_ENV') ?: 'sandbox');

// API Credentials (get these from Airtel Money Merchant Portal)
define('AIRTEL_CLIENT_ID', getenv('AIRTEL_CLIENT_ID') ?: '');
define('AIRTEL_CLIENT_SECRET', getenv('AIRTEL_CLIENT_SECRET') ?: '');
define('AIRTEL_MERCHANT_CODE', getenv('AIRTEL_MERCHANT_CODE') ?: '');

// Callback webhook URL (where Airtel sends payment confirmations)
define('AIRTEL_WEBHOOK_URL', getenv('AIRTEL_WEBHOOK_URL') ?: 'https://yourdomain.com/students/airtel_callback.php');

// Currency code (ZMW for Zambian Kwacha)
define('AIRTEL_CURRENCY', 'ZMW');

// Airtel Money merchant category
define('AIRTEL_MERCHANT_CATEGORY', 'Education');

// Enable/disable Airtel Money as a payment option
define('AIRTEL_MONEY_ENABLED', wuc_airtel_config_flag(getenv('AIRTEL_MONEY_ENABLED')));

// Minimum and maximum transaction amounts (in ZMW)
define('AIRTEL_MIN_AMOUNT', 10);
define('AIRTEL_MAX_AMOUNT', 100000);

// Request timeout in seconds
define('AIRTEL_REQUEST_TIMEOUT', 30);

// Enable request/response logging for debugging
define('AIRTEL_DEBUG_MODE', wuc_airtel_config_flag(getenv('AIRTEL_DEBUG_MODE')));

?>
