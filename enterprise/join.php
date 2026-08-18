<?php
declare(strict_types=1);

$page_title = 'Join Skills and Enterprise Portal';
$epGuardMode = 'join';
require_once __DIR__ . '/includes/guard.php';

$errors = [];
$elig = ep_check_eligibility($db);
$status = ep_membership_effective_status($epMembership);

if (in_array($status, ['pending', 'active', 'changes_requested', 'suspended'], true)) {
    wuc_redirect('/wucportal/enterprise/membership_status.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        $goals = array_values(array_filter(array_map('strval', (array)($_POST['goals'] ?? []))));
        $consents = [];
        foreach (ep_required_consent_types() as $type) {
            $consents[$type] = !empty($_POST['consent'][$type]);
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $result = ep_submit_membership($db, $goals, $consents, $ip, $ua);
        if ($result['ok']) {
            $_SESSION['flash_success'] = $result['message'];
            wuc_redirect('/wucportal/enterprise/membership_status.php');
        }
        $errors[] = $result['message'];
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$activeNav = '';
require_once __DIR__ . '/includes/layout.php';
?>
<div class="ep-card">
    <h2 class="h4 mb-2">Skills and Enterprise Portal</h2>
    <p class="ep-muted mb-3">Build a professional profile, showcase your skills, products, services and innovations, and connect with employment, business and partnership opportunities.</p>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <h3 class="h6">Purpose</h3>
            <p class="small ep-muted mb-0">A permanent institutional platform that connects verified skills and enterprises to customers, employers, mentors, partners and investment pathways.</p>
        </div>
        <div class="col-md-4">
            <h3 class="h6">Benefits</h3>
            <ul class="small ep-muted mb-0">
                <li>Verified public directory presence</li>
                <li>Interest and lead follow-up</li>
                <li>Outcome tracking (jobs, orders, partnerships)</li>
            </ul>
        </div>
        <div class="col-md-4">
            <h3 class="h6">Requirements</h3>
            <ul class="small ep-muted mb-0">
                <li>Eligible student or graduate account</li>
                <li>Voluntary opt-in and consent</li>
                <li>Accurate, reviewable information</li>
            </ul>
        </div>
    </div>
    <div class="alert alert-light border small">
        <strong>Privacy summary:</strong> Private academic identifiers (student number, NRC, home address, results) are never published.
        Public contact is shared only according to your preferences. Most introductions are portal-mediated.
    </div>
    <?php if (!$elig['ok']): ?>
        <div class="alert alert-warning"><?= ep_h($elig['notes']) ?></div>
    <?php else: ?>
        <form method="post" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
            <h3 class="h6">Participation goals</h3>
            <p class="small ep-muted">Select one or more. Forms will adapt to your goals.</p>
            <div class="row g-2 mb-3">
                <?php foreach (ep_participation_goals() as $key => $label): ?>
                    <div class="col-md-6">
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="goals[]" value="<?= ep_h($key) ?>">
                            <span class="form-check-label"><?= ep_h($label) ?></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
            <h3 class="h6">Consent declarations</h3>
            <p class="small ep-muted">None are preselected. All are required.</p>
            <?php foreach (ep_consent_labels() as $key => $label): ?>
                <label class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="consent[<?= ep_h($key) ?>]" value="1">
                    <span class="form-check-label small"><?= ep_h($label) ?></span>
                </label>
            <?php endforeach; ?>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Submit Opt-In Request</button>
                <a href="/wucportal/portal_selection.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
