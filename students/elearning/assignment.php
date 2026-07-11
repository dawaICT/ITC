<?php
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';
require_once __DIR__ . '/../../includes/assignment_storage.php';
require_once __DIR__ . '/../../includes/elearning_assessment_security.php';
require_once __DIR__ . '/../../includes/academic_risk_engine.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$sid = $_SESSION['Sid'] ?? null;
$assignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
$baseHref = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/wucportal/students/elearning/assignment.php'))), '/') . '/';
if (!$sid) {
    http_response_code(401);
    exit('Unauthorized');
}

function studentAssignmentCourseNames(mysqli $db, array $courseCodes): array
{
    if (!$courseCodes) {
        return [];
    }

    $names = [];
    foreach (['courses', 'program_courses'] as $tableName) {
        if (!elearningTableExists($db, $tableName)) {
            continue;
        }

        $courseCol = elearningDetectColumn($db, $tableName, ['course_code', 'code']);
        $nameCol = elearningDetectColumn($db, $tableName, ['course_name', 'name', 'title']);
        if ($courseCol === null || $nameCol === null) {
            continue;
        }

        $missing = array_values(array_diff($courseCodes, array_keys($names)));
        if (!$missing) {
            break;
        }

        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $types = str_repeat('s', count($missing));
        $sql = "SELECT `{$courseCol}` AS course_code, `{$nameCol}` AS course_name FROM `{$tableName}` WHERE `{$courseCol}` IN ({$placeholders})";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($types, ...$missing);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $code = trim((string)($row['course_code'] ?? ''));
                $name = trim((string)($row['course_name'] ?? ''));
                if ($code !== '' && $name !== '') {
                    $names[$code] = $name;
                }
            }
            $stmt->close();
        }
    }

    return $names;
}

function studentAssignmentFetchOverview(mysqli $db, string $sid, array $courseCodes, array $courseOfferingIds = []): array
{
    if (!$courseCodes || !elearningTableExists($db, 'el_assignments')) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
    $types = str_repeat('s', count($courseCodes));
    $courseParams = $courseCodes;
    $offeringSql = elearningOfferingScopeCondition($db, 'el_assignments', 'a', $courseOfferingIds, $types, $courseParams);
    $selectSubmission = 'NULL AS submitted_at';
    $joinSubmission = '';
    $selectGrade = 'NULL AS total_points, NULL AS graded_at';
    $joinGrade = '';
    $bindValues = [];
    $bindTypes = '';

    if (elearningTableExists($db, 'el_submissions')) {
        $selectSubmission = 'sub.submitted_at';
        $joinSubmission = "LEFT JOIN (
            SELECT assignment_id, MAX(submitted_at) AS submitted_at
            FROM el_submissions
            WHERE Sid = ?
            GROUP BY assignment_id
        ) sub ON sub.assignment_id = a.id";
        $bindTypes .= 's';
        $bindValues[] = $sid;
    }

    if (elearningTableExists($db, 'el_grades')) {
        $selectGrade = 'grd.total_points, grd.graded_at';
        $joinGrade = "LEFT JOIN (
            SELECT assignment_id, MAX(total_points) AS total_points, MAX(graded_at) AS graded_at
            FROM el_grades
            WHERE Sid = ?
            GROUP BY assignment_id
        ) grd ON grd.assignment_id = a.id";
        $bindTypes .= 's';
        $bindValues[] = $sid;
    }

    $sql = "SELECT a.*, {$selectSubmission}, {$selectGrade}
            FROM el_assignments a
            {$joinSubmission}
            {$joinGrade}
            WHERE a.course_code IN ({$placeholders})
            {$offeringSql}
            ORDER BY CASE WHEN a.due_at IS NULL THEN 1 ELSE 0 END, a.due_at ASC, a.id DESC";

    $assignments = [];
    if ($stmt = $db->prepare($sql)) {
        $allTypes = $bindTypes . $types;
        $allValues = array_merge($bindValues, $courseParams);
        $stmt->bind_param($allTypes, ...$allValues);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $assignments[] = $row;
        }
        $stmt->close();
    } else {
        error_log('students/elearning/assignment.php: overview prepare failed: ' . $db->error);
    }

    return $assignments;
}

function studentAssignmentStatus(array $assignment): array
{
    if (array_key_exists('total_points', $assignment) && $assignment['total_points'] !== null && $assignment['total_points'] !== '') {
        return ['label' => 'Graded', 'class' => 'badge-success'];
    }
    if (!empty($assignment['submitted_at'])) {
        return ['label' => 'Submitted', 'class' => 'badge-warning'];
    }

    $dueTs = !empty($assignment['due_at']) ? strtotime((string)$assignment['due_at']) : false;
    if ($dueTs !== false && $dueTs < time()) {
        return ['label' => 'Overdue', 'class' => 'badge-danger'];
    }
    if ($dueTs !== false && ($dueTs - time()) <= 24 * 60 * 60) {
        return ['label' => 'Due Soon', 'class' => 'badge-warning'];
    }

    return ['label' => 'Open', 'class' => 'badge-draft'];
}

function studentAssignmentFormatDate(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'No deadline';
    }

    $time = strtotime($value);
    return $time === false ? 'No deadline' : date('M j, Y g:i A', $time);
}

if ($assignmentId <= 0) {
    $enrolledCourses = getStudentEnrolledCourses($db, $sid);
    if (!is_array($enrolledCourses)) {
        $enrolledCourses = [];
    }
    $enrolledCourses = array_values(array_unique(array_filter(array_map(static fn($value) => trim((string)$value), $enrolledCourses))));
    $enrolledOfferingIds = getStudentCourseOfferingIds($db, $sid);
    $courseNames = studentAssignmentCourseNames($db, $enrolledCourses);
    $assignments = studentAssignmentFetchOverview($db, $sid, $enrolledCourses, $enrolledOfferingIds);

    $openCount = 0;
    $submittedCount = 0;
    $gradedCount = 0;
    $overdueCount = 0;
    foreach ($assignments as $item) {
        $status = studentAssignmentStatus($item);
        if ($status['label'] === 'Graded') {
            $gradedCount++;
        } elseif ($status['label'] === 'Submitted') {
            $submittedCount++;
        } elseif ($status['label'] === 'Overdue') {
            $overdueCount++;
        } else {
            $openCount++;
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments - eLearning</title>
    <base href="<?php echo htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/css/elearning-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .assignment-overview-header {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-left: 4px solid #1d4ed8;
            border-radius: 8px;
            padding: 22px;
            margin-bottom: 20px;
        }
        .assignment-overview-header h2 {
            margin: 0;
            font-size: 1.45rem;
            color: #0f172a;
        }
        .assignment-overview-header p {
            margin: 6px 0 0;
            color: #64748b;
        }
        .assignment-list {
            display: grid;
            gap: 12px;
        }
        .assignment-item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 14px;
            align-items: center;
            padding: 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
        }
        .assignment-item h3 {
            margin: 0 0 6px;
            font-size: 1rem;
            color: #0f172a;
        }
        .assignment-meta {
            color: #64748b;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 0.9rem;
        }
        .assignment-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: flex-end;
        }
        @media (max-width: 720px) {
            .assignment-item {
                grid-template-columns: 1fr;
            }
            .assignment-actions {
                justify-content: flex-start;
            }
        }
    </style>

<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="content-wrapper">
    <div style="margin-bottom: 20px;">
        <a href="elearning/index.php" style="color: #666; text-decoration: none;">
            <i class="fas fa-arrow-left"></i> Back to Learning Hub
        </a>
    </div>

    <div class="assignment-overview-header">
        <h2><i class="fas fa-clipboard-check"></i> Assignments & Quizzes</h2>
        <p>View assignments from your enrolled eLearning courses.</p>
    </div>

    <div class="elearning-stat-grid">
        <section class="elearning-stat-card">
            <span class="elearning-stat-label">Open</span>
            <strong><?php echo number_format($openCount); ?></strong>
        </section>
        <section class="elearning-stat-card">
            <span class="elearning-stat-label">Submitted</span>
            <strong><?php echo number_format($submittedCount); ?></strong>
        </section>
        <section class="elearning-stat-card">
            <span class="elearning-stat-label">Graded</span>
            <strong><?php echo number_format($gradedCount); ?></strong>
        </section>
        <section class="elearning-stat-card">
            <span class="elearning-stat-label">Overdue</span>
            <strong><?php echo number_format($overdueCount); ?></strong>
        </section>
    </div>

    <?php if (!$enrolledCourses): ?>
        <div class="alert alert-info"><i class="fas fa-info-circle"></i> You are not enrolled in any courses yet.</div>
    <?php elseif (!elearningTableExists($db, 'el_assignments')): ?>
        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Assignments are not available yet.</div>
    <?php elseif (!$assignments): ?>
        <div class="alert alert-info"><i class="fas fa-info-circle"></i> No assignments have been posted for your enrolled courses yet.</div>
    <?php else: ?>
        <div class="assignment-list">
            <?php foreach ($assignments as $item): ?>
                <?php
                $status = studentAssignmentStatus($item);
                $itemCourseCode = (string)($item['course_code'] ?? '');
                ?>
                <article class="assignment-item">
                    <div>
                        <h3><?php echo htmlspecialchars((string)($item['title'] ?? 'Untitled Assignment')); ?></h3>
                        <div class="assignment-meta">
                            <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($itemCourseCode); ?> - <?php echo htmlspecialchars($courseNames[$itemCourseCode] ?? $itemCourseCode); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo htmlspecialchars(studentAssignmentFormatDate($item['due_at'] ?? null)); ?></span>
                            <?php if (!empty($item['submitted_at'])): ?>
                                <span><i class="fas fa-paper-plane"></i> Submitted <?php echo htmlspecialchars(studentAssignmentFormatDate($item['submitted_at'])); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['total_points'])): ?>
                                <span><i class="fas fa-star"></i> <?php echo htmlspecialchars(number_format((float)$item['total_points'], 1)); ?> pts</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="assignment-actions">
                        <span class="badge <?php echo htmlspecialchars($status['class']); ?>"><?php echo htmlspecialchars($status['label']); ?></span>
                        <a class="btn btn-sm btn-primary" href="elearning/assignment.php?assignment_id=<?php echo (int)$item['id']; ?>">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
</body>
</html>
<?php
    exit;
}

// Load assignment
$assignment = null;
if ($stmt = $db->prepare("SELECT * FROM el_assignments WHERE id=? LIMIT 1")) {
    $stmt->bind_param('i', $assignmentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $assignment = $res->fetch_assoc();
    $stmt->close();
}
if (!$assignment) { die('Assignment not found'); }

$courseCode = $assignment['course_code'];
$attachmentPath = trim((string)($assignment['attachment_path'] ?? ''));
$attachmentExt = strtolower(pathinfo($attachmentPath, PATHINFO_EXTENSION));
$hasAttachment = ($attachmentPath !== '');
$submissionArchiveReady = assignmentStorageEnsureSchema($db);
elearningAssessmentEnsureSchema($db);

// Verify student is enrolled in this course
enforceStudentCourseAccess($db, $sid, $courseCode);
$assignmentOfferingId = (int)($assignment['course_offering_id'] ?? 0);
if ($assignmentOfferingId > 0 && !in_array($assignmentOfferingId, getStudentCourseOfferingIds($db, $sid, $courseCode), true)) {
    die('Assignment not available for your registered class.');
}

$errors = [];
$successMsg = '';
$assignmentSettings = elearningAssessmentSettings($assignment['settings_json'] ?? null, [
    'allow_text_response' => true,
    'allow_file_upload' => true,
    'allowed_file_types' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png'],
    'max_file_mb' => 10,
    'ai_check_enabled' => true,
    'plagiarism_check_enabled' => true,
]);
$allowedSubmissionExt = array_values(array_filter((array)($assignmentSettings['allowed_file_types'] ?? [])));
$maxSubmissionBytes = max(1, (int)($assignmentSettings['max_file_mb'] ?? 10)) * 1024 * 1024;
$csrfToken = elearningAssessmentCsrfToken();

// Load existing submission
$submission = null;
if ($stmt = $db->prepare("SELECT * FROM el_submissions WHERE assignment_id=? AND Sid=? ORDER BY submitted_at DESC LIMIT 1")) {
    $stmt->bind_param('is', $assignmentId, $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    $submission = $res->fetch_assoc();
    $stmt->close();
}

// Load grade
$grade = null;
if ($stmt = $db->prepare("SELECT * FROM el_grades WHERE assignment_id=? AND Sid=? ORDER BY graded_at DESC, id DESC LIMIT 1")) {
    $stmt->bind_param('is', $assignmentId, $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    $grade = $res->fetch_assoc();
    $stmt->close();
}

// Handle submission
$dueTs = !empty($assignment['due_at']) ? strtotime((string)$assignment['due_at']) : null;
$isDue = $dueTs !== null && $dueTs !== false && $dueTs < time();
$isAlmostDue = $dueTs !== null && $dueTs !== false && !$isDue && ($dueTs - time()) <= 24 * 60 * 60;
$dueIso = $dueTs ? date('c', $dueTs) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if past due
    if (!elearningAssessmentValidateCsrf($_POST)) {
        $errors[] = 'Security check failed. Please refresh the page and try again.';
    } elseif ($isDue) {
        $errors[] = 'This assignment is past due.';
    } else {
        $textBody = trim($_POST['text_body'] ?? '');
        $filePath = null;
        $dest = null;

        if ($textBody !== '' && empty($assignmentSettings['allow_text_response'])) {
            $errors[] = 'Text responses are not enabled for this assignment.';
        }
        if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE && empty($assignmentSettings['allow_file_upload'])) {
            $errors[] = 'File uploads are not enabled for this assignment.';
        }
        if (strlen($textBody) > 20000) {
            $errors[] = 'Text response is too long. Please keep it under 20,000 characters or upload a file.';
        }
        
        // Handle file upload
        if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'The file could not be uploaded. Please try again.';
            } elseif ((int)($_FILES['file']['size'] ?? 0) > $maxSubmissionBytes) {
                $errors[] = 'Maximum file size is 10MB.';
            }
        }

        if (!$errors && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../uploads/submissions/';
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
            $ext = strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedSubmissionExt, true)) {
                $errors[] = 'Accepted formats: ' . strtoupper(implode(', ', $allowedSubmissionExt)) . '.';
            }
        }

        if (!$errors && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $safeSid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$sid);
            $basename = 'A' . $assignmentId . '_' . $safeSid . '_' . time() . '.' . $ext;
            $dest = $uploadDir . $basename;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                $mime = assignmentStorageMimeType($dest);
                $allowedMimePrefixes = ['application/', 'image/', 'text/plain'];
                $mimeOk = false;
                foreach ($allowedMimePrefixes as $prefix) {
                    if (str_starts_with($mime, $prefix)) { $mimeOk = true; break; }
                }
                if (!$mimeOk) {
                    @unlink($dest);
                    $errors[] = 'The uploaded file type could not be verified.';
                } else {
                    $filePath = 'uploads/submissions/' . $basename;
                }
            } else {
                $errors[] = 'Failed to upload file.';
            }
        }
        
        if (!$errors && ($textBody !== '' || $filePath !== null)) {
            $absoluteSubmissionPath = assignmentStorageAbsolutePath($filePath);
            $submissionText = assignmentStorageExtractText($absoluteSubmissionPath, $textBody);
            $aiResult = !empty($assignmentSettings['ai_check_enabled'])
                ? assignmentStorageRunAiCheck($submissionText, $absoluteSubmissionPath)
                : ['provider' => null, 'score' => null, 'status' => 'disabled', 'report' => 'AI review is disabled for this assignment.'];
            $plagiarismResult = !empty($assignmentSettings['plagiarism_check_enabled'])
                ? elearningAssessmentRunPlagiarismCheck($db, $assignmentId, (string)$sid, $submissionText)
                : ['provider' => null, 'score' => null, 'status' => 'disabled', 'report' => 'Plagiarism review is disabled for this assignment.'];
            $storageProvider = $filePath !== null ? 'local' : 'text';
            $driveFileId = null;
            $driveWebUrl = null;
            $driveFolderId = null;
            $archiveFilePath = null;
            $archiveOriginalName = null;
            $archiveMovedAt = null;
            $localDeletedAt = null;

            if ($filePath !== null && $absoluteSubmissionPath && is_file($absoluteSubmissionPath)) {
                $driveUpload = assignmentStorageArchiveFile($absoluteSubmissionPath, [
                    'assignment_id' => $assignmentId,
                    'assignment_title' => (string)($assignment['title'] ?? 'Assignment'),
                    'course_code' => (string)$courseCode,
                    'lecturer_id' => (string)($assignment['created_by'] ?? ''),
                    'student_sid' => (string)$sid,
                ]);
                if (!empty($driveUpload['ok'])) {
                    $storageProvider = (string)($driveUpload['provider'] ?? 'google_drive');
                    $driveFileId = (string)($driveUpload['file_id'] ?? '');
                    $driveWebUrl = (string)($driveUpload['web_url'] ?? '');
                    $driveFolderId = (string)($driveUpload['folder_id'] ?? '');
                    $archiveFilePath = (string)($driveUpload['archive_file_path'] ?? '');
                    if ($archiveFilePath !== '') {
                        $archiveOriginalName = basename($absoluteSubmissionPath);
                        $archiveMovedAt = date('Y-m-d H:i:s');
                    }
                } elseif (!empty($driveUpload['enabled'])) {
                    error_log('students/elearning/assignment.php: assignment archive failed: ' . ($driveUpload['message'] ?? 'unknown error'));
                }
            }

            if ($submissionArchiveReady) {
                $aiProvider = $aiResult['provider'];
                $aiScore = $aiResult['score'];
                $aiStatus = $aiResult['status'];
                $aiReport = $aiResult['report'];
                $plagiarismProvider = $plagiarismResult['provider'];
                $plagiarismScore = $plagiarismResult['score'];
                $plagiarismStatus = $plagiarismResult['status'];
                $plagiarismReport = $plagiarismResult['report'];
                $stmt = $db->prepare("INSERT INTO el_submissions (assignment_id, Sid, submitted_at, file_path, text_body, storage_provider, drive_file_id, drive_web_url, drive_folder_id, archive_file_path, archive_original_name, archive_moved_at, local_file_deleted_at, ai_detector_provider, ai_score, ai_status, ai_report, ai_checked_at, plagiarism_provider, plagiarism_score, plagiarism_status, plagiarism_report, plagiarism_checked_at) VALUES (?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,NOW())");
                $stmt->bind_param('issssssssssssdsssdss', $assignmentId, $sid, $filePath, $textBody, $storageProvider, $driveFileId, $driveWebUrl, $driveFolderId, $archiveFilePath, $archiveOriginalName, $archiveMovedAt, $localDeletedAt, $aiProvider, $aiScore, $aiStatus, $aiReport, $plagiarismProvider, $plagiarismScore, $plagiarismStatus, $plagiarismReport);
            } else {
                $stmt = $db->prepare("INSERT INTO el_submissions (assignment_id, Sid, submitted_at, file_path, text_body) VALUES (?,?,NOW(),?,?)");
                $stmt->bind_param('isss', $assignmentId, $sid, $filePath, $textBody);
            }
            if ($stmt->execute()) {
                $successMsg = 'Submission received successfully!';
                $newSubmissionId = (int)$stmt->insert_id;
                wuc_academic_risk_after_student_activity($db, (string)$sid, 'assignment_submitted');
                if ($newSubmissionId > 0 && in_array($storageProvider, ['google_drive', 'local_archive'], true) && $absoluteSubmissionPath && is_file($absoluteSubmissionPath) && !empty($driveUpload['delete_local']) && @unlink($absoluteSubmissionPath)) {
                    $localDeletedAt = date('Y-m-d H:i:s');
                    if ($markDeleted = $db->prepare("UPDATE el_submissions SET local_file_deleted_at=? WHERE id=?")) {
                        $markDeleted->bind_param('si', $localDeletedAt, $newSubmissionId);
                        $markDeleted->execute();
                        $markDeleted->close();
                    }
                }
                // Reload submission
                $stmt->close();
                $stmt = $db->prepare("SELECT * FROM el_submissions WHERE assignment_id=? AND Sid=? ORDER BY submitted_at DESC LIMIT 1");
                $stmt->bind_param('is', $assignmentId, $sid);
                $stmt->execute();
                $res = $stmt->get_result();
                $submission = $res->fetch_assoc();
            } else {
                $errors[] = 'Failed to save submission.';
            }
            $stmt->close();
        } elseif (!$errors) {
            $errors[] = 'Please provide a file or text response.';
        }
    }
}

$maxPoints = 100.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($assignment['title']); ?> - Assignment</title>
    <base href="<?php echo htmlspecialchars($baseHref); ?>">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .assignment-header { background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%); color: #fff; padding: 30px; border-radius: 12px; margin-bottom: 25px; }
        .due-badge { display: inline-block; background: rgba(255,255,255,0.2); padding: 5px 12px; border-radius: 20px; font-size: 0.9rem; margin-top: 10px; }
        .due-badge.overdue { background: #dc3545; }
        .submission-box { background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 10px; padding: 30px; text-align: center; margin-bottom: 20px; }
        .grade-box { background: #d4edda; border: 1px solid #c3e6cb; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .feedback-box { background: #fff3cd; border: 1px solid #ffeeba; border-radius: 10px; padding: 20px; margin-top: 15px; }
        .doc-preview { width: 100%; height: 620px; border: 1px solid #dee2e6; border-radius: 8px; background: #fff; }
        .deadline-alert { border-radius: 10px; padding: 16px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .deadline-alert.warning { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; }
        .deadline-alert.overdue { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .deadline-timer { font-weight: 700; font-size: 1.15rem; font-variant-numeric: tabular-nums; }
        .field-error { color: #dc3545; font-size: .875rem; margin-top: .35rem; display: none; }
        .field-error.show { display: block; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="content-wrapper">
    <div style="margin-bottom: 20px;">
        <a href="elearning/course.php?course_code=<?php echo urlencode($courseCode); ?>" style="color: #666; text-decoration: none;">
            <i class="fas fa-arrow-left"></i> Back to <?php echo htmlspecialchars($courseCode); ?>
        </a>
    </div>
    
    <div class="assignment-header">
        <h2><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($assignment['title']); ?></h2>
        <p style="opacity: 0.9;"><?php echo htmlspecialchars($courseCode); ?></p>
        <?php if (!empty($assignment['due_at'])): ?>
            <span class="due-badge <?php echo $isDue ? 'overdue' : ''; ?>">
                <i class="fas fa-calendar"></i> 
                Due: <?php echo date('M j, Y g:i A', strtotime($assignment['due_at'])); ?>
                <?php if ($isDue): ?> (Overdue)<?php endif; ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($isDue): ?>
        <div class="deadline-alert overdue">
            <div><i class="fas fa-exclamation-triangle me-2"></i>This assignment is past due. New submissions are closed.</div>
        </div>
    <?php elseif ($isAlmostDue): ?>
        <div class="deadline-alert warning" id="deadlineAlert" data-due="<?php echo htmlspecialchars($dueIso); ?>">
            <div><i class="fas fa-hourglass-half me-2"></i>This assignment is almost due.</div>
            <div class="deadline-timer" id="deadlineTimer">Calculating...</div>
        </div>
    <?php endif; ?>
    
    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
    <?php endif; ?>
    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMsg); ?></div>
    <?php endif; ?>
    
    <!-- Assignment Description -->
    <div class="card mb-4">
        <div class="card-header"><strong>Instructions</strong></div>
        <div class="card-body">
            <?php if (!empty($assignment['description'])): ?>
                <?php echo nl2br(htmlspecialchars($assignment['description'])); ?>
            <?php else: ?>
                <p class="text-muted">No instructions provided.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($hasAttachment): ?>
    <div class="card mb-4">
        <div class="card-header"><strong><i class="fas fa-file-alt me-1"></i>Assessment Document</strong></div>
        <div class="card-body">
            <?php if ($attachmentExt === 'pdf'): ?>
                <iframe class="doc-preview" src="../<?php echo htmlspecialchars($attachmentPath); ?>"></iframe>
            <?php else: ?>
                <div class="alert alert-warning mb-2">Preview is available for PDF files only.</div>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary mt-2" href="../<?php echo htmlspecialchars($attachmentPath); ?>" target="_blank">
                <i class="fas fa-download"></i> Download Document
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($grade): ?>
    <!-- Grade Display -->
    <div class="grade-box">
        <h5><i class="fas fa-check-circle" style="color: #28a745;"></i> Graded</h5>
        <p style="font-size: 1.5rem; margin: 10px 0;"><strong><?php echo round((float)$grade['total_points'], 1); ?></strong> / <?php echo round($maxPoints, 0); ?> points</p>
        <small>Graded on <?php echo htmlspecialchars($grade['graded_at']); ?></small>
        <?php if (!empty($grade['feedback'])): ?>
            <div class="feedback-box">
                <strong>Feedback:</strong><br>
                <?php echo nl2br(htmlspecialchars($grade['feedback'])); ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($submission): ?>
    <!-- Previous Submission -->
    <div class="card mb-4">
        <div class="card-header"><strong>Your Submission</strong></div>
        <div class="card-body">
            <p><i class="fas fa-clock"></i> Submitted: <?php echo htmlspecialchars($submission['submitted_at']); ?></p>
            <?php if (!empty($submission['file_path']) || !empty($submission['drive_web_url'])): ?>
                <?php
                    $submissionFileUrl = assignmentStorageSubmissionUrl($submission);
                    $fileLabel = !empty($submission['drive_web_url']) ? 'Open in Google Drive' : 'Download';
                ?>
                <p><i class="fas fa-file"></i> File: <a href="<?php echo htmlspecialchars($submissionFileUrl); ?>" target="_blank"><?php echo htmlspecialchars($fileLabel); ?></a></p>
            <?php endif; ?>
            <?php if (!empty($submission['text_body'])): ?>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 10px;">
                    <?php echo nl2br(htmlspecialchars($submission['text_body'])); ?>
                </div>
            <?php endif; ?>
            <?php if ($submission['turnitin_score'] !== null): ?>
                <p style="margin-top: 10px;"><i class="fas fa-shield-alt"></i> Turnitin Score: <?php echo (int)$submission['turnitin_score']; ?>%</p>
            <?php endif; ?>
            <?php if (!empty($submission['ai_status'])): ?>
                <p style="margin-top: 10px;">
                    <i class="fas fa-brain"></i>
                    AI Writing Review:
                    <?php echo htmlspecialchars(ucfirst((string)$submission['ai_status'])); ?>
                    <?php if ($submission['ai_score'] !== null): ?>
                        (<?php echo htmlspecialchars(number_format((float)$submission['ai_score'], 1)); ?>%)
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($submission['plagiarism_status'])): ?>
                <p style="margin-top: 10px;">
                    <i class="fas fa-shield-alt"></i>
                    Plagiarism Review:
                    <?php echo htmlspecialchars(ucfirst((string)$submission['plagiarism_status'])); ?>
                    <?php if ($submission['plagiarism_score'] !== null): ?>
                        (<?php echo htmlspecialchars(number_format((float)$submission['plagiarism_score'], 1)); ?>%)
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!$isDue && !$grade): ?>
    <!-- Submit Form -->
    <div class="card">
        <div class="card-header"><strong><?php echo $submission ? 'Resubmit' : 'Submit Assignment'; ?></strong></div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" id="assignmentSubmitForm" data-due="<?php echo htmlspecialchars($dueIso); ?>" novalidate>
                <?php echo elearningAssessmentCsrfField(); ?>
                <?php if (!empty($assignmentSettings['ai_check_enabled']) || !empty($assignmentSettings['plagiarism_check_enabled'])): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-shield-alt"></i>
                        This submission will be reviewed for <?php echo !empty($assignmentSettings['ai_check_enabled']) && !empty($assignmentSettings['plagiarism_check_enabled']) ? 'AI-writing signals and plagiarism similarity' : (!empty($assignmentSettings['ai_check_enabled']) ? 'AI-writing signals' : 'plagiarism similarity'); ?>.
                    </div>
                <?php endif; ?>
                <?php if (!empty($assignmentSettings['allow_text_response'])): ?>
                <div class="mb-3">
                    <label class="form-label" for="assignmentTextBody">Text Response (optional)</label>
                    <textarea class="form-control" id="assignmentTextBody" name="text_body" rows="6" placeholder="Type your response here..."></textarea>
                </div>
                <?php endif; ?>
                <?php if (!empty($assignmentSettings['allow_file_upload'])): ?>
                <div class="mb-3">
                    <label class="form-label" for="assignmentFile">Or Upload File</label>
                    <input type="file" class="form-control" id="assignmentFile" name="file" accept="<?php echo htmlspecialchars(implode(',', array_map(static fn($ext) => '.' . $ext, $allowedSubmissionExt))); ?>">
                    <small class="text-muted">Accepted formats: <?php echo htmlspecialchars(strtoupper(implode(', ', $allowedSubmissionExt))); ?>. Maximum size: <?php echo (int)($assignmentSettings['max_file_mb'] ?? 10); ?>MB.</small>
                    <div class="field-error" id="assignmentValidationError"></div>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> <?php echo $submission ? 'Resubmit' : 'Submit'; ?>
                </button>
            </form>
        </div>
    </div>
    <?php elseif ($isDue && !$submission): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-triangle"></i> This assignment is past due and you did not submit.
    </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
<script>
const assignmentDueIso = <?php echo json_encode($dueIso); ?>;

function formatRemaining(ms) {
    if (ms <= 0) return 'Closed';
    const totalSeconds = Math.floor(ms / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
}

function updateDeadlineTimer() {
    const timer = document.getElementById('deadlineTimer');
    if (!timer || !assignmentDueIso) return;
    const due = new Date(assignmentDueIso).getTime();
    if (Number.isNaN(due)) return;
    const remaining = due - Date.now();
    timer.textContent = formatRemaining(remaining);
    if (remaining <= 0) {
        const alert = document.getElementById('deadlineAlert');
        if (alert) {
            alert.classList.remove('warning');
            alert.classList.add('overdue');
        }
        document.querySelectorAll('#assignmentSubmitForm input, #assignmentSubmitForm textarea, #assignmentSubmitForm button').forEach(el => {
            el.disabled = true;
        });
    }
}

updateDeadlineTimer();
setInterval(updateDeadlineTimer, 1000);

const submitForm = document.getElementById('assignmentSubmitForm');
if (submitForm) {
    submitForm.addEventListener('submit', function(e) {
        const text = document.getElementById('assignmentTextBody');
        const file = document.getElementById('assignmentFile');
        const error = document.getElementById('assignmentValidationError');
        const allowed = <?php echo json_encode($allowedSubmissionExt); ?>;
        const maxBytes = <?php echo (int)$maxSubmissionBytes; ?>;
        let message = '';

        if (assignmentDueIso) {
            const due = new Date(assignmentDueIso).getTime();
            if (!Number.isNaN(due) && Date.now() > due) {
                message = 'This assignment is past due. Submission is closed.';
            }
        }

        if (!message && text && text.value.length > 20000) {
            message = 'Text response is too long. Please keep it under 20,000 characters or upload a file.';
        }

        if (!message && file && file.files.length > 0) {
            const selected = file.files[0];
            const ext = selected.name.split('.').pop().toLowerCase();
            if (!allowed.includes(ext)) {
                message = 'Accepted formats: PDF, Word, Excel, PowerPoint, text, JPG, and PNG.';
            } else if (selected.size > maxBytes) {
                message = 'Maximum file size is 10MB.';
            }
        }

        if (!message && (!text || text.value.trim() === '') && (!file || file.files.length === 0)) {
            message = 'Please provide a text response or upload a file.';
        }

        if (message) {
            e.preventDefault();
            if (error) {
                error.textContent = message;
                error.classList.add('show');
            }
        } else if (error) {
            error.textContent = '';
            error.classList.remove('show');
        }
    });
}

if (typeof ElearnTrack !== 'undefined') {
    ElearnTrack.assignmentView('<?php echo htmlspecialchars($courseCode); ?>', <?php echo (int)$assignmentId; ?>);
}
</script>
</body>
</html>

