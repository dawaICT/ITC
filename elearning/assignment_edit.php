<?php
error_reporting(0);
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/assignment_storage.php';
require_once __DIR__ . '/../includes/elearning_assessment_security.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = $_GET['course_code'] ?? ($_POST['course_code'] ?? '');
$assignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : (isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0);
if (!$staffId || !$courseCode) { die('Unauthorized'); }

// Verify lecturer has access to this course
enforceLecturerCourseAccess($db, $staffId, $courseCode);
assignmentStorageEnsureSchema($db);
elearningAssessmentEnsureSchema($db);
$courseOfferingId = getLecturerCourseOfferingId($db, $staffId, $courseCode);
$assignmentHasOffering = elearningTableHasCourseOffering($db, 'el_assignments');

$errors = [];
$successMsg = '';
$csrfToken = elearningAssessmentCsrfToken();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!elearningAssessmentValidateCsrf($_POST)) {
        $errors[] = 'Security check failed. Please refresh the page and try again.';
    }
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $dueAt = trim($_POST['due_at'] ?? '');
    $moduleId = isset($_POST['module_id']) && $_POST['module_id'] !== '' ? (int)$_POST['module_id'] : null;
    $rubricId = isset($_POST['rubric_id']) && $_POST['rubric_id'] !== '' ? (int)$_POST['rubric_id'] : null;
    $allowPeerReview = isset($_POST['allow_peer_review']) ? 1 : 0;
    $anonymity = isset($_POST['anonymity']) ? 1 : 0;
    $settingsJson = elearningAssessmentJson(elearningAssessmentAssignmentSettingsFromPost($_POST));

    if ($title === '') { $errors[] = 'Title is required'; }

    // Normalize datetime-local to MySQL DATETIME
    if ($dueAt !== '') {
        $dueAt = str_replace('T', ' ', $dueAt);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dueAt)) { $dueAt .= ':00'; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dueAt)) {
            $errors[] = 'Invalid due date format.';
        }
    } else {
        $dueAt = null;
    }

    if (!$errors) {
        if ($assignmentId > 0) {
            if ($assignmentHasOffering && $courseOfferingId !== null) {
                $stmt = $db->prepare("UPDATE el_assignments SET title=?, description=?, module_id=?, due_at=?, rubric_id=?, allow_peer_review=?, anonymity=?, settings_json=?, course_offering_id=COALESCE(course_offering_id, ?) WHERE id=? AND course_code=? AND (course_offering_id=? OR course_offering_id IS NULL)");
                $stmt->bind_param('ssisiiisiisi', $title, $description, $moduleId, $dueAt, $rubricId, $allowPeerReview, $anonymity, $settingsJson, $courseOfferingId, $assignmentId, $courseCode, $courseOfferingId);
            } else {
                $stmt = $db->prepare("UPDATE el_assignments SET title=?, description=?, module_id=?, due_at=?, rubric_id=?, allow_peer_review=?, anonymity=?, settings_json=? WHERE id=? AND course_code=?");
                $stmt->bind_param('ssisiiisis', $title, $description, $moduleId, $dueAt, $rubricId, $allowPeerReview, $anonymity, $settingsJson, $assignmentId, $courseCode);
            }
            $stmt->execute();
            $stmt->close();
            $successMsg = 'Assignment updated successfully.';
        } else {
            if ($assignmentHasOffering) {
                $stmt = $db->prepare("INSERT INTO el_assignments (course_offering_id, course_code, module_id, title, description, due_at, rubric_id, allow_peer_review, anonymity, settings_json, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('isisssiiiss', $courseOfferingId, $courseCode, $moduleId, $title, $description, $dueAt, $rubricId, $allowPeerReview, $anonymity, $settingsJson, $staffId);
            } else {
                $stmt = $db->prepare("INSERT INTO el_assignments (course_code, module_id, title, description, due_at, rubric_id, allow_peer_review, anonymity, settings_json, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('sisssiiiss', $courseCode, $moduleId, $title, $description, $dueAt, $rubricId, $allowPeerReview, $anonymity, $settingsJson, $staffId);
            }
            $stmt->execute();
            $assignmentId = (int)$stmt->insert_id;
            $stmt->close();
            $successMsg = 'Assignment created successfully.';
        }
        header('Location: assignment_edit.php?course_code=' . urlencode($courseCode) . '&assignment_id=' . $assignmentId);
        exit;
    }
}

// Load assignment data
$assignment = null;
if ($assignmentId > 0) {
    if ($assignmentHasOffering && $courseOfferingId !== null) {
        $stmt = $db->prepare("SELECT * FROM el_assignments WHERE id=? AND course_code=? AND (course_offering_id=? OR course_offering_id IS NULL) LIMIT 1");
        $stmt->bind_param('isi', $assignmentId, $courseCode, $courseOfferingId);
    } else {
        $stmt = $db->prepare("SELECT * FROM el_assignments WHERE id=? AND course_code=? LIMIT 1");
        $stmt->bind_param('is', $assignmentId, $courseCode);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $assignment = $res->fetch_assoc();
    $stmt->close();
}
$assignmentSettings = elearningAssessmentSettings($assignment['settings_json'] ?? null, [
    'allow_text_response' => true,
    'allow_file_upload' => true,
    'allowed_file_types' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png'],
    'max_file_mb' => 10,
    'ai_check_enabled' => true,
    'plagiarism_check_enabled' => true,
]);

// Load modules for dropdown
$modules = [];
$courseOfferingIds = getLecturerCourseOfferingIds($db, $staffId, $courseCode);
$types = 's';
$params = [$courseCode];
$offeringSql = elearningOfferingScopeCondition($db, 'el_course_modules', null, $courseOfferingIds, $types, $params);
if ($stmt = $db->prepare("SELECT id, title FROM el_course_modules WHERE course_code=? {$offeringSql} ORDER BY position, id")) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $modules[] = $row; }
    $stmt->close();
}

// Load rubrics for dropdown
$rubrics = [];
if ($res = $db->query("SELECT id, title FROM el_rubrics ORDER BY title")) {
    while ($row = $res->fetch_assoc()) { $rubrics[] = $row; }
    $res->free();
}

// Load submissions if editing
$submissions = [];
if ($assignmentId > 0) {
    if ($stmt = $db->prepare("SELECT s.*, g.total_points, g.graded_at FROM el_submissions s LEFT JOIN el_grades g ON g.assignment_id = s.assignment_id AND g.Sid = s.Sid WHERE s.assignment_id=? ORDER BY s.submitted_at DESC")) {
        $stmt->bind_param('i', $assignmentId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $submissions[] = $row; }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $assignment ? 'Edit Assignment' : 'New Assignment'; ?> - <?php echo htmlspecialchars($courseCode); ?></title>
    <link rel="stylesheet" href="../admin/css/admin-style.css">
    <link rel="stylesheet" href="../admin/css/admin-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .submission-row { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 12px 15px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .badge-graded { background: #28a745; color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 0.8rem; }
        .badge-pending { background: #ffc107; color: #000; padding: 3px 8px; border-radius: 4px; font-size: 0.8rem; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../lecturers/includes/nav.php'; ?>

<div class="content-wrapper">
    <h2><i class="fas fa-file-alt"></i> <?php echo $assignment ? 'Edit Assignment' : 'Create New Assignment'; ?></h2>
    <p class="text-muted">Course: <?php echo htmlspecialchars($courseCode); ?></p>
    <hr>
    
    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
    <?php endif; ?>
    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMsg); ?></div>
    <?php endif; ?>
    
    <!-- Assignment Details Form -->
    <div class="card mb-4">
        <div class="card-header"><strong>Assignment Details</strong></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
                <input type="hidden" name="assignment_id" value="<?php echo (int)$assignmentId; ?>">
                <?php echo elearningAssessmentCsrfField(); ?>
                
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Title *</label>
                        <input class="form-control" type="text" name="title" value="<?php echo htmlspecialchars($assignment['title'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Module (optional)</label>
                        <select class="form-select" name="module_id">
                            <option value="">-- No Module --</option>
                            <?php foreach ($modules as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>" <?php echo (isset($assignment['module_id']) && $assignment['module_id'] == $m['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Description / Instructions</label>
                    <textarea class="form-control" name="description" rows="5"><?php echo htmlspecialchars($assignment['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Due Date</label>
                        <input class="form-control" type="datetime-local" name="due_at" value="<?php echo isset($assignment['due_at']) ? date('Y-m-d\TH:i', strtotime($assignment['due_at'])) : ''; ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Rubric (optional)</label>
                        <select class="form-select" name="rubric_id">
                            <option value="">-- No Rubric --</option>
                            <?php foreach ($rubrics as $r): ?>
                                <option value="<?php echo (int)$r['id']; ?>" <?php echo (isset($assignment['rubric_id']) && $assignment['rubric_id'] == $r['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($r['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" name="allow_peer_review" id="peerReview" <?php echo ($assignment && ($assignment['allow_peer_review'] ?? 0)) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="peerReview">Peer Review</label>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" name="anonymity" id="anonymity" <?php echo (!$assignment || ($assignment['anonymity'] ?? 1)) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="anonymity">Anonymous</label>
                        </div>
                    </div>
                </div>

                <div class="row p-3 mb-3 bg-light rounded">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Max File MB</label>
                        <input class="form-control" type="number" name="max_file_mb" min="1" max="50" value="<?php echo (int)($assignmentSettings['max_file_mb'] ?? 10); ?>">
                    </div>
                    <div class="col-md-9 mb-3">
                        <label class="form-label">Allowed File Types</label>
                        <input class="form-control" type="text" name="allowed_file_types" value="<?php echo htmlspecialchars(implode(',', (array)($assignmentSettings['allowed_file_types'] ?? []))); ?>">
                    </div>
                    <div class="col-md-12 d-flex flex-wrap gap-3">
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="allow_text_response" <?php echo !empty($assignmentSettings['allow_text_response']) ? 'checked' : ''; ?>> Text responses</label>
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="allow_file_upload" <?php echo !empty($assignmentSettings['allow_file_upload']) ? 'checked' : ''; ?>> File uploads</label>
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="ai_check_enabled" <?php echo !empty($assignmentSettings['ai_check_enabled']) ? 'checked' : ''; ?>> AI writing review</label>
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="plagiarism_check_enabled" <?php echo !empty($assignmentSettings['plagiarism_check_enabled']) ? 'checked' : ''; ?>> Plagiarism review</label>
                    </div>
                </div>
                
                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save Assignment</button>
                <a class="btn btn-secondary" href="assessments.php?course_code=<?php echo urlencode($courseCode); ?>">Cancel</a>
            </form>
        </div>
    </div>
    
    <?php if ($assignmentId > 0): ?>
    <!-- Submissions Section -->
    <div class="card">
        <div class="card-header"><strong>Submissions (<?php echo count($submissions); ?>)</strong></div>
        <div class="card-body">
            <?php foreach ($submissions as $sub): ?>
                <div class="submission-row">
                    <div>
                        <strong><?php echo htmlspecialchars($sub['Sid']); ?></strong>
                        <small class="text-muted">- Submitted: <?php echo htmlspecialchars($sub['submitted_at']); ?></small>
                        <?php if (!empty($sub['file_path']) || !empty($sub['drive_web_url'])): ?>
                            <?php
                                $subUrl = assignmentStorageSubmissionUrl($sub);
                                $subOnDrive = !empty($sub['drive_web_url']) || !empty($sub['drive_file_id']);
                            ?>
                            - <a href="<?php echo htmlspecialchars($subUrl); ?>" target="_blank"><i class="<?php echo $subOnDrive ? 'fab fa-google-drive' : 'fas fa-download'; ?>"></i> File</a>
                        <?php endif; ?>
                        <?php if (!empty($sub['turnitin_score'])): ?>
                            <small class="text-info">(Turnitin: <?php echo (int)$sub['turnitin_score']; ?>%)</small>
                        <?php endif; ?>
                        <?php if (!empty($sub['ai_status'])): ?>
                            <small class="text-muted">(AI: <?php echo htmlspecialchars(ucfirst((string)$sub['ai_status'])); ?><?php echo $sub['ai_score'] !== null ? ' ' . htmlspecialchars(number_format((float)$sub['ai_score'], 0)) . '%' : ''; ?>)</small>
                        <?php endif; ?>
                        <?php if (!empty($sub['plagiarism_status'])): ?>
                            <small class="text-muted">(Plagiarism: <?php echo htmlspecialchars(ucfirst((string)$sub['plagiarism_status'])); ?><?php echo $sub['plagiarism_score'] !== null ? ' ' . htmlspecialchars(number_format((float)$sub['plagiarism_score'], 0)) . '%' : ''; ?>)</small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($sub['graded_at']): ?>
                            <span class="badge-graded"><i class="fas fa-check"></i> <?php echo (float)$sub['total_points']; ?> pts</span>
                        <?php else: ?>
                            <span class="badge-pending">Needs Grading</span>
                            <a class="btn btn-sm btn-primary ms-2" href="grade_submission.php?assignment_id=<?php echo $assignmentId; ?>&sid=<?php echo urlencode($sub['Sid']); ?>&course_code=<?php echo urlencode($courseCode); ?>">
                                <i class="fas fa-pen"></i> Grade
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (count($submissions) === 0): ?>
                <p class="text-muted">No submissions yet.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
</body>
</html>
