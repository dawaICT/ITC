<?php
declare(strict_types=1);

$page_title = 'Review Opportunity';
$activeNav = 'rev_queue';
$epGuardMode = 'reviewer';
$epNav = 'reviewer';
require_once dirname(__DIR__) . '/includes/guard.php';

$id = (int)($_GET['id'] ?? $_POST['opportunity_id'] ?? 0);
$base = '/wucportal/enterprise/reviewer';

if ($id <= 0) {
    $_SESSION['flash_error'] = 'Invalid opportunity.';
    header('Location: ' . $base . '/queue.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . $base . '/review.php?id=' . $id);
        exit;
    }
    $decision = (string)($_POST['decision'] ?? '');
    $comments = trim((string)($_POST['comments'] ?? ''));
    if ($decision === 'reject' && $comments === '') {
        $_SESSION['flash_error'] = 'Comments are required when rejecting.';
        header('Location: ' . $base . '/review.php?id=' . $id);
        exit;
    }
    if ($decision === 'changes' && $comments === '') {
        $_SESSION['flash_error'] = 'Please describe the changes requested.';
        header('Location: ' . $base . '/review.php?id=' . $id);
        exit;
    }
    $map = ['verify' => 'verify', 'changes' => 'changes', 'reject' => 'reject'];
    if (!isset($map[$decision])) {
        $_SESSION['flash_error'] = 'Invalid decision.';
        header('Location: ' . $base . '/review.php?id=' . $id);
        exit;
    }
    $result = ep_review_opportunity($db, $id, $map[$decision], $comments);
    if (!empty($result['ok'])) {
        $_SESSION['flash_success'] = (string)($result['message'] ?? 'Review saved.');
        header('Location: ' . $base . '/queue.php');
        exit;
    }
    $_SESSION['flash_error'] = (string)($result['message'] ?? 'Review failed.');
    header('Location: ' . $base . '/review.php?id=' . $id);
    exit;
}

$opp = ep_get_opportunity($db, $id);
if (!$opp || (string)$opp['status'] !== 'submitted') {
    $_SESSION['flash_error'] = 'This opportunity is not in the review queue.';
    header('Location: ' . $base . '/queue.php');
    exit;
}

$ownConflict = (int)($opp['owner_user_id'] ?? 0) === ep_current_user_id() && ep_current_user_id() > 0;
$media = ep_list_media($db, $id);
$costs = ep_get_opportunity_costs($db, $id);
$readiness = ep_get_readiness($db, $id);
$types = ep_opportunity_types();

$revStmt = $db->prepare('SELECT * FROM enterprise_opportunity_reviews WHERE enterprise_opportunity_id = ? ORDER BY reviewed_at DESC LIMIT 20');
$revStmt->bind_param('i', $id);
$revStmt->execute();
$reviews = [];
$rres = $revStmt->get_result();
while ($r = $rres->fetch_assoc()) {
    $reviews[] = $r;
}
$revStmt->close();

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<?php if ($ownConflict): ?>
    <div class="alert alert-warning">You cannot review your own opportunity. It appears here for transparency — assign another reviewer.</div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="ep-card">
            <p class="mb-2"><code><?= ep_h((string)$opp['public_code']) ?></code>
                <span class="badge bg-<?= ep_h(ep_status_badge_class((string)$opp['status'])) ?>"><?= ep_h(ep_status_label((string)$opp['status'])) ?></span></p>
            <h2 class="h4"><?= ep_h((string)$opp['title']) ?></h2>
            <p class="ep-muted"><?= ep_h($types[(string)$opp['opportunity_type']] ?? (string)$opp['opportunity_type']) ?></p>
            <hr>
            <div class="mb-2"><strong>Enterprise</strong><br><?= ep_h((string)($opp['business_name'] ?? '')) ?></div>
            <div class="mb-2"><strong>Short description</strong><p class="mb-0"><?= nl2br(ep_h((string)($opp['short_description'] ?? ''))) ?></p></div>
            <div><strong>Full description</strong><p class="mb-0"><?= nl2br(ep_h((string)($opp['full_description'] ?? ''))) ?></p></div>
        </div>
        <?php if ($costs): ?>
            <div class="ep-card">
                <h3 class="h6">Cost summary</h3>
                <p class="small mb-0">Total: <?= ep_h(ep_money($costs['total_cost'] ?? 0, (string)($opp['currency'] ?? 'ZMW'))) ?></p>
                <p class="small ep-muted mb-0"><?= ep_h(ep_disclaimer_finance()) ?></p>
            </div>
        <?php endif; ?>
        <?php if ($readiness): ?>
            <div class="ep-card">
                <h3 class="h6">Readiness</h3>
                <p class="mb-0"><?= ep_h(number_format((float)($readiness['total_score'] ?? 0), 1)) ?> — <?= ep_h((string)($readiness['readiness_level'] ?? '')) ?></p>
            </div>
        <?php endif; ?>
        <div class="ep-card">
            <h3 class="h6">Review history</h3>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>When</th><th>Stage</th><th>Decision</th><th>Comments</th></tr></thead>
                    <tbody>
                    <?php if ($reviews === []): ?>
                        <tr><td colspan="4" class="ep-muted">No prior reviews.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reviews as $rev): ?>
                            <tr>
                                <td class="small"><?= ep_h((string)$rev['reviewed_at']) ?></td>
                                <td><?= ep_h((string)$rev['review_stage']) ?></td>
                                <td><?= ep_h(ep_status_label((string)$rev['resulting_status'])) ?></td>
                                <td class="small"><?= nl2br(ep_h((string)($rev['comments'] ?? ''))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="ep-card">
            <h3 class="h6">Technical decision</h3>
            <?php if ($ownConflict): ?>
                <p class="ep-muted mb-0">Actions disabled due to conflict of interest.</p>
            <?php elseif (!ep_can($db, 'enterprise.review.verify')): ?>
                <p class="ep-muted mb-0">You do not have verify permission.</p>
            <?php else: ?>
                <form method="post" action="<?= ep_h($base) ?>/review.php?id=<?= $id ?>">
                    <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                    <input type="hidden" name="opportunity_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label class="form-label" for="comments">Comments</label>
                        <textarea class="form-control" name="comments" id="comments" rows="4" maxlength="4000"></textarea>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" name="decision" value="verify" class="btn btn-success">Verify</button>
                        <button type="submit" name="decision" value="changes" class="btn btn-warning">Request changes</button>
                        <button type="submit" name="decision" value="reject" class="btn btn-outline-danger">Reject</button>
                    </div>
                </form>
            <?php endif; ?>
            <a href="<?= ep_h($base) ?>/queue.php" class="btn btn-link btn-sm mt-2 px-0">Back to queue</a>
        </div>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
