<?php
declare(strict_types=1);

$page_title = 'Produce listings';
$activeNav = 'agri_produce';
$epGuardMode = 'agriculture';
$epNav = 'agriculture';
require_once dirname(__DIR__) . '/includes/guard.php';

$commodities = [];
$cr = $db->query("SELECT id, commodity_code, commodity_name FROM enterprise_commodities WHERE is_active=1 ORDER BY commodity_name");
if ($cr) {
    while ($row = $cr->fetch_assoc()) {
        $commodities[] = $row;
    }
}
$units = [];
$ur = $db->query("SELECT unit_code, unit_name FROM enterprise_measurement_units WHERE is_active=1 ORDER BY unit_code");
if ($ur) {
    while ($row = $ur->fetch_assoc()) {
        $units[] = $row;
    }
}
$farmerOpts = ep_list_farmers_for_staff($db, ep_current_user_id(), 200);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ep_require_post_csrf();
    if (!ep_staff_can($db, 'agriculture.produce.manage_assigned') && !ep_staff_can($db, 'agriculture.produce.create_own')) {
        $_SESSION['flash_error'] = 'Permission denied.';
        wuc_redirect('/wucportal/enterprise/agriculture/produce.php');
    }
    $res = ProduceListingService::create($db, [
        'farmer_profile_id' => (int)($_POST['farmer_profile_id'] ?? 0),
        'acting_user_id' => ep_current_user_id(),
        'commodity_id' => (int)($_POST['commodity_id'] ?? 0),
        'quantity' => (float)($_POST['quantity'] ?? 0),
        'quantity_unit' => trim((string)($_POST['quantity_unit'] ?? '')),
        'location_label' => trim((string)($_POST['location_label'] ?? '')),
        'province' => trim((string)($_POST['province'] ?? '')),
        'district' => trim((string)($_POST['district'] ?? '')),
        'asking_price' => trim((string)($_POST['asking_price'] ?? '')),
        'grade_label' => trim((string)($_POST['grade_label'] ?? '')),
    ]);
    $_SESSION[$res['ok'] ? 'flash_success' : 'flash_error'] = $res['ok']
        ? ('Listing created: ' . ($res['listing_code'] ?? ''))
        : ($res['message'] ?? 'Failed');
    wuc_redirect('/wucportal/enterprise/agriculture/produce.php');
}

ProduceListingService::expireDue($db);
$orgScope = ep_scope_organization_id($db);
$orgSql = $orgScope !== null ? ' AND f.organization_id = ' . (int)$orgScope : '';
$listings = [];
$lr = $db->query("SELECT l.*, f.full_name, f.farmer_code, c.commodity_name
    FROM enterprise_produce_listings l
    INNER JOIN enterprise_farmer_profiles f ON f.id = l.farmer_profile_id
    INNER JOIN enterprise_commodities c ON c.id = l.commodity_id
    WHERE 1=1{$orgSql} ORDER BY l.id DESC LIMIT 100");
if ($lr) {
    while ($row = $lr->fetch_assoc()) {
        $listings[] = $row;
    }
}

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= ep_h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= ep_h($flashError) ?></div><?php endif; ?>

<div class="ep-card mb-3">
    <h2 class="h6">Create produce listing</h2>
    <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <div class="col-md-4"><label class="form-label">Farmer</label>
            <select class="form-select" name="farmer_profile_id" required>
                <option value="">Select…</option>
                <?php foreach ($farmerOpts as $f): ?>
                    <option value="<?= (int)$f['id'] ?>"><?= ep_h($f['farmer_code'] . ' — ' . $f['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3"><label class="form-label">Commodity</label>
            <select class="form-select" name="commodity_id" required>
                <?php foreach ($commodities as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= ep_h((string)$c['commodity_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label">Quantity</label><input class="form-control" type="number" step="0.001" min="0.001" name="quantity" required></div>
        <div class="col-md-2"><label class="form-label">Unit</label>
            <select class="form-select" name="quantity_unit" required>
                <?php foreach ($units as $u): ?>
                    <option value="<?= ep_h((string)$u['unit_code']) ?>"><?= ep_h((string)$u['unit_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3"><label class="form-label">Location</label><input class="form-control" name="location_label" required></div>
        <div class="col-md-2"><label class="form-label">Province</label><input class="form-control" name="province"></div>
        <div class="col-md-2"><label class="form-label">District</label><input class="form-control" name="district"></div>
        <div class="col-md-2"><label class="form-label">Asking price</label><input class="form-control" type="number" step="0.01" name="asking_price"></div>
        <div class="col-md-2"><label class="form-label">Grade</label><input class="form-control" name="grade_label"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Create listing</button></div>
    </form>
</div>

<div class="ep-card">
    <h2 class="h6">Listings</h2>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Code</th><th>Farmer</th><th>Commodity</th><th>Qty</th><th>Status</th><th>Expires</th></tr></thead>
            <tbody>
            <?php foreach ($listings as $l): ?>
                <tr>
                    <td><?= ep_h((string)$l['listing_code']) ?></td>
                    <td><?= ep_h((string)$l['farmer_code']) ?></td>
                    <td><?= ep_h((string)$l['commodity_name']) ?></td>
                    <td><?= ep_h($l['quantity'] . ' ' . $l['quantity_unit']) ?></td>
                    <td><?= ep_h($l['status'] . '/' . $l['availability_status']) ?></td>
                    <td><?= ep_h((string)$l['expires_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
