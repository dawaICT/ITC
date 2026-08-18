<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/legacy_academic_guard.php';
ep_redirect_certificate_from_legacy_skills_hub($db, (string)($_SESSION['Sid'] ?? ''));
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.item.edit_own');

$base = '/wucportal/students/enterprise';
$itemId = (int)($_GET['item_id'] ?? $_POST['item_id'] ?? 0);
if ($itemId <= 0) {
    wuc_set_flash('error', 'Select an item first.');
    header('Location: ' . $base . '/items.php');
    exit;
}

$item = eh_get_item($db, $itemId);
if (!$item) {
    wuc_set_flash('error', 'Item not found.');
    header('Location: ' . $base . '/items.php');
    exit;
}

try {
    eh_assert_owns_item($db, $item);
} catch (Throwable $e) {
    wuc_set_flash('error', $e->getMessage());
    header('Location: ' . $base . '/items.php');
    exit;
}

$editable = in_array((string)$item['status'], ['draft', 'changes_requested'], true);
$existing = eh_get_readiness($db, $itemId);
$errors = [];
$saved = null;

$scoreFields = [
    'product_score' => 'Product / service quality',
    'market_score' => 'Market demand understanding',
    'costing_score' => 'Costing accuracy',
    'capacity_score' => 'Production / delivery capacity',
    'compliance_score' => 'Compliance & registration',
    'team_score' => 'Team / skills readiness',
];

$form = [];
foreach (array_keys($scoreFields) as $key) {
    $form[$key] = (string)($existing[$key] ?? '50');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        eh_require_post_csrf();
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    if ($errors === []) {
        if (!$editable) {
            $errors[] = 'Readiness can only be updated while the item is a draft or changes were requested.';
        } else {
            foreach (array_keys($form) as $key) {
                $form[$key] = trim((string)($_POST[$key] ?? ''));
            }
            $result = eh_save_readiness($db, $itemId, $form, eh_current_actor_id());
            if (empty($result['ok'])) {
                $errors = array_merge($errors, $result['errors'] ?? ['Could not save readiness assessment.']);
            } else {
                wuc_set_flash('success', 'Readiness assessment saved: ' . (string)$result['data']['readiness_level']);
                header('Location: ' . $base . '/readiness_assessment.php?item_id=' . $itemId);
                exit;
            }
        }
    }
}

$existing = eh_get_readiness($db, $itemId);
$weights = eh_readiness_weights();
$liveTotal = 0.0;
foreach ($weights as $key => $weight) {
    $liveTotal += ((int)$form[$key]) * $weight;
}
$liveTotal = round($liveTotal, 2);
$liveLevel = eh_readiness_level_from_score($liveTotal);
$recommendations = $existing
    ? (json_decode((string)($existing['recommendations_json'] ?? '[]'), true) ?: [])
    : eh_readiness_recommendations(array_map('intval', $form));

$pageTitle = 'Readiness assessment';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main class="content-wrapper portal-dashboard eh-hub pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4">
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title mb-1"><i class="fas fa-clipboard-check me-2 text-primary"></i>Business readiness assessment</h1>
                <p class="eh-page-lead mb-0"><?php echo eh_h((string)$item['title']); ?> — score each area from 0 to 100.</p>
            </div>
            <div class="col-auto">
                <div class="eh-toolbar">
                    <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/item_view.php?id=<?php echo (int)$itemId; ?>">Back to item</a>
                    <a class="btn btn-outline-primary" href="<?php echo eh_h($base); ?>/cost_calculator.php?item_id=<?php echo (int)$itemId; ?>">Costs</a>
                </div>
            </div>
        </div>
    </div>

    <?php
    $doneKeysReady = ['profile', 'item'];
    if (eh_count_item_media($db, $itemId) > 0) {
        $doneKeysReady[] = 'media';
    }
    if (eh_get_costs($db, $itemId)) {
        $doneKeysReady[] = 'costs';
    }
    if ($existing) {
        $doneKeysReady[] = 'readiness';
    }
    eh_render_workflow_stepper($base, 'readiness', $itemId, $doneKeysReady);
    ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach (array_unique($errors) as $error): ?>
                <div><?php echo eh_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0">Score each area (0–100)</h5></div>
                <div class="card-body">
                    <form method="post" id="ehReadinessForm" action="<?php echo eh_h($base); ?>/readiness_assessment.php?item_id=<?php echo (int)$itemId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo eh_h(wuc_csrf_token()); ?>">
                        <input type="hidden" name="item_id" value="<?php echo (int)$itemId; ?>">
                        <fieldset <?php echo $editable ? '' : 'disabled'; ?>>
                            <div class="row g-3">
                                <?php foreach ($scoreFields as $name => $label): ?>
                                    <div class="col-md-6">
                                        <label class="form-label" for="<?php echo eh_h($name); ?>">
                                            <?php echo eh_h($label); ?>
                                            <span class="text-muted small">(weight <?php echo eh_h((string)round($weights[$name] * 100)); ?>%)</span>
                                        </label>
                                        <input class="form-control eh-score-input" type="number" min="0" max="100" step="1"
                                               id="<?php echo eh_h($name); ?>" name="<?php echo eh_h($name); ?>"
                                               value="<?php echo eh_h($form[$name]); ?>" required
                                               data-weight="<?php echo eh_h((string)$weights[$name]); ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($editable): ?>
                                <div class="mt-3">
                                    <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Save assessment</button>
                                </div>
                            <?php endif; ?>
                        </fieldset>
                    </form>
                </div>
            </section>
        </div>
        <div class="col-lg-4">
            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0">Result</h5></div>
                <div class="card-body">
                    <div class="display-6 mb-1" id="rd_total"><?php echo eh_h(number_format($liveTotal, 2)); ?></div>
                    <div class="mb-3" id="rd_level"><?php echo eh_h($liveLevel); ?></div>
                    <?php if ($existing): ?>
                        <p class="small text-muted mb-0">Last saved <?php echo eh_h((string)$existing['assessed_at']); ?></p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0">Recommendations</h5></div>
                <div class="card-body">
                    <ul class="mb-0" id="rd_recs">
                        <?php foreach ($recommendations as $rec): ?>
                            <li><?php echo eh_h((string)$rec); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        </div>
    </div>
</div>
<script>
(function () {
    var weights = {
        product_score: 0.20,
        market_score: 0.20,
        costing_score: 0.20,
        capacity_score: 0.15,
        compliance_score: 0.15,
        team_score: 0.10
    };
    function levelFrom(score) {
        if (score < 40) return 'Early Stage';
        if (score < 60) return 'Development Required';
        if (score < 75) return 'Market Ready';
        if (score < 90) return 'Investment Preparation';
        return 'Strong Investment Readiness';
    }
    function refresh() {
        var total = 0;
        Object.keys(weights).forEach(function (key) {
            var el = document.getElementById(key);
            var v = el ? parseInt(el.value, 10) : 0;
            if (!isFinite(v)) v = 0;
            v = Math.max(0, Math.min(100, v));
            total += v * weights[key];
        });
        total = Math.round(total * 100) / 100;
        document.getElementById('rd_total').textContent = total.toFixed(2);
        document.getElementById('rd_level').textContent = levelFrom(total);
    }
    document.querySelectorAll('.eh-score-input').forEach(function (el) {
        el.addEventListener('input', refresh);
        el.addEventListener('change', refresh);
    });
    refresh();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
