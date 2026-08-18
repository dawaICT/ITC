<?php
declare(strict_types=1);

$page_title = 'Reviewer Dashboard';
$activeNav = 'rev_dash';
$epGuardMode = 'reviewer';
$epNav = 'reviewer';
require_once dirname(__DIR__) . '/includes/guard.php';

$uid = ep_current_user_id();
$actor = ep_current_actor();

$qPending = $db->prepare("SELECT COUNT(*) c FROM enterprise_opportunities o
    JOIN enterprise_member_profiles p ON p.id = o.enterprise_profile_id
    WHERE o.status = 'submitted' AND (p.owner_user_id IS NULL OR p.owner_user_id <> ?)");
$qPending->bind_param('i', $uid);
$qPending->execute();
$pendingCount = (int)($qPending->get_result()->fetch_assoc()['c'] ?? 0);
$qPending->close();

$qConflict = $db->prepare("SELECT COUNT(*) c FROM enterprise_opportunities o
    JOIN enterprise_member_profiles p ON p.id = o.enterprise_profile_id
    WHERE o.status = 'submitted' AND p.owner_user_id = ?");
$qConflict->bind_param('i', $uid);
$qConflict->execute();
$conflictCount = (int)($qConflict->get_result()->fetch_assoc()['c'] ?? 0);
$qConflict->close();

$qHist = $db->prepare('SELECT COUNT(*) c FROM enterprise_opportunity_reviews WHERE reviewer_id = ?');
$qHist->bind_param('s', $actor);
$qHist->execute();
$reviewCount = (int)($qHist->get_result()->fetch_assoc()['c'] ?? 0);
$qHist->close();

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-stat-grid">
    <div class="ep-stat"><div class="label">Queue (eligible)</div><div class="value"><?= $pendingCount ?></div></div>
    <div class="ep-stat"><div class="label">Your conflicts</div><div class="value"><?= $conflictCount ?></div></div>
    <div class="ep-stat"><div class="label">Reviews recorded</div><div class="value"><?= $reviewCount ?></div></div>
</div>
<div class="ep-card">
    <h2 class="h5">Technical verification</h2>
    <p class="ep-muted mb-3">Review submitted opportunities for accuracy and completeness. You cannot verify your own listings.</p>
    <div class="d-flex flex-wrap gap-2">
        <a href="/wucportal/enterprise/reviewer/queue.php" class="btn btn-primary"><i class="fas fa-inbox me-1"></i>Open queue</a>
        <a href="/wucportal/enterprise/reviewer/history.php" class="btn btn-outline-secondary">Review history</a>
        <?php if ($conflictCount > 0): ?>
            <a href="/wucportal/enterprise/reviewer/conflicts.php" class="btn btn-outline-warning">View conflicts</a>
        <?php endif; ?>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
