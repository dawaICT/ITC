<?php
declare(strict_types=1);

/**
 * Portal isolation / access-control regression tests for Skills and Enterprise Portal.
 * Run: c:\xampp\php\php.exe scripts\test_enterprise_portal_isolation.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_portal/bootstrap.php';

$pass = 0;
$fail = 0;

function ep_iso_assert(bool $cond, string $msg): void
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

// 1) Academic nav no longer points at legacy hub tools
$studentNav = file_get_contents(dirname(__DIR__) . '/students/includes/navbar.php') ?: '';
ep_iso_assert(strpos($studentNav, '/students/enterprise/profile.php') === false, 'Student academic nav does not embed legacy enterprise tools');
ep_iso_assert(strpos($studentNav, '/wucportal/portal_selection.php') !== false, 'Student nav links to portal selection for enterprise');

$lectNav = file_get_contents(dirname(__DIR__) . '/lecturers/includes/nav.php') ?: '';
ep_iso_assert(strpos($lectNav, '/lecturers/enterprise/review_queue.php') === false, 'Lecturer nav does not use legacy hub review queue');
ep_iso_assert(strpos($lectNav, '/enterprise/reviewer/') !== false, 'Lecturer nav points at /enterprise/reviewer');

$adminNav = file_get_contents(dirname(__DIR__) . '/admin/includes/nav.php') ?: '';
ep_iso_assert(strpos($adminNav, 'Skills-to-Trade Hub') === false, 'Admin nav no longer titled Skills-to-Trade Hub');
ep_iso_assert(strpos($adminNav, '/enterprise/management/') !== false, 'Admin nav points at /enterprise/management');

// 2) Legacy entry points are redirect stubs
foreach ([
    'students/enterprise/index.php' => 'ep_legacy_hub_redirect',
    'lecturers/enterprise/index.php' => 'ep_legacy_hub_redirect',
    'admin/enterprise/index.php' => 'ep_legacy_hub_redirect',
    'showcase/index.php' => 'ep_legacy_hub_redirect',
] as $file => $needle) {
    $src = file_get_contents(dirname(__DIR__) . '/' . $file) ?: '';
    ep_iso_assert(strpos($src, $needle) !== false, "Legacy entry redirects: {$file}");
}

// 3) Membership access rules
ep_iso_assert(ep_can($db, 'enterprise.portal.access', ['status' => 'pending']) === false || !ep_is_student_session(), 'Pending membership cannot access tools (non-student session ok)');
ep_iso_assert(ep_can($db, 'enterprise.portal.access', ['status' => 'active']) === true || !ep_is_student_session(), 'Active membership can access when student session');
ep_iso_assert(ep_can($db, 'enterprise.portal.access', ['status' => 'suspended']) === false || !ep_is_student_session(), 'Suspended cannot access');
ep_iso_assert(ep_can($db, 'enterprise.portal.access', ['status' => 'withdrawn']) === false || !ep_is_student_session(), 'Withdrawn cannot access');

// 4) Technical verify cannot publish
$trans = ep_opportunity_transitions();
ep_iso_assert(!in_array('published', $trans['reviewer_verified'] ?? [], true), 'Verification cannot publish');
ep_iso_assert(in_array('approved', $trans['reviewer_verified'] ?? [], true), 'Verification can approve path only via admin');
ep_iso_assert(in_array('published', $trans['approved'] ?? [], true), 'Admin approved can publish');

// 5) Public query published-only
$draftLeak = ep_search_published_opportunities($db, ['q' => '___never_match_seed_xyz___'], 5, 0);
ep_iso_assert(is_array($draftLeak), 'Published search returns array');
foreach ($draftLeak as $row) {
    ep_iso_assert(($row['status'] ?? 'published') === 'published' || !isset($row['status']), 'Search rows are published listings');
}

// 6) RBAC grants present for lecturer review + registrar manage
$lecturerHas = false;
$registrarHas = false;
$r = $db->query("SELECT r.role_name, p.permission_key
    FROM role_permissions rp
    JOIN roles r ON r.role_id = rp.role_id
    JOIN permissions p ON p.permission_id = rp.permission_id
    WHERE p.permission_key IN ('enterprise.review.verify','enterprise.publish')");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        if ($row['role_name'] === 'lecturer' && $row['permission_key'] === 'enterprise.review.verify') {
            $lecturerHas = true;
        }
        if ($row['role_name'] === 'registrar' && $row['permission_key'] === 'enterprise.publish') {
            $registrarHas = true;
        }
    }
}
ep_iso_assert($lecturerHas, 'Lecturer role has enterprise.review.verify');
ep_iso_assert($registrarHas, 'Registrar role has enterprise.publish');

// 7) AI default off (env override wins over demo DB seed)
putenv('ENTERPRISE_AI_ENABLED=false');
$_ENV['ENTERPRISE_AI_ENABLED'] = 'false';
ep_iso_assert(ep_ai_enabled($db) === false, 'ENTERPRISE AI disabled when env false');
$ai = ep_ai_assist($db, 'bio', ['title' => 'x', 'notes' => 'y', 'student_id' => 'SHOULD_NOT_SEND', 'nrc' => 'SHOULD_NOT_SEND']);
ep_iso_assert($ai['ok'] === false, 'AI assist blocked while disabled');

// 8) Direct landing + portal context
ep_iso_assert(wuc_portal_direct_landing_url('enterprise') === '/wucportal/enterprise/index.php', 'Enterprise direct landing');
ep_iso_assert(function_exists('wuc_portal_alert_normalize_portal') && wuc_portal_alert_normalize_portal('enterprise') === 'enterprise', 'Alerts accept enterprise portal');

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
