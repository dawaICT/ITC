<?php
declare(strict_types=1);

$page_title = 'Readiness Assessment';
$activeNav = 'readiness';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);
$opps = ep_list_opportunities_for_profile($db, $profileId);
$oppId = (int)($_GET['opportunity_id'] ?? $_POST['opportunity_id'] ?? 0);
$errors = [];
$scores = [
    'product_score' => 50, 'market_score' => 50, 'costing_score' => 50,
    'capacity_score' => 50, 'compliance_score' => 50, 'team_score' => 50,
];
$result = null;

if ($oppId > 0) {
    $existing = ep_get_readiness($db, $oppId);
    if ($existing) {
        foreach ($scores as $k => $_) {
            $scores[$k] = (int)$existing[$k];
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        $oppId = (int)($_POST['opportunity_id'] ?? 0);
        foreach ($scores as $k => $_) {
            $scores[$k] = (int)($_POST[$k] ?? 0);
        }
        $opp = ep_get_opportunity($db, $oppId);
        ep_assert_own_opportunity($opp, $profileId);
        $save = ep_save_readiness($db, $oppId, $scores);
        if ($save['ok']) {
            $_SESSION['flash_success'] = $save['message'];
            $result = $save['calc'];
        } else {
            $errors[] = $save['message'];
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <p class="small ep-muted">Category scores are assessed by you for planning. Total score is calculated server-side and cannot be edited directly. An investment-ready public label requires management approval.</p>
    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <div class="col-md-6">
            <label class="form-label">Opportunity</label>
            <select name="opportunity_id" class="form-select" required>
                <option value="">Select…</option>
                <?php foreach ($opps as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= $oppId === (int)$o['id'] ? 'selected' : '' ?>><?= ep_h((string)$o['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php foreach ($scores as $k => $v): ?>
            <div class="col-md-4">
                <label class="form-label"><?= ep_h(ucwords(str_replace('_', ' ', $k))) ?> (0–100)</label>
                <input type="number" min="0" max="100" name="<?= ep_h($k) ?>" class="form-control" value="<?= (int)$v ?>">
            </div>
        <?php endforeach; ?>
        <div class="col-12"><button class="btn btn-primary" type="submit">Save assessment</button></div>
    </form>
</div>
<?php if ($result && $result['ok']): $d = $result['data']; ?>
<div class="ep-card">
    <h2 class="h5"><?= ep_h((string)$d['readiness_level']) ?> — <?= ep_h((string)$d['total_score']) ?></h2>
    <ul><?php foreach ($d['recommendations'] as $rec): ?><li><?= ep_h($rec) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
