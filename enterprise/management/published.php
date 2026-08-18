<?php
declare(strict_types=1);

$page_title = 'Published Opportunities';
$activeNav = 'mgmt_pub';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.publish') && !ep_can($db, 'enterprise.unpublish')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    header('Location: /wucportal/enterprise/management/index.php');
    exit;
}

$base = '/wucportal/enterprise/management';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . $base . '/published.php');
        exit;
    }
    $oid = (int)($_POST['opportunity_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($oid > 0 && $action === 'unpublish') {
        $result = ep_admin_decide_opportunity($db, $oid, 'unpublish', trim((string)($_POST['comments'] ?? '')));
        $_SESSION[$result['ok'] ?? false ? 'flash_success' : 'flash_error'] = (string)($result['message'] ?? 'Done.');
    } elseif ($oid > 0 && ($action === 'feature' || $action === 'unfeature')) {
        $result = ep_feature_opportunity_fairly($db, $oid, $action === 'feature');
        $_SESSION[$result['ok'] ? 'flash_success' : 'flash_error'] = $result['message'];
    }
    header('Location: ' . $base . '/published.php');
    exit;
}

$items = ep_search_published_opportunities($db, [], 200, 0);

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <h2 class="h5 mb-3">Published directory listings (<?= count($items) ?>)</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Code</th><th>Title</th><th>Enterprise</th><th>Views</th><th>Published</th><th></th></tr></thead>
            <tbody>
            <?php if ($items === []): ?>
                <tr><td colspan="6" class="text-center ep-muted py-4">No published opportunities.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $row): ?>
                    <tr>
                        <td><code><?= ep_h((string)$row['public_code']) ?></code></td>
                        <td><?= ep_h((string)$row['title']) ?></td>
                        <td><?= ep_h((string)($row['business_name'] ?? '')) ?></td>
                        <td><?= (int)($row['view_count'] ?? 0) ?></td>
                        <td class="small"><?= ep_h((string)($row['published_at'] ?? '')) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="<?= ep_h(ep_public_opportunity_url((string)$row['public_code'])) ?>" target="_blank" rel="noopener">Public</a>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= ep_h($base) ?>/qr_label.php?code=<?= rawurlencode((string)$row['public_code']) ?>" target="_blank" rel="noopener">QR</a>
                            <?php if (ep_can($db, 'enterprise.feature') || ep_can($db, 'enterprise.publish')): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                                    <input type="hidden" name="opportunity_id" value="<?= (int)$row['id'] ?>">
                                    <?php if (!empty($row['is_featured'])): ?>
                                        <button type="submit" name="action" value="unfeature" class="btn btn-sm btn-warning">Unfeature</button>
                                    <?php else: ?>
                                        <button type="submit" name="action" value="feature" class="btn btn-sm btn-outline-warning">Feature</button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                            <?php if (ep_can($db, 'enterprise.unpublish')): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Unpublish this listing?');">
                                    <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                                    <input type="hidden" name="opportunity_id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="action" value="unpublish">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Unpublish</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
