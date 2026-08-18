<?php
declare(strict_types=1);

$page_title = 'Management Dashboard';
$activeNav = 'mgmt_dash';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

$stats = [
    'pending_members' => 0,
    'active_members' => 0,
    'suspended_members' => 0,
    'submitted' => 0,
    'reviewer_verified' => 0,
    'published' => 0,
    'products' => 0,
    'services' => 0,
    'innovations' => 0,
    'employment' => 0,
    'new_leads' => 0,
    'unassigned_leads' => 0,
    'overdue_leads' => 0,
    'converted_leads' => 0,
    'outcomes' => 0,
    'jobs_confirmed' => 0,
    'funding_received' => 0,
    'investment_needed' => 0.0,
    'stale' => 0,
];

$map = [
    'pending_members' => "SELECT COUNT(*) c FROM enterprise_memberships WHERE status = 'pending'",
    'active_members' => "SELECT COUNT(*) c FROM enterprise_memberships WHERE status = 'active'",
    'suspended_members' => "SELECT COUNT(*) c FROM enterprise_memberships WHERE status = 'suspended'",
    'submitted' => "SELECT COUNT(*) c FROM enterprise_opportunities WHERE status = 'submitted'",
    'reviewer_verified' => "SELECT COUNT(*) c FROM enterprise_opportunities WHERE status = 'reviewer_verified'",
    'published' => "SELECT COUNT(*) c FROM enterprise_opportunities WHERE status = 'published'",
    'products' => "SELECT COUNT(*) c FROM enterprise_opportunities WHERE opportunity_type = 'product' AND status <> 'archived'",
    'services' => "SELECT COUNT(*) c FROM enterprise_opportunities WHERE opportunity_type = 'service' AND status <> 'archived'",
    'innovations' => "SELECT COUNT(*) c FROM enterprise_opportunities WHERE opportunity_type = 'innovation' AND status <> 'archived'",
    'employment' => "SELECT COUNT(*) c FROM enterprise_opportunities WHERE opportunity_type = 'employment_profile' AND status <> 'archived'",
    'new_leads' => "SELECT COUNT(*) c FROM enterprise_opportunity_interests WHERE lead_status = 'new'",
    'unassigned_leads' => "SELECT COUNT(*) c FROM enterprise_opportunity_interests WHERE lead_status IN ('new','acknowledged') AND (assigned_to IS NULL OR assigned_to = '')",
    'converted_leads' => "SELECT COUNT(*) c FROM enterprise_opportunity_interests WHERE lead_status = 'converted'",
    'outcomes' => 'SELECT COUNT(*) c FROM enterprise_outcomes',
    'jobs_confirmed' => "SELECT COALESCE(SUM(jobs_created),0) c FROM enterprise_outcomes WHERE outcome_type IN ('employment_started') AND verification_status IN ('verified','unverified')",
    'funding_received' => "SELECT COUNT(*) c FROM enterprise_outcomes WHERE outcome_type = 'funding_received'",
];
foreach ($map as $k => $sql) {
    $r = $db->query($sql);
    if ($r) {
        $stats[$k] = (float)($r->fetch_assoc()['c'] ?? 0);
    }
}
$r = $db->query("SELECT COALESCE(SUM(investment_required),0) c FROM enterprise_opportunities WHERE status IN ('published','approved','reviewer_verified') AND investment_required IS NOT NULL");
if ($r) {
    $stats['investment_needed'] = (float)($r->fetch_assoc()['c'] ?? 0);
}
$stats['stale'] = count(ep_list_stale_candidates($db, 500));
$ovd = (int)(ep_setting($db, 'lead_overdue_days', '6') ?? 6);
$r = $db->query("SELECT COUNT(*) c FROM enterprise_opportunity_interests WHERE lead_status NOT IN ('converted','closed_unsuccessful','invalid','spam') AND DATEDIFF(NOW(), created_at) >= {$ovd}");
if ($r) {
    $stats['overdue_leads'] = (int)($r->fetch_assoc()['c'] ?? 0);
}

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-stat-grid">
    <div class="ep-stat"><div class="label">Pending memberships</div><div class="value"><?= (int)$stats['pending_members'] ?></div></div>
    <div class="ep-stat"><div class="label">Active participants</div><div class="value"><?= (int)$stats['active_members'] ?></div></div>
    <div class="ep-stat"><div class="label">Suspended</div><div class="value"><?= (int)$stats['suspended_members'] ?></div></div>
    <div class="ep-stat"><div class="label">Submitted</div><div class="value"><?= (int)$stats['submitted'] ?></div></div>
    <div class="ep-stat"><div class="label">Awaiting approval</div><div class="value"><?= (int)$stats['reviewer_verified'] ?></div></div>
    <div class="ep-stat"><div class="label">Published</div><div class="value"><?= (int)$stats['published'] ?></div></div>
    <div class="ep-stat"><div class="label">Products</div><div class="value"><?= (int)$stats['products'] ?></div></div>
    <div class="ep-stat"><div class="label">Services</div><div class="value"><?= (int)$stats['services'] ?></div></div>
    <div class="ep-stat"><div class="label">Innovations</div><div class="value"><?= (int)$stats['innovations'] ?></div></div>
    <div class="ep-stat"><div class="label">Employment profiles</div><div class="value"><?= (int)$stats['employment'] ?></div></div>
    <div class="ep-stat"><div class="label">New leads</div><div class="value"><?= (int)$stats['new_leads'] ?></div></div>
    <div class="ep-stat"><div class="label">Unassigned leads</div><div class="value"><?= (int)$stats['unassigned_leads'] ?></div></div>
    <div class="ep-stat"><div class="label">Overdue leads</div><div class="value"><?= (int)$stats['overdue_leads'] ?></div></div>
    <div class="ep-stat"><div class="label">Converted leads</div><div class="value"><?= (int)$stats['converted_leads'] ?></div></div>
    <div class="ep-stat"><div class="label">Outcomes</div><div class="value"><?= (int)$stats['outcomes'] ?></div></div>
    <div class="ep-stat"><div class="label">Confirmed jobs (outcomes)</div><div class="value"><?= (int)$stats['jobs_confirmed'] ?></div></div>
    <div class="ep-stat"><div class="label">Funding received</div><div class="value"><?= (int)$stats['funding_received'] ?></div></div>
    <div class="ep-stat"><div class="label">Investment needs (listed)</div><div class="value" style="font-size:1rem"><?= ep_h(ep_money($stats['investment_needed'])) ?></div></div>
    <div class="ep-stat"><div class="label">Stale published</div><div class="value"><?= (int)$stats['stale'] ?></div></div>
</div>
<div class="ep-card">
    <p class="small ep-muted mb-3">Figures are database-backed. Leads are not counted as outcomes. Investment needs are listings, not funds received.</p>
    <div class="d-flex flex-wrap gap-2">
        <a href="/wucportal/enterprise/management/memberships.php" class="btn btn-outline-primary">Memberships</a>
        <a href="/wucportal/enterprise/management/approvals.php" class="btn btn-primary">Approvals</a>
        <a href="/wucportal/enterprise/management/interests.php" class="btn btn-outline-primary">Interests</a>
        <a href="/wucportal/enterprise/management/stale.php" class="btn btn-outline-warning">Stale records</a>
        <a href="/wucportal/enterprise/management/reports.php" class="btn btn-outline-secondary">Reports</a>
        <a href="/wucportal/opportunities/index.php" class="btn btn-outline-success" target="_blank" rel="noopener">Public directory</a>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
