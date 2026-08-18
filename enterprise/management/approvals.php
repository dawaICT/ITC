<?php
declare(strict_types=1);

$page_title = 'Opportunity Approvals';
$activeNav = 'mgmt_app';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.approve') && !ep_can($db, 'enterprise.publish')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    header('Location: /wucportal/enterprise/management/index.php');
    exit;
}

$base = '/wucportal/enterprise/management';
$id = (int)($_GET['id'] ?? $_POST['opportunity_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $id > 0) {
    try {
        ep_require_post_csrf();
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . $base . '/approvals.php?id=' . $id);
        exit;
    }
    $decision = (string)($_POST['decision'] ?? '');
    $comments = trim((string)($_POST['comments'] ?? ''));
    if ($decision === 'reject' && $comments === '') {
        $_SESSION['flash_error'] = 'Comments required for rejection.';
        header('Location: ' . $base . '/approvals.php?id=' . $id);
        exit;
    }
    if (!in_array($decision, ['approve', 'reject', 'publish'], true)) {
        $_SESSION['flash_error'] = 'Invalid decision.';
        header('Location: ' . $base . '/approvals.php');
        exit;
    }
    $result = ep_admin_decide_opportunity($db, $id, $decision, $comments);
    if (!empty($result['ok'])) {
        $_SESSION['flash_success'] = (string)($result['message'] ?? 'Saved.');
        header('Location: ' . $base . '/approvals.php');
        exit;
    }
    $_SESSION['flash_error'] = (string)($result['message'] ?? 'Action failed.');
    header('Location: ' . $base . '/approvals.php?id=' . $id);
    exit;
}

if ($id > 0) {
    $opp = ep_get_opportunity($db, $id);
    if (!$opp) {
        $_SESSION['flash_error'] = 'Opportunity not found.';
        header('Location: ' . $base . '/approvals.php');
        exit;
    }
    require_once dirname(__DIR__) . '/includes/layout.php';
    $status = (string)$opp['status'];
    ?>
    <div class="ep-card">
        <h2 class="h5"><?= ep_h((string)$opp['title']) ?></h2>
        <p><code><?= ep_h((string)$opp['public_code']) ?></code>
            <span class="badge bg-<?= ep_h(ep_status_badge_class($status)) ?>"><?= ep_h(ep_status_label($status)) ?></span></p>
        <p class="ep-muted"><?= ep_h((string)($opp['business_name'] ?? '')) ?></p>
        <p><?= nl2br(ep_h((string)($opp['short_description'] ?? ''))) ?></p>
        <form method="post" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
            <input type="hidden" name="opportunity_id" value="<?= $id ?>">
            <div class="mb-3">
                <label class="form-label" for="comments">Comments</label>
                <textarea class="form-control" name="comments" id="comments" rows="3"></textarea>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($status === 'reviewer_verified' && ep_can($db, 'enterprise.approve')): ?>
                    <button type="submit" name="decision" value="approve" class="btn btn-success">Approve</button>
                    <button type="submit" name="decision" value="reject" class="btn btn-outline-danger">Reject</button>
                <?php endif; ?>
                <?php if ($status === 'approved' && ep_can($db, 'enterprise.publish')): ?>
                    <button type="submit" name="decision" value="publish" class="btn btn-primary">Publish</button>
                <?php endif; ?>
            </div>
        </form>
        <a href="<?= ep_h($base) ?>/approvals.php" class="btn btn-link btn-sm mt-2 px-0">Back</a>
    </div>
    <?php
    require_once dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$stmt = $db->prepare("SELECT o.id, o.public_code, o.title, o.status, o.submitted_at, p.business_name
    FROM enterprise_opportunities o
    JOIN enterprise_member_profiles p ON p.id = o.enterprise_profile_id
    WHERE o.status IN ('reviewer_verified','approved')
    ORDER BY FIELD(o.status,'reviewer_verified','approved'), o.updated_at DESC
    LIMIT 200");
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while ($row = $res->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <h2 class="h5 mb-3">Administrative decisions</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Code</th><th>Title</th><th>Enterprise</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if ($items === []): ?>
                <tr><td colspan="5" class="text-center ep-muted py-4">Nothing awaiting decision.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $row): ?>
                    <tr>
                        <td><code><?= ep_h((string)$row['public_code']) ?></code></td>
                        <td><?= ep_h((string)$row['title']) ?></td>
                        <td><?= ep_h((string)($row['business_name'] ?? '')) ?></td>
                        <td><span class="badge bg-<?= ep_h(ep_status_badge_class((string)$row['status'])) ?>"><?= ep_h(ep_status_label((string)$row['status'])) ?></span></td>
                        <td class="text-end"><a href="<?= ep_h($base) ?>/approvals.php?id=<?= (int)$row['id'] ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
