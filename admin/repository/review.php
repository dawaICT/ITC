<?php
require_once __DIR__ . '/_common.php';

repo_handle_admin_action($db, '/wucportal/admin/repository/review.php?id=' . (int)($_POST['id'] ?? $_GET['id'] ?? 0));
$id = (int)($_GET['id'] ?? 0);
$material = $id > 0 ? repo_fetch_material($db, $id) : null;
$options = repo_lookup_options($db);

repo_admin_header('Review Repository Material', 'Preview metadata, approve or reject uploads, and trigger AI processing.');
if (!$material): ?>
    <div class="alert alert-danger">Material not found.</div>
<?php else: ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <?php repo_render_material_view($db, $material, '/wucportal/admin/repository/preview.php', true); ?>
        </div>
        <div class="col-xl-4">
            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-pen-to-square me-2"></i>Edit Metadata</h5></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$material['id']; ?>">
                        <input type="hidden" name="action" value="save_metadata">
                        <div class="mb-2"><label class="form-label">Title</label><input class="form-control" name="title" value="<?php echo repo_h($material['title']); ?>" required></div>
                        <div class="mb-2"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo repo_h($material['description']); ?></textarea></div>
                        <div class="mb-2"><label class="form-label">Type</label><select class="form-select" name="material_type"><?php foreach (repo_material_types() as $key => $label): ?><option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)$material['material_type'], $key); ?>><?php echo repo_h($label); ?></option><?php endforeach; ?></select></div>
                        <div class="mb-2"><label class="form-label">Visibility</label><select class="form-select" name="visibility"><?php foreach (repo_visibility_levels() as $key => $label): ?><option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)$material['visibility'], $key); ?>><?php echo repo_h($label); ?></option><?php endforeach; ?></select></div>
                        <div class="mb-2"><label class="form-label">Department</label><select class="form-select" name="department_id"><option value="">Not specified</option><?php foreach ($options['departments'] as $key => $label): ?><option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)$material['department_id'], (string)$key); ?>><?php echo repo_h($label); ?></option><?php endforeach; ?></select></div>
                        <div class="mb-2"><label class="form-label">Programme</label><select class="form-select" name="programme_code"><option value="">Not specified</option><?php foreach ($options['programmes'] as $key => $label): ?><option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)$material['programme_code'], (string)$key); ?>><?php echo repo_h($label); ?></option><?php endforeach; ?></select></div>
                        <div class="mb-2"><label class="form-label">Course</label><select class="form-select" name="course_code"><option value="">Not specified</option><?php foreach ($options['courses'] as $key => $label): ?><option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)$material['course_code'], (string)$key); ?>><?php echo repo_h($label); ?></option><?php endforeach; ?></select></div>
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label">Academic year</label><input class="form-control" name="academic_year" value="<?php echo repo_h($material['academic_year']); ?>"></div>
                            <div class="col-6"><label class="form-label">Year</label><input class="form-control" type="number" name="year_of_study" value="<?php echo repo_h($material['year_of_study']); ?>"></div>
                            <div class="col-6"><label class="form-label">Term</label><input class="form-control" name="term" value="<?php echo repo_h($material['term']); ?>"></div>
                            <div class="col-6"><label class="form-label">Semester</label><input class="form-control" name="semester" value="<?php echo repo_h($material['semester']); ?>"></div>
                        </div>
                        <button class="btn btn-outline-primary w-100 mt-3" type="submit">Save Metadata</button>
                    </form>
                </div>
            </section>
            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Review Actions</h5></div>
                <div class="card-body">
                    <form method="post" class="mb-3">
                        <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$material['id']; ?>">
                        <label class="form-label">Approved visibility</label>
                        <select class="form-select mb-3" name="visibility">
                            <?php foreach (repo_visibility_levels() as $key => $label): ?>
                                <option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)($material['visibility'] ?: $material['requested_visibility']), $key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-success w-100" name="action" value="approve"><i class="fas fa-check me-2"></i>Approve and Publish</button>
                    </form>
                    <form method="post" class="mb-3">
                        <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$material['id']; ?>">
                        <label class="form-label">Rejection reason</label>
                        <textarea class="form-control mb-3" name="rejection_reason" rows="4"><?php echo repo_h($material['rejection_reason'] ?? ''); ?></textarea>
                        <button class="btn btn-danger w-100" name="action" value="reject"><i class="fas fa-times me-2"></i>Reject Upload</button>
                    </form>
                    <div class="d-grid gap-2">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$material['id']; ?>">
                            <button class="btn btn-outline-primary w-100" name="action" value="ai"><i class="fas fa-wand-magic-sparkles me-2"></i>Run AI Processing</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$material['id']; ?>">
                            <button class="btn btn-outline-warning w-100" name="action" value="<?php echo (int)$material['is_published'] === 1 ? 'unpublish' : 'publish'; ?>"><?php echo (int)$material['is_published'] === 1 ? 'Unpublish' : 'Publish'; ?></button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$material['id']; ?>">
                            <button class="btn btn-outline-dark w-100" name="action" value="archive">Archive</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this material record? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$material['id']; ?>">
                            <button class="btn btn-outline-danger w-100" name="action" value="delete">Delete Unsafe / Incorrect</button>
                        </form>
                    </div>
                </div>
            </section>
            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-comment-dots me-2"></i>Status Notes</h5></div>
                <div class="card-body small">
                    <p><strong>Requested visibility:</strong> <?php echo repo_h(repo_visibility_levels()[$material['requested_visibility']] ?? $material['requested_visibility']); ?></p>
                    <p><strong>Status:</strong> <?php echo repo_badge((string)$material['status']); ?></p>
                    <p><strong>AI status:</strong> <?php echo repo_h($material['ai_status']); ?></p>
                    <?php if (!empty($material['rejection_reason'])): ?><p><strong>Rejection reason:</strong><br><?php echo nl2br(repo_h($material['rejection_reason'])); ?></p><?php endif; ?>
                </div>
            </section>
        </div>
    </div>
<?php endif;
repo_admin_footer();
