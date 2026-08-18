<?php
declare(strict_types=1);

$page_title = 'Membership status';
$epGuardMode = 'status';
require_once __DIR__ . '/includes/guard.php';

$status = $epMembershipStatus;
$goals = $epMembership
    ? (json_decode((string)($epMembership['participation_goals_json'] ?? '[]'), true) ?: [])
    : [];
$goalLabels = ep_participation_goals();

$activeNav = '';
require_once __DIR__ . '/includes/layout.php';
?>
<div class="ep-card">
    <h2 class="h4 mb-3">Your membership</h2>
    <p class="mb-3">
        Status:
        <span class="badge bg-<?= ep_h(ep_status_badge_class($status)) ?>"><?= ep_h(ep_status_label($status)) ?></span>
    </p>

    <?php if ($status === 'not_enrolled'): ?>
        <p class="ep-muted">You have not joined the Skills and Enterprise Portal yet.</p>
        <a class="btn btn-primary" href="/wucportal/enterprise/join.php">Join the portal</a>
    <?php elseif ($status === 'pending'): ?>
        <p class="ep-muted">Your opt-in request is awaiting institutional review. You will be notified when a decision is made.</p>
        <?php if ($goals !== []): ?>
            <h3 class="h6 mt-3">Participation goals</h3>
            <ul class="small">
                <?php foreach ($goals as $g): ?>
                    <li><?= ep_h($goalLabels[$g] ?? $g) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php elseif ($status === 'changes_requested'): ?>
        <div class="alert alert-warning">
            <?= ep_h(trim((string)($epMembership['eligibility_notes'] ?? 'Please update your membership information and resubmit.'))) ?>
        </div>
        <a class="btn btn-primary" href="/wucportal/enterprise/join.php">Update and resubmit</a>
    <?php elseif ($status === 'active'): ?>
        <p class="ep-muted">Your membership is active.</p>
        <a class="btn btn-primary" href="/wucportal/enterprise/index.php">Open dashboard</a>
        <a class="btn btn-outline-secondary ms-2" href="/wucportal/enterprise/onboarding.php">Continue setup</a>
    <?php elseif ($status === 'declined'): ?>
        <div class="alert alert-danger">
            <?= ep_h(trim((string)($epMembership['decline_reason'] ?? 'Your request was declined.'))) ?>
        </div>
        <a class="btn btn-outline-primary" href="/wucportal/enterprise/join.php">Submit a new request</a>
    <?php elseif ($status === 'suspended'): ?>
        <div class="alert alert-danger">
            <?= ep_h(trim((string)($epMembership['suspension_reason'] ?? 'Your access is suspended.'))) ?>
        </div>
    <?php elseif ($status === 'withdrawn'): ?>
        <p class="ep-muted">You withdrew from the portal. You may opt in again if eligible.</p>
        <a class="btn btn-outline-primary" href="/wucportal/enterprise/join.php">Rejoin</a>
    <?php endif; ?>

    <div class="mt-4">
        <a class="btn btn-link btn-sm" href="/wucportal/portal_selection.php">Back to portal selection</a>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
