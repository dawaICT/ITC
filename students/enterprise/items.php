<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/legacy_academic_guard.php';
ep_redirect_certificate_from_legacy_skills_hub($db, (string)($_SESSION['Sid'] ?? ''));
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.item.edit_own');

$base = '/wucportal/students/enterprise';
$profile = eh_get_profile_for_owner($db, eh_current_owner_user_id(), eh_current_student_id());
$items = [];
$itemTypes = eh_item_types();

if ($profile) {
    eh_assert_owns_profile($profile);
    $items = eh_list_items_for_profile($db, (int)$profile['id']);
}

$pageTitle = 'My showcase items';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main class="content-wrapper portal-dashboard eh-hub pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4">
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title mb-1"><i class="fas fa-boxes-stacked me-2 text-primary"></i>My showcase items</h1>
                <p class="eh-page-lead mb-0">Manage drafts, respond to feedback, and track published opportunities.</p>
            </div>
            <div class="col-auto">
                <div class="eh-toolbar">
                    <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/index.php">Hub home</a>
                    <?php if ($profile): ?>
                        <a class="btn btn-primary" href="<?php echo eh_h($base); ?>/item_create.php"><i class="fas fa-plus me-1"></i>New item</a>
                    <?php else: ?>
                        <a class="btn btn-primary" href="<?php echo eh_h($base); ?>/profile_edit.php"><i class="fas fa-id-card me-1"></i>Create profile first</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$profile): ?>
        <div class="alert alert-info d-flex align-items-center gap-2">
            <i class="fas fa-info-circle"></i>
            <div>Create an enterprise profile before adding showcase items. <a href="<?php echo eh_h($base); ?>/profile_edit.php" class="alert-link">Create profile</a></div>
        </div>
    <?php endif; ?>

    <section class="data-table-card">
        <div class="card-body p-0">
            <?php if ($items === []): ?>
                <div class="p-3">
                    <?php eh_render_empty(
                        'fa-box-open',
                        $profile ? 'No items yet. Add a product, service or investment opportunity.' : 'Create your profile first, then add an item.',
                        $profile ? $base . '/item_create.php' : $base . '/profile_edit.php',
                        $profile ? 'Add your first item' : 'Create profile'
                    ); ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $status = (string)$item['status'];
                            $editable = in_array($status, ['draft', 'changes_requested'], true);
                            $id = (int)$item['id'];
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo eh_h((string)$item['title']); ?></div>
                                    <div class="small text-muted"><?php echo eh_h((string)$item['public_code']); ?></div>
                                </td>
                                <td>
                                    <i class="fas <?php echo eh_h(eh_item_type_icon((string)$item['item_type'])); ?> text-primary me-1"></i>
                                    <?php echo eh_h($itemTypes[(string)$item['item_type']] ?? (string)$item['item_type']); ?>
                                </td>
                                <td><?php echo eh_h((string)($item['category_name'] ?? '—')); ?></td>
                                <td><?php echo eh_status_chip($status); ?></td>
                                <td class="small"><?php echo eh_h((string)($item['updated_at'] ?? '')); ?></td>
                                <td class="text-end eh-item-actions">
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo eh_h($base); ?>/item_view.php?id=<?php echo $id; ?>">Open</a>
                                    <?php if ($editable): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo eh_h($base); ?>/item_edit.php?id=<?php echo $id; ?>">Edit</a>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo eh_h($base); ?>/cost_calculator.php?item_id=<?php echo $id; ?>">Costs</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
