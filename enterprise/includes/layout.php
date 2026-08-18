<?php
declare(strict_types=1);

/** @var string $page_title */
/** @var string $epNav */
/** @var string $epGuardMode */
/** @var mysqli $db */
$epNav = $epNav ?? 'participant';
$epGuardMode = $epGuardMode ?? 'member';
$page_title = $page_title ?? ep_platform_product_name();
$displayName = (string)($_SESSION['user_name'] ?? $_SESSION['Sid'] ?? 'User');
$epLogoutTarget = !empty($_SESSION['Sid']) ? 'student' : 'staff';
$epUserRoleLabel = $epLogoutTarget === 'student' ? 'Student' : 'Staff';

$participantNav = [
    ['section' => 'Workspace'],
    ['Dashboard', '/wucportal/enterprise/index.php', 'fa-tachometer-alt', 'dashboard'],
    ['Professional Profile', '/wucportal/enterprise/profile/index.php', 'fa-id-card', 'profile'],
    ['Skills', '/wucportal/enterprise/skills/index.php', 'fa-screwdriver-wrench', 'skills'],
    ['Opportunities', '/wucportal/enterprise/opportunities/index.php', 'fa-briefcase', 'opportunities'],
    ['section' => 'Discover'],
    ['Public Directory', '/wucportal/opportunities/index.php', 'fa-globe', 'public_dir', true],
    ['Skill Discovery', '/wucportal/enterprise/tools/skill_discovery.php', 'fa-wand-magic-sparkles', 'skill_discovery'],
    ['section' => 'Tools'],
    ['Cost Calculator', '/wucportal/enterprise/tools/cost_calculator.php', 'fa-calculator', 'tools'],
    ['Readiness', '/wucportal/enterprise/tools/readiness_assessment.php', 'fa-chart-line', 'readiness'],
    ['AI Writing Assist', '/wucportal/enterprise/tools/ai_assist.php', 'fa-wand-magic-sparkles', 'ai'],
    ['section' => 'Engagement'],
    ['Received Interest', '/wucportal/enterprise/interests/index.php', 'fa-handshake', 'interests'],
    ['Outcomes', '/wucportal/enterprise/outcomes/index.php', 'fa-flag-checkered', 'outcomes'],
    ['section' => 'Account'],
    ['Settings', '/wucportal/enterprise/settings.php', 'fa-gear', 'settings'],
    ['Withdraw', '/wucportal/enterprise/withdraw.php', 'fa-right-from-bracket', 'withdraw'],
];

$joinStatusNav = [
    ['section' => 'Membership'],
    ['Join portal', '/wucportal/enterprise/join.php', 'fa-user-plus', 'join'],
    ['Membership status', '/wucportal/enterprise/membership_status.php', 'fa-id-badge', 'status'],
    ['Public Directory', '/wucportal/opportunities/index.php', 'fa-globe', 'public', true],
];

$reviewerNav = [
    ['section' => 'Technical review'],
    ['Dashboard', '/wucportal/enterprise/reviewer/index.php', 'fa-tachometer-alt', 'rev_dash'],
    ['Review Queue', '/wucportal/enterprise/reviewer/queue.php', 'fa-inbox', 'rev_queue'],
    ['History', '/wucportal/enterprise/reviewer/history.php', 'fa-clock-rotate-left', 'rev_hist'],
    ['Conflicts', '/wucportal/enterprise/reviewer/conflicts.php', 'fa-triangle-exclamation', 'rev_conf'],
];

$managementNav = [
    ['section' => 'Oversight'],
    ['Dashboard', '/wucportal/enterprise/management/index.php', 'fa-tachometer-alt', 'mgmt_dash'],
    ['Memberships', '/wucportal/enterprise/management/memberships.php', 'fa-users', 'mgmt_mem'],
    ['Approvals', '/wucportal/enterprise/management/approvals.php', 'fa-clipboard-check', 'mgmt_app'],
    ['Published', '/wucportal/enterprise/management/published.php', 'fa-globe', 'mgmt_pub'],
    ['Stale Records', '/wucportal/enterprise/management/stale.php', 'fa-clock', 'mgmt_stale'],
    ['section' => 'Leads & outcomes'],
    ['Interests', '/wucportal/enterprise/management/interests.php', 'fa-handshake', 'mgmt_int'],
    ['Outcomes', '/wucportal/enterprise/management/outcomes.php', 'fa-flag-checkered', 'mgmt_out'],
    ['Reports', '/wucportal/enterprise/management/reports.php', 'fa-chart-pie', 'mgmt_rep'],
    ['section' => 'Configuration'],
    ['Categories', '/wucportal/enterprise/management/categories.php', 'fa-tags', 'mgmt_cat'],
    ['Settings', '/wucportal/enterprise/management/settings.php', 'fa-sliders', 'mgmt_set'],
    ['Audit log', '/wucportal/enterprise/management/audit.php', 'fa-clipboard-list', 'mgmt_audit'],
    ['Organizations', '/wucportal/enterprise/management/organizations.php', 'fa-building', 'mgmt_orgs'],
];

$agricultureNav = [
    ['section' => 'Agriculture & Market Access'],
    ['Dashboard', '/wucportal/enterprise/agriculture/index.php', 'fa-seedling', 'agri_dash'],
    ['Farmers', '/wucportal/enterprise/agriculture/farmers.php', 'fa-user-group', 'agri_farmers'],
    ['Produce listings', '/wucportal/enterprise/agriculture/produce.php', 'fa-basket-shopping', 'agri_produce'],
    ['Buyer demands', '/wucportal/enterprise/agriculture/demands.php', 'fa-building', 'agri_demands'],
    ['Matching', '/wucportal/enterprise/agriculture/matches.php', 'fa-link', 'agri_matches'],
    ['section' => 'Prices & channels'],
    ['Commodity prices', '/wucportal/enterprise/agriculture/prices.php', 'fa-tags', 'agri_prices'],
    ['USSD simulator', '/wucportal/enterprise/agriculture/ussd_simulator.php', 'fa-mobile-screen', 'agri_ussd'],
    ['Reports', '/wucportal/enterprise/agriculture/reports.php', 'fa-chart-column', 'agri_reports'],
];

$navItems = $participantNav;
if (in_array($epGuardMode, ['join', 'status'], true)) {
    $navItems = $joinStatusNav;
    if (($activeNav ?? '') === '') {
        $activeNav = $epGuardMode === 'join' ? 'join' : 'status';
    }
} elseif ($epNav === 'reviewer') {
    $navItems = $reviewerNav;
} elseif ($epNav === 'management') {
    $navItems = $managementNav;
} elseif ($epNav === 'agriculture') {
    $navItems = $agricultureNav;
}
$activeKey = $activeNav ?? '';

$sidebarSubtitle = match ($epNav) {
    'reviewer' => 'Enterprise · Reviewer',
    'management' => 'Enterprise · Management',
    'agriculture' => 'Enterprise · Agriculture',
    default => in_array($epGuardMode, ['join', 'status'], true) ? 'Enterprise · Membership' : 'Enterprise · Participant',
};

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$layoutCsrf = function_exists('wuc_csrf_token') ? wuc_csrf_token() : (string)$_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ep_h($page_title) ?> | <?= ep_h(ep_platform_product_name()) ?></title>
    <?php require_once dirname(__DIR__, 2) . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link href="/wucportal/assets/vendor/bootstrap/5.3.2/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/ui-portal.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/css/project-reusable.css">
    <link rel="stylesheet" href="/wucportal/assets/css/main.css">
    <link rel="stylesheet" href="/wucportal/enterprise/css/enterprise-sidebar.css?v=20260721d">
    <link rel="stylesheet" href="/wucportal/css/typography-override.css">
    <link rel="stylesheet" href="/wucportal/css/consistent-styles.css">
    <link rel="stylesheet" href="/wucportal/assets/vendor/fontawesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/wucportal/css/wuc-premium.css?v=20260613">
    <link rel="stylesheet" href="/wucportal/students/css/dashboard.css?v=20260712-dashboard-responsive-v1">
    <link rel="stylesheet" href="/wucportal/enterprise/css/enterprise-shell.css?v=20260721e">
    <link rel="stylesheet" href="/wucportal/css/enterprise-portal.css?v=20260721d">
    <script src="/wucportal/js/sidebar-collapsible.js?v=20260708" defer></script>
</head>
<body class="bg-light enterprise-portal has-unified-sidebar">

<button type="button" class="sidebar-toggle" aria-label="Open sidebar" aria-controls="enterpriseSidebar" aria-expanded="false">
    <i class="fas fa-bars"></i>
</button>
<div class="sidebar-backdrop" data-enterprise-sidebar-backdrop></div>

<nav class="sidebar" id="enterpriseSidebar" aria-label="Skills and Enterprise navigation">
    <div class="sidebar-header">
        <div class="logo-container">
            <img src="/wucportal/images/favicon.png" alt="ITC Logo" class="logo">
            <span class="logo-text">ITC</span>
        </div>
        <p class="small text-center mb-0 mt-2" style="color: rgba(255,255,255,0.72);"><?= ep_h($sidebarSubtitle) ?></p>
    </div>
    <div class="sidebar-content">
        <?php
        $epSectionOpen = false;
        foreach ($navItems as $item):
            if (isset($item['section'])):
                if ($epSectionOpen):
                    echo '</div>';
                endif;
                $epSectionOpen = true;
                echo '<div class="nav-section"><div class="nav-section-title">' . ep_h((string)$item['section']) . '</div>';
                continue;
            endif;
            [$label, $href, $icon, $key] = $item;
            $external = !empty($item[4]);
            echo '<a href="' . ep_h($href) . '" class="nav-item' . ($activeKey === $key ? ' active' : '') . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>';
            echo '<i class="fas ' . ep_h($icon) . '"></i><span>' . ep_h($label) . '</span></a>';
        endforeach;
        if ($epSectionOpen):
            echo '</div>';
        endif;
        ?>

        <div class="nav-section">
            <div class="nav-section-title">Portal</div>
            <a href="/wucportal/portal_selection.php" class="nav-item"><i class="fas fa-table-columns"></i><span>Switch Portal</span></a>
            <a href="/wucportal/opportunities/index.php" class="nav-item" target="_blank" rel="noopener"><i class="fas fa-globe"></i><span>Public Directory</span></a>
            <?php if (!in_array($epGuardMode, ['join', 'status'], true)): ?>
                <?php if (ep_can($db, 'enterprise.review.access')): ?>
                    <a href="/wucportal/enterprise/reviewer/index.php" class="nav-item"><i class="fas fa-clipboard-check"></i><span>Reviewer workspace</span></a>
                <?php endif; ?>
                <?php if (ep_can($db, 'enterprise.memberships.manage') || ep_can($db, 'enterprise.approve')): ?>
                    <a href="/wucportal/enterprise/management/index.php" class="nav-item"><i class="fas fa-user-shield"></i><span>Management console</span></a>
                <?php endif; ?>
                <?php if (ep_staff_can($db, 'agriculture.reports.view') || ep_staff_can($db, 'agriculture.farmers.create')): ?>
                    <a href="/wucportal/enterprise/agriculture/index.php" class="nav-item"><i class="fas fa-seedling"></i><span>Agriculture &amp; Market Access</span></a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="sidebar-footer">
        <?php
        $epOrgs = $epWorkspaceOrganizations ?? [];
        $epCurOrg = $epCurrentOrganization ?? null;
        $epMems = $epUserMemberships ?? [];
        ?>
        <?php if (count($epOrgs) > 1 || ep_can_manage_organizations($db)): ?>
            <form method="post" action="/wucportal/enterprise/switch_workspace.php" class="px-2 pb-2">
                <input type="hidden" name="csrf_token" value="<?= ep_h($layoutCsrf) ?>">
                <input type="hidden" name="return_url" value="<?= ep_h($_SERVER['REQUEST_URI'] ?? '/wucportal/enterprise/index.php') ?>">
                <label class="form-label text-white-50 small mb-1">Organization</label>
                <select name="organization_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($epOrgs as $o): ?>
                        <option value="<?= (int)$o['id'] ?>"<?= ($epCurOrg && (int)$o['id'] === (int)$epCurOrg['id']) ? ' selected' : '' ?>>
                            <?= ep_h((string)$o['org_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php elseif ($epCurOrg): ?>
            <div class="px-3 pb-2 small text-white-50">Org: <?= ep_h((string)$epCurOrg['org_name']) ?></div>
        <?php endif; ?>
        <?php if (count($epMems) > 1): ?>
            <form method="post" action="/wucportal/enterprise/switch_workspace.php" class="px-2 pb-2">
                <input type="hidden" name="csrf_token" value="<?= ep_h($layoutCsrf) ?>">
                <input type="hidden" name="return_url" value="<?= ep_h($_SERVER['REQUEST_URI'] ?? '/wucportal/enterprise/index.php') ?>">
                <label class="form-label text-white-50 small mb-1">Participation</label>
                <select name="membership_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($epMems as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"<?= ($epMembership && (int)$m['id'] === (int)$epMembership['id']) ? ' selected' : '' ?>>
                            <?= ep_h(str_replace('_', ' ', (string)$m['membership_type'])) ?> (<?= ep_h((string)$m['status']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <div class="user-info">
            <div class="user-avatar"><?= ep_h(strtoupper(substr($displayName, 0, 1))) ?></div>
            <div class="user-details">
                <div class="user-name"><?= ep_h($displayName) ?></div>
                <div class="user-role"><?= ep_h($epUserRoleLabel) ?></div>
            </div>
        </div>
        <div class="quick-actions">
            <a href="/wucportal/portal_selection.php" class="quick-action-btn"><i class="fas fa-table-columns"></i> Portals</a>
            <form method="POST" action="/wucportal/logout.php" class="quick-action-inline-form">
                <input type="hidden" name="target" value="<?= ep_h($epLogoutTarget) ?>">
                <input type="hidden" name="csrf_token" value="<?= ep_h($layoutCsrf) ?>">
                <button type="submit" class="quick-action-btn quick-action-submit-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </form>
        </div>
    </div>
</nav>

<main class="dash-content content-wrapper portal-dashboard pt-3">
    <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success"><?= ep_h((string)$flashSuccess) ?></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
        <div class="alert alert-danger"><?= ep_h((string)$flashError) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors) && is_array($errors)): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= ep_h((string)$e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="dashboard-header student-section mb-3">
        <div class="row align-items-center g-2">
            <div class="col">
                <h1 class="dashboard-title mb-0"><?= ep_h($page_title) ?></h1>
            </div>
            <div class="col-auto header-actions d-flex flex-wrap gap-2 justify-content-end">
                <a class="btn btn-sm btn-outline-primary" href="/wucportal/opportunities/index.php" target="_blank" rel="noopener"><i class="fas fa-globe me-1"></i>Public Directory</a>
            </div>
        </div>
    </div>
