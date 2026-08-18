<?php
declare(strict_types=1);

$page_title = 'Opportunity';
$activeNav = 'opportunities';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);
$id = (int)($_GET['id'] ?? 0);
$opp = ep_get_opportunity($db, $id);
ep_assert_own_opportunity($opp, $profileId);

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'submit') {
            $result = ep_transition_opportunity($db, $id, 'submitted', 'Submitted by participant', 'participant');
        } elseif ($action === 'archive') {
            $result = ep_transition_opportunity($db, $id, 'archived', 'Archived by participant', 'participant');
        } elseif ($action === 'confirm_availability') {
            $result = ep_confirm_opportunity_availability($db, $id);
        } else {
            $result = ['ok' => false, 'message' => 'Unknown action.'];
        }
        if ($result['ok']) {
            $_SESSION['flash_success'] = $result['message'];
            wuc_redirect('/wucportal/enterprise/opportunities/view.php?id=' . $id);
        }
        $errors[] = $result['message'];
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$reviews = ep_list_reviews_for_opportunity($db, $id);
$costs = ep_get_opportunity_costs($db, $id);
$ready = ep_get_readiness($db, $id);
require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <div class="d-flex justify-content-between flex-wrap gap-2">
        <div>
            <h2 class="h4 mb-1"><?= ep_h((string)$opp['title']) ?></h2>
            <span class="badge bg-<?= ep_h(ep_status_badge_class((string)$opp['status'])) ?>"><?= ep_h(ep_status_label((string)$opp['status'])) ?></span>
            <span class="small text-muted ms-2"><?= ep_h((string)$opp['public_code']) ?></span>
        </div>
        <div class="d-flex gap-2">
            <?php if (in_array((string)$opp['status'], ['draft', 'changes_requested', 'update_required'], true)): ?>
                <a class="btn btn-sm btn-outline-primary" href="/wucportal/enterprise/opportunities/edit.php?id=<?= $id ?>">Edit</a>
            <?php endif; ?>
            <?php if ((string)$opp['status'] === 'published'): ?>
                <a class="btn btn-sm btn-outline-success" target="_blank" href="<?= ep_h(ep_public_opportunity_url((string)$opp['public_code'])) ?>">Public page</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-secondary" href="/wucportal/enterprise/opportunities/preview.php?id=<?= $id ?>">Public preview</a>
            <?php endif; ?>
        </div>
    </div>
    <p class="mt-3"><?= nl2br(ep_h((string)$opp['short_description'])) ?></p>
    <p><?= nl2br(ep_h((string)($opp['full_description'] ?? ''))) ?></p>

    <form method="post" class="d-flex gap-2 flex-wrap">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <?php if (in_array((string)$opp['status'], ['draft', 'changes_requested', 'update_required'], true)): ?>
            <button class="btn btn-primary" name="action" value="submit" type="submit">Submit for review</button>
        <?php endif; ?>
        <?php if ((string)$opp['status'] === 'published'): ?>
            <button class="btn btn-outline-success" name="action" value="confirm_availability" type="submit">Confirm availability</button>
        <?php endif; ?>
        <?php if (in_array((string)$opp['status'], ['draft', 'rejected', 'unpublished'], true)): ?>
            <button class="btn btn-outline-dark" name="action" value="archive" type="submit">Archive</button>
        <?php endif; ?>
    </form>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="ep-card">
            <h3 class="h6">Costing</h3>
            <?php if ($costs): ?>
                <p class="mb-1">Cost/unit: <?= ep_h(ep_money($costs['cost_per_unit'])) ?></p>
                <p class="mb-1">Selling: <?= ep_h(ep_money($costs['selling_price'])) ?></p>
                <p class="small ep-muted mb-0"><?= ep_h(ep_disclaimer_finance()) ?></p>
            <?php else: ?>
                <p class="ep-muted">No costing yet. <a href="/wucportal/enterprise/tools/cost_calculator.php?opportunity_id=<?= $id ?>">Open calculator</a></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="ep-card">
            <h3 class="h6">Readiness</h3>
            <?php if ($ready): ?>
                <p class="mb-0"><?= ep_h((string)$ready['readiness_level']) ?> (<?= ep_h((string)$ready['total_score']) ?>)</p>
            <?php else: ?>
                <p class="ep-muted mb-0">No assessment yet. <a href="/wucportal/enterprise/tools/readiness_assessment.php?opportunity_id=<?= $id ?>">Assess readiness</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($reviews): ?>
<div class="ep-card">
    <h3 class="h6">Review feedback</h3>
    <?php foreach ($reviews as $r): ?>
        <div class="border-bottom py-2 small">
            <strong><?= ep_h(ep_status_label((string)$r['decision'])) ?></strong>
            <span class="text-muted">· <?= ep_h((string)$r['reviewed_at']) ?></span>
            <div><?= ep_h((string)($r['comments'] ?? '')) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
