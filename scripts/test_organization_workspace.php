<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_portal/bootstrap.php';

$pass = 0;
$fail = 0;
function ow_assert(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? '[PASS] ' : '[FAIL] ') . $m . "\n";
    $c ? $pass++ : $fail++;
}

ow_assert(function_exists('ep_create_organization'), 'Organization create helper');
ow_assert(function_exists('ep_list_accessible_organizations'), 'Accessible org list');
ow_assert(function_exists('ep_get_memberships_for_user'), 'Multi-membership list');
ow_assert(function_exists('ep_resolve_membership_context'), 'Membership context resolver');
ow_assert(ep_platform_product_name() !== '', 'Platform product name');

if (ep_organizations_table_ready($db)) {
    $created = ep_create_organization($db, [
        'org_name' => 'Test Cooperative ' . bin2hex(random_bytes(2)),
        'org_type' => 'cooperative',
        'province' => 'North-Western',
    ]);
    ow_assert(!empty($created['ok']), 'Create test cooperative org');
    ow_assert(function_exists('ep_scope_organization_id'), 'Org scope helper');
}

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
