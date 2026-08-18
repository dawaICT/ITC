<?php
declare(strict_types=1);

/**
 * Skills-to-Trade Hub — Published items + unpublish / feature actions.
 */

$page_title = 'Published Items — Skills-to-Trade';
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.publish');

$base = '/wucportal/admin/enterprise';

if (empty($_SESSION['eh_admin_pub_token']) || !is_string($_SESSION['eh_admin_pub_token'])) {
    $_SESSION['eh_admin_pub_token'] = bin2hex(random_bytes(16));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        eh_require_post_csrf();
    } catch (Throwable $e) {
        wuc_set_flash('error', $e->getMessage());
        header('Location: ' . $base . '/published_items.php');
        exit;
    }

    $postedFormToken = (string)($_POST['form_token'] ?? '');
    $sessionFormToken = (string)($_SESSION['eh_admin_pub_token'] ?? '');
    if ($sessionFormToken === '' || !hash_equals($sessionFormToken, $postedFormToken)) {
        wuc_set_flash('error', 'Form expired or already submitted. Please try again.');
        header('Location: ' . $base . '/published_items.php');
        exit;
    }
    unset($_SESSION['eh_admin_pub_token']);

    $itemId = (int)($_POST['item_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');

    if ($itemId <= 0) {
        $_SESSION['eh_admin_pub_token'] = bin2hex(random_bytes(16));
        wuc_set_flash('error', 'Invalid item.');
        header('Location: ' . $base . '/published_items.php');
        exit;
    }

    if ($action === 'unpublish') {
        $result = eh_transition_item($db, $itemId, 'unpublished', 'unpublish', '', 'administration');
        wuc_set_flash($result['success'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'toggle_featured') {
        $featured = !empty($_POST['is_featured']) ? 1 : 0;
        $stmt = $db->prepare('UPDATE enterprise_items SET is_featured = ? WHERE id = ?');
        $stmt->bind_param('ii', $featured, $itemId);
        $stmt->execute();
        $stmt->close();
        eh_audit($db, 'enterprise_hub.feature_toggled', ['item_id' => $itemId, 'is_featured' => $featured]);
        wuc_set_flash('success', $featured ? 'Item featured.' : 'Featured flag cleared.');
    } else {
        wuc_set_flash('error', 'Unknown action.');
    }

    $_SESSION['eh_admin_pub_token'] = bin2hex(random_bytes(16));
    header('Location: ' . $base . '/published_items.php');
    exit;
}

$items = eh_list_by_status($db, 'published', 300);
$formToken = (string)$_SESSION['eh_admin_pub_token'];
$csrf = wuc_csrf_token();
$flash = function_exists('wuc_get_flash') ? wuc_get_flash() : null;

require_once __DIR__ . '/../includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-globe me-2 text-success"></i>Published Items</h1>
                <p class="text-muted mb-0">Live showcase opportunities — unpublish or feature as needed.</p>
            </div>
            <div class="col-auto">
                <a href="<?php echo eh_h($base); ?>/index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Hub home</a>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <?php $alertType = ($flash['type'] ?? '') === 'error' ? 'danger' : (string)$flash['type']; ?>
        <div class="alert alert-<?php echo eh_h($alertType); ?> alert-dismissible fade show" role="alert">
            <?php echo eh_h((string)($flash['message'] ?? '')); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <section class="data-table-card">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-list me-2"></i>Published (<?php echo count($items); ?>)</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Title</th>
                            <th>Enterprise</th>
                            <th>Type</th>
                            <th>Featured</th>
                            <th>Published</th>
                            <th>Views</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$items): ?>
                            <tr><td colspan="8" class="text-center text-muted py-5">No published items.</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $row): ?>
                                <tr>
                                    <td><code><?php echo eh_h((string)$row['public_code']); ?></code></td>
                                    <td>
                                        <strong><?php echo eh_h((string)$row['title']); ?></strong>
                                        <div class="small">
                                            <a href="<?php echo eh_h($base); ?>/review.php?id=<?php echo (int)$row['id']; ?>">Review</a>
                                            ·
                                            <a href="<?php echo eh_h($base); ?>/qr_label.php?id=<?php echo (int)$row['id']; ?>" target="_blank" rel="noopener">QR</a>
                                        </div>
                                    </td>
                                    <td><?php echo eh_h((string)($row['business_name'] ?? '')); ?></td>
                                    <td><?php echo eh_h(eh_item_types()[(string)$row['item_type']] ?? (string)$row['item_type']); ?></td>
                                    <td>
                                        <?php if (!empty($row['is_featured'])): ?>
                                            <span class="badge bg-warning text-dark">Yes</span>
                                        <?php else: ?>
                                            <span class="text-muted">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?php echo eh_h((string)($row['published_at'] ?? '')); ?></td>
                                    <td><?php echo (int)($row['view_count'] ?? 0); ?></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo eh_h($csrf); ?>">
                                                <input type="hidden" name="form_token" value="<?php echo eh_h($formToken); ?>">
                                                <input type="hidden" name="item_id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="hidden" name="action" value="toggle_featured">
                                                <input type="hidden" name="is_featured" value="<?php echo !empty($row['is_featured']) ? '0' : '1'; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                                    <?php echo !empty($row['is_featured']) ? 'Unfeature' : 'Feature'; ?>
                                                </button>
                                            </form>
                                            <?php if (eh_can($db, 'enterprise.unpublish')): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Unpublish this item?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo eh_h($csrf); ?>">
                                                    <input type="hidden" name="form_token" value="<?php echo eh_h($formToken); ?>">
                                                    <input type="hidden" name="item_id" value="<?php echo (int)$row['id']; ?>">
                                                    <input type="hidden" name="action" value="unpublish">
                                                    <button type="submit" class="btn btn-sm btn-outline-dark">Unpublish</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
