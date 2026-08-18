<?php
declare(strict_types=1);

/**
 * Smoke tests for Skills and Enterprise Portal domain logic.
 * Run: c:\xampp\php\php.exe scripts/test_enterprise_portal_smoke.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_portal/bootstrap.php';

$pass = 0;
$fail = 0;

function ep_assert(bool $cond, string $msg): void
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

ep_assert(ep_portal_enabled($db), 'Portal enabled setting readable');
ep_assert(ep_can_transition_membership('not_enrolled', 'pending'), 'Membership not_enrolled → pending');
ep_assert(!ep_can_transition_membership('pending', 'withdrawn'), 'Membership pending cannot withdraw directly');
ep_assert(ep_can_transition_membership('active', 'suspended'), 'Membership active → suspended');
ep_assert(ep_can_transition_membership('active', 'withdrawn'), 'Membership active → withdrawn');

$trans = ep_opportunity_transitions();
ep_assert(in_array('submitted', $trans['draft'] ?? [], true), 'Opportunity draft → submitted');
ep_assert(in_array('published', $trans['approved'] ?? [], true), 'Opportunity approved → published');
ep_assert(!in_array('published', $trans['submitted'] ?? [], true), 'Technical submit cannot publish');
ep_assert(!in_array('published', $trans['reviewer_verified'] ?? [], true), 'Verification cannot publish');

$calc = ep_calculate_costs([
    'material_cost' => 100, 'labour_cost' => 50, 'transport_cost' => 10, 'utilities_cost' => 5,
    'packaging_cost' => 5, 'marketing_cost' => 10, 'other_cost' => 20, 'number_of_units' => 10, 'selling_price' => 30,
]);
ep_assert($calc['ok'], 'Cost calculator accepts valid input');
ep_assert((float)$calc['data']['total_cost'] === 200.0, 'Total cost = 200');
ep_assert((float)$calc['data']['cost_per_unit'] === 20.0, 'Cost/unit = 20');

$bad = ep_calculate_costs(['material_cost' => -1, 'number_of_units' => 0, 'selling_price' => 1]);
ep_assert(!$bad['ok'], 'Cost calculator rejects negatives / zero units');

$ready = ep_calculate_readiness([
    'product_score' => 80, 'market_score' => 70, 'costing_score' => 60,
    'capacity_score' => 50, 'compliance_score' => 40, 'team_score' => 90,
]);
ep_assert($ready['ok'], 'Readiness calculator ok');
ep_assert(isset($ready['data']['total_score']), 'Readiness total present');
ep_assert(isset($ready['data']['readiness_level']), 'Readiness level present');

$empErrors = ep_validate_opportunity_payload(['title' => 'X', 'short_description' => 'Y'], 'employment_profile');
ep_assert($empErrors !== [], 'Employment profile requires extra fields');
$empOk = ep_validate_opportunity_payload([
    'title' => 'Welder', 'short_description' => 'Seeking work',
    'preferred_employment_type' => 'Full-time', 'preferred_location' => 'Lusaka',
], 'employment_profile');
ep_assert($empOk === [], 'Employment profile validates when complete');

$prodErrors = ep_validate_opportunity_payload(['title' => 'Soap', 'short_description' => 'Bar'], 'product');
ep_assert($prodErrors !== [], 'Product requires pricing/capacity');

$tables = [
    'enterprise_memberships', 'enterprise_consents', 'enterprise_member_profiles', 'enterprise_skills',
    'enterprise_opportunities', 'enterprise_media', 'enterprise_opportunity_costs', 'enterprise_opportunity_readiness',
    'enterprise_opportunity_reviews', 'enterprise_opportunity_interests', 'enterprise_outcomes', 'enterprise_complaints',
    'enterprise_portal_settings',
];
foreach ($tables as $t) {
    $res = $db->query("SHOW TABLES LIKE '{$t}'");
    ep_assert($res && $res->num_rows > 0, "Table exists: {$t}");
}

$landing = wuc_portal_direct_landing_url('enterprise');
ep_assert($landing === '/wucportal/enterprise/index.php', 'Enterprise direct landing URL');

// AI disabled by default
ep_assert(ep_ai_enabled($db) === false || ep_setting_bool($db, 'ai_enabled', false) === ep_ai_enabled($db), 'AI setting readable');
$aiOff = ep_ai_assist($db, 'bio', ['title' => 'Test', 'notes' => 'Synthetic']);
if (!ep_ai_enabled($db)) {
    ep_assert($aiOff['ok'] === false, 'AI assist refuses when disabled');
}

// Fair feature helper exists
ep_assert(function_exists('ep_feature_opportunity_fairly'), 'Feature fairness helper present');
ep_assert(function_exists('ep_process_stale_opportunities'), 'Stale processor present');
ep_assert(function_exists('ep_list_stale_candidates'), 'Stale list helper present');

// Required routes exist
$routes = [
    'enterprise/join.php', 'enterprise/index.php', 'enterprise/membership_status.php',
    'enterprise/management/index.php', 'enterprise/reviewer/queue.php',
    'opportunities/index.php', 'opportunities/express_interest.php',
    'docs/enterprise_portal_implementation_audit.md', 'docs/enterprise_portal_acceptance_report.md',
];
foreach ($routes as $rel) {
    ep_assert(is_file(dirname(__DIR__) . '/' . $rel), "File exists: {$rel}");
}

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
