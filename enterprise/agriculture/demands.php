<?php
declare(strict_types=1);

$page_title = 'Buyer crop demands';
$activeNav = 'agri_demands';
$epGuardMode = 'agriculture';
$epNav = 'agriculture';
require_once dirname(__DIR__) . '/includes/guard.php';

$commodities = [];
$cr = $db->query("SELECT id, commodity_name FROM enterprise_commodities WHERE is_active=1 ORDER BY commodity_name");
if ($cr) {
    while ($row = $cr->fetch_assoc()) {
        $commodities[] = $row;
    }
}
$units = [];
$ur = $db->query("SELECT unit_code, unit_name FROM enterprise_measurement_units WHERE is_active=1");
if ($ur) {
    while ($row = $ur->fetch_assoc()) {
        $units[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ep_require_post_csrf();
    $action = (string)($_POST['action'] ?? 'create');
    if ($action === 'verify_buyer' && ep_staff_can($db, 'agriculture.buyers.manage')) {
        $id = (int)($_POST['demand_id'] ?? 0);
        $chk = $db->prepare('SELECT organization_id FROM enterprise_buyer_crop_demands WHERE id = ? LIMIT 1');
        if ($chk) {
            $chk->bind_param('i', $id);
            $chk->execute();
            $drow = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$drow || !ep_record_belongs_to_scope($db, isset($drow['organization_id']) ? (int)$drow['organization_id'] : null)) {
                $_SESSION['flash_error'] = 'Demand not in your organization workspace.';
                wuc_redirect('/wucportal/enterprise/agriculture/demands.php');
            }
        }
        $st = $db->prepare("UPDATE enterprise_buyer_crop_demands SET buyer_verification_status='verified', status='open' WHERE id=?");
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        ep_adapters()['audit']->log($db, 'agriculture.buyer_verified_on_demand', ['demand_id' => $id]);
        $_SESSION['flash_success'] = 'Buyer marked verified on demand; demand opened.';
        wuc_redirect('/wucportal/enterprise/agriculture/demands.php');
    }
    if (!ep_staff_can($db, 'agriculture.demands.create') && !ep_staff_can($db, 'agriculture.demands.review')) {
        $_SESSION['flash_error'] = 'Permission denied.';
        wuc_redirect('/wucportal/enterprise/agriculture/demands.php');
    }
    $res = BuyerCropDemandService::create($db, [
        'commodity_id' => (int)($_POST['commodity_id'] ?? 0),
        'quantity_required' => (float)($_POST['quantity_required'] ?? 0),
        'quantity_unit' => trim((string)($_POST['quantity_unit'] ?? '')),
        'location_label' => trim((string)($_POST['location_label'] ?? '')),
        'province' => trim((string)($_POST['province'] ?? '')),
        'district' => trim((string)($_POST['district'] ?? '')),
        'payment_terms' => trim((string)($_POST['payment_terms'] ?? '')),
        'buyer_verification_status' => trim((string)($_POST['buyer_verification_status'] ?? 'unverified')),
        'offered_price' => trim((string)($_POST['offered_price'] ?? '')),
    ]);
    $_SESSION[$res['ok'] ? 'flash_success' : 'flash_error'] = $res['ok']
        ? ('Demand created: ' . ($res['demand_code'] ?? ''))
        : ($res['message'] ?? 'Failed');
    wuc_redirect('/wucportal/enterprise/agriculture/demands.php');
}

$demands = [];
$orgScope = ep_scope_organization_id($db);
$orgSql = $orgScope !== null ? ' AND d.organization_id = ' . (int)$orgScope : '';
$dr = $db->query("SELECT d.*, c.commodity_name FROM enterprise_buyer_crop_demands d
    INNER JOIN enterprise_commodities c ON c.id = d.commodity_id WHERE 1=1{$orgSql} ORDER BY d.id DESC LIMIT 100");
if ($dr) {
    while ($row = $dr->fetch_assoc()) {
        $demands[] = $row;
    }
}

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= ep_h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= ep_h($flashError) ?></div><?php endif; ?>
<div class="alert alert-warning small">Unverified or suspended buyers cannot receive farmer referrals. Demands require institutional review before matching.</div>

<div class="ep-card mb-3">
    <h2 class="h6">Create buyer crop demand</h2>
    <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <input type="hidden" name="action" value="create">
        <div class="col-md-3"><label class="form-label">Commodity</label>
            <select class="form-select" name="commodity_id" required>
                <?php foreach ($commodities as $c): ?><option value="<?= (int)$c['id'] ?>"><?= ep_h((string)$c['commodity_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label">Qty required</label><input class="form-control" type="number" step="0.001" min="0.001" name="quantity_required" required></div>
        <div class="col-md-2"><label class="form-label">Unit</label>
            <select class="form-select" name="quantity_unit"><?php foreach ($units as $u): ?><option value="<?= ep_h((string)$u['unit_code']) ?>"><?= ep_h((string)$u['unit_name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-3"><label class="form-label">Location</label><input class="form-control" name="location_label" required></div>
        <div class="col-md-2"><label class="form-label">Buyer status</label>
            <select class="form-select" name="buyer_verification_status"><option value="unverified">Unverified</option><option value="verified">Verified</option><option value="suspended">Suspended</option></select>
        </div>
        <div class="col-md-2"><label class="form-label">Province</label><input class="form-control" name="province"></div>
        <div class="col-md-2"><label class="form-label">District</label><input class="form-control" name="district"></div>
        <div class="col-md-2"><label class="form-label">Offered price</label><input class="form-control" type="number" step="0.01" name="offered_price"></div>
        <div class="col-md-4"><label class="form-label">Payment terms</label><input class="form-control" name="payment_terms"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Create demand</button></div>
    </form>
</div>

<div class="ep-card">
    <h2 class="h6">Demands</h2>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Code</th><th>Commodity</th><th>Qty</th><th>Buyer</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($demands as $d): ?>
                <tr>
                    <td><?= ep_h((string)$d['demand_code']) ?></td>
                    <td><?= ep_h((string)$d['commodity_name']) ?></td>
                    <td><?= ep_h($d['quantity_required'] . ' ' . $d['quantity_unit']) ?></td>
                    <td><?= ep_h((string)$d['buyer_verification_status']) ?></td>
                    <td><?= ep_h((string)$d['status']) ?></td>
                    <td>
                        <?php if ($d['buyer_verification_status'] !== 'verified' && ep_staff_can($db, 'agriculture.buyers.manage')): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                                <input type="hidden" name="action" value="verify_buyer">
                                <input type="hidden" name="demand_id" value="<?= (int)$d['id'] ?>">
                                <button class="btn btn-xs btn-outline-success btn-sm" type="submit">Verify buyer</button>
                            </form>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline-primary" href="/wucportal/enterprise/agriculture/matches.php?demand_id=<?= (int)$d['id'] ?>">Match</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
