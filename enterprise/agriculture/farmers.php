<?php
declare(strict_types=1);

$page_title = 'Farmers';
$activeNav = 'agri_farmers';
$epGuardMode = 'agriculture';
$epNav = 'agriculture';
require_once dirname(__DIR__) . '/includes/guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ep_require_post_csrf();
    if (!ep_staff_can($db, 'agriculture.farmers.create')) {
        $_SESSION['flash_error'] = 'Permission denied.';
        wuc_redirect('/wucportal/enterprise/agriculture/farmers.php');
    }
    $res = FarmerProfileService::register($db, [
        'full_name' => trim((string)($_POST['full_name'] ?? '')),
        'mobile' => trim((string)($_POST['mobile'] ?? '')),
        'province' => trim((string)($_POST['province'] ?? '')),
        'district' => trim((string)($_POST['district'] ?? '')),
        'camp_or_village' => trim((string)($_POST['camp_or_village'] ?? '')),
        'preferred_language' => trim((string)($_POST['preferred_language'] ?? 'en')),
        'preferred_channel' => trim((string)($_POST['preferred_channel'] ?? 'sms')),
        'consent_method' => trim((string)($_POST['consent_method'] ?? 'agent_assisted')),
        'create_channel_user' => !empty($_POST['create_web_user']),
    ], ep_current_user_id());
    if (!empty($res['ok'])) {
        $_SESSION['flash_success'] = 'Farmer registered: ' . ($res['farmer_code'] ?? '');
    } else {
        $_SESSION['flash_error'] = $res['message'] ?? 'Registration failed.';
    }
    wuc_redirect('/wucportal/enterprise/agriculture/farmers.php');
}

$farmers = ep_list_farmers_for_staff($db, ep_current_user_id(), 100);

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= ep_h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= ep_h($flashError) ?></div><?php endif; ?>

<div class="ep-card mb-3">
    <h2 class="h6">Agent-assisted farmer registration</h2>
    <p class="small text-muted">Web login is optional. Phone is a communication channel, not the primary key. Verification is not a crop-quality guarantee.</p>
    <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <div class="col-md-4"><label class="form-label">Full name</label><input class="form-control" name="full_name" required></div>
        <div class="col-md-4"><label class="form-label">Mobile</label><input class="form-control" name="mobile" required placeholder="0977..."></div>
        <div class="col-md-2"><label class="form-label">Province</label><input class="form-control" name="province" required></div>
        <div class="col-md-2"><label class="form-label">District</label><input class="form-control" name="district" required></div>
        <div class="col-md-3"><label class="form-label">Camp / village</label><input class="form-control" name="camp_or_village"></div>
        <div class="col-md-2"><label class="form-label">Language</label><input class="form-control" name="preferred_language" value="en"></div>
        <div class="col-md-2"><label class="form-label">Channel</label>
            <select class="form-select" name="preferred_channel"><option value="sms">SMS</option><option value="ussd">USSD</option><option value="call">Call</option><option value="agent">Agent</option></select>
        </div>
        <div class="col-md-3"><label class="form-label">Consent method</label>
            <select class="form-select" name="consent_method"><option value="agent_assisted">Agent assisted</option><option value="web">Web</option><option value="call_centre">Call centre</option></select>
        </div>
        <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="create_web_user" id="cwu"><label class="form-check-label" for="cwu">Also create web user</label></div></div>
        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Register farmer</button></div>
    </form>
</div>

<div class="ep-card">
    <h2 class="h6">Recent farmers</h2>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Code</th><th>Name</th><th>Phone</th><th>Location</th><th>Verification</th><th>User</th></tr></thead>
            <tbody>
            <?php foreach ($farmers as $f): ?>
                <tr>
                    <td><?= ep_h((string)$f['farmer_code']) ?></td>
                    <td><?= ep_h((string)$f['full_name']) ?></td>
                    <td><?= ep_h((string)($f['phone_e164'] ?? '')) ?></td>
                    <td><?= ep_h(trim(($f['district'] ?? '') . ', ' . ($f['province'] ?? ''), ', ')) ?></td>
                    <td><?= ep_h((string)$f['verification_level']) ?></td>
                    <td><?= $f['user_id'] ? (int)$f['user_id'] : 'none' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($farmers === []): ?><tr><td colspan="6" class="text-muted">No farmers yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
