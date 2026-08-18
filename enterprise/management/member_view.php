<?php
declare(strict_types=1);

$page_title = 'Membership Detail';
$activeNav = 'mgmt_mem';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.memberships.manage')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    header('Location: /wucportal/enterprise/management/memberships.php');
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['membership_id'] ?? 0);
$base = '/wucportal/enterprise/management';

if ($id <= 0) {
    $_SESSION['flash_error'] = 'Invalid membership.';
    header('Location: ' . $base . '/memberships.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . $base . '/member_view.php?id=' . $id);
        exit;
    }
    $toStatus = trim((string)($_POST['to_status'] ?? ''));
    $reason = trim((string)($_POST['reason'] ?? ''));
    $result = ep_transition_membership($db, $id, $toStatus, $reason);
    if (!empty($result['ok'])) {
        $_SESSION['flash_success'] = (string)($result['message'] ?? 'Updated.');
    } else {
        $_SESSION['flash_error'] = (string)($result['message'] ?? 'Update failed.');
    }
    header('Location: ' . $base . '/member_view.php?id=' . $id);
    exit;
}

$stmt = $db->prepare('SELECT * FROM enterprise_memberships WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$membership = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$membership) {
    $_SESSION['flash_error'] = 'Membership not found.';
    header('Location: ' . $base . '/memberships.php');
    exit;
}

$profile = ep_get_profile_by_membership($db, $id);
$from = (string)$membership['status'];
$allowed = array_filter(
    ep_membership_allowed_transitions()[$from] ?? [],
    static fn(string $to): bool => ep_can_transition_membership($from, $to)
);

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="ep-card">
            <h2 class="h5">Membership #<?= $id ?></h2>
            <p class="mb-1">Type: <?= ep_h(ep_status_label((string)$membership['membership_type'])) ?></p>
            <p class="mb-1">Status: <span class="badge bg-<?= ep_h(ep_status_badge_class($from)) ?>"><?= ep_h(ep_status_label($from)) ?></span></p>
            <?php if (!empty($membership['eligibility_notes'])): ?>
                <p class="small ep-muted mb-0"><?= ep_h((string)$membership['eligibility_notes']) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($profile): ?>
            <div class="ep-card">
                <h3 class="h6">Professional profile</h3>
                <p class="mb-1"><strong><?= ep_h((string)($profile['business_name'] ?? '')) ?></strong></p>
                <p class="mb-1 ep-muted"><?= ep_h((string)($profile['professional_title'] ?? '')) ?></p>
                <p class="small mb-0"><?= nl2br(ep_h((string)($profile['short_bio'] ?? ''))) ?></p>
            </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-5">
        <div class="ep-card">
            <h3 class="h6">Change status</h3>
            <?php if ($allowed === []): ?>
                <p class="ep-muted mb-0">No transitions available from this status.</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                    <input type="hidden" name="membership_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label class="form-label" for="to_status">New status</label>
                        <select name="to_status" id="to_status" class="form-select" required>
                            <?php foreach ($allowed as $to): ?>
                                <option value="<?= ep_h($to) ?>"><?= ep_h(ep_status_label($to)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="reason">Reason / notes</label>
                        <textarea class="form-control" name="reason" id="reason" rows="3" maxlength="2000"></textarea>
                        <div class="form-text">Required for decline, suspension, or changes requested.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Apply transition</button>
                </form>
            <?php endif; ?>
            <a href="<?= ep_h($base) ?>/memberships.php" class="btn btn-link btn-sm mt-2 px-0">Back to list</a>
        </div>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
