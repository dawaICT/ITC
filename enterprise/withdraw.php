<?php
declare(strict_types=1);

$page_title = 'Withdraw Participation';
$activeNav = 'withdraw';
$epGuardMode = 'status';
require_once __DIR__ . '/includes/guard.php';

$errors = [];
if (!$epMembership || !in_array($epMembershipStatus, ['pending', 'active', 'changes_requested'], true)) {
    $_SESSION['flash_error'] = 'There is no active participation to withdraw.';
    wuc_redirect('/wucportal/enterprise/membership_status.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        $reason = trim((string)($_POST['withdrawal_reason'] ?? ''));
        $result = ep_transition_membership($db, (int)$epMembership['id'], 'withdrawn', $reason);
        if ($result['ok']) {
            $_SESSION['flash_success'] = 'Your participation has been withdrawn. Public opportunities were unpublished.';
            wuc_redirect('/wucportal/enterprise/membership_status.php');
        }
        $errors[] = $result['message'];
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

require_once __DIR__ . '/includes/layout.php';
?>
<div class="ep-card">
    <h2 class="h5">Withdraw from the Skills and Enterprise Portal</h2>
    <p class="ep-muted">Withdrawal disables active workflows and unpublishes your public opportunities by default. Consent history, reviews, legitimate enquiry and outcome records are preserved.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <div class="mb-3">
            <label class="form-label">Reason (optional)</label>
            <textarea name="withdrawal_reason" class="form-control" rows="3" maxlength="500"></textarea>
        </div>
        <button type="submit" class="btn btn-danger" onclick="return confirm('Withdraw participation now?');">Confirm Withdrawal</button>
        <a href="/wucportal/enterprise/index.php" class="btn btn-outline-secondary">Cancel</a>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
