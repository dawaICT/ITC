<?php
$page_title = 'Post Assignment';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/elearning_access.php';

function post_assign_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function post_assign_table_columns(mysqli $db, string $table): array
{
    $columns = [];
    $safeTable = str_replace('`', '``', $table);
    if ($result = $db->query("SHOW COLUMNS FROM `{$safeTable}`")) {
        while ($row = $result->fetch_assoc()) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $columns[strtolower($field)] = $field;
            }
        }
        $result->free();
    }
    return $columns;
}

function post_assign_ensure_column(mysqli $db, string $table, string $column, string $definition): bool
{
    // The DML-only app user cannot run DDL. Attempt the ALTER, but never let a
    // privilege/DDL failure fatal the page: schema is owned by the migrations, so
    // a still-missing column degrades to a graceful "storage not ready" message.
    $safeTable = str_replace('`', '``', $table);
    $safeColumn = str_replace('`', '``', $column);
    try {
        return (bool)$db->query("ALTER TABLE `{$safeTable}` ADD COLUMN `{$safeColumn}` {$definition}");
    } catch (Throwable $e) {
        error_log('post_assign_ensure_column: cannot add ' . $table . '.' . $column
            . ' at runtime (apply migrations as a DDL-capable user): ' . $e->getMessage());
        return false;
    }
}

function post_assign_format_due(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'No due date';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return (string)$value;
    }

    return date('M d, Y g:i A', $timestamp);
}

function post_assign_recent(mysqli $db, string $staffId, int $limit = 6): array
{
    if ($staffId === '' || !elearningTableExists($db, 'el_assignments')) {
        return [];
    }

    $columns = post_assign_table_columns($db, 'el_assignments');
    $createdAtExpr = isset($columns['created_at']) ? 'created_at' : 'NULL AS created_at';
    $attachmentExpr = isset($columns['attachment_path']) ? 'attachment_path' : 'NULL AS attachment_path';
    $orderExpr = isset($columns['created_at']) ? 'created_at DESC, id DESC' : 'id DESC';

    $sql = "SELECT id, course_code, title, due_at, {$attachmentExpr}, {$createdAtExpr}
            FROM el_assignments
            WHERE created_by = ?
            ORDER BY {$orderExpr}
            LIMIT ?";

    $records = [];
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('si', $staffId, $limit);
        $stmt->execute();
        if ($res = $stmt->get_result()) {
            while ($row = $res->fetch_assoc()) {
                $records[] = $row;
            }
        }
        $stmt->close();
    }

    return $records;
}

function post_assign_upload_error_message(int $code): string
{
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'The attachment is larger than the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'The attachment is larger than the form upload limit.',
        UPLOAD_ERR_PARTIAL => 'The attachment was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload folder.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
    ];

    return $messages[$code] ?? 'File upload failed.';
}

function post_assign_validate_upload(array $file, array &$errors): ?array
{
    $fileError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($fileError === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($fileError !== UPLOAD_ERR_OK) {
        $errors[] = post_assign_upload_error_message($fileError);
        return null;
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        $errors[] = 'The attachment appears to be empty.';
        return null;
    }
    if ($size > 10 * 1024 * 1024) {
        $errors[] = 'Maximum file size is 10MB.';
        return null;
    }

    $original = (string)($file['name'] ?? '');
    $tmp = (string)($file['tmp_name'] ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
    ];

    if (!isset($allowed[$ext])) {
        $errors[] = 'Only PDF, DOC and DOCX files are allowed.';
        return null;
    }

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $errors[] = 'The uploaded file could not be verified.';
        return null;
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($mime !== '' && !in_array($mime, $allowed[$ext], true)) {
            $errors[] = 'The attachment type does not match the selected file extension.';
            return null;
        }
    }

    return [
        'original' => $original,
        'tmp' => $tmp,
        'ext' => $ext,
    ];
}

function post_assign_store_upload(array $upload, array &$errors): ?array
{
    $uploadDir = __DIR__ . '/../uploads/assessment_docs';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
        $errors[] = 'Unable to create the assignment upload folder.';
        return null;
    }

    $base = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo((string)$upload['original'], PATHINFO_FILENAME));
    $base = trim((string)$base, '_');
    if ($base === '') {
        $base = 'assignment';
    }

    $filename = $base . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $upload['ext'];
    $target = $uploadDir . '/' . $filename;
    if (!@move_uploaded_file((string)$upload['tmp'], $target)) {
        $errors[] = 'Unable to save uploaded file.';
        return null;
    }

    return [
        'absolute' => $target,
        'relative' => 'uploads/assessment_docs/' . $filename,
    ];
}

$staffId = (string)($_SESSION['staff_id'] ?? '');
$records = getLecturerCourseDetails($db, $staffId);
$courseNames = [];
foreach ($records as $record) {
    $courseNames[(string)$record['course_code']] = (string)$record['course_name'];
}

$errors = [];
$success = '';
$old = [
    'title' => '',
    'course_code' => '',
    'description' => '',
    'due_at' => '',
];

$assignmentTableReady = elearningTableExists($db, 'el_assignments');
if ($assignmentTableReady) {
    $requiredColumns = [
        'assessment_type' => "VARCHAR(20) NOT NULL DEFAULT 'assignment'",
        'attachment_path' => 'VARCHAR(255) NULL',
        'due_at' => 'DATETIME NULL',
        'created_by' => 'VARCHAR(64) NULL',
        'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
    ];

    // One SHOW COLUMNS covers every check; per-column INFORMATION_SCHEMA
    // lookups here have blocked for the full execution limit under load.
    $existingColumns = post_assign_table_columns($db, 'el_assignments');
    foreach ($requiredColumns as $column => $definition) {
        if (isset($existingColumns[strtolower($column)])) {
            continue;
        }
        if (!post_assign_ensure_column($db, 'el_assignments', $column, $definition)) {
            $assignmentTableReady = false;
            error_log('lecturers/post_assign.php: failed to ensure el_assignments.' . $column . ': ' . $db->error);
            $errors[] = 'Assignment storage is not ready. Please contact the administrator.';
            break;
        }
    }
} else {
    $errors[] = 'Assignment storage is not installed yet. Please contact the administrator.';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['submit'])) {
    wuc_verify_csrf();

    $old = [
        'title' => trim((string)($_POST['title'] ?? '')),
        'course_code' => trim((string)($_POST['course_code'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'due_at' => trim((string)($_POST['due_at'] ?? '')),
    ];

    $title = $old['title'];
    $courseCode = $old['course_code'];
    $description = $old['description'];
    $dueInput = $old['due_at'];

    if (!$assignmentTableReady) {
        $errors[] = 'Assignment storage is not available.';
    }
    if ($title === '') {
        $errors[] = 'Title is required.';
    } elseif (mb_strlen($title) > 255) {
        $errors[] = 'Title must be 255 characters or fewer.';
    }
    if ($courseCode === '') {
        $errors[] = 'Course is required.';
    } elseif (!isLecturerAssignedToCourse($db, $staffId, $courseCode)) {
        $errors[] = 'You are not assigned to this course.';
    }
    if (mb_strlen($description) > 12000) {
        $errors[] = 'Instructions are too long.';
    }

    $dueAt = null;
    if ($dueInput === '') {
        $errors[] = 'Due date and time is required.';
    } else {
        $dueDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $dueInput);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$dueDate || ($dateErrors !== false && ((int)$dateErrors['warning_count'] > 0 || (int)$dateErrors['error_count'] > 0))) {
            $errors[] = 'Invalid due date format.';
        } elseif ($dueDate <= new DateTimeImmutable('+1 minute')) {
            $errors[] = 'Due date must be in the future.';
        } else {
            $dueAt = $dueDate->format('Y-m-d H:i:s');
        }
    }

    $pendingUpload = null;
    if (isset($_FILES['assignment_file']) && is_array($_FILES['assignment_file'])) {
        $pendingUpload = post_assign_validate_upload($_FILES['assignment_file'], $errors);
    }

    $storedUpload = null;
    if (!$errors && $pendingUpload !== null) {
        $storedUpload = post_assign_store_upload($pendingUpload, $errors);
    }

    if (!$errors) {
        $kind = 'assignment';
        $attachmentPath = $storedUpload['relative'] ?? null;
        $columns = post_assign_table_columns($db, 'el_assignments');
        $hasCreatedAt = isset($columns['created_at']);
        $sql = $hasCreatedAt
            ? 'INSERT INTO el_assignments (course_code, title, description, due_at, assessment_type, attachment_path, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())'
            : 'INSERT INTO el_assignments (course_code, title, description, due_at, assessment_type, attachment_path, created_by) VALUES (?,?,?,?,?,?,?)';

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            $errors[] = 'Could not prepare assignment create query.';
            error_log('lecturers/post_assign.php: assignment create prepare failed: ' . $db->error);
        } else {
            $stmt->bind_param('sssssss', $courseCode, $title, $description, $dueAt, $kind, $attachmentPath, $staffId);
            if ($stmt->execute()) {
                wuc_flash('success', 'Assignment posted successfully. Students registered in this course can now submit from eLearning.');
                $stmt->close();
                wuc_safe_redirect('post_assign.php?posted=1');
            }

            $errors[] = 'Failed to post assignment.';
            error_log('lecturers/post_assign.php: assignment create execute failed: ' . $stmt->error);
            $stmt->close();
        }
    }

    if ($errors && $storedUpload !== null && is_file((string)$storedUpload['absolute'])) {
        @unlink((string)$storedUpload['absolute']);
    }
}

$flash = function_exists('wuc_get_flash') ? wuc_get_flash() : null;
if ($flash && ($flash['type'] ?? '') === 'success') {
    $success = (string)($flash['message'] ?? '');
}

$recentAssignments = post_assign_recent($db, $staffId);
$postedCount = count($recentAssignments);
$soonCount = 0;
$now = time();
foreach ($recentAssignments as $assignment) {
    $dueTs = !empty($assignment['due_at']) ? strtotime((string)$assignment['due_at']) : false;
    if ($dueTs !== false && $dueTs >= $now && $dueTs <= strtotime('+7 days')) {
        $soonCount++;
    }
}

require_once __DIR__ . '/includes/nav.php';
?>
<div class="container-fluid px-4 portal-dashboard assignment-post-page">
    <div class="dashboard-header lecturer-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title">Post Assignment</h1>
                <p class="text-muted mb-0">Create an eLearning assignment for one of your active course allocations.</p>
            </div>
            <div class="col-auto header-actions">
                <a class="btn btn-outline-primary" href="assessments.php">
                    <i class="fas fa-inbox me-2"></i>Submissions
                </a>
                <a class="btn btn-primary" href="upload_ca.php">
                    <i class="fas fa-upload me-2"></i>Upload CA
                </a>
            </div>
        </div>
    </div>

    <div class="assignment-command-bar mb-4" role="navigation" aria-label="Assessment navigation">
        <a class="command-link" href="assessments.php">
            <i class="fas fa-file-alt"></i>
            <span>Student Submissions</span>
        </a>
        <a class="command-link" href="upload_ca.php">
            <i class="fas fa-upload"></i>
            <span>Upload CA</span>
        </a>
        <a class="command-link active" href="post_assign.php" aria-current="page">
            <i class="fas fa-tasks"></i>
            <span>Post Assignments</span>
        </a>
        <a class="command-link" href="ai_question_bank.php">
            <i class="fas fa-wand-magic-sparkles"></i>
            <span>AI Question Bank</span>
        </a>
        <a class="command-link" href="viewCaRes.php">
            <i class="fas fa-eye"></i>
            <span>View CA Results</span>
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger assignment-alert" role="alert">
            <div class="fw-semibold mb-1"><i class="fas fa-exclamation-circle me-2"></i>Check the assignment details</div>
            <?php foreach (array_unique($errors) as $error): ?>
                <div><?php echo post_assign_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success assignment-alert" role="status">
            <i class="fas fa-check-circle me-2"></i><?php echo post_assign_h($success); ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="assignment-workspace">
                <div class="assignment-workspace-head">
                    <div>
                        <span class="eyebrow">Assignment composer</span>
                        <h2>Details students will see</h2>
                    </div>
                    <div class="assignment-mini-stat" title="Active assigned courses">
                        <span><?php echo count($records); ?></span>
                        <small>courses</small>
                    </div>
                </div>

                <form action="post_assign.php" method="post" enctype="multipart/form-data" class="needs-validation assignment-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo post_assign_h($_SESSION['csrf_token'] ?? ''); ?>">

                    <div class="row g-3">
                        <div class="col-12">
                            <label for="title" class="form-label">Title</label>
                            <input
                                type="text"
                                class="form-control form-control-lg"
                                id="title"
                                name="title"
                                maxlength="255"
                                value="<?php echo post_assign_h($old['title']); ?>"
                                placeholder="Example: Health Promotion Case Study"
                                required
                            >
                            <div class="invalid-feedback">Please enter a title.</div>
                        </div>

                        <div class="col-lg-7">
                            <label for="course_code" class="form-label">Course</label>
                            <select class="form-select form-select-lg" name="course_code" id="course_code" required>
                                <option value="" disabled <?php echo $old['course_code'] === '' ? 'selected' : ''; ?>>Select course</option>
                                <?php foreach ($records as $r): ?>
                                    <?php
                                    $code = (string)$r['course_code'];
                                    $selected = strcasecmp($old['course_code'], $code) === 0 ? 'selected' : '';
                                    ?>
                                    <option
                                        value="<?php echo post_assign_h($code); ?>"
                                        data-course-name="<?php echo post_assign_h($r['course_name']); ?>"
                                        <?php echo $selected; ?>
                                    >
                                        <?php echo post_assign_h($code . ' - ' . $r['course_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a course.</div>
                            <?php if (!$records): ?>
                                <div class="assignment-empty-note mt-2">
                                    <i class="fas fa-lock me-2"></i>No active courses are assigned to your lecturer account.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-lg-5">
                            <label for="due_at" class="form-label">Due date and time</label>
                            <input
                                type="datetime-local"
                                class="form-control form-control-lg"
                                id="due_at"
                                name="due_at"
                                value="<?php echo post_assign_h($old['due_at']); ?>"
                                required
                            >
                            <div class="invalid-feedback">Select a future due date and time.</div>
                        </div>

                        <div class="col-12">
                            <div class="due-presets" aria-label="Due date shortcuts">
                                <button type="button" class="due-chip" data-days="3">+3 days</button>
                                <button type="button" class="due-chip" data-days="7">+1 week</button>
                                <button type="button" class="due-chip" data-days="14">+2 weeks</button>
                                <button type="button" class="due-chip" data-days="30">+1 month</button>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="assignment-template-row" aria-label="Instruction starters">
                                <button type="button" class="template-chip" data-template="Read the attached brief, complete the required tasks, and submit one file before the deadline. Late submissions may be flagged for review.">Brief + upload</button>
                                <button type="button" class="template-chip" data-template="Answer each question clearly. Include references where applicable and keep your work in the requested format.">Question set</button>
                                <button type="button" class="template-chip" data-template="Work individually. Use the case information provided in class and support your recommendations with course concepts.">Case study</button>
                            </div>
                        </div>

                        <div class="col-12">
                            <label for="description" class="form-label">Instructions</label>
                            <textarea
                                class="form-control"
                                id="description"
                                name="description"
                                rows="7"
                                maxlength="12000"
                                placeholder="Enter assignment instructions, required format, grading expectations, and submission notes."
                            ><?php echo post_assign_h($old['description']); ?></textarea>
                            <div class="assignment-field-meta">
                                <span>Students see this inside eLearning.</span>
                                <span><span id="descriptionCount">0</span>/12000</span>
                            </div>
                        </div>

                        <div class="col-12">
                            <label for="assignment_file" class="form-label">Attachment</label>
                            <label class="assignment-dropzone" for="assignment_file" id="assignmentDropzone">
                                <input type="file" id="assignment_file" name="assignment_file" accept=".pdf,.doc,.docx">
                                <span class="dropzone-icon"><i class="fas fa-paperclip"></i></span>
                                <span class="dropzone-copy">
                                    <strong id="fileName">Attach a brief, marking guide, or document</strong>
                                    <small>PDF, DOC, or DOCX up to 10MB</small>
                                </span>
                            </label>
                        </div>

                        <div class="col-12">
                            <div class="assignment-submit-row">
                                <button type="submit" name="submit" class="btn btn-primary btn-lg" <?php echo (!$records || !$assignmentTableReady) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-paper-plane me-2"></i>Post Assignment
                                </button>
                                <a class="btn btn-outline-secondary btn-lg" href="assessments.php">
                                    <i class="fas fa-list-check me-2"></i>Review Submissions
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-xl-4">
            <aside class="assignment-side-panel">
                <div class="course-preview">
                    <span class="preview-label">Selected course</span>
                    <h3 id="coursePreviewCode"><?php echo $old['course_code'] !== '' ? post_assign_h($old['course_code']) : 'Choose a course'; ?></h3>
                    <p id="coursePreviewName"><?php echo $old['course_code'] !== '' ? post_assign_h($courseNames[$old['course_code']] ?? $old['course_code']) : 'The course summary updates as you select an allocation.'; ?></p>
                </div>

                <div class="assignment-stats-grid">
                    <div>
                        <span><?php echo $postedCount; ?></span>
                        <small>recent posts</small>
                    </div>
                    <div>
                        <span><?php echo $soonCount; ?></span>
                        <small>due this week</small>
                    </div>
                </div>

                <div class="recent-assignment-list">
                    <div class="side-panel-title">
                        <i class="fas fa-clock"></i>
                        <span>Recently posted</span>
                    </div>
                    <?php if ($recentAssignments): ?>
                        <?php foreach ($recentAssignments as $assignment): ?>
                            <div class="recent-assignment-item">
                                <div>
                                    <strong><?php echo post_assign_h($assignment['title'] ?? 'Untitled assignment'); ?></strong>
                                    <span><?php echo post_assign_h(($assignment['course_code'] ?? '') . ' - ' . post_assign_format_due($assignment['due_at'] ?? null)); ?></span>
                                </div>
                                <?php if (!empty($assignment['attachment_path'])): ?>
                                    <i class="fas fa-paperclip" title="Has attachment" aria-label="Has attachment"></i>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="assignment-empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>No recent assignments posted from this account.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const dueInput = document.getElementById('due_at');
    const courseSelect = document.getElementById('course_code');
    const coursePreviewCode = document.getElementById('coursePreviewCode');
    const coursePreviewName = document.getElementById('coursePreviewName');
    const description = document.getElementById('description');
    const descriptionCount = document.getElementById('descriptionCount');
    const fileInput = document.getElementById('assignment_file');
    const fileName = document.getElementById('fileName');
    const dropzone = document.getElementById('assignmentDropzone');

    const pad = value => String(value).padStart(2, '0');
    const formatLocalDateTime = function(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    };

    const setMinDue = function() {
        if (!dueInput) {
            return;
        }
        const now = new Date();
        now.setMinutes(now.getMinutes() + 5);
        dueInput.min = formatLocalDateTime(now);
    };

    const setDuePreset = function(days) {
        if (!dueInput) {
            return;
        }
        const due = new Date();
        due.setDate(due.getDate() + Number(days));
        due.setHours(23, 59, 0, 0);
        dueInput.value = formatLocalDateTime(due);
        dueInput.dispatchEvent(new Event('input', { bubbles: true }));
    };

    const updateCoursePreview = function() {
        if (!courseSelect || !coursePreviewCode || !coursePreviewName) {
            return;
        }
        const option = courseSelect.options[courseSelect.selectedIndex];
        const hasCourse = option && option.value;
        coursePreviewCode.textContent = hasCourse ? option.value : 'Choose a course';
        coursePreviewName.textContent = hasCourse
            ? (option.dataset.courseName || option.textContent.trim())
            : 'The course summary updates as you select an allocation.';
    };

    const updateDescriptionCount = function() {
        if (description && descriptionCount) {
            descriptionCount.textContent = String(description.value.length);
        }
    };

    const updateFileName = function() {
        if (!fileInput || !fileName) {
            return;
        }
        const file = fileInput.files && fileInput.files.length ? fileInput.files[0] : null;
        fileName.textContent = file ? file.name : 'Attach a brief, marking guide, or document';
        if (dropzone) {
            dropzone.classList.toggle('has-file', Boolean(file));
        }
    };

    setMinDue();
    setInterval(setMinDue, 60000);
    updateCoursePreview();
    updateDescriptionCount();
    updateFileName();

    document.querySelectorAll('.due-chip').forEach(button => {
        button.addEventListener('click', () => setDuePreset(button.dataset.days || '7'));
    });

    document.querySelectorAll('.template-chip').forEach(button => {
        button.addEventListener('click', () => {
            if (!description) {
                return;
            }
            const template = button.dataset.template || '';
            const separator = description.value.trim() === '' ? '' : "\n\n";
            description.value = description.value.trim() + separator + template;
            description.focus();
            updateDescriptionCount();
        });
    });

    if (courseSelect) {
        courseSelect.addEventListener('change', updateCoursePreview);
    }
    if (description) {
        description.addEventListener('input', updateDescriptionCount);
    }
    if (fileInput) {
        fileInput.addEventListener('change', updateFileName);
    }
    if (dropzone) {
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, event => {
                event.preventDefault();
                dropzone.classList.add('is-dragging');
            });
        });
        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, event => {
                event.preventDefault();
                dropzone.classList.remove('is-dragging');
            });
        });
        dropzone.addEventListener('drop', event => {
            if (fileInput && event.dataTransfer && event.dataTransfer.files.length) {
                fileInput.files = event.dataTransfer.files;
                updateFileName();
            }
        });
    }

    document.querySelectorAll('.needs-validation').forEach(form => {
        form.addEventListener('submit', event => {
            if (dueInput) {
                dueInput.setCustomValidity('');
            }

            let valid = form.checkValidity();
            if (dueInput && dueInput.value) {
                const due = new Date(dueInput.value).getTime();
                if (Number.isNaN(due) || due <= Date.now()) {
                    valid = false;
                    dueInput.setCustomValidity('Due date must be in the future.');
                }
            }

            if (!valid) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
