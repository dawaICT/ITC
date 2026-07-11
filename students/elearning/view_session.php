<?php
/**
 * Video Player Page for Internal Live Sessions
 * Enforces payment verification before allowing video playback
 */

session_start();
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/payment_verification.php';
require_once __DIR__ . '/../../includes/elearning_access.php';

// Check if student is logged in
if (!isset($_SESSION['Sid']) || empty($_SESSION['Sid'])) {
    header('Location: ../../student_login.php');
    exit;
}

$student_id = $_SESSION['Sid'];
$session_id = (int)($_GET['id'] ?? 0);

if ($session_id <= 0) {
    die('Invalid session ID');
}

// Get session details
$stmt = $db->prepare("SELECT * FROM lms_sessions WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $session_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die('Session not found');
}

$session = $result->fetch_assoc();
$stmt->close();

// Verify student is enrolled in the course for this session
if (!empty($session['course_code'])) {
    if (!canStudentAccessElearningCourse($db, $student_id, $session['course_code'])) {
        die('<div style="font-family: Arial; padding: 40px; text-align: center;">
            <h3 style="color: #dc3545;">Access Denied</h3>
            <p>You are not enrolled in the course for this session.</p>
            <a href="live_sessions.php" style="color: #2196F3;">← Back to Live Sessions</a>
        </div>');
    }
}

// Verify payment access
$access = verifySessionAccess($db, $student_id, $session_id, $session['min_payment_percentage'] ?? 50.00);

// Log access attempt
logSessionAccess(
    $db, 
    $session_id, 
    $student_id, 
    $access['allowed'], 
    $access['allowed'] ? null : $access['reason'],
    $access['payment_info']['percentage'] ?? null
);

// Deny access if not allowed
if (!$access['allowed']) {
    require_once __DIR__ . '/../includes/student_header.php';
    ?>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0"><i class="fas fa-lock me-2"></i>Access Denied</h4>
                    </div>
                    <div class="card-body text-center py-5">
                        <i class="fas fa-exclamation-triangle fa-5x text-danger mb-4"></i>
                        <h5>Cannot Access This Session</h5>
                        <p class="text-muted"><?php echo htmlspecialchars($access['reason']); ?></p>
                        
                        <?php if ($access['payment_info']): ?>
                            <div class="alert alert-warning mt-4">
                                <h6>Your Payment Status:</h6>
                                <?php echo formatPaymentSummary($access['payment_info']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <a href="live_sessions.php" class="btn btn-primary mt-3">
                            <i class="fas fa-arrow-left me-2"></i>Back to Sessions
                        </a>
                        <a href="../payments.php" class="btn btn-success mt-3">
                            <i class="fas fa-credit-card me-2"></i>Make Payment
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/student_footer.php';
    exit;
}

// Check if video file exists
if (empty($session['video_file_path']) || !file_exists(__DIR__ . '/../../' . $session['video_file_path'])) {
    die('Video file not available');
}

require_once __DIR__ . '/../includes/student_header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="row mb-3">
        <div class="col">
            <a href="live_sessions.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Sessions
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <!-- Video Player -->
            <div class="card mb-4">
                <div class="card-body p-0">
                    <video id="sessionVideo" class="w-100" controls controlsList="nodownload" style="max-height: 600px; background: #000;">
                        <source src="../../<?php echo htmlspecialchars($session['video_file_path']); ?>" 
                                type="video/<?php echo htmlspecialchars($session['video_format'] ?? 'mp4'); ?>">
                        Your browser does not support the video tag.
                    </video>
                </div>
            </div>

            <!-- Session Info -->
            <div class="card">
                <div class="card-body">
                    <h3><?php echo htmlspecialchars($session['topic']); ?></h3>
                    <p class="text-muted mb-3">
                        <i class="fas fa-book me-2"></i><?php echo htmlspecialchars($session['course_code']); ?>
                        <i class="fas fa-calendar ms-3 me-2"></i><?php echo date('M d, Y', strtotime($session['start_time'])); ?>
                    </p>
                    
                    <?php if (!empty($session['description'])): ?>
                        <div class="mt-4">
                            <h5>Description</h5>
                            <p><?php echo nl2br(htmlspecialchars($session['description'] ?? '')); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Session Details -->
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Session Details</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <small class="text-muted d-block">Duration</small>
                        <strong><?php echo $session['duration_minutes']; ?> minutes</strong>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted d-block">Instructor</small>
                        <strong><?php echo htmlspecialchars($session['created_by'] ?? 'N/A'); ?></strong>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted d-block">Status</small>
                        <span class="badge bg-success"><?php echo ucfirst($session['status']); ?></span>
                    </div>
                    
                    <?php if ($session['video_file_size']): ?>
                        <div class="mb-3">
                            <small class="text-muted d-block">File Size</small>
                            <strong><?php echo number_format($session['video_file_size'] / 1024 / 1024, 2); ?> MB</strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Access Info -->
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="fas fa-check-circle me-2"></i>Access Granted</h6>
                </div>
                <div class="card-body">
                    <p class="mb-2"><?php echo htmlspecialchars($access['reason']); ?></p>
                    <?php if ($access['payment_info']): ?>
                        <?php echo formatPaymentSummary($access['payment_info']); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
video::-webkit-media-controls-download-button {
    display: none;
}

video::-webkit-media-controls-enclosure {
    overflow: hidden;
}

video::-webkit-media-controls-panel {
    width: calc(100% + 30px);
}

/* Prevent right-click on video */
video {
    pointer-events: none;
}

/* Re-enable controls */
video::-webkit-media-controls {
    pointer-events: auto;
}
</style>

<script>
// Disable right-click on video
document.getElementById('sessionVideo').addEventListener('contextmenu', e => e.preventDefault());

// Track viewing progress (optional - for analytics)
const video = document.getElementById('sessionVideo');
let lastUpdate = 0;

video.addEventListener('timeupdate', function() {
    const currentTime = Math.floor(this.currentTime);
    
    // Update every 30 seconds
    if (currentTime - lastUpdate >= 30) {
        lastUpdate = currentTime;
        
        // Send viewing progress to server (via AJAX)
        fetch('../../api/track_video_progress.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                session_id: <?php echo $session_id; ?>,
                current_time: currentTime,
                duration: Math.floor(this.duration)
            })
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
