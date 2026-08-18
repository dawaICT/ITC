<?php
declare(strict_types=1);

$page_title = 'Stale Records';
$activeNav = 'mgmt_stale';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.publish') && !ep_can($db, 'enterprise.approve')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    wuc_redirect('/wucportal/enterprise/management/index.php');
}

$errors = [];
$resultMsg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        if (!empty($_POST['run_stale'])) {
            $r = ep_process_stale_opportunities($db);
            $resultMsg = 'Processed: ' . (int)$r['marked_update'] . ' marked update_required, ' . (int)$r['unpublished'] . ' unpublished.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$candidates = ep_list_stale_candidates($db, 100);
$settings = ep_stale_settings($db);
require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <p class="small ep-muted">Published opportunities must be confirmed periodically. Thresholds: confirm <?= (int)$settings['confirm_days'] ?> days · update_required <?= (int)$settings['update_days'] ?> days · unpublish <?= (int)$settings['unpublish_days'] ?> days.</p>
    <form method="post" class="mb-3">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <button class="btn btn-warning" name="run_stale" value="1" type="submit">Run stale-record check now</button>
    </form>
    <?php if ($resultMsg): ?><div class="alert alert-success"><?= ep_h($resultMsg) ?></div><?php endif; ?>
    <?php if ($candidates === []): ?>
        <p class="ep-muted mb-0">No stale published opportunities found.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Code</th><th>Title</th><th>Days since confirm</th><th>Next review</th></tr></thead>
                <tbody>
                <?php foreach ($candidates as $c): ?>
                    <tr>
                        <td><?= ep_h((string)$c['public_code']) ?></td>
                        <td><?= ep_h((string)$c['title']) ?></td>
                        <td><?= (int)$c['days_since_confirm'] ?></td>
                        <td><?= ep_h((string)($c['next_review_at'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
