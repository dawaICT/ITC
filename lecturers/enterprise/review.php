<?php
declare(strict_types=1);

/**
 * Skills-to-Trade Hub — Lecturer full review & decision.
 */

$page_title = 'Review Item — Skills-to-Trade';
require_once __DIR__ . '/../includes/guard.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.review.lecturer');

$itemId = (int)($_GET['id'] ?? $_POST['item_id'] ?? 0);
$base = '/wucportal/lecturers/enterprise';

if ($itemId <= 0) {
    wuc_set_flash('error', 'Invalid item.');
    header('Location: ' . $base . '/review_queue.php');
    exit;
}

// Double-submit token
if (empty($_SESSION['eh_lecturer_form_token']) || !is_string($_SESSION['eh_lecturer_form_token'])) {
    $_SESSION['eh_lecturer_form_token'] = bin2hex(random_bytes(16));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        eh_require_post_csrf();
    } catch (Throwable $e) {
        wuc_set_flash('error', $e->getMessage());
        header('Location: ' . $base . '/review.php?id=' . $itemId);
        exit;
    }

    $postedFormToken = (string)($_POST['form_token'] ?? '');
    $sessionFormToken = (string)($_SESSION['eh_lecturer_form_token'] ?? '');
    if ($sessionFormToken === '' || !hash_equals($sessionFormToken, $postedFormToken)) {
        wuc_set_flash('error', 'This review was already submitted or the form expired. Refresh and try again.');
        header('Location: ' . $base . '/review.php?id=' . $itemId);
        exit;
    }
    // Consume token before processing (prevents double submit)
    unset($_SESSION['eh_lecturer_form_token']);

    $decision = (string)($_POST['decision'] ?? '');
    $comments = trim((string)($_POST['comments'] ?? ''));
    $allowedDecisions = ['request_changes', 'verify', 'reject'];
    if (!in_array($decision, $allowedDecisions, true)) {
        wuc_set_flash('error', 'Invalid decision.');
        header('Location: ' . $base . '/review.php?id=' . $itemId);
        exit;
    }

    if (in_array($decision, ['request_changes', 'reject'], true) && $comments === '') {
        $_SESSION['eh_lecturer_form_token'] = bin2hex(random_bytes(16));
        wuc_set_flash('error', 'Comments are required when requesting changes or rejecting.');
        header('Location: ' . $base . '/review.php?id=' . $itemId);
        exit;
    }

    $toStatus = eh_decision_to_status($decision);
    if ($toStatus === null) {
        wuc_set_flash('error', 'Unknown decision mapping.');
        header('Location: ' . $base . '/review.php?id=' . $itemId);
        exit;
    }

    $result = eh_transition_item($db, $itemId, $toStatus, $decision, $comments, 'lecturer');
    if ($result['success']) {
        wuc_set_flash('success', $result['message'] . ' Decision: ' . str_replace('_', ' ', $decision) . '.');
        if ($decision === 'verify') {
            header('Location: ' . $base . '/verified_items.php');
        } elseif ($decision === 'reject') {
            header('Location: ' . $base . '/rejected_items.php');
        } else {
            header('Location: ' . $base . '/review_queue.php');
        }
        exit;
    }

    $_SESSION['eh_lecturer_form_token'] = bin2hex(random_bytes(16));
    wuc_set_flash('error', $result['message']);
    header('Location: ' . $base . '/review.php?id=' . $itemId);
    exit;
}

$item = eh_get_item($db, $itemId);
if (!$item) {
    wuc_set_flash('error', 'Item not found.');
    header('Location: ' . $base . '/review_queue.php');
    exit;
}

$costs = eh_get_costs($db, $itemId);
$readiness = eh_get_readiness($db, $itemId);
$media = eh_list_media($db, $itemId);
$reviews = eh_list_reviews($db, $itemId);
$currency = (string)($item['currency'] ?? 'ZMW');
$canDecide = ((string)$item['status'] === 'submitted');
$formToken = (string)$_SESSION['eh_lecturer_form_token'];
$csrf = wuc_csrf_token();
$flash = function_exists('wuc_get_flash') ? wuc_get_flash() : null;

$checklist = [
    ['label' => 'Title, short and full descriptions are complete and clear', 'ok' => trim((string)$item['title']) !== '' && trim((string)($item['short_description'] ?? '')) !== '' && trim((string)($item['full_description'] ?? '')) !== ''],
    ['label' => 'Category and item type are appropriate', 'ok' => !empty($item['category_id']) && !empty($item['item_type'])],
    ['label' => 'At least one image is attached (primary preferred)', 'ok' => count($media) >= 1],
    ['label' => 'Cost & profit calculation is present', 'ok' => $costs !== null],
    ['label' => 'Business-readiness assessment is completed', 'ok' => $readiness !== null],
    ['label' => 'Investment ask (if any) has a stated purpose', 'ok' => empty($item['investment_required']) || trim((string)($item['investment_purpose'] ?? '')) !== ''],
    ['label' => 'Enterprise identity (business name + student ID) is identifiable', 'ok' => trim((string)($item['business_name'] ?? '')) !== ''],
];

$recommendations = [];
if ($readiness && !empty($readiness['recommendations_json'])) {
    $decoded = json_decode((string)$readiness['recommendations_json'], true);
    if (is_array($decoded)) {
        $recommendations = $decoded;
    }
}

require_once __DIR__ . '/../includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title"><?php echo eh_h((string)$item['title']); ?></h1>
                <p class="text-muted mb-0">
                    <code><?php echo eh_h((string)$item['public_code']); ?></code>
                    ·
                    <span class="badge bg-<?php echo eh_h(eh_status_badge_class((string)$item['status'])); ?>">
                        <?php echo eh_h(eh_status_label((string)$item['status'])); ?>
                    </span>
                </p>
            </div>
            <div class="col-auto d-flex gap-2">
                <a href="<?php echo eh_h($base); ?>/review_queue.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Queue</a>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <?php $alertType = ($flash['type'] ?? '') === 'error' ? 'danger' : (string)$flash['type']; ?>
        <div class="alert alert-<?php echo eh_h($alertType); ?> alert-dismissible fade show" role="alert">
            <?php echo eh_h((string)($flash['message'] ?? '')); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Student / enterprise identity</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Business name</div>
                            <div class="fw-semibold"><?php echo eh_h((string)($item['business_name'] ?? '')); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Student ID</div>
                            <div><code><?php echo eh_h((string)($item['student_id'] ?? '—')); ?></code></div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Profile type</div>
                            <div><?php echo eh_h(eh_profile_types()[(string)($item['profile_type'] ?? '')] ?? (string)($item['profile_type'] ?? '—')); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Programme</div>
                            <div><?php echo eh_h(trim((string)($item['programme_name'] ?? '') . ' ' . (string)($item['programme_code'] ?? '')) ?: '—'); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Province / district</div>
                            <div><?php echo eh_h(trim((string)($item['province'] ?? '') . ' / ' . (string)($item['district'] ?? ''), ' /') ?: '—'); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Public contact</div>
                            <div class="small"><?php echo eh_h((string)($item['public_email'] ?? '—')); ?><br><?php echo eh_h((string)($item['public_phone'] ?? '')); ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-box-open me-2"></i>Item details</h5></div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Type</div>
                            <div><?php echo eh_h(eh_item_types()[(string)$item['item_type']] ?? (string)$item['item_type']); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Category</div>
                            <div><?php echo eh_h((string)($item['category_name'] ?? '—')); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Capacity</div>
                            <div>
                                <?php
                                $cap = $item['current_capacity'] ?? null;
                                echo $cap !== null && $cap !== ''
                                    ? eh_h((string)$cap . ' / ' . (string)($item['capacity_period'] ?? ''))
                                    : '—';
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Short description</div>
                        <p class="mb-0"><?php echo nl2br(eh_h((string)($item['short_description'] ?? ''))); ?></p>
                    </div>
                    <div>
                        <div class="text-muted small">Full description</div>
                        <p class="mb-0"><?php echo nl2br(eh_h((string)($item['full_description'] ?? ''))); ?></p>
                    </div>
                </div>
            </section>

            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-images me-2"></i>Images</h5></div>
                <div class="card-body">
                    <?php if (!$media): ?>
                        <p class="text-muted mb-0">No images uploaded.</p>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($media as $m): ?>
                                <div class="col-6 col-md-4 col-lg-3">
                                    <div class="border rounded p-1 h-100">
                                        <img
                                            src="/wucportal/showcase/media.php?id=<?php echo (int)$m['id']; ?>"
                                            alt="<?php echo eh_h((string)($m['caption'] ?? $m['original_filename'] ?? 'Image')); ?>"
                                            class="img-fluid rounded"
                                            style="width:100%;height:140px;object-fit:cover;"
                                        >
                                        <div class="small px-1 pt-1">
                                            <?php if (!empty($m['is_primary'])): ?>
                                                <span class="badge bg-primary">Primary</span>
                                            <?php endif; ?>
                                            <?php echo eh_h((string)($m['caption'] ?? '')); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-calculator me-2"></i>Costs &amp; profit</h5></div>
                <div class="card-body">
                    <?php if (!$costs): ?>
                        <p class="text-muted mb-0">No cost calculation recorded.</p>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-md-3"><div class="text-muted small">Material</div><div><?php echo eh_h(eh_money($costs['material_cost'], $currency)); ?></div></div>
                            <div class="col-md-3"><div class="text-muted small">Labour</div><div><?php echo eh_h(eh_money($costs['labour_cost'], $currency)); ?></div></div>
                            <div class="col-md-3"><div class="text-muted small">Transport</div><div><?php echo eh_h(eh_money($costs['transport_cost'], $currency)); ?></div></div>
                            <div class="col-md-3"><div class="text-muted small">Other costs</div><div><?php echo eh_h(eh_money(
                                (float)$costs['utilities_cost'] + (float)$costs['packaging_cost'] + (float)$costs['marketing_cost'] + (float)$costs['other_cost'],
                                $currency
                            )); ?></div></div>
                            <div class="col-md-3"><div class="text-muted small">Units</div><div><?php echo (int)$costs['number_of_units']; ?></div></div>
                            <div class="col-md-3"><div class="text-muted small">Total cost</div><div class="fw-semibold"><?php echo eh_h(eh_money($costs['total_cost'], $currency)); ?></div></div>
                            <div class="col-md-3"><div class="text-muted small">Cost / unit</div><div><?php echo eh_h(eh_money($costs['cost_per_unit'], $currency)); ?></div></div>
                            <div class="col-md-3"><div class="text-muted small">Selling price</div><div><?php echo eh_h(eh_money($costs['selling_price'], $currency)); ?></div></div>
                            <div class="col-md-3"><div class="text-muted small">Profit / unit</div><div><?php echo eh_h(eh_money($costs['profit_per_unit'], $currency)); ?></div></div>
                            <div class="col-md-3"><div class="text-muted small">Expected revenue</div><div><?php echo eh_h(eh_money($costs['expected_revenue'], $currency)); ?></div></div>
                            <div class="col-md-3"><div class="text-muted small">Expected profit</div><div><?php echo eh_h(eh_money($costs['expected_profit'], $currency)); ?></div></div>
                            <div class="col-md-3"><div class="text-muted small">Margin</div><div><?php echo eh_h(number_format((float)$costs['profit_margin'], 2) . '%'); ?></div></div>
                        </div>
                        <p class="small text-muted mt-3 mb-0"><?php echo eh_h(eh_disclaimer_finance()); ?></p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Readiness</h5></div>
                <div class="card-body">
                    <?php if (!$readiness): ?>
                        <p class="text-muted mb-0">No readiness assessment recorded.</p>
                    <?php else: ?>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="display-6 fw-bold text-primary mb-0"><?php echo eh_h(number_format((float)$readiness['total_score'], 1)); ?></div>
                            <div>
                                <div class="fw-semibold"><?php echo eh_h((string)$readiness['readiness_level']); ?></div>
                                <div class="small text-muted">Assessed <?php echo eh_h((string)$readiness['assessed_at']); ?></div>
                            </div>
                        </div>
                        <div class="row g-2">
                            <?php
                            $scoreKeys = [
                                'product_score' => 'Product',
                                'market_score' => 'Market',
                                'costing_score' => 'Costing',
                                'capacity_score' => 'Capacity',
                                'compliance_score' => 'Compliance',
                                'team_score' => 'Team',
                            ];
                            foreach ($scoreKeys as $key => $label):
                            ?>
                                <div class="col-6 col-md-4">
                                    <div class="border rounded p-2">
                                        <div class="small text-muted"><?php echo eh_h($label); ?></div>
                                        <div class="fw-semibold"><?php echo (int)($readiness[$key] ?? 0); ?>/100</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($recommendations): ?>
                            <ul class="mt-3 mb-0">
                                <?php foreach ($recommendations as $rec): ?>
                                    <li><?php echo eh_h(is_string($rec) ? $rec : json_encode($rec)); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Investment</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Investment required</div>
                            <div class="fw-semibold">
                                <?php
                                echo isset($item['investment_required']) && $item['investment_required'] !== null && $item['investment_required'] !== ''
                                    ? eh_h(eh_money($item['investment_required'], $currency))
                                    : '—';
                                ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Expected capacity</div>
                            <div>
                                <?php
                                $ec = $item['expected_capacity'] ?? null;
                                echo $ec !== null && $ec !== ''
                                    ? eh_h((string)$ec . ' / ' . (string)($item['expected_capacity_period'] ?? ''))
                                    : '—';
                                ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Employment potential</div>
                            <div><?php echo isset($item['employment_potential']) && $item['employment_potential'] !== null ? (int)$item['employment_potential'] : '—'; ?></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">Purpose</div>
                            <p class="mb-0"><?php echo nl2br(eh_h((string)($item['investment_purpose'] ?? '—'))); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-history me-2"></i>Review history</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>When</th>
                                    <th>Stage</th>
                                    <th>Reviewer</th>
                                    <th>Decision</th>
                                    <th>From → To</th>
                                    <th>Comments</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$reviews): ?>
                                    <tr><td colspan="6" class="text-muted text-center py-3">No reviews yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($reviews as $rev): ?>
                                        <tr>
                                            <td class="small"><?php echo eh_h((string)$rev['reviewed_at']); ?></td>
                                            <td><?php echo eh_h((string)$rev['review_stage']); ?></td>
                                            <td><code><?php echo eh_h((string)$rev['reviewer_id']); ?></code></td>
                                            <td><?php echo eh_h(str_replace('_', ' ', (string)$rev['decision'])); ?></td>
                                            <td class="small">
                                                <?php echo eh_h(eh_status_label((string)$rev['previous_status'])); ?>
                                                →
                                                <?php echo eh_h(eh_status_label((string)$rev['resulting_status'])); ?>
                                            </td>
                                            <td class="small"><?php echo nl2br(eh_h((string)($rev['comments'] ?? ''))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-4">
            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Review checklist</h5></div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($checklist as $c): ?>
                            <li class="d-flex align-items-start gap-2 mb-2">
                                <?php if ($c['ok']): ?>
                                    <i class="fas fa-check-circle text-success mt-1"></i>
                                <?php else: ?>
                                    <i class="fas fa-exclamation-circle text-warning mt-1"></i>
                                <?php endif; ?>
                                <span><?php echo eh_h($c['label']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>

            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-gavel me-2"></i>Decision</h5></div>
                <div class="card-body">
                    <?php if (!$canDecide): ?>
                        <div class="alert alert-info mb-0">
                            This item is currently <strong><?php echo eh_h(eh_status_label((string)$item['status'])); ?></strong>
                            and cannot be decided from the lecturer review queue.
                        </div>
                    <?php else: ?>
                        <form method="post" action="<?php echo eh_h($base); ?>/review.php?id=<?php echo (int)$itemId; ?>" class="needs-validation" novalidate id="ehLecturerDecisionForm">
                            <input type="hidden" name="csrf_token" value="<?php echo eh_h($csrf); ?>">
                            <input type="hidden" name="form_token" value="<?php echo eh_h($formToken); ?>">
                            <input type="hidden" name="item_id" value="<?php echo (int)$itemId; ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="decision">Decision</label>
                                <select class="form-select" name="decision" id="decision" required>
                                    <option value="">Select…</option>
                                    <option value="verify">Verify (lecturer verified)</option>
                                    <option value="request_changes">Request changes</option>
                                    <option value="reject">Reject</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="comments">Comments</label>
                                <textarea class="form-control" name="comments" id="comments" rows="5" maxlength="4000" placeholder="Required for request changes and reject"></textarea>
                                <div class="form-text">Mandatory when requesting changes or rejecting.</div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100" id="ehSubmitBtn">
                                <i class="fas fa-paper-plane me-2"></i>Submit decision
                            </button>
                        </form>
                        <script>
                        (function () {
                            var form = document.getElementById('ehLecturerDecisionForm');
                            if (!form) return;
                            form.addEventListener('submit', function (e) {
                                var decision = document.getElementById('decision').value;
                                var comments = (document.getElementById('comments').value || '').trim();
                                if ((decision === 'request_changes' || decision === 'reject') && comments === '') {
                                    e.preventDefault();
                                    alert('Comments are required for this decision.');
                                    return;
                                }
                                var btn = document.getElementById('ehSubmitBtn');
                                if (btn) {
                                    btn.disabled = true;
                                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting…';
                                }
                            });
                        })();
                        </script>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
