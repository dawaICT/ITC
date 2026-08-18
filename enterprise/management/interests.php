<?php
declare(strict_types=1);

$page_title = 'Directory Interests';
$activeNav = 'mgmt_int';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.interests.manage') && !ep_can($db, 'enterprise.leads.assign')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    header('Location: /wucportal/enterprise/management/index.php');
    exit;
}

$filter = trim((string)($_GET['status'] ?? ''));
$leadStatuses = ep_lead_statuses();
if ($filter !== '' && !in_array($filter, $leadStatuses, true)) {
    $filter = '';
}
$interests = ep_list_interests($db, $filter, 200);

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <form method="get" class="row g-2 align-items-end mb-3">
        <div class="col-auto">
            <label class="form-label" for="status">Lead status</label>
            <select name="status" id="status" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($leadStatuses as $st): ?>
                    <option value="<?= ep_h($st) ?>"<?= $filter === $st ? ' selected' : '' ?>><?= ep_h(ep_status_label($st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary">Filter</button></div>
    </form>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>When</th><th>Opportunity</th><th>Visitor</th><th>Type</th><th>Status</th><th>Age</th><th></th></tr></thead>
            <tbody>
            <?php if ($interests === []): ?>
                <tr><td colspan="7" class="text-center ep-muted py-4">No interests recorded.</td></tr>
            <?php else: ?>
                <?php foreach ($interests as $row): ?>
                    <tr>
                        <td class="small"><?= ep_h((string)$row['created_at']) ?></td>
                        <td><code><?= ep_h((string)$row['public_code']) ?></code><br><span class="small"><?= ep_h((string)$row['title']) ?></span></td>
                        <td><?= ep_h((string)$row['visitor_name']) ?></td>
                        <td class="small"><?= ep_h(ep_status_label((string)$row['interest_type'])) ?></td>
                        <td><?= ep_h(ep_status_label((string)$row['lead_status'])) ?></td>
                        <td><span class="badge bg-secondary"><?= ep_h(ep_lead_age_label($db, (string)$row['created_at'])) ?></span></td>
                        <td class="text-end"><a href="/wucportal/enterprise/management/interest_view.php?id=<?= (int)$row['id'] ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
