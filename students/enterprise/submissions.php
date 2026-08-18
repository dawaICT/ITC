<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/legacy_academic_guard.php';
ep_redirect_certificate_from_legacy_skills_hub($db, (string)($_SESSION['Sid'] ?? ''));
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.item.submit');

$base = '/wucportal/students/enterprise';
$profile = eh_get_profile_for_owner($db, eh_current_owner_user_id(), eh_current_student_id());
$itemTypes = eh_item_types();
$statuses = ['submitted', 'changes_requested', 'lecturer_verified', 'rejected', 'approved', 'published'];
$rows = [];

if ($profile) {
    eh_assert_owns_profile($profile);
    $all = eh_list_items_for_profile($db, (int)$profile['id']);
    foreach ($all as $item) {
        if (in_array((string)$item['status'], $statuses, true)) {
            $rows[] = $item;
        }
    }
}

$pageTitle = 'Submissions';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main class="content-wrapper portal-dashboard eh-hub pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4">
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title mb-1"><i class="fas fa-inbox me-2"></i>Submissions</h1>
                <p class="text-muted mb-0">Track items that have entered the review and publication workflow.</p>
            </div>
            <div class="col-auto d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/index.php">Hub home</a>
                <a class="btn btn-outline-primary" href="<?php echo eh_h($base); ?>/items.php">All items</a>
            </div>
        </div>
    </div>

    <?php if (!$profile): ?>
        <div class="alert alert-info">Create an enterprise profile to start submissions.
            <a href="<?php echo eh_h($base); ?>/profile_edit.php">Create profile</a>
        </div>
    <?php endif; ?>

    <section class="data-table-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Updated</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                No submitted items yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $item): ?>
                            <?php $status = (string)$item['status']; ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo eh_h((string)$item['title']); ?></div>
                                    <div class="small text-muted"><?php echo eh_h((string)$item['public_code']); ?></div>
                                </td>
                                <td><?php echo eh_h($itemTypes[(string)$item['item_type']] ?? (string)$item['item_type']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo eh_h(eh_status_badge_class($status)); ?>">
                                        <?php echo eh_h(eh_status_label($status)); ?>
                                    </span>
                                </td>
                                <td class="small"><?php echo eh_h((string)($item['submitted_at'] ?? '—')); ?></td>
                                <td class="small"><?php echo eh_h((string)($item['updated_at'] ?? '')); ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo eh_h($base); ?>/item_view.php?id=<?php echo (int)$item['id']; ?>">Open</a>
                                    <?php if ($status === 'changes_requested'): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo eh_h($base); ?>/item_edit.php?id=<?php echo (int)$item['id']; ?>">Fix &amp; resubmit</a>
                                    <?php endif; ?>
                                    <?php if ($status === 'published'): ?>
                                        <a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener"
                                           href="<?php echo eh_h(eh_public_item_url((string)$item['public_code'])); ?>">Public</a>
                                    <?php endif; ?>
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
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
