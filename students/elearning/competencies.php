<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';

$sid = $_SESSION['Sid'] ?? null;
$courseCode = trim((string)($_GET['course_code'] ?? ''));

if (!$sid || !$courseCode) {
    http_response_code(401);
    die('Unauthorized');
}

enforceStudentCourseAccess($db, $sid, $courseCode);

// Resolve course name
$courseName = $courseCode;
if ($stmt = $db->prepare("SELECT course_name FROM courses WHERE course_code=? LIMIT 1")) {
    $stmt->bind_param('s', $courseCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $courseName = $row['course_name'];
    }
    $stmt->close();
}

$baseHref = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/wucportal/students/elearning/competencies.php'))), '/') . '/';
$studentElearningRoot = $baseHref . 'elearning/';

$errors = [];
$success = null;

// Handle evidence upload
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['upload_evidence'])) {
    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $errors[] = 'Security token validation failed. Refresh and try again.';
    }
    
    $competencyId = (int)($_POST['competency_id'] ?? 0);
    
    // Check if competency is valid for this course
    $checkComp = $db->prepare("SELECT id FROM el_competencies WHERE id=? AND course_code=? LIMIT 1");
    $checkComp->bind_param('is', $competencyId, $courseCode);
    $checkComp->execute();
    if (!$checkComp->get_result()->num_rows) {
        $errors[] = 'Invalid competency task.';
    }
    $checkComp->close();
    
    if (!$errors && isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $fileErr = $_FILES['evidence_file']['error'];
        if ($fileErr !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed with error code ' . $fileErr;
        } elseif ((int)$_FILES['evidence_file']['size'] > 10 * 1024 * 1024) {
            $errors[] = 'Evidence file must be 10MB or smaller.';
        } else {
            $original = (string)$_FILES['evidence_file']['name'];
            $tmp = (string)$_FILES['evidence_file']['tmp_name'];
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'zip', 'doc', 'docx'];
            if (!in_array($ext, $allowed, true)) {
                $errors[] = 'Only PDF, DOCX, ZIP, PNG, and JPG files are allowed.';
            } else {
                $uploadDir = __DIR__ . '/../../uploads/evidence';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0775, true);
                }
                $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($original, PATHINFO_FILENAME));
                $filename = $cleanName . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $target = $uploadDir . '/' . $filename;
                
                if (@move_uploaded_file($tmp, $target)) {
                    $evidencePath = 'uploads/evidence/' . $filename;
                    
                    // Insert or update status to 'in_progress' and set evidence path
                    $stmt = $db->prepare("INSERT INTO el_student_competencies (student_id, competency_id, status, evidence_path) 
                        VALUES (?, ?, 'in_progress', ?)
                        ON DUPLICATE KEY UPDATE status='in_progress', evidence_path=?, notes=NULL");
                    $stmt->bind_param('siss', $sid, $competencyId, $evidencePath, $evidencePath);
                    if ($stmt->execute()) {
                        $success = 'Evidence uploaded successfully! Awaiting verification.';
                    } else {
                        $errors[] = 'Database update failed.';
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'Failed to save evidence file.';
                }
            }
        }
    } else if (!$errors) {
        $errors[] = 'Please select a file to upload.';
    }
}

// Load competencies for this course
$competencies = [];
$sql = "SELECT c.*, sc.status, sc.evidence_path, sc.lecturer_id, sc.lecturer_verified_at, 
               sc.trainer_id, sc.trainer_verified_at, sc.industry_supervisor_name, 
               sc.industry_verified_at, sc.notes,
               l.Fname AS l_fname, l.Lname AS l_lname,
               t.Fname AS t_fname, t.Lname AS t_lname
        FROM el_competencies c
        LEFT JOIN el_student_competencies sc ON sc.competency_id = c.id AND sc.student_id = ?
        LEFT JOIN staff l ON l.staff_id = sc.lecturer_id
        LEFT JOIN staff t ON t.staff_id = sc.trainer_id
        WHERE c.course_code = ?
        ORDER BY c.id ASC";

if ($stmt = $db->prepare($sql)) {
    $stmt->bind_param('ss', $sid, $courseCode);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $competencies[] = $row;
    }
    $stmt->close();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Competencies & Practicals</title>
    <base href="<?php echo htmlspecialchars($baseHref); ?>">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/css/elearning-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .competency-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .status-badge { font-size: 0.85rem; padding: 6px 14px; border-radius: 20px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
        .status-not_started { background: #f1f5f9; color: #475569; }
        .status-in_progress { background: #fef3c7; color: #d97706; }
        .status-competent { background: #dcfce7; color: #15803d; }
        .status-verified { background: #faf5ff; color: #6f42c1; border: 1px solid #d8b4fe; }
        .verification-detail { background: #f8fafc; border-radius: 8px; padding: 12px 16px; margin-top: 14px; font-size: 0.88rem; border-left: 3px solid #cbd5e1; }
        .verification-detail.verified { border-left-color: #6f42c1; background: #faf5ff; }
        .btn-upload { background: #6f42c1; color: #fff; border: none; border-radius: 8px; padding: 8px 18px; font-size: 0.88rem; transition: background 0.2s; }
        .btn-upload:hover { background: #5a32a3; color: #fff; }
    </style>

<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="bg-light">
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<main class="content-wrapper pt-3 pb-5">
    <div class="container-fluid px-3 px-lg-4">
        
        <div class="back-link mb-3">
            <a href="elearning/course.php?course_code=<?php echo urlencode($courseCode); ?>" class="text-muted text-decoration-none">
                <i class="fas fa-arrow-left"></i> Back to Course Modules
            </a>
        </div>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1" style="font-weight: 700; color: #1e293b;">
                    <i class="fas fa-tasks text-purple"></i> Practical & Competency Checklist
                </h1>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($courseCode); ?> — <?php echo htmlspecialchars($courseName); ?></p>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <?php if (empty($competencies)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No competency standards have been mapped to this course yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($competencies as $c): 
                        $status = $c['status'] ?: 'not_started';
                        $statusLabel = ucfirst(str_replace('_', ' ', $status));
                        
                        $isVerified = $status === 'verified';
                        $isCompetent = $status === 'competent';
                    ?>
                        <div class="competency-card">
                            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                                <div>
                                    <h4 class="h5 mb-1" style="font-weight: 600; color: #0f172a;"><?php echo htmlspecialchars($c['title']); ?></h4>
                                    <p class="text-muted mb-2" style="font-size: 0.9rem;"><?php echo nl2br(htmlspecialchars($c['description'])); ?></p>
                                </div>
                                <span class="status-badge status-<?php echo $status; ?>">
                                    <i class="fas <?php 
                                        if ($status === 'verified') echo 'fa-circle-check';
                                        elseif ($status === 'competent') echo 'fa-check';
                                        elseif ($status === 'in_progress') echo 'fa-spinner fa-spin';
                                        else echo 'fa-circle-xmark';
                                    ?>"></i>
                                    <?php echo $statusLabel; ?>
                                </span>
                            </div>
                            
                            <?php if ($c['evidence_path']): ?>
                                <div class="mb-3" style="font-size: 0.88rem;">
                                    <i class="fas fa-paperclip text-muted"></i> <strong>Evidence uploaded:</strong> 
                                    <a href="<?php echo htmlspecialchars($c['evidence_path']); ?>" target="_blank" class="text-primary text-decoration-none">
                                        View submitted file
                                    </a>
                                </div>
                            <?php endif; ?>

                            <!-- Upload Evidence Form -->
                            <?php if (!$isVerified && !$isCompetent): ?>
                                <form method="POST" enctype="multipart/form-data" class="d-flex align-items-center gap-2 mt-3 flex-wrap">
                                    <input type="hidden" name="upload_evidence" value="1">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="competency_id" value="<?php echo (int)$c['id']; ?>">
                                    <div class="form-group mb-0 me-2" style="max-width: 250px;">
                                        <input type="file" name="evidence_file" class="form-control form-control-sm" required>
                                    </div>
                                    <button type="submit" class="btn btn-upload btn-sm">
                                        <i class="fas fa-cloud-upload-alt"></i> Upload Evidence
                                    </button>
                                </form>
                            <?php endif; ?>

                            <!-- Verification Layer Logs -->
                            <?php if ($status !== 'not_started'): ?>
                                <div class="verification-detail <?php echo $isVerified ? 'verified' : ''; ?>">
                                    <h6 class="mb-2" style="font-weight: 600; font-size: 0.88rem;">Verification Status Details:</h6>
                                    
                                    <div class="mb-1">
                                        <strong>Lecturer Verification:</strong> 
                                        <?php if ($c['lecturer_verified_at']): ?>
                                            <span class="text-success"><i class="fas fa-check-circle"></i> Yes</span> by <?php echo htmlspecialchars($c['l_fname'] . ' ' . $c['l_lname']); ?> on <?php echo date('M j, Y g:i A', strtotime($c['lecturer_verified_at'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted"><i class="fas fa-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mb-1">
                                        <strong>Trainer Verification:</strong> 
                                        <?php if ($c['trainer_verified_at']): ?>
                                            <span class="text-success"><i class="fas fa-check-circle"></i> Yes</span> by <?php echo htmlspecialchars($c['t_fname'] . ' ' . $c['t_lname']); ?> on <?php echo date('M j, Y g:i A', strtotime($c['trainer_verified_at'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted"><i class="fas fa-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($c['industry_supervisor_name'] || $c['industry_verified_at']): ?>
                                        <div class="mb-1">
                                            <strong>Industry Supervisor Verification:</strong>
                                            <span class="text-success"><i class="fas fa-check-circle"></i> Yes</span> by <?php echo htmlspecialchars($c['industry_supervisor_name']); ?> on <?php echo date('M j, Y', strtotime($c['industry_verified_at'])); ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($c['notes']): ?>
                                        <div class="mt-2 p-2 border-top" style="font-size: 0.85rem; font-style: italic; color: #475569;">
                                            <strong>Feedback / Notes:</strong> "<?php echo htmlspecialchars($c['notes']); ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-3" style="font-weight: 700; color: #1e293b;">Competency Gating</h5>
                        <p class="text-muted small">Practicals and Competencies track the real-world skills required for this course. To achieve completion of this course unit, you must demonstrate a status of <strong>Competent</strong> or <strong>Verified</strong> for each task.</p>
                        <hr>
                        <div class="small">
                            <div class="mb-2"><i class="fas fa-circle-xmark text-muted"></i> <strong>Not Started:</strong> Task checklist is pending.</div>
                            <div class="mb-2"><i class="fas fa-spinner fa-spin text-warning"></i> <strong>In Progress:</strong> Evidence submitted, awaiting evaluation.</div>
                            <div class="mb-2"><i class="fas fa-circle-check text-success"></i> <strong>Competent:</strong> Evaluated and approved as skilled by lecturer/trainer.</div>
                            <div><i class="fas fa-circle-check text-purple"></i> <strong>Verified:</strong> Officially signed off by academic moderators or industrial attachment supervisors.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</main>
</body>
</html>
