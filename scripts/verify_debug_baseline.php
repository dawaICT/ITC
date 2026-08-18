<?php
declare(strict_types=1);

/**
 * Collect environment + schema signals for full debug baseline.
 * Run: php scripts/verify_debug_baseline.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_portal/bootstrap.php';

$checks = [];
$fail = 0;

function dbg(bool $ok, string $label, array &$checks, int &$fail): void
{
    $checks[] = ['ok' => $ok, 'label' => $label];
    if (!$ok) {
        $fail++;
    }
}

dbg(ep_portal_enabled($db), 'Enterprise portal enabled', $checks, $fail);
dbg(ep_agriculture_enabled($db), 'Agriculture module enabled', $checks, $fail);
dbg(ep_deployment_mode() === 'integrated' || ep_deployment_mode() === 'standalone', 'Deployment mode valid', $checks, $fail);

$tables = [
    'enterprise_memberships', 'enterprise_opportunities', 'enterprise_farmer_profiles',
    'enterprise_produce_listings', 'enterprise_buyer_crop_demands', 'enterprise_commodity_prices',
    'enterprise_sms_messages', 'enterprise_ussd_sessions',
];
foreach ($tables as $t) {
    dbg(ep_agri_table_exists($db, $t) || @$db->query("SHOW TABLES LIKE '{$t}'")->num_rows > 0, "Table {$t}", $checks, $fail);
}

$smsProvider = ep_adapters()['sms']->providerName();
$ussdProvider = ep_adapters()['ussd']->providerName();
dbg($smsProvider === 'mock' || $smsProvider !== '', 'SMS provider configured', $checks, $fail);
dbg($ussdProvider === 'simulator' || $ussdProvider !== '', 'USSD provider configured', $checks, $fail);
dbg(ep_organizations_table_ready($db), 'Table enterprise_organizations', $checks, $fail);
dbg(!ep_adapters()['ussd']->credentialsConfigured(), 'USSD live creds not claimed (expected in dev)', $checks, $fail);

echo "=== Debug baseline probe ===\n";
foreach ($checks as $c) {
    echo ($c['ok'] ? '[OK] ' : '[MISSING] ') . $c['label'] . "\n";
}
echo "\nBase URL: http://localhost/wucportal/\n";
echo "Agriculture UI: http://localhost/wucportal/enterprise/agriculture/index.php\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Product: " . ep_platform_product_name() . " — " . ep_platform_tagline() . "\n";
echo "SMS: {$smsProvider} | USSD: {$ussdProvider}\n";
echo "Failed checks: {$fail}\n";
exit($fail > 0 ? 1 : 0);
