<?php
declare(strict_types=1);

$page_title = 'Produce matching';
$activeNav = 'agri_matches';
$epGuardMode = 'agriculture';
$epNav = 'agriculture';
require_once dirname(__DIR__) . '/includes/guard.php';

$demandId = (int)($_GET['demand_id'] ?? $_POST['demand_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ep_require_post_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'suggest_record' && ep_staff_can($db, 'agriculture.matches.create')) {
        $res = AgricultureMatchingService::record(
            $db,
            (int)$_POST['demand_id'],
            (int)$_POST['listing_id'],
            (float)$_POST['quantity_proposed'],
            (string)$_POST['quantity_unit'],
            (string)($_POST['match_explanation'] ?? ''),
            isset($_POST['match_score']) ? (float)$_POST['match_score'] : null
        );
        $_SESSION[$res['ok'] ? 'flash_success' : 'flash_error'] = $res['ok']
            ? 'Match recorded for officer review (not a sale).'
            : ($res['message'] ?? 'Failed');
    }
    if ($action === 'officer_decision' && ep_staff_can($db, 'agriculture.matches.review')) {
        $mid = (int)$_POST['match_id'];
        $dec = (string)$_POST['officer_decision'];
        if (in_array($dec, ['approved', 'rejected'], true)) {
            $st = $db->prepare('UPDATE enterprise_produce_matches SET officer_decision=? WHERE id=?');
            $st->bind_param('si', $dec, $mid);
            $st->execute();
            $st->close();
            ep_adapters()['audit']->log($db, 'agriculture.match_officer_decision', ['match_id' => $mid, 'decision' => $dec]);
            $_SESSION['flash_success'] = 'Officer decision saved.';
        }
    }
    if ($action === 'farmer_consent' && ep_staff_can($db, 'agriculture.referrals.manage')) {
        $res = EnterpriseConsentService::farmerMatchConsent(
            $db,
            (int)$_POST['match_id'],
            (string)$_POST['decision'],
            (string)$_POST['channel']
        );
        $_SESSION[$res['ok'] ? 'flash_success' : 'flash_error'] = $res['ok']
            ? 'Consent recorded. Contact details shared only if accepted and approved.'
            : ($res['message'] ?? 'Failed');
    }
    wuc_redirect('/wucportal/enterprise/agriculture/matches.php' . ($demandId ? '?demand_id=' . $demandId : ''));
}

$suggestions = $demandId > 0 ? AgricultureMatchingService::suggest($db, $demandId) : [];
$matches = [];
$orgScope = ep_scope_organization_id($db);
$orgSql = $orgScope !== null ? ' AND f.organization_id = ' . (int)$orgScope : '';
$sql = 'SELECT m.*, d.demand_code, l.listing_code, f.full_name AS farmer_name
        FROM enterprise_produce_matches m
        INNER JOIN enterprise_buyer_crop_demands d ON d.id = m.demand_id
        INNER JOIN enterprise_produce_listings l ON l.id = m.listing_id
        INNER JOIN enterprise_farmer_profiles f ON f.id = l.farmer_profile_id
        WHERE 1=1' . $orgSql . '
        ORDER BY m.id DESC LIMIT 50';
$mr = $db->query($sql);
if ($mr) {
    while ($row = $mr->fetch_assoc()) {
        $matches[] = $row;
    }
}

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= ep_h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= ep_h($flashError) ?></div><?php endif; ?>
<div class="alert alert-secondary small">Filter-assisted suggestions only. Officer review required. Farmer consent required before sharing contact. A match is not a completed sale.</div>

<div class="ep-card mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label">Demand ID</label><input class="form-control" name="demand_id" value="<?= $demandId ?: '' ?>"></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm" type="submit">Suggest matches</button></div>
    </form>
</div>

<?php if ($demandId > 0): ?>
<div class="ep-card mb-3">
    <h2 class="h6">Suggestions for demand #<?= $demandId ?></h2>
    <?php if ($suggestions === []): ?>
        <p class="text-muted small mb-0">No suggestions (unverified buyer, expired demand, or no compatible listings).</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Listing</th><th>Farmer</th><th>Qty</th><th>Score</th><th>Why</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($suggestions as $s): ?>
                    <tr>
                        <td><?= ep_h((string)$s['listing_code']) ?></td>
                        <td><?= ep_h((string)$s['full_name']) ?> (<?= ep_h((string)$s['verification_level']) ?>)</td>
                        <td><?= ep_h($s['quantity'] . ' ' . $s['quantity_unit']) ?></td>
                        <td><?= ep_h((string)$s['match_score']) ?></td>
                        <td class="small"><?= ep_h((string)$s['match_explanation']) ?></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                                <input type="hidden" name="action" value="suggest_record">
                                <input type="hidden" name="demand_id" value="<?= $demandId ?>">
                                <input type="hidden" name="listing_id" value="<?= (int)$s['id'] ?>">
                                <input type="hidden" name="quantity_proposed" value="<?= ep_h((string)$s['quantity_proposed']) ?>">
                                <input type="hidden" name="quantity_unit" value="<?= ep_h((string)$s['quantity_unit']) ?>">
                                <input type="hidden" name="match_score" value="<?= ep_h((string)$s['match_score']) ?>">
                                <input type="hidden" name="match_explanation" value="<?= ep_h((string)$s['match_explanation']) ?>">
                                <button class="btn btn-sm btn-outline-primary" type="submit">Record for review</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="ep-card">
    <h2 class="h6">Match records</h2>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>ID</th><th>Demand</th><th>Listing</th><th>Farmer</th><th>Officer</th><th>Consent</th><th>Outcome</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($matches as $m): ?>
                <tr>
                    <td><?= (int)$m['id'] ?></td>
                    <td><?= ep_h((string)$m['demand_code']) ?></td>
                    <td><?= ep_h((string)$m['listing_code']) ?></td>
                    <td><?= ep_h((string)$m['farmer_name']) ?></td>
                    <td><?= ep_h((string)$m['officer_decision']) ?></td>
                    <td><?= ep_h((string)$m['farmer_consent_status']) ?></td>
                    <td><?= ep_h((string)$m['outcome_status']) ?></td>
                    <td class="small">
                        <?php if ($m['officer_decision'] === 'suggested'): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                                <input type="hidden" name="action" value="officer_decision">
                                <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
                                <input type="hidden" name="demand_id" value="<?= (int)$m['demand_id'] ?>">
                                <button name="officer_decision" value="approved" class="btn btn-sm btn-success">Approve</button>
                                <button name="officer_decision" value="rejected" class="btn btn-sm btn-outline-danger">Reject</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($m['officer_decision'] === 'approved' && $m['farmer_consent_status'] === 'pending'): ?>
                            <form method="post" class="d-inline mt-1">
                                <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                                <input type="hidden" name="action" value="farmer_consent">
                                <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
                                <input type="hidden" name="demand_id" value="<?= (int)$m['demand_id'] ?>">
                                <input type="hidden" name="channel" value="agent_assisted">
                                <button name="decision" value="accepted" class="btn btn-sm btn-primary">Consent accept</button>
                                <button name="decision" value="declined" class="btn btn-sm btn-outline-secondary">Decline</button>
                            </form>
                        <?php endif; ?>
                        <?php if (EnterpriseReferralService::canShareFarmerContact($m)): ?>
                            <span class="badge bg-success">Contact share allowed</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
