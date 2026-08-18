<?php
declare(strict_types=1);

/**
 * Agriculture & Market Access domain tests + regression hook.
 * Run: c:\xampp\php\php.exe scripts/test_agriculture_market_access.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_portal/bootstrap.php';

$pass = 0;
$fail = 0;

function agri_assert(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] {$msg}\n";
        $pass++;
    } else {
        echo "[FAIL] {$msg}\n";
        $fail++;
    }
}

echo "=== Agriculture market access ===\n";
agri_assert(ep_deployment_mode() === 'integrated' || ep_deployment_mode() === 'standalone', 'Deployment mode readable');
agri_assert(isset(ep_adapters()['sms'], ep_adapters()['ussd'], ep_adapters()['identity']), 'Adapters loaded');
agri_assert(ep_adapters()['sms']->providerName() !== '', 'SMS provider name');
agri_assert(ep_normalize_msisdn('0977123456') === '260977123456', 'MSISDN normalize ZM local');
agri_assert(isset(ep_opportunity_types()['produce_listing'], ep_opportunity_types()['crop_demand']), 'Opportunity types extended');
agri_assert(isset(ep_commodity_price_types()['verified_buyer_offer']), 'Price types defined');
agri_assert(ep_price_type_label('government_reference_price') !== 'verified_buyer_offer', 'Price labels distinct');

$tables = [
    'enterprise_commodities', 'enterprise_measurement_units', 'enterprise_farmer_profiles',
    'enterprise_produce_listings', 'enterprise_buyer_crop_demands', 'enterprise_produce_matches',
    'enterprise_commodity_prices', 'enterprise_commodity_price_sources', 'enterprise_sms_messages',
    'enterprise_ussd_sessions', 'enterprise_communication_channels',
];
$schemaReady = true;
foreach ($tables as $t) {
    $ok = ep_agri_table_exists($db, $t);
    agri_assert($ok, "Table exists: {$t}");
    if (!$ok) {
        $schemaReady = false;
    }
}

if ($schemaReady) {
    $reg = ep_register_farmer($db, [
        'full_name' => 'Test Farmer ' . bin2hex(random_bytes(2)),
        'mobile' => '0977' . random_int(100000, 999999),
        'province' => 'North-Western',
        'district' => 'Solwezi',
        'camp_or_village' => 'Test Camp',
        'consent_method' => 'agent_assisted',
    ], null);
    agri_assert(!empty($reg['ok']), 'Farmer register without web user: ' . ($reg['message'] ?? 'ok'));

    $c = $db->query("SELECT id FROM enterprise_commodities WHERE commodity_code='MAIZE' LIMIT 1")->fetch_assoc();
    $u = $db->query("SELECT unit_code FROM enterprise_measurement_units WHERE unit_code='bag50' LIMIT 1")->fetch_assoc();
    $s = $db->query("SELECT id FROM enterprise_commodity_price_sources WHERE source_code='LOCAL_MARKET' LIMIT 1")->fetch_assoc();
    agri_assert($c && $u && $s, 'Seed commodity/unit/source present');

    if (!empty($reg['ok']) && $c && $u) {
        $listing = ep_create_produce_listing($db, [
            'farmer_profile_id' => (int)$reg['farmer_id'],
            'commodity_id' => (int)$c['id'],
            'quantity' => 40,
            'quantity_unit' => 'bag50',
            'location_label' => 'Solwezi',
            'province' => 'North-Western',
            'district' => 'Solwezi',
        ]);
        agri_assert(!empty($listing['ok']), 'Produce listing created');

        $zero = ep_create_produce_listing($db, [
            'farmer_profile_id' => (int)$reg['farmer_id'],
            'commodity_id' => (int)$c['id'],
            'quantity' => 0,
            'quantity_unit' => 'bag50',
            'location_label' => 'Solwezi',
        ]);
        agri_assert(empty($zero['ok']), 'Zero quantity rejected');
    }

    if ($c && $s) {
        $draft = ep_create_commodity_price_draft($db, [
            'commodity_id' => (int)$c['id'],
            'price' => 180,
            'quantity_unit' => 'bag50',
            'location_label' => 'Solwezi',
            'price_type' => 'local_market_reference',
            'source_id' => (int)$s['id'],
            'effective_from' => date('Y-m-d'),
            'province' => 'North-Western',
        ]);
        agri_assert(!empty($draft['ok']), 'Price draft created');

        $nosource = ep_create_commodity_price_draft($db, [
            'commodity_id' => (int)$c['id'],
            'price' => 180,
            'quantity_unit' => 'bag50',
            'location_label' => 'Solwezi',
            'price_type' => 'local_market_reference',
            'source_id' => 0,
            'effective_from' => date('Y-m-d'),
        ]);
        agri_assert(empty($nosource['ok']), 'Price without source rejected');

        if (!empty($draft['ok'])) {
            $pub = ep_publish_commodity_price($db, (int)$draft['price_id'], false);
            agri_assert(!empty($pub['ok']), 'Local market price publishable with single path');
            $current = ep_current_commodity_prices($db, ['commodity_id' => (int)$c['id']]);
            agri_assert($current !== [], 'Current prices return published non-expired rows');
            if ($current !== []) {
                $sms = ep_format_price_sms($current[0]);
                agri_assert(str_contains($sms, 'Source:'), 'SMS price includes source');
                agri_assert(!str_contains(strtolower($sms), 'guaranteed sale completed'), 'SMS does not claim completed sale');
            }
        }
    }

    $sms = ep_adapters()['sms']->send($db, '260977000001', 'Test price SMS mock', 'price_result', 'idem_' . bin2hex(random_bytes(4)));
    agri_assert(!empty($sms['ok']), 'Mock SMS enqueue works');
    $sms2 = ep_adapters()['sms']->send($db, '260977000001', 'Test price SMS mock', 'price_result', (string)$sms['provider_reference']);
    agri_assert(!empty($sms2['ok']) && str_contains((string)($sms2['message'] ?? ''), 'Idempotent'), 'SMS idempotent replay');

    $ussd = ep_ussd_start_session($db, '0977000002', 'req_' . bin2hex(random_bytes(3)));
    agri_assert(!empty($ussd['ok']), 'USSD simulator session starts');
    if (!empty($ussd['ok'])) {
        $r = ep_ussd_handle_input($db, (string)$ussd['session_reference'], '1');
        agri_assert(!empty($r['ok']), 'USSD price check path');
    }

    // Unverified buyer cannot get matches that lead to referral
    if ($c && $u && !empty($reg['ok'])) {
        $demand = ep_create_buyer_crop_demand($db, [
            'commodity_id' => (int)$c['id'],
            'quantity_required' => 100,
            'quantity_unit' => 'bag50',
            'location_label' => 'Solwezi',
            'province' => 'North-Western',
            'buyer_verification_status' => 'unverified',
        ]);
        agri_assert(!empty($demand['ok']), 'Demand created as pending review');
        if (!empty($demand['ok'])) {
            $suggestions = ep_suggest_produce_matches($db, (int)$demand['demand_id']);
            agri_assert($suggestions === [], 'Unverified buyer gets no match suggestions');
        }

        if (!empty($listing['ok']) && !empty($demand['ok'])) {
            $db->query("UPDATE enterprise_buyer_crop_demands SET buyer_verification_status='verified', status='open' WHERE id=" . (int)$demand['demand_id']);
            $badMatch = ep_record_produce_match($db, (int)$demand['demand_id'], (int)$listing['listing_id'], 0, 'bag50');
            agri_assert(empty($badMatch['ok']), 'Zero proposed quantity rejected on match record');
        }
    }

    agri_assert(function_exists('ep_expire_buyer_crop_demands'), 'Buyer demand expiry helper exists');
    agri_assert(function_exists('ep_list_farmers_for_staff'), 'Scoped farmer list helper exists');
} else {
    echo "[INFO] Run migrations/20260722_enterprise_agriculture_market_access.php then re-run.\n";
}

echo "\n=== Regression smoke (membership/opportunity still loaded) ===\n";
agri_assert(ep_can_transition_membership('not_enrolled', 'pending'), 'Membership transition still works');
agri_assert(in_array('published', ep_opportunity_transitions()['approved'] ?? [], true), 'Opportunity publish path intact');

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
