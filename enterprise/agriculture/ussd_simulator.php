<?php
declare(strict_types=1);

$page_title = 'USSD simulator';
$activeNav = 'agri_ussd';
$epGuardMode = 'agriculture';
$epNav = 'agriculture';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_staff_can($db, 'agriculture.ussd.manage') && !ep_staff_can($db, 'agriculture.reports.view')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    wuc_redirect('/wucportal/enterprise/agriculture/index.php');
}

$sessionRef = (string)($_SESSION['agri_ussd_sim_ref'] ?? '');
$screen = (string)($_SESSION['agri_ussd_sim_screen'] ?? ep_ussd_main_menu());
$liveConfigured = ep_adapters()['ussd']->credentialsConfigured();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ep_require_post_csrf();
    $action = (string)($_POST['action'] ?? 'input');
    if ($action === 'start') {
        $phone = trim((string)($_POST['phone'] ?? ''));
        $res = UssdGatewayService::start($db, $phone, 'sim_' . bin2hex(random_bytes(4)));
        if (!empty($res['ok'])) {
            $sessionRef = (string)$res['session_reference'];
            $screen = (string)($res['menu'] ?? ep_ussd_main_menu());
            $_SESSION['agri_ussd_sim_ref'] = $sessionRef;
            $_SESSION['agri_ussd_sim_screen'] = $screen;
        } else {
            $_SESSION['flash_error'] = $res['message'] ?? 'Could not start session';
        }
    } elseif ($action === 'input' && $sessionRef !== '') {
        $res = UssdGatewayService::handle($db, $sessionRef, trim((string)($_POST['input'] ?? '')));
        $screen = (string)($res['response'] ?? $res['message'] ?? 'Error');
        $_SESSION['agri_ussd_sim_screen'] = $screen;
        if (!empty($res['end'])) {
            unset($_SESSION['agri_ussd_sim_ref']);
            $sessionRef = '';
        }
    }
    wuc_redirect('/wucportal/enterprise/agriculture/ussd_simulator.php');
}

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= ep_h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= ep_h($flashError) ?></div><?php endif; ?>
<div class="alert alert-warning small">
    Local USSD simulator only. Provider: <code><?= ep_h(ep_adapters()['ussd']->providerName()) ?></code>.
    Live credentials configured: <?= $liveConfigured ? 'yes' : 'no — live USSD not claimed complete' ?>.
    Shortcode is not hard-coded.
</div>

<div class="ep-card mb-3" style="max-width:420px">
    <h2 class="h6">Session</h2>
    <?php if ($sessionRef === ''): ?>
        <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
            <input type="hidden" name="action" value="start">
            <div class="col-12"><label class="form-label">Phone</label><input class="form-control" name="phone" value="0977000001" required></div>
            <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Start session</button></div>
        </form>
    <?php else: ?>
        <p class="small text-muted">Ref: <?= ep_h($sessionRef) ?></p>
        <pre class="bg-dark text-light p-3 rounded" style="white-space:pre-wrap"><?= ep_h($screen) ?></pre>
        <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
            <input type="hidden" name="action" value="input">
            <div class="col-8"><input class="form-control" name="input" placeholder="Enter option" autofocus></div>
            <div class="col-4"><button class="btn btn-primary w-100" type="submit">Send</button></div>
        </form>
    <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
