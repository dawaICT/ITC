<?php
require_once __DIR__ . '/_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!repo_table_exists($db, 'repository_categories')) {
        repo_flash('danger', 'The repository category table is not installed yet. Apply the repository migration first.');
        repo_redirect('/wucportal/admin/repository/categories.php');
    }
    $errors = [];
    repo_require_csrf($errors);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $active = isset($_POST['is_active']) ? 1 : 0;
    $id = (int)($_POST['id'] ?? 0);
    if ($name === '') {
        $errors[] = 'Category name is required.';
    }
    if ($errors) {
        repo_flash('danger', implode(' ', $errors));
    } elseif ($id > 0) {
        $dupStmt = $db->prepare('SELECT id FROM repository_categories WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1');
        if ($dupStmt) {
            $dupStmt->bind_param('si', $name, $id);
            $dupStmt->execute();
            if ($dupStmt->get_result()->num_rows > 0) {
                $dupStmt->close();
                repo_flash('danger', 'A category with this name already exists.');
                repo_redirect('/wucportal/admin/repository/categories.php');
            }
            $dupStmt->close();
        }
        $stmt = $db->prepare("UPDATE repository_categories SET name=?, description=?, is_active=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('ssii', $name, $description, $active, $id);
            $stmt->execute();
            $stmt->close();
        }
        repo_flash('success', 'Category updated.');
        repo_redirect('/wucportal/admin/repository/categories.php');
    } else {
        $dupStmt = $db->prepare('SELECT id FROM repository_categories WHERE LOWER(name) = LOWER(?) LIMIT 1');
        if ($dupStmt) {
            $dupStmt->bind_param('s', $name);
            $dupStmt->execute();
            if ($dupStmt->get_result()->num_rows > 0) {
                $dupStmt->close();
                repo_flash('danger', 'This category already exists. Choose a different name or edit the existing row.');
                repo_redirect('/wucportal/admin/repository/categories.php');
            }
            $dupStmt->close();
        }
        $stmt = $db->prepare("INSERT INTO repository_categories (name, description, is_active) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('ssi', $name, $description, $active);
            $stmt->execute();
            $stmt->close();
        }
        repo_flash('success', 'Category added.');
        repo_redirect('/wucportal/admin/repository/categories.php');
    }
}

$schemaReady = repo_table_exists($db, 'repository_categories');
$editRow = null;
if ($schemaReady && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId > 0 && ($est = $db->prepare('SELECT * FROM repository_categories WHERE id = ? LIMIT 1'))) {
        $est->bind_param('i', $editId);
        $est->execute();
        $editRow = $est->get_result()->fetch_assoc() ?: null;
        $est->close();
    }
}
$rows = [];
if ($schemaReady && ($res = $db->query("SELECT * FROM repository_categories ORDER BY name"))) {
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();
}

repo_admin_header('Repository Categories', 'Manage material categories used by lecturer uploads and filters.');
?>
<?php if (!$schemaReady): ?>
<div class="alert alert-warning">
    <strong>Repository schema not installed.</strong>
    The <code>repository_categories</code> table is missing. Run the migration
    <code>migrations/20260703_digital_learning_repository.sql</code> as a DB user with CREATE privileges
    (e.g. <code>mysql -u root wucportal &lt; migrations/20260703_digital_learning_repository.sql</code>),
    then refresh this page.
</div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-4">
        <section class="data-table-card">
            <div class="card-header"><h5 class="mb-0"><?php echo $editRow ? 'Edit Category' : 'Add Category'; ?></h5></div>
            <div class="card-body">
                <?php if (!$schemaReady): ?>
                    <p class="text-muted mb-0">Category management is unavailable until the repository migration is applied.</p>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
                    <?php if ($editRow): ?>
                        <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
                    <?php endif; ?>
                    <div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" required value="<?php echo repo_h($editRow['name'] ?? ''); ?>"></div>
                    <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo repo_h($editRow['description'] ?? ''); ?></textarea></div>
                    <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" <?php echo (!$editRow || (int)($editRow['is_active'] ?? 1) === 1) ? 'checked' : ''; ?>> <span class="form-check-label">Active</span></label>
                    <button class="btn btn-primary" type="submit"><?php echo $editRow ? 'Update Category' : 'Save Category'; ?></button>
                    <?php if ($editRow): ?>
                        <a class="btn btn-outline-secondary" href="categories.php">Cancel</a>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-8">
        <section class="data-table-card">
            <div class="card-body table-responsive">
                <?php if (!$schemaReady): ?>
                    <p class="text-muted mb-0">No categories to show.</p>
                <?php elseif (!$rows): ?>
                    <p class="text-muted mb-0">No categories yet. Default categories are seeded automatically when the schema is first created.</p>
                <?php else: ?>
                <table class="table table-hover align-middle">
                    <thead><tr><th>Name</th><th>Description</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo repo_h($row['name']); ?></td>
                            <td><?php echo repo_h($row['description']); ?></td>
                            <td><?php echo (int)$row['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="categories.php?edit=<?php echo (int)$row['id']; ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
<?php repo_admin_footer(); ?>
