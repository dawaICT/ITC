<?php
declare(strict_types=1);

$page_title = 'Commodity prices';
$activeNav = 'agri_prices';
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
$sources = [];
$sr = $db->query("SELECT id, source_code, source_name FROM enterprise_commodity_price_sources WHERE is_active=1");
if ($sr) {
    while ($row = $sr->fetch_assoc()) {
        $sources[] = $row;
    }
}
$units = [];
$ur = $db->query("SELECT unit_code FROM enterprise_measurement_units WHERE is_active=1");
if ($ur) {
    while ($row = $ur->fetch_assoc()) {
        $units[] = $row;
    }
}
$types = ep_commodity_price_types();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ep_require_post_csrf();
    $action = (string)($_POST['action'] ?? 'draft');
    if ($action === 'draft' && ep_staff_can($db, 'agriculture.prices.create')) {
        $res = EnterprisePriceService::createDraft($db, [
            'commodity_id' => (int)($_POST['commodity_id'] ?? 0),
            'price' => (float)($_POST['price'] ?? 0),
            'quantity_unit' => trim((string)($_POST['quantity_unit'] ?? '')),
            'location_label' => trim((string)($_POST['location_label'] ?? '')),
            'price_type' => trim((string)($_POST['price_type'] ?? '')),
            'source_id' => (int)($_POST['source_id'] ?? 0),
            'source_reference' => trim((string)($_POST['source_reference'] ?? '')),
            'effective_from' => trim((string)($_POST['effective_from'] ?? '')),
            'effective_to' => trim((string)($_POST['effective_to'] ?? '')),
            'province' => trim((string)($_POST['province'] ?? '')),
        ]);
        $_SESSION[$res['ok'] ? 'flash_success' : 'flash_error'] = $res['ok'] ? 'Draft price created.' : ($res['message'] ?? 'Failed');
    }
    if ($action === 'publish' && (ep_staff_can($db, 'agriculture.prices.publish') || ep_staff_can($db, 'agriculture.prices.verify'))) {
        $res = EnterprisePriceService::publish($db, (int)$_POST['price_id'], !empty($_POST['second_approval']));
        $_SESSION[$res['ok'] ? 'flash_success' : 'flash_error'] = $res['message'] ?? ($res['ok'] ? 'Published' : 'Failed');
    }
    wuc_redirect('/wucportal/enterprise/agriculture/prices.php');
}

$drafts = [];
$dr = $db->query("SELECT p.*, c.commodity_name, s.source_name FROM enterprise_commodity_prices p
    INNER JOIN enterprise_commodities c ON c.id = p.commodity_id
    INNER JOIN enterprise_commodity_price_sources s ON s.id = p.source_id
    ORDER BY p.id DESC LIMIT 80");
if ($dr) {
    while ($row = $dr->fetch_assoc()) {
        $drafts[] = $row;
    }
}
$current = EnterprisePriceService::current($db, []);

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= ep_h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= ep_h($flashError) ?></div><?php endif; ?>
<div class="alert alert-info small">
    Price types are distinct. Buyer offers and farmer asking prices are never labelled official/government.
    No source or effective date → no publication. Expired prices are excluded from current results.
    Official approval mode: <code><?= ep_h(ep_official_price_approval_mode()) ?></code>.
</div>

<div class="ep-card mb-3">
    <h2 class="h6">Draft price</h2>
    <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <input type="hidden" name="action" value="draft">
        <div class="col-md-3"><label class="form-label">Commodity</label>
            <select class="form-select" name="commodity_id"><?php foreach ($commodities as $c): ?><option value="<?= (int)$c['id'] ?>"><?= ep_h((string)$c['commodity_name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-3"><label class="form-label">Price type</label>
            <select class="form-select" name="price_type"><?php foreach ($types as $k => $lab): ?><option value="<?= ep_h($k) ?>"><?= ep_h($lab) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-2"><label class="form-label">Price</label><input class="form-control" type="number" step="0.01" min="0.01" name="price" required></div>
        <div class="col-md-2"><label class="form-label">Unit</label>
            <select class="form-select" name="quantity_unit"><?php foreach ($units as $u): ?><option value="<?= ep_h((string)$u['unit_code']) ?>"><?= ep_h((string)$u['unit_code']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-3"><label class="form-label">Source</label>
            <select class="form-select" name="source_id"><?php foreach ($sources as $s): ?><option value="<?= (int)$s['id'] ?>"><?= ep_h((string)$s['source_name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-3"><label class="form-label">Location</label><input class="form-control" name="location_label" required></div>
        <div class="col-md-2"><label class="form-label">Effective from</label><input class="form-control" type="date" name="effective_from" value="<?= ep_h(date('Y-m-d')) ?>" required></div>
        <div class="col-md-2"><label class="form-label">Effective to</label><input class="form-control" type="date" name="effective_to"></div>
        <div class="col-md-3"><label class="form-label">Source reference</label><input class="form-control" name="source_reference"></div>
        <div class="col-md-2"><label class="form-label">Province</label><input class="form-control" name="province"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Save draft</button></div>
    </form>
</div>

<div class="ep-card mb-3">
    <h2 class="h6">Current published prices</h2>
    <ul class="mb-0">
        <?php foreach ($current as $p): ?>
            <li class="small"><?= ep_h(ep_format_price_sms($p)) ?></li>
        <?php endforeach; ?>
        <?php if ($current === []): ?><li class="text-muted small">None</li><?php endif; ?>
    </ul>
</div>

<div class="ep-card">
    <h2 class="h6">All price records</h2>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>ID</th><th>Commodity</th><th>Type</th><th>Price</th><th>Source</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($drafts as $p): ?>
                <tr>
                    <td><?= (int)$p['id'] ?></td>
                    <td><?= ep_h((string)$p['commodity_name']) ?></td>
                    <td><?= ep_h(ep_price_type_label((string)$p['price_type'])) ?></td>
                    <td><?= ep_h($p['currency'] . ' ' . $p['price'] . '/' . $p['quantity_unit']) ?></td>
                    <td><?= ep_h((string)$p['source_name']) ?></td>
                    <td><?= ep_h((string)$p['publication_status']) ?></td>
                    <td>
                        <?php if ($p['publication_status'] !== 'published'): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                                <input type="hidden" name="action" value="publish">
                                <input type="hidden" name="price_id" value="<?= (int)$p['id'] ?>">
                                <?php if (ep_price_is_official_class((string)$p['price_type']) && $p['publication_status'] === 'verified'): ?>
                                    <input type="hidden" name="second_approval" value="1">
                                    <button class="btn btn-sm btn-warning" type="submit">Second approve &amp; publish</button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-primary" type="submit">Verify / publish</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
