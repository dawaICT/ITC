<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/legacy_academic_guard.php';
ep_redirect_certificate_from_legacy_skills_hub($db, (string)($_SESSION['Sid'] ?? ''));
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.item.edit_own');

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

$status = (string)$item['status'];
$editable = in_array($status, ['draft', 'changes_requested'], true);
$aiAllowed = $status === 'draft';
$categories = eh_list_categories($db, true);
$itemTypes = eh_item_types();
$media = eh_list_media($db, $itemId);
$errors = [];
$aiResult = null;

$form = [
    'title' => (string)$item['title'],
    'item_type' => (string)$item['item_type'],
    'category_id' => (string)($item['category_id'] ?? ''),
    'short_description' => (string)($item['short_description'] ?? ''),
    'full_description' => (string)($item['full_description'] ?? ''),
    'current_capacity' => $item['current_capacity'] !== null ? (string)$item['current_capacity'] : '',
    'capacity_period' => (string)($item['capacity_period'] ?? ''),
    'investment_required' => $item['investment_required'] !== null ? (string)$item['investment_required'] : '',
    'investment_purpose' => (string)($item['investment_purpose'] ?? ''),
    'expected_capacity' => $item['expected_capacity'] !== null ? (string)$item['expected_capacity'] : '',
    'expected_capacity_period' => (string)($item['expected_capacity_period'] ?? ''),
    'employment_potential' => $item['employment_potential'] !== null ? (string)$item['employment_potential'] : '',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        eh_require_post_csrf();
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    $action = (string)($_POST['action'] ?? 'save_item');

    if ($errors === [] && $action === 'ai_assist') {
        if (!$aiAllowed) {
            $errors[] = 'AI assistance is only available while the item is in draft status.';
        } else {
            $task = (string)($_POST['ai_task'] ?? 'improve_description');
            $aiResult = eh_ai_assist($db, $task, [
                'title' => $form['title'],
                'item_type' => $form['item_type'],
                'category' => (string)($item['category_name'] ?? ''),
                'short_description' => $form['short_description'],
                'full_description' => $form['full_description'],
                'readiness_level' => (string)($item['readiness_level'] ?? ''),
            ]);
            // Keep posted text fields if user typed while requesting AI
            foreach (array_keys($form) as $key) {
                if (isset($_POST[$key])) {
                    $form[$key] = trim((string)$_POST[$key]);
                }
            }
        }
    } elseif ($errors === [] && !$editable) {
        $errors[] = 'This item cannot be edited in its current status.';
    } elseif ($errors === [] && $action === 'upload_media') {
        $caption = trim((string)($_POST['caption'] ?? ''));
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Choose an image to upload.';
        } else {
            $upload = eh_upload_item_image($db, $itemId, $file, eh_current_actor_id(), $caption);
            if (empty($upload['ok'])) {
                $errors[] = (string)($upload['message'] ?? 'Upload failed.');
            } else {
                wuc_set_flash('success', (string)$upload['message']);
                header('Location: ' . $base . '/item_edit.php?id=' . $itemId);
                exit;
            }
        }
    } elseif ($errors === [] && $action === 'set_primary') {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        try {
            eh_set_primary_media($db, $itemId, $mediaId);
            wuc_set_flash('success', 'Primary image updated.');
            header('Location: ' . $base . '/item_edit.php?id=' . $itemId);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($errors === [] && $action === 'delete_media') {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        try {
            eh_delete_media($db, $mediaId, $itemId);
            wuc_set_flash('success', 'Image deleted.');
            header('Location: ' . $base . '/item_edit.php?id=' . $itemId);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($errors === [] && $action === 'save_item') {
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
        if ($errors === []) {
            try {
                eh_update_item($db, $itemId, [
                    'title' => $form['title'],
                    'item_type' => $form['item_type'],
                    'category_id' => $form['category_id'] !== '' ? (int)$form['category_id'] : null,
                    'short_description' => $form['short_description'],
                    'full_description' => $form['full_description'],
                    'current_capacity' => $form['current_capacity'],
                    'capacity_period' => $form['capacity_period'] !== '' ? $form['capacity_period'] : null,
                    'investment_required' => $form['investment_required'],
                    'investment_purpose' => $form['investment_purpose'] !== '' ? $form['investment_purpose'] : null,
                    'expected_capacity' => $form['expected_capacity'],
                    'expected_capacity_period' => $form['expected_capacity_period'] !== '' ? $form['expected_capacity_period'] : null,
                    'employment_potential' => $form['employment_potential'],
                ]);
                wuc_set_flash('success', 'Item updated. AI suggestions are never saved automatically.');
                header('Location: ' . $base . '/item_edit.php?id=' . $itemId);
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$item = eh_get_item($db, $itemId) ?? $item;
$media = eh_list_media($db, $itemId);
$aiTasks = [
    'improve_description' => 'Improve description',
    'short_summary' => 'Short summary',
    'target_customers' => 'Target customers',
    'marketing_wording' => 'Marketing wording',
    'missing_information' => 'Missing information',
    'investor_summary' => 'Investor summary',
    'investor_questions' => 'Investor questions',
];

$pageTitle = 'Edit item';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main class="content-wrapper portal-dashboard eh-hub pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4">
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title mb-1"><i class="fas fa-pen-to-square me-2"></i>Edit item</h1>
                <p class="eh-page-lead mb-0">
                    <?php echo eh_h((string)$item['title']); ?>
                    <span class="ms-2"><?php echo eh_status_chip((string)$item['status']); ?></span>
                </p>
            </div>
            <div class="col-auto">
                <div class="eh-toolbar">
                    <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/item_view.php?id=<?php echo (int)$itemId; ?>">View</a>
                    <a class="btn btn-outline-primary" href="<?php echo eh_h($base); ?>/cost_calculator.php?item_id=<?php echo (int)$itemId; ?>">Costs</a>
                    <a class="btn btn-outline-primary" href="<?php echo eh_h($base); ?>/readiness_assessment.php?item_id=<?php echo (int)$itemId; ?>">Readiness</a>
                </div>
            </div>
        </div>
    </div>

    <?php
    $doneKeysEdit = ['profile', 'item'];
    if (eh_count_item_media($db, $itemId) > 0) {
        $doneKeysEdit[] = 'media';
    }
    if (eh_get_costs($db, $itemId)) {
        $doneKeysEdit[] = 'costs';
    }
    if (eh_get_readiness($db, $itemId)) {
        $doneKeysEdit[] = 'readiness';
    }
    eh_render_workflow_stepper($base, 'media', $itemId, $doneKeysEdit);
    ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach (array_unique($errors) as $error): ?>
                <div><?php echo eh_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$editable): ?>
        <div class="alert alert-warning">This item is <?php echo eh_h(eh_status_label($status)); ?> and cannot be edited. Contact support if you need changes.</div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0">Item details</h5></div>
                <div class="card-body">
                    <form method="post" action="<?php echo eh_h($base); ?>/item_edit.php?id=<?php echo (int)$itemId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo eh_h(wuc_csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_item">
                        <input type="hidden" name="item_id" value="<?php echo (int)$itemId; ?>">
                        <fieldset <?php echo $editable ? '' : 'disabled'; ?>>
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label" for="title">Title</label>
                                    <input class="form-control" type="text" id="title" name="title" maxlength="200" required value="<?php echo eh_h($form['title']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="item_type">Type</label>
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
                                            <option value="<?php echo (int)$cat['id']; ?>" <?php echo $form['category_id'] === (string)$cat['id'] ? 'selected' : ''; ?>>
                                                <?php echo eh_h((string)$cat['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="short_description">Short description</label>
                                    <textarea class="form-control" id="short_description" name="short_description" rows="2" maxlength="500" required><?php echo eh_h($form['short_description']); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="full_description">Full description</label>
                                    <textarea class="form-control" id="full_description" name="full_description" rows="7" required><?php echo eh_h($form['full_description']); ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="current_capacity">Current capacity</label>
                                    <input class="form-control" type="number" id="current_capacity" name="current_capacity" min="0" value="<?php echo eh_h($form['current_capacity']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="capacity_period">Capacity period</label>
                                    <input class="form-control" type="text" id="capacity_period" name="capacity_period" value="<?php echo eh_h($form['capacity_period']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="employment_potential">Employment potential</label>
                                    <input class="form-control" type="number" id="employment_potential" name="employment_potential" min="0" value="<?php echo eh_h($form['employment_potential']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="investment_required">Investment required</label>
                                    <input class="form-control" type="number" step="0.01" id="investment_required" name="investment_required" min="0" value="<?php echo eh_h($form['investment_required']); ?>">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label" for="investment_purpose">Investment purpose</label>
                                    <input class="form-control" type="text" id="investment_purpose" name="investment_purpose" maxlength="500" value="<?php echo eh_h($form['investment_purpose']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="expected_capacity">Expected capacity</label>
                                    <input class="form-control" type="number" id="expected_capacity" name="expected_capacity" min="0" value="<?php echo eh_h($form['expected_capacity']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="expected_capacity_period">Expected capacity period</label>
                                    <input class="form-control" type="text" id="expected_capacity_period" name="expected_capacity_period" value="<?php echo eh_h($form['expected_capacity_period']); ?>">
                                </div>
                            </div>
                            <?php if ($editable): ?>
                                <div class="mt-3">
                                    <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Save changes</button>
                                </div>
                            <?php endif; ?>
                        </fieldset>
                    </form>
                </div>
            </section>

            <?php if ($editable): ?>
                <section class="data-table-card mt-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fas fa-images me-2"></i>Media</h5></div>
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data" action="<?php echo eh_h($base); ?>/item_edit.php?id=<?php echo (int)$itemId; ?>" class="row g-3 mb-4">
                            <input type="hidden" name="csrf_token" value="<?php echo eh_h(wuc_csrf_token()); ?>">
                            <input type="hidden" name="action" value="upload_media">
                            <div class="col-md-6">
                                <label class="form-label" for="image">Upload image</label>
                                <input class="form-control" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="caption">Caption</label>
                                <input class="form-control" type="text" id="caption" name="caption" maxlength="255">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button class="btn btn-outline-primary w-100" type="submit">Upload</button>
                            </div>
                        </form>

                        <?php if ($media === []): ?>
                            <p class="text-muted mb-0">No images uploaded yet. At least one primary image is required before submission.</p>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($media as $m): ?>
                                    <div class="col-md-4">
                                        <div class="border rounded p-2 h-100">
                                            <img class="img-fluid rounded mb-2" alt="<?php echo eh_h((string)$m['caption']); ?>"
                                                 src="/wucportal/showcase/media.php?id=<?php echo (int)$m['id']; ?>">
                                            <div class="small mb-2">
                                                <?php if ((int)$m['is_primary'] === 1): ?>
                                                    <span class="badge bg-success">Primary</span>
                                                <?php endif; ?>
                                                <?php echo eh_h((string)$m['original_filename']); ?>
                                            </div>
                                            <div class="d-flex gap-1">
                                                <?php if ((int)$m['is_primary'] !== 1): ?>
                                                    <form method="post" action="<?php echo eh_h($base); ?>/item_edit.php?id=<?php echo (int)$itemId; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo eh_h(wuc_csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="set_primary">
                                                        <input type="hidden" name="media_id" value="<?php echo (int)$m['id']; ?>">
                                                        <button class="btn btn-sm btn-outline-primary" type="submit">Set primary</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" action="<?php echo eh_h($base); ?>/item_edit.php?id=<?php echo (int)$itemId; ?>" onsubmit="return confirm('Delete this image?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo eh_h(wuc_csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="delete_media">
                                                    <input type="hidden" name="media_id" value="<?php echo (int)$m['id']; ?>">
                                                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <?php if ($aiAllowed): ?>
                <section class="data-table-card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>AI assist</h5></div>
                    <div class="card-body">
                        <p class="small text-muted">Draft suggestions only. Nothing is saved until you copy text into the form and click Save.</p>
                        <form method="post" action="<?php echo eh_h($base); ?>/item_edit.php?id=<?php echo (int)$itemId; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo eh_h(wuc_csrf_token()); ?>">
                            <input type="hidden" name="action" value="ai_assist">
                            <?php foreach ($form as $key => $val): ?>
                                <input type="hidden" name="<?php echo eh_h($key); ?>" value="<?php echo eh_h((string)$val); ?>">
                            <?php endforeach; ?>
                            <label class="form-label" for="ai_task">Task</label>
                            <select class="form-select mb-3" id="ai_task" name="ai_task">
                                <?php foreach ($aiTasks as $key => $label): ?>
                                    <option value="<?php echo eh_h($key); ?>"><?php echo eh_h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-primary w-100" type="submit">Generate draft</button>
                        </form>
                        <?php if (is_array($aiResult)): ?>
                            <div class="alert alert-<?php echo !empty($aiResult['ok']) ? 'info' : 'warning'; ?> mt-3 mb-2">
                                <?php echo eh_h((string)($aiResult['message'] ?? '')); ?>
                            </div>
                            <label class="form-label" for="ai_output">Suggestion (not saved)</label>
                            <textarea class="form-control" id="ai_output" rows="10" readonly><?php echo eh_h((string)($aiResult['text'] ?? '')); ?></textarea>
                        <?php endif; ?>
                    </div>
                </section>
            <?php else: ?>
                <div class="alert alert-secondary">AI assist is available only for draft items.</div>
            <?php endif; ?>

            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0">Next steps</h5></div>
                <div class="card-body d-grid gap-2">
                    <a class="btn btn-outline-primary" href="<?php echo eh_h($base); ?>/cost_calculator.php?item_id=<?php echo (int)$itemId; ?>">Cost calculator</a>
                    <a class="btn btn-outline-primary" href="<?php echo eh_h($base); ?>/readiness_assessment.php?item_id=<?php echo (int)$itemId; ?>">Readiness assessment</a>
                    <a class="btn btn-primary" href="<?php echo eh_h($base); ?>/item_view.php?id=<?php echo (int)$itemId; ?>">Review &amp; submit</a>
                </div>
            </section>
        </div>
    </div>
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
