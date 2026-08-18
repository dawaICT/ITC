<?php
declare(strict_types=1);

$page_title = 'Outcomes';
$activeNav = 'mgmt_out';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.outcomes.manage')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    header('Location: /wucportal/enterprise/management/index.php');
    exit;
}

$base = '/wucportal/enterprise/management';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . $base . '/outcomes.php');
        exit;
    }
    $data = [
        'enterprise_interest_id' => (int)($_POST['enterprise_interest_id'] ?? 0) ?: null,
        'enterprise_opportunity_id' => (int)($_POST['enterprise_opportunity_id'] ?? 0) ?: null,
        'outcome_type' => trim((string)($_POST['outcome_type'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'estimated_value' => $_POST['estimated_value'] ?? '',
        'jobs_created' => $_POST['jobs_created'] ?? '',
    ];
    $result = ep_record_outcome($db, $data);
    $_SESSION[!empty($result['ok']) ? 'flash_success' : 'flash_error'] = (string)($result['message'] ?? 'Saved.');
    header('Location: ' . $base . '/outcomes.php');
    exit;
}

$outcomes = ep_list_outcomes($db, 100);
$types = ep_outcome_types();

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="ep-card">
            <h2 class="h6">Record outcome</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                <div class="mb-2">
                    <label class="form-label" for="enterprise_interest_id">Interest ID (optional)</label>
                    <input type="number" class="form-control" name="enterprise_interest_id" id="enterprise_interest_id" min="0">
                </div>
                <div class="mb-2">
                    <label class="form-label" for="enterprise_opportunity_id">Opportunity ID (optional)</label>
                    <input type="number" class="form-control" name="enterprise_opportunity_id" id="enterprise_opportunity_id" min="0">
                </div>
                <div class="mb-2">
                    <label class="form-label" for="outcome_type">Type</label>
                    <select name="outcome_type" id="outcome_type" class="form-select" required>
                        <?php foreach ($types as $k => $label): ?>
                            <option value="<?= ep_h($k) ?>"><?= ep_h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="estimated_value">Estimated value</label>
                    <input type="number" step="0.01" class="form-control" name="estimated_value" id="estimated_value">
                </div>
                <div class="mb-2">
                    <label class="form-label" for="jobs_created">Jobs created</label>
                    <input type="number" class="form-control" name="jobs_created" id="jobs_created" min="0">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="description">Description</label>
                    <textarea class="form-control" name="description" id="description" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100">Record</button>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="ep-card">
            <h2 class="h6 mb-3">Recent outcomes</h2>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>When</th><th>Type</th><th>Value</th><th>Stage</th></tr></thead>
                    <tbody>
                    <?php if ($outcomes === []): ?>
                        <tr><td colspan="4" class="ep-muted text-center py-3">None yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($outcomes as $o): ?>
                            <tr>
                                <td class="small"><?= ep_h((string)($o['recorded_at'] ?? '')) ?></td>
                                <td><?= ep_h($types[(string)$o['outcome_type']] ?? (string)$o['outcome_type']) ?></td>
                                <td><?= isset($o['estimated_value']) && $o['estimated_value'] !== null ? ep_h(ep_money($o['estimated_value'], (string)($o['currency'] ?? 'ZMW'))) : '—' ?></td>
                                <td><?= ep_h(ep_status_label((string)($o['outcome_stage'] ?? ''))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
