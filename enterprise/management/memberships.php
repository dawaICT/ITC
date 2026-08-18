<?php
declare(strict_types=1);

$page_title = 'Memberships';
$activeNav = 'mgmt_mem';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.memberships.manage')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    header('Location: /wucportal/enterprise/management/index.php');
    exit;
}

$filter = trim((string)($_GET['status'] ?? ''));
$statuses = ep_membership_statuses();
if ($filter !== '' && !in_array($filter, $statuses, true)) {
    $filter = '';
}
$members = ep_list_memberships($db, $filter, 200);

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <form method="get" class="row g-2 align-items-end mb-3">
        <div class="col-auto">
            <label class="form-label" for="status">Status</label>
            <select name="status" id="status" class="form-select form-select-sm">
                <option value="">All active</option>
                <?php foreach ($statuses as $st): ?>
                    <?php if ($st === 'archived') {
                        continue;
                    } ?>
                    <option value="<?= ep_h($st) ?>"<?= $filter === $st ? ' selected' : '' ?>><?= ep_h(ep_status_label($st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary">Filter</button></div>
    </form>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Submitted</th><th>Updated</th><th></th></tr></thead>
            <tbody>
            <?php if ($members === []): ?>
                <tr><td colspan="6" class="text-center ep-muted py-4">No memberships found.</td></tr>
            <?php else: ?>
                <?php foreach ($members as $m): ?>
                    <tr>
                        <td><?= (int)$m['id'] ?></td>
                        <td><?= ep_h(ep_status_label((string)($m['membership_type'] ?? ''))) ?></td>
                        <td><span class="badge bg-<?= ep_h(ep_status_badge_class((string)$m['status'])) ?>"><?= ep_h(ep_status_label((string)$m['status'])) ?></span></td>
                        <td class="small"><?= ep_h((string)($m['submitted_at'] ?? '—')) ?></td>
                        <td class="small"><?= ep_h((string)($m['updated_at'] ?? '')) ?></td>
                        <td class="text-end"><a href="/wucportal/enterprise/management/member_view.php?id=<?= (int)$m['id'] ?>">Manage</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
