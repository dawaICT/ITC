<?php
declare(strict_types=1);

/**
 * Skills-to-Trade Hub — Category management.
 */

$page_title = 'Categories — Skills-to-Trade';
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.settings.manage');

$base = '/wucportal/admin/enterprise';

if (empty($_SESSION['eh_cat_form_token']) || !is_string($_SESSION['eh_cat_form_token'])) {
    $_SESSION['eh_cat_form_token'] = bin2hex(random_bytes(16));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        eh_require_post_csrf();
    } catch (Throwable $e) {
        wuc_set_flash('error', $e->getMessage());
        header('Location: ' . $base . '/categories.php');
        exit;
    }

    $postedFormToken = (string)($_POST['form_token'] ?? '');
    $sessionFormToken = (string)($_SESSION['eh_cat_form_token'] ?? '');
    if ($sessionFormToken === '' || !hash_equals($sessionFormToken, $postedFormToken)) {
        wuc_set_flash('error', 'Form expired or already submitted.');
        header('Location: ' . $base . '/categories.php');
        exit;
    }
    unset($_SESSION['eh_cat_form_token']);

    $action = (string)($_POST['action'] ?? '');
    $name = trim((string)($_POST['category_name'] ?? ''));
    $slug = trim((string)($_POST['category_slug'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $displayOrder = (int)($_POST['display_order'] ?? 0);
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $categoryId = (int)($_POST['category_id'] ?? 0);

    if ($slug === '' && $name !== '') {
        $slug = eh_slugify($name);
    }

    try {
        if ($action === 'add') {
            if ($name === '' || $slug === '') {
                throw new RuntimeException('Category name and slug are required.');
            }
            $stmt = $db->prepare("
                INSERT INTO enterprise_categories (category_name, category_slug, description, is_active, display_order)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('sssii', $name, $slug, $description, $isActive, $displayOrder);
            $stmt->execute();
            $stmt->close();
            eh_audit($db, 'enterprise_hub.category_created', ['slug' => $slug]);
            wuc_set_flash('success', 'Category added.');
        } elseif ($action === 'edit') {
            if ($categoryId <= 0 || $name === '' || $slug === '') {
                throw new RuntimeException('Valid category, name and slug are required.');
            }
            $stmt = $db->prepare("
                UPDATE enterprise_categories
                SET category_name = ?, category_slug = ?, description = ?, is_active = ?, display_order = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('sssiii', $name, $slug, $description, $isActive, $displayOrder, $categoryId);
            $stmt->execute();
            $stmt->close();
            eh_audit($db, 'enterprise_hub.category_updated', ['category_id' => $categoryId]);
            wuc_set_flash('success', 'Category updated.');
        } elseif ($action === 'toggle_active') {
            if ($categoryId <= 0) {
                throw new RuntimeException('Invalid category.');
            }
            $stmt = $db->prepare('UPDATE enterprise_categories SET is_active = IF(is_active=1,0,1), updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $categoryId);
            $stmt->execute();
            $stmt->close();
            eh_audit($db, 'enterprise_hub.category_toggled', ['category_id' => $categoryId]);
            wuc_set_flash('success', 'Category activation updated.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        wuc_set_flash('error', $e->getMessage());
    }

    $_SESSION['eh_cat_form_token'] = bin2hex(random_bytes(16));
    header('Location: ' . $base . '/categories.php');
    exit;
}

$categories = eh_list_categories($db, false);
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    foreach ($categories as $c) {
        if ((int)$c['id'] === $editId) {
            $editRow = $c;
            break;
        }
    }
}

$formToken = (string)$_SESSION['eh_cat_form_token'];
$csrf = wuc_csrf_token();
$flash = function_exists('wuc_get_flash') ? wuc_get_flash() : null;

require_once __DIR__ . '/../includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-tags me-2 text-primary"></i>Categories</h1>
                <p class="text-muted mb-0">Maintain showcase categories used by student enterprises.</p>
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

    <div class="row g-4">
        <div class="col-lg-4">
            <section class="data-table-card">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo $editRow ? 'Edit category' : 'Add category'; ?></h5>
                </div>
                <div class="card-body">
                    <form method="post" action="<?php echo eh_h($base); ?>/categories.php">
                        <input type="hidden" name="csrf_token" value="<?php echo eh_h($csrf); ?>">
                        <input type="hidden" name="form_token" value="<?php echo eh_h($formToken); ?>">
                        <input type="hidden" name="action" value="<?php echo $editRow ? 'edit' : 'add'; ?>">
                        <?php if ($editRow): ?>
                            <input type="hidden" name="category_id" value="<?php echo (int)$editRow['id']; ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label" for="category_name">Name</label>
                            <input class="form-control" type="text" name="category_name" id="category_name" required maxlength="120"
                                   value="<?php echo eh_h((string)($editRow['category_name'] ?? '')); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="category_slug">Slug</label>
                            <input class="form-control" type="text" name="category_slug" id="category_slug" maxlength="140"
                                   value="<?php echo eh_h((string)($editRow['category_slug'] ?? '')); ?>"
                                   placeholder="auto from name if blank">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="description">Description</label>
                            <textarea class="form-control" name="description" id="description" rows="3" maxlength="500"><?php echo eh_h((string)($editRow['description'] ?? '')); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="display_order">Display order</label>
                            <input class="form-control" type="number" name="display_order" id="display_order"
                                   value="<?php echo (int)($editRow['display_order'] ?? 0); ?>">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                                <?php echo (!$editRow || !empty($editRow['is_active'])) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <?php echo $editRow ? 'Save changes' : 'Add category'; ?>
                        </button>
                        <?php if ($editRow): ?>
                            <a href="<?php echo eh_h($base); ?>/categories.php" class="btn btn-outline-secondary w-100 mt-2">Cancel edit</a>
                        <?php endif; ?>
                    </form>
                </div>
            </section>
        </div>

        <div class="col-lg-8">
            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-list me-2"></i>All categories (<?php echo count($categories); ?>)</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Order</th>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Active</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$categories): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No categories.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($categories as $c): ?>
                                        <tr>
                                            <td><?php echo (int)$c['display_order']; ?></td>
                                            <td>
                                                <strong><?php echo eh_h((string)$c['category_name']); ?></strong>
                                                <?php if (!empty($c['description'])): ?>
                                                    <div class="small text-muted"><?php echo eh_h((string)$c['description']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?php echo eh_h((string)$c['category_slug']); ?></code></td>
                                            <td>
                                                <?php if (!empty($c['is_active'])): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <a class="btn btn-sm btn-outline-primary" href="<?php echo eh_h($base); ?>/categories.php?edit=<?php echo (int)$c['id']; ?>">Edit</a>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo eh_h($csrf); ?>">
                                                    <input type="hidden" name="form_token" value="<?php echo eh_h($formToken); ?>">
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="category_id" value="<?php echo (int)$c['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                        <?php echo !empty($c['is_active']) ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </form>
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
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
