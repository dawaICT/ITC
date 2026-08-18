<?php
declare(strict_types=1);

$page_title = 'Lead Detail';
$activeNav = 'mgmt_int';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.interests.manage') && !ep_can($db, 'enterprise.leads.assign')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    header('Location: /wucportal/enterprise/management/interests.php');
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['interest_id'] ?? 0);
$base = '/wucportal/enterprise/management';

if ($id <= 0) {
    $_SESSION['flash_error'] = 'Invalid lead.';
    header('Location: ' . $base . '/interests.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . $base . '/interest_view.php?id=' . $id);
        exit;
    }
    $status = trim((string)($_POST['lead_status'] ?? ''));
    $notes = trim((string)($_POST['internal_notes'] ?? ''));
    $assigned = trim((string)($_POST['assigned_to'] ?? ''));
    $closure = trim((string)($_POST['closure_reason'] ?? ''));
    $result = ep_update_lead_status($db, $id, $status, $notes, $assigned, $closure);
    $_SESSION[!empty($result['ok']) ? 'flash_success' : 'flash_error'] = (string)($result['message'] ?? 'Updated.');
    header('Location: ' . $base . '/interest_view.php?id=' . $id);
    exit;
}

$lead = ep_get_interest($db, $id);
if (!$lead) {
    $_SESSION['flash_error'] = 'Lead not found.';
    header('Location: ' . $base . '/interests.php');
    exit;
}

$types = ep_interest_types();
require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="ep-card">
            <h2 class="h5"><?= ep_h((string)$lead['visitor_name']) ?></h2>
            <p class="mb-1"><strong>Opportunity:</strong> <?= ep_h((string)$lead['title']) ?> (<code><?= ep_h((string)$lead['public_code']) ?></code>)</p>
            <p class="mb-1"><strong>Type:</strong> <?= ep_h($types[(string)$lead['interest_type']] ?? (string)$lead['interest_type']) ?></p>
            <p class="mb-1"><strong>Email:</strong> <?= ep_h((string)$lead['email']) ?></p>
            <p class="mb-1"><strong>Phone:</strong> <?= ep_h((string)$lead['phone']) ?></p>
            <?php if (!empty($lead['organization'])): ?>
                <p class="mb-1"><strong>Organization:</strong> <?= ep_h((string)$lead['organization']) ?></p>
            <?php endif; ?>
            <hr>
            <p class="mb-0"><?= nl2br(ep_h((string)$lead['message'])) ?></p>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="ep-card">
            <h3 class="h6">Update lead</h3>
            <p class="small">Age: <span class="badge bg-secondary"><?= ep_h(ep_lead_age_label($db, (string)$lead['created_at'])) ?></span></p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                <input type="hidden" name="interest_id" value="<?= $id ?>">
                <div class="mb-2">
                    <label class="form-label" for="lead_status">Status</label>
                    <select name="lead_status" id="lead_status" class="form-select" required>
                        <?php foreach (ep_lead_statuses() as $st): ?>
                            <option value="<?= ep_h($st) ?>"<?= ((string)$lead['lead_status'] === $st) ? ' selected' : '' ?>><?= ep_h(ep_status_label($st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="assigned_to">Assigned to</label>
                    <input class="form-control" name="assigned_to" id="assigned_to" value="<?= ep_h((string)($lead['assigned_to'] ?? '')) ?>" maxlength="80">
                </div>
                <div class="mb-2">
                    <label class="form-label" for="internal_notes">Internal notes</label>
                    <textarea class="form-control" name="internal_notes" id="internal_notes" rows="3"><?= ep_h((string)($lead['internal_notes'] ?? '')) ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="closure_reason">Closure reason</label>
                    <input class="form-control" name="closure_reason" id="closure_reason" maxlength="500">
                </div>
                <button type="submit" class="btn btn-primary w-100">Save</button>
            </form>
            <a href="<?= ep_h($base) ?>/interests.php" class="btn btn-link btn-sm mt-2 px-0">Back</a>
        </div>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
