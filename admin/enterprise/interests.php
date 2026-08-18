<?php
declare(strict_types=1);

/**
 * Skills-to-Trade Hub — Interests list with status filter.
 */

$page_title = 'Interests — Skills-to-Trade';
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.interests.manage');

$base = '/wucportal/admin/enterprise';
$statusFilter = trim((string)($_GET['status'] ?? ''));
$followUps = eh_follow_up_statuses();
if ($statusFilter !== '' && !array_key_exists($statusFilter, $followUps)) {
    $statusFilter = '';
}

$filters = [];
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}
$interests = eh_list_interests($db, $filters, 200);
$interestTypes = eh_interest_types();
$flash = function_exists('wuc_get_flash') ? wuc_get_flash() : null;

require_once __DIR__ . '/../includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-envelope-open-text me-2 text-primary"></i>Expressions of Interest</h1>
                <p class="text-muted mb-0">Track and follow up on showcase interest submissions.</p>
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

    <section class="data-table-card mb-3">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold" for="status">Follow-up status</label>
                    <select class="form-select" name="status" id="status">
                        <option value="">All statuses</option>
                        <?php foreach ($followUps as $key => $label): ?>
                            <option value="<?php echo eh_h($key); ?>" <?php echo $statusFilter === $key ? 'selected' : ''; ?>>
                                <?php echo eh_h($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="<?php echo eh_h($base); ?>/interests.php" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </section>

    <section class="data-table-card">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-list me-2"></i>Interests (<?php echo count($interests); ?>)</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>When</th>
                            <th>Visitor</th>
                            <th>Type</th>
                            <th>Item</th>
                            <th>Enterprise</th>
                            <th>Status</th>
                            <th>Assigned</th>
                            <th class="text-end">Open</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$interests): ?>
                            <tr><td colspan="8" class="text-center text-muted py-5">No interests found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($interests as $row): ?>
                                <tr>
                                    <td class="small"><?php echo eh_h((string)$row['created_at']); ?></td>
                                    <td>
                                        <strong><?php echo eh_h((string)$row['visitor_name']); ?></strong>
                                        <div class="small text-muted"><?php echo eh_h((string)$row['email']); ?></div>
                                    </td>
                                    <td class="small"><?php echo eh_h($interestTypes[(string)$row['interest_type']] ?? (string)$row['interest_type']); ?></td>
                                    <td>
                                        <?php echo eh_h((string)$row['title']); ?>
                                        <div class="small text-muted"><code><?php echo eh_h((string)$row['public_code']); ?></code></div>
                                    </td>
                                    <td><?php echo eh_h((string)($row['business_name'] ?? '')); ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo eh_h($followUps[(string)$row['follow_up_status']] ?? (string)$row['follow_up_status']); ?></span>
                                    </td>
                                    <td class="small"><?php echo eh_h((string)($row['assigned_to'] ?? '—')); ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-primary" href="<?php echo eh_h($base); ?>/interest_view.php?id=<?php echo (int)$row['id']; ?>">View</a>
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
