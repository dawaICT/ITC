<?php
error_reporting(0);
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/assignment_storage.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = $_GET['course_code'] ?? ($_POST['course_code'] ?? '');
$assignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : (isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0);
$studentSid = $_GET['sid'] ?? ($_POST['sid'] ?? '');
if (!$staffId || !$courseCode || !$assignmentId || !$studentSid) { die('Unauthorized'); }

// Verify lecturer has access to this course
enforceLecturerCourseAccess($db, $staffId, $courseCode);
assignmentStorageEnsureSchema($db);

// Load assignment
$assignment = null;
if ($stmt = $db->prepare("SELECT * FROM el_assignments WHERE id=? AND course_code=? LIMIT 1")) {
    $stmt->bind_param('is', $assignmentId, $courseCode);
    $stmt->execute();
    $res = $stmt->get_result();
    $assignment = $res->fetch_assoc();
    $stmt->close();
}
if (!$assignment) { die('Assignment not found'); }

// Load submission
$submission = null;
if ($stmt = $db->prepare("SELECT * FROM el_submissions WHERE assignment_id=? AND Sid=? ORDER BY submitted_at DESC LIMIT 1")) {
    $stmt->bind_param('is', $assignmentId, $studentSid);
    $stmt->execute();
    $res = $stmt->get_result();
    $submission = $res->fetch_assoc();
    $stmt->close();
}
if (!$submission) { die('Submission not found'); }

// Load existing grade
$existingGrade = null;
if ($stmt = $db->prepare("SELECT * FROM el_grades WHERE assignment_id=? AND Sid=? LIMIT 1")) {
    $stmt->bind_param('is', $assignmentId, $studentSid);
    $stmt->execute();
    $res = $stmt->get_result();
    $existingGrade = $res->fetch_assoc();
    $stmt->close();
}

// Load rubric if any
$rubric = null;
$rubricCriteria = [];
if (!empty($assignment['rubric_id'])) {
    if ($stmt = $db->prepare("SELECT * FROM el_rubrics WHERE id=? LIMIT 1")) {
        $stmt->bind_param('i', $assignment['rubric_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        $rubric = $res->fetch_assoc();
        $stmt->close();
    }
    if ($rubric) {
        if ($stmt = $db->prepare("SELECT * FROM el_rubric_criteria WHERE rubric_id=? ORDER BY position, id")) {
            $stmt->bind_param('i', $rubric['id']);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $rubricCriteria[] = $row; }
            $stmt->close();
        }
    }
}

$errors = [];
$successMsg = '';

// Handle grading submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $totalPoints = (float)($_POST['total_points'] ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');
    $rubricBreakdown = null;
    
    // If rubric used, collect scores per criterion
    if ($rubric && count($rubricCriteria) > 0) {
        $breakdown = [];
        foreach ($rubricCriteria as $c) {
            $score = isset($_POST['criterion_' . $c['id']]) ? (float)$_POST['criterion_' . $c['id']] : 0;
            $breakdown[$c['id']] = ['criterion' => $c['criterion'], 'score' => $score, 'max' => (float)$c['max_points']];
        }
        $rubricBreakdown = json_encode($breakdown);
        // Sum up total from rubric
        $totalPoints = array_sum(array_column($breakdown, 'score'));
    }
    
    if ($totalPoints < 0) { $errors[] = 'Points cannot be negative'; }
    
    if (!$errors) {
        if ($existingGrade) {
            // Update existing grade
            $stmt = $db->prepare("UPDATE el_grades SET total_points=?, rubric_breakdown=?, feedback=?, graded_by=?, graded_at=NOW() WHERE id=?");
            $stmt->bind_param('dsssi', $totalPoints, $rubricBreakdown, $feedback, $staffId, $existingGrade['id']);
            $stmt->execute();
            $stmt->close();
            $successMsg = 'Grade updated successfully.';
        } else {
            // Insert new grade
            $stmt = $db->prepare("INSERT INTO el_grades (assignment_id, Sid, graded_by, total_points, rubric_breakdown, feedback) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('issdss', $assignmentId, $studentSid, $staffId, $totalPoints, $rubricBreakdown, $feedback);
            $stmt->execute();
            $stmt->close();
            $successMsg = 'Grade saved successfully.';
        }
        
        // Reload
        if ($stmt = $db->prepare("SELECT * FROM el_grades WHERE assignment_id=? AND Sid=? LIMIT 1")) {
            $stmt->bind_param('is', $assignmentId, $studentSid);
            $stmt->execute();
            $res = $stmt->get_result();
            $existingGrade = $res->fetch_assoc();
            $stmt->close();
        }
    }
}

// Parse existing rubric breakdown
$savedBreakdown = [];
if ($existingGrade && !empty($existingGrade['rubric_breakdown'])) {
    $savedBreakdown = json_decode($existingGrade['rubric_breakdown'], true) ?: [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Submission - <?php echo htmlspecialchars($studentSid); ?></title>
    <link rel="stylesheet" href="../admin/css/admin-style.css">
    <link rel="stylesheet" href="../admin/css/admin-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .submission-preview { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .rubric-row { display: flex; align-items: center; padding: 15px; border-bottom: 1px solid #e9ecef; }
        .rubric-row:last-child { border-bottom: none; }
        .rubric-label { flex: 2; }
        .rubric-input { flex: 1; max-width: 150px; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../lecturers/includes/nav.php'; ?>

<div class="content-wrapper">
    <h2><i class="fas fa-pen"></i> Grade Submission</h2>
    <p class="text-muted">
        Assignment: <?php echo htmlspecialchars($assignment['title']); ?> |
        Student: <?php echo htmlspecialchars($studentSid); ?>
    </p>
    <hr>
    
    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
    <?php endif; ?>
    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMsg); ?></div>
    <?php endif; ?>
    
    <!-- Submission Preview -->
    <div class="submission-preview">
        <h5><i class="fas fa-file-alt"></i> Student Submission</h5>
        <p><small class="text-muted">Submitted: <?php echo htmlspecialchars($submission['submitted_at']); ?></small></p>
        
        <?php if (!empty($submission['file_path']) || !empty($submission['drive_web_url'])): ?>
            <?php
                $submissionUrl = assignmentStorageSubmissionUrl($submission);
                $isDriveSubmission = !empty($submission['drive_web_url']) || !empty($submission['drive_file_id']);
            ?>
            <p>
                <i class="<?php echo $isDriveSubmission ? 'fab fa-google-drive' : 'fas fa-download'; ?>"></i>
                <a href="<?php echo htmlspecialchars($submissionUrl); ?>" target="_blank"><?php echo $isDriveSubmission ? 'Open from Google Drive' : 'Download Submitted File'; ?></a>
            </p>
        <?php endif; ?>

        <?php if (!empty($submission['ai_status'])): ?>
            <div class="alert alert-secondary">
                <strong>AI Writing Review:</strong>
                <?php echo htmlspecialchars(ucfirst((string)$submission['ai_status'])); ?>
                <?php if ($submission['ai_score'] !== null): ?>
                    (<?php echo htmlspecialchars(number_format((float)$submission['ai_score'], 1)); ?>%)
                <?php endif; ?>
                <br><small><?php echo htmlspecialchars($submission['ai_report'] ?: 'No report details available.'); ?></small>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($submission['text_body'])): ?>
            <div style="background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #dee2e6; margin-top: 10px;">
                <?php echo nl2br(htmlspecialchars($submission['text_body'])); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($submission['turnitin_score'] !== null): ?>
            <p style="margin-top: 10px;">
                <i class="fas fa-shield-alt"></i> Turnitin Score: 
                <strong style="color: <?php echo $submission['turnitin_score'] > 25 ? '#dc3545' : '#28a745'; ?>">
                    <?php echo (int)$submission['turnitin_score']; ?>%
                </strong>
            </p>
        <?php endif; ?>
    </div>
    
    <!-- Grading Form -->
    <div class="card">
        <div class="card-header"><strong>Grade</strong></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="assignment_id" value="<?php echo (int)$assignmentId; ?>">
                <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
                <input type="hidden" name="sid" value="<?php echo htmlspecialchars($studentSid); ?>">
                
                <?php if ($rubric && count($rubricCriteria) > 0): ?>
                <!-- Rubric Grading -->
                <h5><?php echo htmlspecialchars($rubric['title']); ?></h5>
                <p class="text-muted"><?php echo htmlspecialchars($rubric['description'] ?? ''); ?></p>
                
                <div style="border: 1px solid #e9ecef; border-radius: 8px; overflow: hidden; margin-bottom: 20px;">
                    <?php foreach ($rubricCriteria as $c): 
                        $savedScore = isset($savedBreakdown[$c['id']]['score']) ? $savedBreakdown[$c['id']]['score'] : '';
                    ?>
                        <div class="rubric-row">
                            <div class="rubric-label">
                                <strong><?php echo htmlspecialchars($c['criterion']); ?></strong>
                                <?php if (!empty($c['description'])): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($c['description']); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="rubric-input">
                                <div class="input-group">
                                    <input type="number" class="form-control" name="criterion_<?php echo $c['id']; ?>" 
                                           min="0" max="<?php echo (float)$c['max_points']; ?>" step="0.5"
                                           value="<?php echo htmlspecialchars($savedScore); ?>" required>
                                    <span class="input-group-text">/ <?php echo (float)$c['max_points']; ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <!-- Simple Points Entry -->
                <div class="mb-3">
                    <label class="form-label">Total Points</label>
                    <input type="number" class="form-control" name="total_points" min="0" step="0.5" 
                           value="<?php echo $existingGrade ? (float)$existingGrade['total_points'] : ''; ?>" required style="max-width: 200px;">
                </div>
                <?php endif; ?>
                
                <div class="mb-3">
                    <label class="form-label">Feedback</label>
                    <textarea class="form-control" name="feedback" rows="5" placeholder="Provide feedback to the student..."><?php echo htmlspecialchars($existingGrade['feedback'] ?? ''); ?></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo $existingGrade ? 'Update Grade' : 'Save Grade'; ?>
                </button>
                <a href="assignment_edit.php?assignment_id=<?php echo (int)$assignmentId; ?>&course_code=<?php echo urlencode($courseCode); ?>" class="btn btn-secondary">
                    Cancel
                </a>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
</body>
</html>
