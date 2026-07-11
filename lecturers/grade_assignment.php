<?php
$page_title = 'Grade Assignment';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/assignment_storage.php';
require_once __DIR__ . '/includes/nav.php';

function grade_assignment_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function grade_assignment_url(?string $path): string
{
    return assignmentStoragePublicUrl($path);
}

$staffId = (string)($_SESSION['staff_id'] ?? '');
assignmentStorageEnsureSchema($db);
$submissionId = (int)($_GET['submission_id'] ?? $_POST['submission_id'] ?? 0);
$errors = [];
$success = '';
$submission = null;
$grade = null;

if ($submissionId <= 0) {
    $errors[] = 'Invalid submission.';
} else {
    $sql = "SELECT
                s.id AS submission_id,
                s.assignment_id,
                s.Sid,
                s.submitted_at,
                s.file_path,
                s.text_body,
                s.turnitin_score,
                s.storage_provider,
                s.drive_file_id,
                s.drive_web_url,
                s.drive_folder_id,
                s.archive_file_path,
                s.archive_original_name,
                s.archive_moved_at,
                s.local_file_deleted_at,
                s.ai_detector_provider,
                s.ai_score,
                s.ai_status,
                s.ai_report,
                s.ai_checked_at,
                a.course_code,
                a.title AS assignment_title,
                a.description AS assignment_description,
                a.due_at,
                c.course_name,
                st.Fname,
                st.Lname
            FROM el_submissions s
            INNER JOIN el_assignments a ON a.id = s.assignment_id
            LEFT JOIN courses c ON UPPER(TRIM(c.course_code)) = UPPER(TRIM(a.course_code))
            LEFT JOIN students st ON st.SID = s.Sid
            WHERE s.id = ?
            LIMIT 1";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('i', $submissionId);
        $stmt->execute();
        $submission = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        error_log('lecturers/grade_assignment.php: submission query prepare failed: ' . $db->error);
        $errors[] = 'Unable to load submission.';
    }
}

if ($submission && !isLecturerAssignedToCourse($db, $staffId, (string)$submission['course_code'])) {
    http_response_code(403);
    $errors[] = 'You are not assigned to this course.';
    $submission = null;
}

if ($submission && ($stmt = $db->prepare("SELECT * FROM el_grades WHERE assignment_id=? AND Sid=? ORDER BY graded_at DESC, id DESC LIMIT 1"))) {
    $assignmentId = (int)$submission['assignment_id'];
    $sid = (string)$submission['Sid'];
    $stmt->bind_param('is', $assignmentId, $sid);
    $stmt->execute();
    $grade = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($submission && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $markRaw = trim((string)($_POST['total_points'] ?? ''));
    $feedback = trim((string)($_POST['feedback'] ?? ''));
    $mark = filter_var($markRaw, FILTER_VALIDATE_FLOAT);

    if ($mark === false || $mark < 0 || $mark > 100) {
        $errors[] = 'Mark must be a number between 0 and 100.';
    }
    if (strlen($feedback) > 5000) {
        $errors[] = 'Feedback is too long.';
    }

    if (!$errors) {
        $assignmentId = (int)$submission['assignment_id'];
        $sid = (string)$submission['Sid'];
        $existingId = $grade ? (int)$grade['id'] : 0;

        if ($existingId > 0) {
            $stmt = $db->prepare("UPDATE el_grades SET graded_by=?, graded_at=NOW(), total_points=?, feedback=? WHERE id=?");
            if ($stmt) {
                $stmt->bind_param('sdsi', $staffId, $mark, $feedback, $existingId);
                if ($stmt->execute()) {
                    $success = 'Mark updated successfully.';
                } else {
                    $errors[] = 'Failed to update mark.';
                }
                $stmt->close();
            } else {
                $errors[] = 'Could not prepare grade update.';
            }
        } else {
            $stmt = $db->prepare("INSERT INTO el_grades (assignment_id, Sid, graded_by, graded_at, total_points, feedback) VALUES (?,?,?,NOW(),?,?)");
            if ($stmt) {
                $stmt->bind_param('issds', $assignmentId, $sid, $staffId, $mark, $feedback);
                if ($stmt->execute()) {
                    $success = 'Mark awarded successfully.';
                } else {
                    $errors[] = 'Failed to save mark.';
                }
                $stmt->close();
            } else {
                $errors[] = 'Could not prepare grade insert.';
            }
        }

        if (!$errors && ($stmt = $db->prepare("SELECT * FROM el_grades WHERE assignment_id=? AND Sid=? ORDER BY graded_at DESC, id DESC LIMIT 1"))) {
            $stmt->bind_param('is', $assignmentId, $sid);
            $stmt->execute();
            $grade = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
}

$studentName = '';
if ($submission) {
    $studentName = trim((string)($submission['Fname'] ?? '') . ' ' . (string)($submission['Lname'] ?? ''));
    if ($studentName === '') {
        $studentName = (string)$submission['Sid'];
    }
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Grade Assignment</h1>
                <p class="text-muted">Review the student submission and award marks out of 100.</p>
            </div>
            <div class="col-auto">
                <a href="assessments.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Submissions
                </a>
            </div>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $error): ?>
                <div><i class="fas fa-exclamation-circle me-2"></i><?php echo grade_assignment_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i><?php echo grade_assignment_h($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($submission): ?>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="data-table-card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Submission</h5>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Student</dt>
                            <dd class="col-sm-8"><?php echo grade_assignment_h($studentName); ?> <span class="text-muted">(<?php echo grade_assignment_h($submission['Sid']); ?>)</span></dd>

                            <dt class="col-sm-4">Course</dt>
                            <dd class="col-sm-8"><?php echo grade_assignment_h($submission['course_code'] . ' - ' . ($submission['course_name'] ?: $submission['course_code'])); ?></dd>

                            <dt class="col-sm-4">Assignment</dt>
                            <dd class="col-sm-8"><?php echo grade_assignment_h($submission['assignment_title']); ?></dd>

                            <dt class="col-sm-4">Submitted</dt>
                            <dd class="col-sm-8"><?php echo grade_assignment_h($submission['submitted_at']); ?></dd>

                            <dt class="col-sm-4">Due</dt>
                            <dd class="col-sm-8"><?php echo grade_assignment_h($submission['due_at'] ?: 'No due date'); ?></dd>
                        </dl>

                        <?php if (!empty($submission['file_path']) || !empty($submission['drive_web_url']) || !empty($submission['archive_file_path'])): ?>
                            <?php
                                $submittedFileUrl = assignmentStorageSubmissionUrl($submission);
                                $isDriveFile = !empty($submission['drive_web_url']) || !empty($submission['drive_file_id']);
                                $isArchivedFile = !$isDriveFile && !empty($submission['archive_file_path']);
                            ?>
                            <a href="<?php echo grade_assignment_h($submittedFileUrl); ?>" target="_blank" class="btn btn-primary mt-3">
                                <i class="<?php echo $isDriveFile ? 'fab fa-google-drive' : ($isArchivedFile ? 'fas fa-box-archive' : 'fas fa-download'); ?> me-2"></i><?php echo $isDriveFile ? 'Open from Google Drive' : ($isArchivedFile ? 'Download from Archive' : 'Open Submitted File'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="data-table-card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-brain me-2"></i>AI Writing Review</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($submission['ai_status'])): ?>
                            <?php
                                $aiStatus = (string)$submission['ai_status'];
                                $aiBadge = $aiStatus === 'high' ? 'danger' : ($aiStatus === 'medium' ? 'warning text-dark' : ($aiStatus === 'low' ? 'success' : 'secondary'));
                            ?>
                            <p class="mb-2">
                                <span class="badge bg-<?php echo grade_assignment_h($aiBadge); ?>"><?php echo grade_assignment_h(ucfirst($aiStatus)); ?></span>
                                <?php if ($submission['ai_score'] !== null): ?>
                                    <strong class="ms-2"><?php echo grade_assignment_h(number_format((float)$submission['ai_score'], 1)); ?>%</strong>
                                <?php endif; ?>
                                <span class="text-muted ms-2"><?php echo grade_assignment_h($submission['ai_detector_provider'] ?: 'local review'); ?></span>
                            </p>
                            <p class="mb-0 text-muted"><?php echo grade_assignment_h($submission['ai_report'] ?: 'No report details available.'); ?></p>
                        <?php else: ?>
                            <p class="text-muted mb-0">This submission has not been checked by the AI-writing review adapter.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="data-table-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-align-left me-2"></i>Text Response</h5>
                    </div>
                    <div class="card-body">
                        <?php if (trim((string)$submission['text_body']) !== ''): ?>
                            <div class="p-3 rounded bg-light"><?php echo nl2br(grade_assignment_h($submission['text_body'])); ?></div>
                        <?php else: ?>
                            <p class="text-muted mb-0">No text response was provided.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="data-table-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-star me-2"></i>Award Marks</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" class="needs-validation" novalidate>
                            <input type="hidden" name="submission_id" value="<?php echo (int)$submission['submission_id']; ?>">
                            <div class="mb-3">
                                <label for="total_points" class="form-label">Mark out of 100</label>
                                <input type="number" step="0.01" min="0" max="100" class="form-control" id="total_points" name="total_points" value="<?php echo grade_assignment_h($grade['total_points'] ?? ''); ?>" required>
                                <div class="invalid-feedback">Enter a mark between 0 and 100.</div>
                            </div>
                            <div class="mb-2 d-flex justify-content-between align-items-center">
                                <label for="feedback" class="form-label mb-0">Feedback</label>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="aiDraftBtn"
                                        data-submission-id="<?php echo (int)$submission['submission_id']; ?>">
                                    <i class="fas fa-wand-magic-sparkles me-1"></i>Draft with AI
                                </button>
                            </div>
                            <div id="aiFeedbackNote" class="small mb-2"></div>
                            <div class="mb-3">
                                <textarea class="form-control" id="feedback" name="feedback" rows="6" placeholder="Optional feedback for the student"><?php echo grade_assignment_h($grade['feedback'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-save me-2"></i><?php echo $grade ? 'Update Mark' : 'Save Mark'; ?>
                            </button>
                        </form>
                        <p class="text-muted small mt-2 mb-0">
                            <i class="fas fa-circle-info me-1"></i>AI feedback is a draft suggestion — review and edit before saving.
                        </p>
                        <input type="hidden" id="aiCsrf" value="<?php echo grade_assignment_h($_SESSION['csrf_token'] ?? ''); ?>">

                        <?php if ($grade): ?>
                            <hr>
                            <p class="mb-1"><strong>Current mark:</strong> <?php echo grade_assignment_h(number_format((float)$grade['total_points'], 2)); ?>/100</p>
                            <p class="mb-0 text-muted">Last marked: <?php echo grade_assignment_h($grade['graded_at']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(function() {
    'use strict';
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();

// AI feedback drafting
(function() {
    'use strict';
    const btn = document.getElementById('aiDraftBtn');
    if (!btn) return;
    const note = document.getElementById('aiFeedbackNote');
    const feedbackEl = document.getElementById('feedback');
    const markEl = document.getElementById('total_points');
    const csrf = (document.getElementById('aiCsrf') || {}).value || '';

    btn.addEventListener('click', async function() {
        const submissionId = btn.getAttribute('data-submission-id');
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Drafting…';
        note.className = 'small mb-2 text-muted';
        note.textContent = 'Generating feedback from the submission…';

        try {
            const resp = await fetch('ai_feedback_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ submission_id: submissionId, csrf_token: csrf })
            });
            const data = await resp.json();
            if (!resp.ok || !data.ok) {
                note.className = 'small mb-2 text-danger';
                note.textContent = data.message || ('Request failed (HTTP ' + resp.status + ')');
                return;
            }
            if (data.feedback) {
                feedbackEl.value = data.feedback;
            }
            if (data.suggested_mark !== '' && markEl && !markEl.value) {
                markEl.value = data.suggested_mark;
            }
            if (data.used_ai) {
                note.className = 'small mb-2 text-success';
                note.innerHTML = '<i class="fas fa-robot me-1"></i>Draft generated by ' + (data.model || 'AI')
                    + (data.suggested_mark !== '' ? ' · suggested mark ' + data.suggested_mark + '/100' : '')
                    + '. Review and edit before saving.';
            } else {
                note.className = 'small mb-2 text-warning';
                note.textContent = data.note || 'AI offline — a manual review checklist was inserted.';
            }
        } catch (e) {
            note.className = 'small mb-2 text-danger';
            note.textContent = 'Could not reach the AI service. Please try again.';
        } finally {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
