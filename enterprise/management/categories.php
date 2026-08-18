<?php
declare(strict_types=1);

$page_title = 'Categories';
$activeNav = 'mgmt_cat';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.settings.manage')) {
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
        header('Location: ' . $base . '/categories.php');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    $name = trim((string)($_POST['category_name'] ?? ''));
    $slug = trim((string)($_POST['category_slug'] ?? ''));
    if ($slug === '' && $name !== '') {
        $slug = ep_slugify($name);
    }
    $description = trim((string)($_POST['description'] ?? ''));
    $displayOrder = (int)($_POST['display_order'] ?? 0);
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $categoryId = (int)($_POST['category_id'] ?? 0);

    if ($action === 'add' && $name !== '' && $slug !== '') {
        $stmt = $db->prepare('INSERT INTO enterprise_categories (category_name, category_slug, description, is_active, display_order) VALUES (?,?,?,?,?)');
        $stmt->bind_param('sssii', $name, $slug, $description, $isActive, $displayOrder);
        $stmt->execute();
        $stmt->close();
        ep_audit($db, 'enterprise_portal.category_created', ['slug' => $slug]);
        $_SESSION['flash_success'] = 'Category added.';
    } elseif ($action === 'edit' && $categoryId > 0 && $name !== '' && $slug !== '') {
        $stmt = $db->prepare('UPDATE enterprise_categories SET category_name=?, category_slug=?, description=?, is_active=?, display_order=?, updated_at=NOW() WHERE id=?');
        $stmt->bind_param('sssiii', $name, $slug, $description, $isActive, $displayOrder, $categoryId);
        $stmt->execute();
        $stmt->close();
        ep_audit($db, 'enterprise_portal.category_updated', ['category_id' => $categoryId]);
        $_SESSION['flash_success'] = 'Category updated.';
    } else {
        $_SESSION['flash_error'] = 'Invalid category submission.';
    }
    header('Location: ' . $base . '/categories.php');
    exit;
}

$categories = ep_list_categories($db, false);

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="ep-card">
            <h2 class="h6">Add category</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                <input type="hidden" name="action" value="add">
                <div class="mb-2"><label class="form-label">Name</label><input class="form-control" name="category_name" required maxlength="120"></div>
                <div class="mb-2"><label class="form-label">Slug</label><input class="form-control" name="category_slug" maxlength="120"></div>
                <div class="mb-2"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2"></textarea></div>
                <div class="mb-2"><label class="form-label">Display order</label><input type="number" class="form-control" name="display_order" value="0"></div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="add_active" checked><label class="form-check-label" for="add_active">Active</label></div>
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="ep-card">
            <h2 class="h6 mb-3">All categories</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Name</th><th>Slug</th><th>Order</th><th>Active</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td colspan="5" class="p-0 border-0">
                                <form method="post" class="p-2 border-bottom">
                                    <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-md-3"><input class="form-control form-control-sm" name="category_name" value="<?= ep_h((string)$cat['category_name']) ?>"></div>
                                        <div class="col-md-3"><input class="form-control form-control-sm" name="category_slug" value="<?= ep_h((string)$cat['category_slug']) ?>"></div>
                                        <div class="col-md-2"><input type="number" class="form-control form-control-sm" name="display_order" value="<?= (int)($cat['display_order'] ?? 0) ?>"></div>
                                        <div class="col-md-2"><input class="form-check-input" type="checkbox" name="is_active" value="1"<?= !empty($cat['is_active']) ? ' checked' : '' ?>></div>
                                        <div class="col-md-2"><button type="submit" class="btn btn-sm btn-outline-primary">Save</button></div>
                                    </div>
                                    <textarea class="form-control form-control-sm mt-1" name="description" rows="1"><?= ep_h((string)($cat['description'] ?? '')) ?></textarea>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
