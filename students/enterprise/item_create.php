<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/legacy_academic_guard.php';
ep_redirect_certificate_from_legacy_skills_hub($db, (string)($_SESSION['Sid'] ?? ''));
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.item.create');

$base = '/wucportal/students/enterprise';
$profile = eh_get_profile_for_owner($db, eh_current_owner_user_id(), eh_current_student_id());

if (!$profile) {
    wuc_set_flash('info', 'Create your enterprise profile before adding an item.');
    header('Location: ' . $base . '/profile_edit.php');
    exit;
}
eh_assert_owns_profile($profile);

$categories = eh_list_categories($db, true);
$itemTypes = eh_item_types();
$errors = [];

$form = [
    'title' => '',
    'item_type' => 'product',
    'category_id' => '',
    'short_description' => '',
    'full_description' => '',
    'current_capacity' => '',
    'capacity_period' => 'per month',
    'investment_required' => '',
    'investment_purpose' => '',
    'expected_capacity' => '',
    'expected_capacity_period' => 'per month',
    'employment_potential' => '',
    'currency' => 'ZMW',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        eh_require_post_csrf();
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if ($form['title'] === '' || mb_strlen($form['title']) > 200) {
        $errors[] = 'Title is required (max 200 characters).';
    }
    if (!array_key_exists($form['item_type'], $itemTypes)) {
        $errors[] = 'Select a valid item type.';
    }
    if ($form['short_description'] === '' || mb_strlen($form['short_description']) > 500) {
        $errors[] = 'Short description is required (max 500 characters).';
    }
    if ($form['full_description'] === '') {
        $errors[] = 'Full description is required.';
    }
    if ($form['category_id'] !== '' && !ctype_digit($form['category_id'])) {
        $errors[] = 'Invalid category.';
    }
    if ($form['investment_required'] !== '' && !is_numeric($form['investment_required'])) {
        $errors[] = 'Investment required must be a number.';
    }

    if ($errors === []) {
        try {
            $itemId = eh_create_item($db, [
                'enterprise_profile_id' => (int)$profile['id'],
                'category_id' => $form['category_id'] !== '' ? (int)$form['category_id'] : null,
                'item_type' => $form['item_type'],
                'title' => $form['title'],
                'short_description' => $form['short_description'],
                'full_description' => $form['full_description'],
                'currency' => $form['currency'] !== '' ? $form['currency'] : 'ZMW',
                'current_capacity' => $form['current_capacity'],
                'capacity_period' => $form['capacity_period'] !== '' ? $form['capacity_period'] : null,
                'investment_required' => $form['investment_required'],
                'investment_purpose' => $form['investment_purpose'] !== '' ? $form['investment_purpose'] : null,
                'expected_capacity' => $form['expected_capacity'],
                'expected_capacity_period' => $form['expected_capacity_period'] !== '' ? $form['expected_capacity_period'] : null,
                'employment_potential' => $form['employment_potential'],
            ]);
            wuc_set_flash('success', 'Draft saved. Next: add a photo, then costs and readiness.');
            header('Location: ' . $base . '/item_edit.php?id=' . $itemId);
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Could not create item. Please try again.';
            error_log('enterprise item_create: ' . $e->getMessage());
        }
    }
}

$pageTitle = 'Create showcase item';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main class="content-wrapper portal-dashboard eh-hub pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4">
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title mb-1"><i class="fas fa-plus-circle me-2 text-primary"></i>Create showcase item</h1>
                <p class="eh-page-lead mb-0">Linked to <strong><?php echo eh_h((string)$profile['business_name']); ?></strong>. Start with a clear title — photos and costs come next.</p>
            </div>
            <div class="col-auto">
                <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/items.php">Back to items</a>
            </div>
        </div>
    </div>

    <?php eh_render_workflow_stepper($base, 'item', null, ['profile']); ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach (array_unique($errors) as $error): ?>
                <div><?php echo eh_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="data-table-card">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-edit me-2"></i>Item details</h5></div>
        <div class="card-body">
            <form method="post" action="<?php echo eh_h($base); ?>/item_create.php">
                <input type="hidden" name="csrf_token" value="<?php echo eh_h(wuc_csrf_token()); ?>">

                <div class="eh-section-label">Basics</div>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="title">Title <span class="text-danger">*</span></label>
                        <input class="form-control" type="text" id="title" name="title" maxlength="200" required value="<?php echo eh_h($form['title']); ?>" placeholder="e.g. Solar dryer vegetable packs">
                        <span class="eh-form-hint">Use a plain name visitors will understand.</span>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="item_type">Item type <span class="text-danger">*</span></label>
                        <select class="form-select" id="item_type" name="item_type" required>
                            <?php foreach ($itemTypes as $key => $label): ?>
                                <option value="<?php echo eh_h($key); ?>" <?php echo $form['item_type'] === $key ? 'selected' : ''; ?>><?php echo eh_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="category_id">Category</label>
                        <select class="form-select" id="category_id" name="category_id">
                            <option value="">Select category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat['id']; ?>" <?php echo $form['category_id'] === (string)$cat['id'] ? 'selected' : ''; ?>><?php echo eh_h((string)$cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="eh-form-hint">Required before you can submit for review.</span>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="currency">Currency</label>
                        <input class="form-control" type="text" id="currency" name="currency" maxlength="3" value="<?php echo eh_h($form['currency']); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="short_description">Short description <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="short_description" name="short_description" rows="2" maxlength="500" required placeholder="One or two sentences for the catalogue card"><?php echo eh_h($form['short_description']); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="full_description">Full description <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="full_description" name="full_description" rows="6" required placeholder="Explain what you offer, who it helps, and how it is made or delivered"><?php echo eh_h($form['full_description']); ?></textarea>
                    </div>
                </div>

                <div class="eh-section-label">Capacity and investment (optional now)</div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="current_capacity">Current capacity</label>
                        <input class="form-control" type="number" id="current_capacity" name="current_capacity" min="0" value="<?php echo eh_h($form['current_capacity']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="capacity_period">Capacity period</label>
                        <input class="form-control" type="text" id="capacity_period" name="capacity_period" maxlength="40" value="<?php echo eh_h($form['capacity_period']); ?>" placeholder="per month">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="employment_potential">Employment potential (jobs)</label>
                        <input class="form-control" type="number" id="employment_potential" name="employment_potential" min="0" value="<?php echo eh_h($form['employment_potential']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="investment_required">Investment required (ZMW)</label>
                        <input class="form-control" type="number" step="0.01" id="investment_required" name="investment_required" min="0" value="<?php echo eh_h($form['investment_required']); ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="investment_purpose">Investment purpose</label>
                        <input class="form-control" type="text" id="investment_purpose" name="investment_purpose" maxlength="500" value="<?php echo eh_h($form['investment_purpose']); ?>" placeholder="e.g. Packaging equipment">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="expected_capacity">Expected capacity after investment</label>
                        <input class="form-control" type="number" id="expected_capacity" name="expected_capacity" min="0" value="<?php echo eh_h($form['expected_capacity']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="expected_capacity_period">Expected capacity period</label>
                        <input class="form-control" type="text" id="expected_capacity_period" name="expected_capacity_period" maxlength="40" value="<?php echo eh_h($form['expected_capacity_period']); ?>">
                    </div>
                </div>

                <div class="mt-4 eh-toolbar">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Save draft and continue</button>
                    <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/items.php">Cancel</a>
                </div>
                <p class="eh-form-hint mt-2 mb-0">Next: upload a photo, calculate costs, then complete readiness.</p>
            </form>
        </div>
    </section>
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
