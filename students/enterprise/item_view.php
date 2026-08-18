<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/legacy_academic_guard.php';
ep_redirect_certificate_from_legacy_skills_hub($db, (string)($_SESSION['Sid'] ?? ''));
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.item.submit');

$base = '/wucportal/students/enterprise';
$itemId = (int)($_GET['id'] ?? $_POST['item_id'] ?? 0);
if ($itemId <= 0) {
    wuc_set_flash('error', 'Item not found.');
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

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        eh_require_post_csrf();
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    $action = (string)($_POST['action'] ?? '');
    if ($errors === [] && $action === 'submit') {
        $result = eh_transition_item($db, $itemId, 'submitted', 'submit', 'Student submitted for lecturer review.', 'workflow');
        if (!empty($result['success'])) {
            wuc_set_flash('success', 'Item submitted for lecturer review.');
            header('Location: ' . $base . '/item_view.php?id=' . $itemId);
            exit;
        }
        $errors[] = (string)($result['message'] ?? 'Submission failed.');
    }
}

$item = eh_get_item($db, $itemId) ?? $item;
$media = eh_list_media($db, $itemId);
$costs = eh_get_costs($db, $itemId);
$readiness = eh_get_readiness($db, $itemId);
$reviews = eh_list_reviews($db, $itemId);
$submissionErrors = eh_item_submission_errors($db, $itemId);
$itemTypes = eh_item_types();
$status = (string)$item['status'];
$canSubmit = in_array($status, ['draft', 'changes_requested'], true);
$editable = $canSubmit;

$pageTitle = 'View item';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main class="content-wrapper portal-dashboard eh-hub pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4">
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title mb-1"><?php echo eh_h((string)$item['title']); ?></h1>
                <p class="text-muted mb-0">
                    <?php echo eh_h((string)$item['public_code']); ?>
                    · <?php echo eh_h($itemTypes[(string)$item['item_type']] ?? (string)$item['item_type']); ?>
                    <span class="badge bg-<?php echo eh_h(eh_status_badge_class($status)); ?> ms-2">
                        <?php echo eh_h(eh_status_label($status)); ?>
                    </span>
                </p>
            </div>
            <div class="col-auto d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/items.php">All items</a>
                <?php if ($editable): ?>
                    <a class="btn btn-outline-primary" href="<?php echo eh_h($base); ?>/item_edit.php?id=<?php echo (int)$itemId; ?>">Edit</a>
                <?php endif; ?>
                <?php if ($status === 'published'): ?>
                    <a class="btn btn-outline-success" target="_blank" rel="noopener" href="<?php echo eh_h(eh_public_item_url((string)$item['public_code'])); ?>">Public page</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach (array_unique($errors) as $error): ?>
                <div><?php echo eh_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0">Overview</h5></div>
                <div class="card-body">
                    <p class="fw-semibold"><?php echo eh_h((string)($item['short_description'] ?? '')); ?></p>
                    <div class="mb-3"><?php echo nl2br(eh_h((string)($item['full_description'] ?? ''))); ?></div>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Enterprise</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($item['business_name'] ?? '')); ?></dd>
                        <dt class="col-sm-4">Category</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($item['category_name'] ?? '—')); ?></dd>
                        <dt class="col-sm-4">Unit price</dt>
                        <dd class="col-sm-8"><?php echo $item['unit_price'] !== null ? eh_h(eh_money($item['unit_price'], (string)$item['currency'])) : '—'; ?></dd>
                        <dt class="col-sm-4">Current capacity</dt>
                        <dd class="col-sm-8">
                            <?php
                            echo $item['current_capacity'] !== null
                                ? eh_h((string)$item['current_capacity'] . ' ' . (string)($item['capacity_period'] ?? ''))
                                : '—';
                            ?>
                        </dd>
                        <dt class="col-sm-4">Investment required</dt>
                        <dd class="col-sm-8">
                            <?php echo $item['investment_required'] !== null ? eh_h(eh_money($item['investment_required'], (string)$item['currency'])) : '—'; ?>
                        </dd>
                        <dt class="col-sm-4">Investment purpose</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($item['investment_purpose'] ?? '—')); ?></dd>
                        <dt class="col-sm-4">Employment potential</dt>
                        <dd class="col-sm-8"><?php echo $item['employment_potential'] !== null ? (int)$item['employment_potential'] : '—'; ?></dd>
                        <dt class="col-sm-4">Readiness</dt>
                        <dd class="col-sm-8">
                            <?php
                            if ($item['readiness_score'] !== null) {
                                echo eh_h((string)$item['readiness_score'] . ' — ' . (string)($item['readiness_level'] ?? ''));
                            } else {
                                echo '—';
                            }
                            ?>
                        </dd>
                    </dl>
                </div>
            </section>

            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-images me-2"></i>Images</h5></div>
                <div class="card-body">
                    <?php if ($media === []): ?>
                        <p class="text-muted mb-0">No images uploaded.</p>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($media as $m): ?>
                                <div class="col-md-4">
                                    <img class="img-fluid rounded border" alt="<?php echo eh_h((string)$m['caption']); ?>"
                                         src="/wucportal/showcase/media.php?id=<?php echo (int)$m['id']; ?>">
                                    <?php if ((int)$m['is_primary'] === 1): ?>
                                        <div class="small mt-1"><span class="badge bg-success">Primary</span></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-calculator me-2"></i>Costs summary</h5></div>
                <div class="card-body">
                    <?php if (!$costs): ?>
                        <p class="text-muted mb-2">Cost calculation not completed.</p>
                        <?php if ($editable): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?php echo eh_h($base); ?>/cost_calculator.php?item_id=<?php echo (int)$itemId; ?>">Open calculator</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="row g-2">
                            <div class="col-md-4"><strong>Total cost:</strong> <?php echo eh_h(eh_money($costs['total_cost'])); ?></div>
                            <div class="col-md-4"><strong>Cost / unit:</strong> <?php echo eh_h(eh_money($costs['cost_per_unit'])); ?></div>
                            <div class="col-md-4"><strong>Selling price:</strong> <?php echo eh_h(eh_money($costs['selling_price'])); ?></div>
                            <div class="col-md-4"><strong>Profit / unit:</strong> <?php echo eh_h(eh_money($costs['profit_per_unit'])); ?></div>
                            <div class="col-md-4"><strong>Expected profit:</strong> <?php echo eh_h(eh_money($costs['expected_profit'])); ?></div>
                            <div class="col-md-4"><strong>Margin:</strong> <?php echo eh_h(number_format((float)$costs['profit_margin'], 2)); ?>%</div>
                        </div>
                        <p class="small text-muted mt-3 mb-0"><?php echo eh_h(eh_disclaimer_finance()); ?></p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-history me-2"></i>Review history</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                            <tr>
                                <th>When</th>
                                <th>Stage</th>
                                <th>Decision</th>
                                <th>From → To</th>
                                <th>Comments</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($reviews === []): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No reviews yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reviews as $review): ?>
                                    <tr>
                                        <td class="small"><?php echo eh_h((string)$review['reviewed_at']); ?></td>
                                        <td><?php echo eh_h((string)$review['review_stage']); ?></td>
                                        <td><?php echo eh_h(ucwords(str_replace('_', ' ', (string)$review['decision']))); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo eh_h(eh_status_badge_class((string)$review['previous_status'])); ?>">
                                                <?php echo eh_h(eh_status_label((string)$review['previous_status'])); ?>
                                            </span>
                                            →
                                            <span class="badge bg-<?php echo eh_h(eh_status_badge_class((string)$review['resulting_status'])); ?>">
                                                <?php echo eh_h(eh_status_label((string)$review['resulting_status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo nl2br(eh_h((string)($review['comments'] ?? ''))); ?></td>
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
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Submission checklist</h5></div>
                <div class="card-body">
                    <?php if ($submissionErrors === []): ?>
                        <div class="alert alert-success mb-3">Ready to submit.</div>
                    <?php else: ?>
                        <ul class="mb-3">
                            <?php foreach ($submissionErrors as $err): ?>
                                <li><?php echo eh_h($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($canSubmit): ?>
                        <form method="post" action="<?php echo eh_h($base); ?>/item_view.php?id=<?php echo (int)$itemId; ?>"
                              onsubmit="return confirm('Submit this item for lecturer review?');">
                            <input type="hidden" name="csrf_token" value="<?php echo eh_h(wuc_csrf_token()); ?>">
                            <input type="hidden" name="action" value="submit">
                            <input type="hidden" name="item_id" value="<?php echo (int)$itemId; ?>">
                            <button class="btn btn-primary w-100" type="submit" <?php echo $submissionErrors !== [] ? 'disabled' : ''; ?>>
                                <i class="fas fa-paper-plane me-1"></i>Submit for review
                            </button>
                        </form>
                    <?php elseif ($status === 'submitted'): ?>
                        <div class="alert alert-info mb-0">Submitted — awaiting lecturer review.</div>
                    <?php elseif ($status === 'published'): ?>
                        <div class="alert alert-success mb-0">Published on the public showcase.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0">Readiness</h5></div>
                <div class="card-body">
                    <?php if (!$readiness): ?>
                        <p class="text-muted mb-2">Not assessed yet.</p>
                        <?php if ($editable): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?php echo eh_h($base); ?>/readiness_assessment.php?item_id=<?php echo (int)$itemId; ?>">Assess now</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="mb-1"><strong><?php echo eh_h(number_format((float)$readiness['total_score'], 2)); ?></strong>
                            — <?php echo eh_h((string)$readiness['readiness_level']); ?></p>
                        <p class="small text-muted mb-0">Assessed <?php echo eh_h((string)$readiness['assessed_at']); ?></p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
