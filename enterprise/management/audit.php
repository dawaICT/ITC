<?php
declare(strict_types=1);

$page_title = 'Audit Log';
$activeNav = 'mgmt_audit';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.reports.view') && !ep_can($db, 'enterprise.settings.manage')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    header('Location: /wucportal/enterprise/management/index.php');
    exit;
}

$rows = [];
$like = '%enterprise_portal%';
if ($stmt = $db->prepare("SELECT id, user_id, action, details, created_at FROM audit_log WHERE action LIKE ? OR details LIKE ? ORDER BY id DESC LIMIT 200")) {
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
} elseif ($res = $db->query("SELECT id, user_id, action, details, created_at FROM audit_logs WHERE action LIKE '%enterprise_portal%' ORDER BY id DESC LIMIT 200")) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
}

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <h2 class="h5 mb-3">Enterprise portal audit events</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="4" class="text-center ep-muted py-4">No audit entries found (or audit table unavailable).</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="small"><?= ep_h((string)($row['created_at'] ?? '')) ?></td>
                        <td><code><?= ep_h((string)($row['user_id'] ?? '')) ?></code></td>
                        <td class="small"><?= ep_h((string)$row['action']) ?></td>
                        <td class="small text-break"><?= ep_h((string)($row['details'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
