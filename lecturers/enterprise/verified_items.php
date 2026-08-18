<?php
declare(strict_types=1);

/**
 * Skills-to-Trade Hub — Lecturer verified / approved / published list.
 */

$page_title = 'Verified Items — Skills-to-Trade';
require_once __DIR__ . '/../includes/guard.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.review.lecturer');

$items = eh_list_by_status($db, ['lecturer_verified', 'approved', 'published'], 200);
$flash = function_exists('wuc_get_flash') ? wuc_get_flash() : null;
$base = '/wucportal/lecturers/enterprise';

require_once __DIR__ . '/../includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-check-circle me-2 text-success"></i>Verified Items</h1>
                <p class="text-muted mb-0">Items that have passed lecturer verification (including approved and published).</p>
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
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Verified onward (<?php echo count($items); ?>)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Title</th>
                            <th>Enterprise</th>
                            <th>Student ID</th>
                            <th>Status</th>
                            <th>Verified</th>
                            <th class="text-end">View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$items): ?>
                            <tr><td colspan="7" class="text-center text-muted py-5">No verified items yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $row): ?>
                                <tr>
                                    <td><code><?php echo eh_h((string)$row['public_code']); ?></code></td>
                                    <td><strong><?php echo eh_h((string)$row['title']); ?></strong></td>
                                    <td><?php echo eh_h((string)($row['business_name'] ?? '')); ?></td>
                                    <td><code><?php echo eh_h((string)($row['student_id'] ?? '—')); ?></code></td>
                                    <td>
                                        <span class="badge bg-<?php echo eh_h(eh_status_badge_class((string)$row['status'])); ?>">
                                            <?php echo eh_h(eh_status_label((string)$row['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="small"><?php echo eh_h((string)($row['lecturer_verified_at'] ?? '—')); ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo eh_h($base); ?>/review.php?id=<?php echo (int)$row['id']; ?>">Open</a>
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
