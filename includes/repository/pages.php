<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';

function repo_flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null && $message !== null) {
        $_SESSION['_repo_flash'] = ['type' => $type, 'message' => $message];
        return null;
    }
    $flash = $_SESSION['_repo_flash'] ?? null;
    unset($_SESSION['_repo_flash']);
    return is_array($flash) ? $flash : null;
}

function repo_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function repo_require_csrf(array &$errors): bool
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        $errors[] = 'Request verification failed. Refresh and try again.';
        return false;
    }
    return true;
}

function repo_selected(string $a, string $b): string
{
    return $a === $b ? ' selected' : '';
}

function repo_badge(string $status): string
{
    $status = strtolower($status);
    $class = [
        'approved' => 'bg-success',
        'pending' => 'bg-warning text-dark',
        'rejected' => 'bg-danger',
        'archived' => 'bg-secondary',
    ][$status] ?? 'bg-light text-dark';
    return '<span class="badge ' . $class . '">' . repo_h(ucfirst($status)) . '</span>';
}

function repo_filter_form(array $filters, array $options, string $action): void
{
    ?>
    <div class="card search-form-card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Repository Resources</h5>
        </div>
        <div class="card-body">
            <form method="get" action="<?php echo repo_h($action); ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input class="form-control" name="q" value="<?php echo repo_h($filters['q'] ?? ''); ?>" placeholder="Title, summary, keywords">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="material_type">
                            <option value="">All types</option>
                            <?php foreach (repo_material_types() as $key => $label): ?>
                                <option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)($filters['material_type'] ?? ''), $key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Course</label>
                        <select class="form-select" name="course_code">
                            <option value="">All courses</option>
                            <?php foreach ($options['courses'] as $key => $label): ?>
                                <option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)($filters['course_code'] ?? ''), (string)$key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Programme</label>
                        <select class="form-select" name="programme_code">
                            <option value="">All programmes</option>
                            <?php foreach ($options['programmes'] as $key => $label): ?>
                                <option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)($filters['programme_code'] ?? ''), (string)$key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Sort</label>
                        <select class="form-select" name="sort">
                            <option value="">Newest</option>
                            <option value="views"<?php echo repo_selected((string)($filters['sort'] ?? ''), 'views'); ?>>Most viewed</option>
                            <option value="downloads"<?php echo repo_selected((string)($filters['sort'] ?? ''), 'downloads'); ?>>Most downloaded</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-grid">
                        <button class="btn btn-success" type="submit"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php
}

function repo_material_cards(mysqli $db, array $materials, string $viewBase, string $downloadBase): void
{
    if (!$materials): ?>
        <div class="data-table-card">
            <div class="card-body text-center py-5 text-muted">
                <div class="repository-empty-icon mb-3">
                    <i class="fas fa-folder-open fa-2x"></i>
                </div>
                <h5 class="text-dark mb-1">No Materials Found</h5>
                <p class="mb-0">No approved repository materials match this view.</p>
            </div>
        </div>
        <?php return;
    endif; ?>
    <div class="row g-4">
        <?php foreach ($materials as $material): ?>
            <div class="col-md-6 col-xl-4">
                <section class="data-table-card repository-material-card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-start gap-3 mb-3">
                            <i class="<?php echo repo_h(repo_material_icon($material)); ?> fa-2x"></i>
                            <div class="flex-grow-1">
                                <h5 class="mb-1"><?php echo repo_h($material['title']); ?></h5>
                                <div class="small text-muted"><?php echo repo_h(repo_material_types()[$material['material_type']] ?? $material['material_type']); ?></div>
                            </div>
                        </div>
                        <p class="text-muted small flex-grow-1"><?php echo repo_h(wuc_ai_truncate((string)($material['description'] ?? ''), 180)); ?></p>
                        <div class="small text-muted mb-3">
                            <span class="me-2"><i class="fas fa-eye me-1"></i><?php echo (int)$material['view_count']; ?></span>
                            <span class="me-2"><i class="fas fa-download me-1"></i><?php echo (int)$material['download_count']; ?></span>
                            <span><i class="fas fa-lock me-1"></i><?php echo repo_h(repo_visibility_levels()[$material['visibility']] ?? $material['visibility']); ?></span>
                        </div>
                        <div class="d-flex gap-2">
                            <a class="btn btn-outline-primary btn-sm" href="<?php echo repo_h($viewBase); ?>?id=<?php echo (int)$material['id']; ?>">View</a>
                            <a class="btn btn-primary btn-sm" href="<?php echo repo_h($downloadBase); ?>?id=<?php echo (int)$material['id']; ?>">Open</a>
                        </div>
                    </div>
                </section>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function repo_handle_upload(mysqli $db, string $returnUrl, ?int $editId = null): void
{
    repo_ensure_schema($db);
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if (!repo_schema_ready($db)) {
        repo_flash('danger', 'The repository database tables are not installed yet. Ask an administrator to apply the repository migration.');
        return;
    }
    repo_require_csrf($errors);
    $staffId = repo_current_staff_id();
    if ($staffId === '') {
        $errors[] = 'Please log in as a lecturer.';
    }
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $materialType = (string)($_POST['material_type'] ?? '');
    $visibility = (string)($_POST['visibility'] ?? 'course');
    $uploadMode = (string)($_POST['upload_mode'] ?? 'file');
    if ($title === '' || strlen($title) > 255) {
        $errors[] = 'Enter a title up to 255 characters.';
    }
    if (!isset(repo_material_types()[$materialType])) {
        $errors[] = 'Select a valid material type.';
    }
    if (!isset(repo_visibility_levels()[$visibility])) {
        $errors[] = 'Select a valid visibility level.';
    }
    if (!in_array($uploadMode, ['file', 'link'], true)) {
        $errors[] = 'Select file or external link.';
    }

    $fileData = ['file_path' => null, 'external_url' => null, 'original_filename' => null, 'mime_type' => null, 'file_size' => null, 'checksum_sha256' => null];
    if ($uploadMode === 'link') {
        $url = trim((string)($_POST['external_url'] ?? ''));
        if ($url === '' || !wuc_validate_external_http_url($url)) {
            $errors[] = 'Enter a valid external http or https link.';
        } else {
            $fileData['external_url'] = $url;
        }
    } elseif ($editId === null || !empty($_FILES['material_file']['name'])) {
        $upload = repo_validate_upload($_FILES['material_file'] ?? [], $errors);
        if ($upload) {
            $stored = repo_store_upload($upload, $errors);
            if ($stored) {
                $fileData = [
                    'file_path' => $stored['relative'],
                    'external_url' => null,
                    'original_filename' => $upload['original'],
                    'mime_type' => $upload['mime'],
                    'file_size' => $upload['size'],
                    'checksum_sha256' => $upload['checksum'],
                ];
            }
        }
    }

    if ($errors) {
        repo_flash('danger', implode(' ', array_unique($errors)));
        return;
    }

    $fields = [
        'title' => $title,
        'description' => $description,
        'material_type' => $materialType,
        'upload_mode' => $uploadMode,
        'department_id' => trim((string)($_POST['department_id'] ?? '')),
        'programme_code' => trim((string)($_POST['programme_code'] ?? '')),
        'course_code' => trim((string)($_POST['course_code'] ?? '')),
        'academic_year' => trim((string)($_POST['academic_year'] ?? '')),
        'year_of_study' => (int)($_POST['year_of_study'] ?? 0) ?: null,
        'term' => trim((string)($_POST['term'] ?? '')),
        'semester' => trim((string)($_POST['semester'] ?? '')),
        'requested_visibility' => $visibility,
        'visibility' => $visibility,
    ];

    if ($editId !== null) {
        $material = repo_fetch_material($db, $editId);
        if (!$material || (string)$material['uploader_staff_id'] !== $staffId || !in_array((string)$material['status'], ['pending', 'rejected'], true)) {
            repo_flash('danger', 'Only your pending or rejected uploads can be edited.');
            return;
        }
        $sets = "title=?, description=?, material_type=?, upload_mode=?, department_id=?, programme_code=?, course_code=?, academic_year=?, year_of_study=?, term=?, semester=?, requested_visibility=?, visibility=?, status='pending', rejection_reason=NULL, is_published=0";
        $types = 'ssssssssissss';
        $params = [$fields['title'], $fields['description'], $fields['material_type'], $fields['upload_mode'], $fields['department_id'], $fields['programme_code'], $fields['course_code'], $fields['academic_year'], $fields['year_of_study'], $fields['term'], $fields['semester'], $fields['requested_visibility'], $fields['visibility']];
        if ($fileData['file_path'] !== null || $uploadMode === 'link') {
            $sets .= ", file_path=?, external_url=?, original_filename=?, mime_type=?, file_size=?, checksum_sha256=?";
            $types .= 'ssssis';
            array_push($params, $fileData['file_path'], $fileData['external_url'], $fileData['original_filename'], $fileData['mime_type'], $fileData['file_size'], $fileData['checksum_sha256']);
        }
        $types .= 'i';
        $params[] = $editId;
        $stmt = $db->prepare("UPDATE repository_materials SET {$sets} WHERE id=?");
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }
        repo_flash('success', 'Upload updated and sent back for admin approval.');
        repo_redirect($returnUrl);
    }

    $stmt = $db->prepare("INSERT INTO repository_materials
        (title, description, material_type, upload_mode, file_path, external_url, original_filename, mime_type, file_size, checksum_sha256, uploader_staff_id, department_id, programme_code, course_code, academic_year, year_of_study, term, semester, requested_visibility, visibility, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    if ($stmt) {
        $stmt->bind_param(
            'ssssssssissssssissss',
            $fields['title'], $fields['description'], $fields['material_type'], $fields['upload_mode'],
            $fileData['file_path'], $fileData['external_url'], $fileData['original_filename'], $fileData['mime_type'],
            $fileData['file_size'], $fileData['checksum_sha256'], $staffId, $fields['department_id'],
            $fields['programme_code'], $fields['course_code'], $fields['academic_year'], $fields['year_of_study'],
            $fields['term'], $fields['semester'], $fields['requested_visibility'], $fields['visibility']
        );
        $stmt->execute();
        $stmt->close();
    }
    repo_flash('success', 'Material uploaded. It is pending admin approval.');
    repo_redirect($returnUrl);
}

function repo_render_upload_form(mysqli $db, string $action, array $material = []): void
{
    $options = repo_lookup_options($db);
    $isEdit = !empty($material);
    ?>
    <section class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-upload me-2"></i><?php echo $isEdit ? 'Edit Upload' : 'Upload Academic Material'; ?></h5>
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" action="<?php echo repo_h($action); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Title</label>
                        <input class="form-control" name="title" maxlength="255" required value="<?php echo repo_h($material['title'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Material type</label>
                        <select class="form-select" name="material_type" required>
                            <?php foreach (repo_material_types() as $key => $label): ?>
                                <option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)($material['material_type'] ?? ''), $key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="4"><?php echo repo_h($material['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Upload mode</label>
                        <select class="form-select" name="upload_mode">
                            <option value="file"<?php echo repo_selected((string)($material['upload_mode'] ?? 'file'), 'file'); ?>>File</option>
                            <option value="link"<?php echo repo_selected((string)($material['upload_mode'] ?? ''), 'link'); ?>>External link</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">File <?php echo $isEdit ? '(leave blank to keep current)' : ''; ?></label>
                        <input class="form-control" type="file" name="material_file">
                        <div class="form-text">Allowed: PDF, Office docs, text/CSV, images, and safe video files. Maximum 50 MB.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">External link</label>
                        <input class="form-control" name="external_url" value="<?php echo repo_h($material['external_url'] ?? ''); ?>" placeholder="https://...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Visibility</label>
                        <select class="form-select" name="visibility">
                            <?php foreach (repo_visibility_levels() as $key => $label): ?>
                                <option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)($material['requested_visibility'] ?? 'course'), $key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Department</label>
                        <select class="form-select" name="department_id">
                            <option value="">Not specified</option>
                            <?php foreach ($options['departments'] as $key => $label): ?>
                                <option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)($material['department_id'] ?? ''), (string)$key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Programme</label>
                        <select class="form-select" name="programme_code">
                            <option value="">Not specified</option>
                            <?php foreach ($options['programmes'] as $key => $label): ?>
                                <option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)($material['programme_code'] ?? ''), (string)$key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Course</label>
                        <select class="form-select" name="course_code">
                            <option value="">Not specified</option>
                            <?php foreach ($options['courses'] as $key => $label): ?>
                                <option value="<?php echo repo_h($key); ?>"<?php echo repo_selected((string)($material['course_code'] ?? ''), (string)$key); ?>><?php echo repo_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="form-label">Academic year</label><input class="form-control" name="academic_year" value="<?php echo repo_h($material['academic_year'] ?? ''); ?>"></div>
                    <div class="col-md-3"><label class="form-label">Year of study</label><input class="form-control" type="number" min="1" max="10" name="year_of_study" value="<?php echo repo_h($material['year_of_study'] ?? ''); ?>"></div>
                    <div class="col-md-3"><label class="form-label">Term</label><input class="form-control" name="term" value="<?php echo repo_h($material['term'] ?? ''); ?>"></div>
                    <div class="col-md-3"><label class="form-label">Semester</label><input class="form-control" name="semester" value="<?php echo repo_h($material['semester'] ?? ''); ?>"></div>
                </div>
                <div class="mt-4">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane me-2"></i><?php echo $isEdit ? 'Save and Resubmit' : 'Submit for Approval'; ?></button>
                </div>
            </form>
        </div>
    </section>
    <?php
}

function repo_render_material_view(mysqli $db, array $material, string $downloadUrl, bool $showAdmin = false): void
{
    repo_log_access($db, (int)$material['id'], 'view');
    $ai = null;
    $stmt = repo_table_exists($db, 'repository_ai_metadata')
        ? $db->prepare("SELECT * FROM repository_ai_metadata WHERE material_id = ? LIMIT 1")
        : false;
    if ($stmt instanceof mysqli_stmt) {
        $id = (int)$material['id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ai = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    $questions = [];
    $stmtQ = repo_table_exists($db, 'repository_ai_questions')
        ? $db->prepare("SELECT question_text FROM repository_ai_questions WHERE material_id = ? ORDER BY id LIMIT 10")
        : false;
    if ($stmtQ instanceof mysqli_stmt) {
        $id = (int)$material['id'];
        $stmtQ->bind_param('i', $id);
        $stmtQ->execute();
        $res = $stmtQ->get_result();
        while ($row = $res->fetch_assoc()) {
            $questions[] = (string)$row['question_text'];
        }
        $stmtQ->close();
    }
    ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <section class="data-table-card">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <i class="<?php echo repo_h(repo_material_icon($material)); ?> fa-3x"></i>
                        <div>
                            <h1 class="dashboard-title mb-1"><?php echo repo_h($material['title']); ?></h1>
                            <div class="text-muted"><?php echo repo_h(repo_material_types()[$material['material_type']] ?? $material['material_type']); ?> · <?php echo repo_h(repo_visibility_levels()[$material['visibility']] ?? $material['visibility']); ?></div>
                        </div>
                    </div>
                    <p><?php echo nl2br(repo_h($material['description'] ?? '')); ?></p>
                    <a class="btn btn-primary" href="<?php echo repo_h($downloadUrl); ?>?id=<?php echo (int)$material['id']; ?>"><i class="fas fa-download me-2"></i>Open / Download</a>
                    <?php if ($showAdmin): ?>
                        <span class="ms-2"><?php echo repo_badge((string)$material['status']); ?></span>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <div class="col-lg-4">
            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-circle-info me-2"></i>Details</h5></div>
                <div class="card-body small">
                    <div><strong>Course:</strong> <?php echo repo_h($material['course_code'] ?: 'Any'); ?></div>
                    <div><strong>Programme:</strong> <?php echo repo_h($material['programme_code'] ?: 'Any'); ?></div>
                    <div><strong>Academic year:</strong> <?php echo repo_h($material['academic_year'] ?: 'Any'); ?></div>
                    <div><strong>File size:</strong> <?php echo repo_h(repo_format_size($material['file_size'] ?? 0)); ?></div>
                    <div><strong>Views:</strong> <?php echo (int)$material['view_count']; ?></div>
                    <div><strong>Downloads:</strong> <?php echo (int)$material['download_count']; ?></div>
                </div>
            </section>
            <?php
            $relatedFilters = [];
            if (trim((string)$material['course_code']) !== '') {
                $relatedFilters['course_code'] = (string)$material['course_code'];
            } else {
                $relatedFilters['material_type'] = (string)$material['material_type'];
            }
            $related = array_values(array_filter(repo_accessible_materials($db, $relatedFilters, 6), static function ($row) use ($material) {
                return (int)$row['id'] !== (int)$material['id'];
            }));
            ?>
            <?php if ($related): ?>
            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-lightbulb me-2"></i>Recommended Materials</h5></div>
                <div class="card-body small">
                    <?php foreach (array_slice($related, 0, 4) as $row): ?>
                        <div class="border-bottom py-2">
                            <div class="fw-semibold"><?php echo repo_h($row['title']); ?></div>
                            <div class="text-muted"><?php echo repo_h(repo_material_types()[$row['material_type']] ?? $row['material_type']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($ai): ?>
        <section class="data-table-card mt-4">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>AI Study Support</h5></div>
            <div class="card-body">
                <?php echo wuc_ai_output_block((string)$ai['summary']); ?>
                <?php if ($questions): ?>
                    <hr>
                    <h6>Revision Questions</h6>
                    <ol>
                        <?php foreach ($questions as $question): ?><li><?php echo repo_h($question); ?></li><?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </section>
    <?php endif;
}

function repo_handle_admin_action(mysqli $db, string $returnUrl): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if (!repo_schema_ready($db)) {
        repo_flash('danger', 'The repository database tables are not installed yet. Apply the repository migration before managing materials.');
        return;
    }
    $errors = [];
    repo_require_csrf($errors);
    if (!repo_is_admin()) {
        $errors[] = 'Admin access is required.';
    }
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $material = $id > 0 ? repo_fetch_material($db, $id) : null;
    if (!$material) {
        $errors[] = 'Material not found.';
    }
    if ($errors) {
        repo_flash('danger', implode(' ', $errors));
        return;
    }
    $staffId = repo_current_staff_id();
    if ($action === 'approve') {
        $visibility = (string)($_POST['visibility'] ?? $material['requested_visibility']);
        if (!isset(repo_visibility_levels()[$visibility])) {
            $visibility = (string)$material['requested_visibility'];
        }
        $stmt = $db->prepare("UPDATE repository_materials SET status='approved', visibility=?, is_published=1, is_archived=0, rejection_reason=NULL, approved_by=?, reviewed_at=NOW() WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('ssi', $visibility, $staffId, $id);
            $stmt->execute();
            $stmt->close();
        }
        repo_process_ai($db, $id);
        require_once dirname(__DIR__, 2) . '/includes/notification_integrations.php';
        $courseCode = trim((string)($material['course_code'] ?? ''));
        $matTitle = trim((string)($material['title'] ?? 'Course material'));
        wuc_notify_material_published($db, $courseCode, $matTitle, (string)$id);
        repo_flash('success', 'Material approved and AI processing was queued/run.');
    } elseif ($action === 'reject') {
        $reason = trim((string)($_POST['rejection_reason'] ?? ''));
        if ($reason === '') {
            $reason = 'Rejected by administrator.';
        }
        $stmt = $db->prepare("UPDATE repository_materials SET status='rejected', is_published=0, rejection_reason=?, approved_by=?, reviewed_at=NOW() WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('ssi', $reason, $staffId, $id);
            $stmt->execute();
            $stmt->close();
        }
        repo_flash('success', 'Material rejected with reason.');
    } elseif ($action === 'save_metadata') {
        $title = trim((string)($_POST['title'] ?? $material['title']));
        $description = trim((string)($_POST['description'] ?? $material['description']));
        $materialType = (string)($_POST['material_type'] ?? $material['material_type']);
        $visibility = (string)($_POST['visibility'] ?? $material['visibility']);
        if ($title === '' || !isset(repo_material_types()[$materialType]) || !isset(repo_visibility_levels()[$visibility])) {
            repo_flash('danger', 'Title, material type, and visibility are required.');
            repo_redirect($returnUrl);
        }
        $department = trim((string)($_POST['department_id'] ?? ''));
        $programme = trim((string)($_POST['programme_code'] ?? ''));
        $course = trim((string)($_POST['course_code'] ?? ''));
        $academicYear = trim((string)($_POST['academic_year'] ?? ''));
        $year = (int)($_POST['year_of_study'] ?? 0) ?: null;
        $term = trim((string)($_POST['term'] ?? ''));
        $semester = trim((string)($_POST['semester'] ?? ''));
        $stmt = $db->prepare("UPDATE repository_materials
                                 SET title=?, description=?, material_type=?, department_id=?, programme_code=?, course_code=?, academic_year=?, year_of_study=?, term=?, semester=?, visibility=?
                               WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('sssssssisssi', $title, $description, $materialType, $department, $programme, $course, $academicYear, $year, $term, $semester, $visibility, $id);
            $stmt->execute();
            $stmt->close();
        }
        repo_flash('success', 'Material metadata updated.');
    } elseif ($action === 'archive') {
        $db->query("UPDATE repository_materials SET status='archived', is_archived=1, is_published=0 WHERE id=" . $id);
        repo_flash('success', 'Material archived.');
    } elseif ($action === 'publish') {
        $db->query("UPDATE repository_materials SET is_published=1, status='approved', is_archived=0 WHERE id=" . $id);
        repo_flash('success', 'Material published.');
    } elseif ($action === 'unpublish') {
        $db->query("UPDATE repository_materials SET is_published=0 WHERE id=" . $id);
        repo_flash('success', 'Material unpublished.');
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM repository_materials WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        repo_flash('success', 'Material deleted.');
    } elseif ($action === 'ai') {
        repo_process_ai($db, $id);
        repo_flash('success', 'AI processing completed for the material.');
    }
    repo_redirect($returnUrl);
}

function repo_admin_action_buttons(array $material): void
{
    ?>
    <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$material['id']; ?>">
        <?php if ($material['status'] !== 'approved'): ?>
            <input type="hidden" name="visibility" value="<?php echo repo_h($material['requested_visibility'] ?: 'course'); ?>">
            <button class="btn btn-sm btn-success" name="action" value="approve">Approve</button>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-secondary" name="action" value="ai">Run AI</button>
        <?php if ((int)$material['is_published'] === 1): ?>
            <button class="btn btn-sm btn-outline-warning" name="action" value="unpublish">Unpublish</button>
        <?php else: ?>
            <button class="btn btn-sm btn-outline-primary" name="action" value="publish">Publish</button>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-dark" name="action" value="archive">Archive</button>
    </form>
    <?php
}

function repo_render_admin_table(array $materials, string $reviewUrl): void
{
    ?>
    <section class="data-table-card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Title</th><th>Type</th><th>Scope</th><th>Status</th><th>AI</th><th>Activity</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$materials): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No materials found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($materials as $material): ?>
                        <tr>
                            <td><a href="<?php echo repo_h($reviewUrl); ?>?id=<?php echo (int)$material['id']; ?>"><?php echo repo_h($material['title']); ?></a><div class="small text-muted"><?php echo repo_h($material['uploader_name'] ?: $material['uploader_staff_id']); ?></div></td>
                            <td><?php echo repo_h(repo_material_types()[$material['material_type']] ?? $material['material_type']); ?></td>
                            <td class="small"><?php echo repo_h($material['course_code'] ?: $material['programme_code'] ?: $material['department_id'] ?: 'General'); ?><br><?php echo repo_h(repo_visibility_levels()[$material['visibility']] ?? $material['visibility']); ?></td>
                            <td><?php echo repo_badge((string)$material['status']); ?></td>
                            <td><?php echo repo_h($material['ai_status']); ?></td>
                            <td class="small"><?php echo (int)$material['view_count']; ?> views<br><?php echo (int)$material['download_count']; ?> downloads</td>
                            <td><?php repo_admin_action_buttons($material); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php
}
