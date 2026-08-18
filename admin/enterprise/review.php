<?php
declare(strict_types=1);

/**
 * Skills-to-Trade Hub — Admin review / approve / publish / feature.
 */

$page_title = 'Admin Review — Skills-to-Trade';
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.approve');

$itemId = (int)($_GET['id'] ?? $_POST['item_id'] ?? 0);
$base = '/wucportal/admin/enterprise';

if ($itemId <= 0) {
    wuc_set_flash('error', 'Invalid item.');
    header('Location: ' . $base . '/pending_approvals.php');
    exit;
}

if (empty($_SESSION['eh_admin_form_token']) || !is_string($_SESSION['eh_admin_form_token'])) {
    $_SESSION['eh_admin_form_token'] = bin2hex(random_bytes(16));
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
    $sessionFormToken = (string)($_SESSION['eh_admin_form_token'] ?? '');
    if ($sessionFormToken === '' || !hash_equals($sessionFormToken, $postedFormToken)) {
        wuc_set_flash('error', 'This action was already submitted or the form expired. Refresh and try again.');
        header('Location: ' . $base . '/review.php?id=' . $itemId);
        exit;
    }
    unset($_SESSION['eh_admin_form_token']);

    $action = (string)($_POST['action'] ?? '');
    $comments = trim((string)($_POST['comments'] ?? ''));

    if ($action === 'toggle_featured') {
        if (!eh_can($db, 'enterprise.publish')) {
            wuc_set_flash('error', 'Permission denied for feature toggle.');
            header('Location: ' . $base . '/review.php?id=' . $itemId);
            exit;
        }
        $featured = !empty($_POST['is_featured']) ? 1 : 0;
        $stmt = $db->prepare('UPDATE enterprise_items SET is_featured = ? WHERE id = ?');
        $stmt->bind_param('ii', $featured, $itemId);
        $stmt->execute();
        $stmt->close();
        eh_audit($db, 'enterprise_hub.feature_toggled', ['item_id' => $itemId, 'is_featured' => $featured]);
        $_SESSION['eh_admin_form_token'] = bin2hex(random_bytes(16));
        wuc_set_flash('success', $featured ? 'Item marked as featured.' : 'Featured flag removed.');
        header('Location: ' . $base . '/review.php?id=' . $itemId);
        exit;
    }

    $decisionMap = [
        'approve' => 'approve',
        'reject' => 'reject',
        'publish' => 'publish',
        'unpublish' => 'unpublish',
    ];
    if (!isset($decisionMap[$action])) {
        $_SESSION['eh_admin_form_token'] = bin2hex(random_bytes(16));
        wuc_set_flash('error', 'Invalid action.');
        header('Location: ' . $base . '/review.php?id=' . $itemId);
        exit;
    }

    $decision = $decisionMap[$action];
    if ($decision === 'reject' && $comments === '') {
        $_SESSION['eh_admin_form_token'] = bin2hex(random_bytes(16));
        wuc_set_flash('error', 'Comments are required when rejecting.');
        header('Location: ' . $base . '/review.php?id=' . $itemId);
        exit;
    }

    $toStatus = eh_decision_to_status($decision);
    if ($toStatus === null) {
        $_SESSION['eh_admin_form_token'] = bin2hex(random_bytes(16));
        wuc_set_flash('error', 'Unknown decision mapping.');
        header('Location: ' . $base . '/review.php?id=' . $itemId);
        exit;
    }

    $result = eh_transition_item($db, $itemId, $toStatus, $decision, $comments, 'administration');
    if ($result['success']) {
        wuc_set_flash('success', $result['message']);
        if ($decision === 'approve') {
            header('Location: ' . $base . '/review.php?id=' . $itemId);
        } elseif ($decision === 'publish' || $decision === 'unpublish') {
            header('Location: ' . $base . '/published_items.php');
        } elseif ($decision === 'reject') {
            header('Location: ' . $base . '/pending_approvals.php');
        } else {
            header('Location: ' . $base . '/review.php?id=' . $itemId);
        }
        exit;
    }

    $_SESSION['eh_admin_form_token'] = bin2hex(random_bytes(16));
    wuc_set_flash('error', $result['message']);
    header('Location: ' . $base . '/review.php?id=' . $itemId);
    exit;
}

$item = eh_get_item($db, $itemId);
if (!$item) {
    wuc_set_flash('error', 'Item not found.');
    header('Location: ' . $base . '/pending_approvals.php');
    exit;
}

$costs = eh_get_costs($db, $itemId);
$readiness = eh_get_readiness($db, $itemId);
$media = eh_list_media($db, $itemId);
$reviews = eh_list_reviews($db, $itemId);
$status = (string)$item['status'];
$currency = (string)($item['currency'] ?? 'ZMW');
$formToken = (string)$_SESSION['eh_admin_form_token'];
$csrf = wuc_csrf_token();
$flash = function_exists('wuc_get_flash') ? wuc_get_flash() : null;

$canApprove = $status === 'lecturer_verified' && eh_can($db, 'enterprise.approve');
$canReject = in_array($status, ['lecturer_verified', 'submitted'], true) && eh_can($db, 'enterprise.review.reject');
$canPublish = $status === 'approved' && eh_can($db, 'enterprise.publish');
$canUnpublish = $status === 'published' && eh_can($db, 'enterprise.unpublish');
$canFeature = in_array($status, ['published', 'approved'], true) && eh_can($db, 'enterprise.publish');

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
                    <span class="badge bg-<?php echo eh_h(eh_status_badge_class($status)); ?>"><?php echo eh_h(eh_status_label($status)); ?></span>
                    <?php if (!empty($item['is_featured'])): ?>
                        <span class="badge bg-warning text-dark">Featured</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-auto d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/pending_approvals.php"><i class="fas fa-arrow-left me-1"></i>Back</a>
                <a class="btn btn-outline-primary" href="<?php echo eh_h($base); ?>/qr_label.php?id=<?php echo (int)$itemId; ?>" target="_blank" rel="noopener">
                    <i class="fas fa-qrcode me-1"></i>QR label
                </a>
                <?php if ($status === 'published'): ?>
                    <a class="btn btn-outline-success" href="<?php echo eh_h(eh_public_item_url((string)$item['public_code'])); ?>" target="_blank" rel="noopener">Public page</a>
                <?php endif; ?>
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
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-building me-2"></i>Enterprise</h5></div>
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
                            <div class="text-muted small">Programme</div>
                            <div class="small"><?php echo eh_h((string)($item['programme_name'] ?? '—')); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Type</div>
                            <div><?php echo eh_h(eh_item_types()[(string)$item['item_type']] ?? (string)$item['item_type']); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Category</div>
                            <div><?php echo eh_h((string)($item['category_name'] ?? '—')); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Location</div>
                            <div><?php echo eh_h(trim((string)($item['province'] ?? '') . ' / ' . (string)($item['district'] ?? ''), ' /') ?: '—'); ?></div>
                        </div>
                    </div>
                    <hr>
                    <div class="mb-2"><div class="text-muted small">Short description</div><p class="mb-0"><?php echo nl2br(eh_h((string)($item['short_description'] ?? ''))); ?></p></div>
                    <div><div class="text-muted small">Full description</div><p class="mb-0"><?php echo nl2br(eh_h((string)($item['full_description'] ?? ''))); ?></p></div>
                </div>
            </section>

            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-images me-2"></i>Images</h5></div>
                <div class="card-body">
                    <?php if (!$media): ?>
                        <p class="text-muted mb-0">No images.</p>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($media as $m): ?>
                                <div class="col-6 col-md-3">
                                    <img
                                        src="/wucportal/showcase/media.php?id=<?php echo (int)$m['id']; ?>"
                                        alt="<?php echo eh_h((string)($m['caption'] ?? 'Image')); ?>"
                                        class="img-fluid rounded border"
                                        style="width:100%;height:120px;object-fit:cover;"
                                    >
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <section class="data-table-card h-100">
                        <div class="card-header"><h5 class="mb-0"><i class="fas fa-calculator me-2"></i>Costs</h5></div>
                        <div class="card-body">
                            <?php if (!$costs): ?>
                                <p class="text-muted mb-0">No cost data.</p>
                            <?php else: ?>
                                <div class="small mb-1">Total: <strong><?php echo eh_h(eh_money($costs['total_cost'], $currency)); ?></strong></div>
                                <div class="small mb-1">Cost/unit: <?php echo eh_h(eh_money($costs['cost_per_unit'], $currency)); ?></div>
                                <div class="small mb-1">Selling: <?php echo eh_h(eh_money($costs['selling_price'], $currency)); ?></div>
                                <div class="small mb-1">Profit: <?php echo eh_h(eh_money($costs['expected_profit'], $currency)); ?> (<?php echo eh_h(number_format((float)$costs['profit_margin'], 1)); ?>%)</div>
                                <p class="small text-muted mb-0 mt-2"><?php echo eh_h(eh_disclaimer_finance()); ?></p>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
                <div class="col-md-6">
                    <section class="data-table-card h-100">
                        <div class="card-header"><h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Readiness &amp; investment</h5></div>
                        <div class="card-body">
                            <?php if ($readiness): ?>
                                <div class="mb-2"><strong><?php echo eh_h(number_format((float)$readiness['total_score'], 1)); ?></strong> — <?php echo eh_h((string)$readiness['readiness_level']); ?></div>
                            <?php else: ?>
                                <div class="text-muted mb-2">No readiness score.</div>
                            <?php endif; ?>
                            <div class="small mb-1">Investment:
                                <?php
                                echo isset($item['investment_required']) && $item['investment_required'] !== null && $item['investment_required'] !== ''
                                    ? eh_h(eh_money($item['investment_required'], $currency))
                                    : '—';
                                ?>
                            </div>
                            <div class="small mb-1">Jobs: <?php echo isset($item['employment_potential']) && $item['employment_potential'] !== null ? (int)$item['employment_potential'] : '—'; ?></div>
                            <div class="small"><?php echo nl2br(eh_h((string)($item['investment_purpose'] ?? ''))); ?></div>
                        </div>
                    </section>
                </div>
            </div>

            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-history me-2"></i>Review history</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>When</th><th>Stage</th><th>Decision</th><th>Comments</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!$reviews): ?>
                                    <tr><td colspan="4" class="text-muted text-center py-3">No reviews.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($reviews as $rev): ?>
                                        <tr>
                                            <td class="small"><?php echo eh_h((string)$rev['reviewed_at']); ?></td>
                                            <td><?php echo eh_h((string)$rev['review_stage']); ?></td>
                                            <td><?php echo eh_h(str_replace('_', ' ', (string)$rev['decision'])); ?></td>
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
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-gavel me-2"></i>Admin actions</h5></div>
                <div class="card-body">
                    <?php if (!$canApprove && !$canReject && !$canPublish && !$canUnpublish && !$canFeature): ?>
                        <div class="alert alert-info mb-0">No actions available for status <strong><?php echo eh_h(eh_status_label($status)); ?></strong>.</div>
                    <?php else: ?>
                        <?php if ($canApprove || $canReject || $canPublish || $canUnpublish): ?>
                            <form method="post" action="<?php echo eh_h($base); ?>/review.php?id=<?php echo (int)$itemId; ?>" id="ehAdminDecisionForm" class="mb-3">
                                <input type="hidden" name="csrf_token" value="<?php echo eh_h($csrf); ?>">
                                <input type="hidden" name="form_token" value="<?php echo eh_h($formToken); ?>">
                                <input type="hidden" name="item_id" value="<?php echo (int)$itemId; ?>">

                                <div class="mb-3">
                                    <label class="form-label" for="comments">Comments</label>
                                    <textarea class="form-control" name="comments" id="comments" rows="4" maxlength="4000" placeholder="Required for reject"></textarea>
                                </div>

                                <div class="d-grid gap-2">
                                    <?php if ($canApprove): ?>
                                        <button type="submit" name="action" value="approve" class="btn btn-success" onclick="return ehConfirmAction(this);">
                                            <i class="fas fa-check me-1"></i>Approve
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($canPublish): ?>
                                        <button type="submit" name="action" value="publish" class="btn btn-primary" onclick="return ehConfirmAction(this);">
                                            <i class="fas fa-globe me-1"></i>Publish
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($canUnpublish): ?>
                                        <button type="submit" name="action" value="unpublish" class="btn btn-outline-dark" onclick="return ehConfirmAction(this);">
                                            <i class="fas fa-eye-slash me-1"></i>Unpublish
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($canReject): ?>
                                        <button type="submit" name="action" value="reject" class="btn btn-outline-danger" onclick="return ehConfirmReject(this);">
                                            <i class="fas fa-times me-1"></i>Reject
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endif; ?>

                        <?php if ($canFeature): ?>
                            <form method="post" action="<?php echo eh_h($base); ?>/review.php?id=<?php echo (int)$itemId; ?>" class="border-top pt-3">
                                <input type="hidden" name="csrf_token" value="<?php echo eh_h($csrf); ?>">
                                <input type="hidden" name="form_token" value="<?php echo eh_h($formToken); ?>">
                                <input type="hidden" name="item_id" value="<?php echo (int)$itemId; ?>">
                                <input type="hidden" name="action" value="toggle_featured">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="is_featured" name="is_featured" value="1" <?php echo !empty($item['is_featured']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_featured">Featured on showcase</label>
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-warning w-100">Save featured flag</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
function ehConfirmAction(btn) {
    btn.disabled = true;
    var form = btn.form;
    if (form) {
        Array.prototype.forEach.call(form.querySelectorAll('button[type=submit]'), function (b) { b.disabled = true; });
    }
    return true;
}
function ehConfirmReject(btn) {
    var comments = (document.getElementById('comments') || {}).value || '';
    if (!String(comments).trim()) {
        alert('Comments are required when rejecting.');
        return false;
    }
    if (!confirm('Reject this enterprise item?')) return false;
    return ehConfirmAction(btn);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
