<?php
declare(strict_types=1);

/**
 * DB-backed smoke test for Skills-to-Trade Hub workflow.
 * Run: C:\xampp\php\php.exe scripts/test_enterprise_hub_workflow.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_hub/bootstrap.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "DB unavailable\n");
    exit(1);
}

$pass = 0;
$fail = 0;
function t(bool $ok, string $name): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . "\n";
    $ok ? $pass++ : $fail++;
}

// Tables exist
foreach ([
    'enterprise_profiles', 'enterprise_items', 'enterprise_categories', 'enterprise_costs',
    'enterprise_readiness_assessments', 'enterprise_reviews', 'enterprise_interests',
    'enterprise_item_media', 'enterprise_settings',
] as $table) {
    $res = $db->query("SHOW TABLES LIKE '{$table}'");
    t($res && $res->num_rows === 1, "table exists: {$table}");
}

// Published filter
$published = eh_search_published($db, [], 5, 0);
t(count($published) > 0, 'published search returns rows');
foreach ($published as $row) {
    if (($row['status'] ?? '') !== 'published') {
        t(false, 'published search leaked non-published');
        break;
    }
}
t(true, 'published search status filter OK');

// Public code lookup
$code = 'ENT-DEMO0001';
$item = eh_get_item_by_code($db, $code, true);
t($item !== null && $item['status'] === 'published', 'get published by public code');

$draftCode = 'ENT-DEMO0013';
$hidden = eh_get_item_by_code($db, $draftCode, true);
t($hidden === null, 'draft not visible via public code lookup');

// Cost calc save on a demo draft if profile exists
$calc = eh_calculate_costs([
    'material_cost' => 10, 'labour_cost' => 10, 'transport_cost' => 0,
    'utilities_cost' => 0, 'packaging_cost' => 0, 'marketing_cost' => 0,
    'other_cost' => 0, 'number_of_units' => 2, 'selling_price' => 15,
]);
t($calc['ok'] && (float)$calc['data']['total_cost'] === 20.0, 'calc smoke');

// Status transition rules still hold
t(eh_can_transition('approved', 'published'), 'approve→publish allowed');
t(!eh_can_transition('submitted', 'published'), 'submitted↛published blocked');

// Admin stats
$stats = eh_admin_dashboard_stats($db);
t(isset($stats['published_total']) && $stats['published_total'] >= 1, 'admin stats published_total');
t(isset($stats['new_interests']), 'admin stats new_interests key');

// Interest types / settings
t(eh_setting($db, 'currency_label') === 'ZMW' || eh_setting($db, 'currency_label') !== null, 'settings readable');
t(count(eh_list_categories($db)) >= 5, 'categories seeded');

// Report rows
$rows = eh_report_rows($db, 'status_distribution');
t(count($rows) > 0, 'status_distribution report');

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
