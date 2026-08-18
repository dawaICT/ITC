<?php
declare(strict_types=1);

$page_title = 'Settings';
$epGuardMode = 'member';
$activeNav = 'settings';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/participant_helpers.php';

$membershipId = (int)($epMembership['id'] ?? 0);
$consents = [];
if ($membershipId > 0) {
    $stmt = $db->prepare(
        'SELECT consent_type, consent_version, accepted_at FROM enterprise_consents
         WHERE membership_id = ? AND is_accepted = 1 ORDER BY accepted_at DESC'
    );
    $stmt->bind_param('i', $membershipId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $consents[] = $row;
    }
    $stmt->close();
}

$consentLabels = ep_consent_labels();
$goalLabels = ep_participation_goals();
$goals = json_decode((string)($epMembership['participation_goals_json'] ?? '[]'), true) ?: [];

require_once __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="ep-card">
            <h2 class="h6 mb-3">Membership</h2>
            <dl class="row small mb-0">
                <dt class="col-sm-4">Status</dt>
                <dd class="col-sm-8">
                    <span class="badge bg-<?= ep_h(ep_status_badge_class($epMembershipStatus)) ?>"><?= ep_h(ep_status_label($epMembershipStatus)) ?></span>
                </dd>
                <dt class="col-sm-4">Type</dt>
                <dd class="col-sm-8"><?= ep_h(ep_status_label((string)($epMembership['membership_type'] ?? 'student'))) ?></dd>
                <?php if ($goals !== []): ?>
                    <dt class="col-sm-4">Goals</dt>
                    <dd class="col-sm-8">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($goals as $g): ?>
                                <li><?= ep_h($goalLabels[$g] ?? $g) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </dd>
                <?php endif; ?>
            </dl>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-primary" href="/wucportal/enterprise/profile/edit.php">Edit profile &amp; contact</a>
                <a class="btn btn-sm btn-outline-secondary" href="/wucportal/enterprise/notifications.php">Notifications</a>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="ep-card">
            <h2 class="h6 mb-3">Consent declarations</h2>
            <?php if ($consents === []): ?>
                <p class="small ep-muted mb-0">No consent records on file.</p>
            <?php else: ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($consents as $c): ?>
                        <li class="mb-2">
                            <i class="fas fa-check text-success me-1"></i>
                            <?= ep_h($consentLabels[(string)$c['consent_type']] ?? (string)$c['consent_type']) ?>
                            <div class="text-muted">v<?= ep_h((string)$c['consent_version']) ?> · <?= ep_h(substr((string)$c['accepted_at'], 0, 10)) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-12">
        <div class="ep-card border-danger">
            <h2 class="h6 text-danger">Withdraw participation</h2>
            <p class="small ep-muted mb-2">Voluntary withdrawal unpublishes your listings and revokes enterprise portal access until you opt in again.</p>
            <a class="btn btn-outline-danger btn-sm" href="/wucportal/enterprise/withdraw.php">Withdraw from portal</a>
        </div>
    </div>
</div>
<p class="small ep-muted mt-3">Listings reflect institutional verification of submitted information. Verification does not guarantee employment, sales, funding, or investment returns.</p>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
