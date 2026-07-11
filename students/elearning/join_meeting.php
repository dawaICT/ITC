<?php
/**
 * Student Meeting Join Handler
 * Validates personalized meeting links and redirects to actual meeting
 */

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';
require_once __DIR__ . '/../../includes/elearning_live_sessions.php';
require_once __DIR__ . '/../../includes/google_meet_integration.php';
require_once __DIR__ . '/../../includes/payment_verification.php';

$student_id = $_SESSION['Sid'];
$secureToken = trim((string)($_GET['token'] ?? ''));
$baseHref = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/wucportal/students/elearning/join_meeting.php'))), '/') . '/';

function renderStudentMeetingPageStart(string $title, string $baseHref): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
        <base href="<?php echo htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8'); ?>">
        <link rel="stylesheet" href="/wucportal/css/admin-style.css">
        <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
        <link rel="stylesheet" href="/wucportal/css/elearning-ui.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
    <body>
    <?php require __DIR__ . '/../includes/navbar.php'; ?>
    <div class="content-wrapper">
    <?php
}

function renderStudentMeetingPageEnd(): void
{
    ?>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../lecturers/dist/js/bootstrap.min.js"></script>
    </body>
    </html>
    <?php
}

if ($secureToken !== '') {
    $validation = elearningValidateSecureSessionToken($db, $secureToken, $student_id);
    if (!$validation['valid']) {
        renderStudentMeetingPageStart('Cannot Join Meeting', $baseHref);
        ?>
        <div class="container py-5">
            <div class="alert alert-danger">
                <h4 class="alert-heading"><i class="fas fa-ban me-2"></i>Cannot Join Meeting</h4>
                <p><?php echo htmlspecialchars($validation['reason']); ?></p>
                <a href="elearning/live_sessions.php" class="btn btn-primary">Back to Sessions</a>
            </div>
        </div>
        <?php
        renderStudentMeetingPageEnd();
        exit;
    }

    $session = $validation['session'];
    if (!canStudentAccessElearningCourse($db, $student_id, (string)$session['course_code'])) {
        http_response_code(403);
        exit('Access denied.');
    }
    $paymentStatus = elearningStudentPaidUpStatus($db, $student_id, (string)$session['course_code'], 100.0);
    if (empty($paymentStatus['allowed'])) {
        renderStudentMeetingPageStart('Payment Required', $baseHref);
        ?>
        <div class="container py-5">
            <div class="alert alert-danger">
                <h4 class="alert-heading"><i class="fas fa-lock me-2"></i>Payment Required</h4>
                <p>Only paid-up students can access live session links.</p>
                <?php if (!empty($paymentStatus['reason'])): ?>
                    <p class="mb-3"><?php echo htmlspecialchars((string)$paymentStatus['reason']); ?></p>
                <?php endif; ?>
                <a href="/wucportal/students/elearning/live_sessions.php" class="btn btn-primary">Back to Sessions</a>
                <a href="/wucportal/students/fees.php" class="btn btn-outline-primary ms-2">View Fees</a>
            </div>
        </div>
        <?php
        renderStudentMeetingPageEnd();
        exit;
    }
    if (!elearningAllowedMeetingHost((string)$session['platform'], (string)$session['join_url'])) {
        http_response_code(400);
        exit('Meeting link is not valid.');
    }

    if ((string)$session['platform'] === 'internal') {
        // Portal-hosted room: render the in-system embed instead of redirecting out.
        require_once __DIR__ . '/../../includes/elearning_room_embed.php';
        $room = trim((string)($session['external_meeting_id'] ?? ''));
        if ($room === '') {
            $room = rawurldecode(ltrim((string)parse_url((string)$session['join_url'], PHP_URL_PATH), '/'));
        }
        $cfg = elearningLiveMeetingConfig();
        $studentName = trim((string)(($_SESSION['Fname'] ?? '') . ' ' . ($_SESSION['Lname'] ?? '')));
        if ($studentName === '') {
            $studentName = (string)($_SESSION['student_name'] ?? $student_id);
        }
        elearningRenderJitsiRoom([
            'domain' => (string)($cfg['domain'] ?? 'meet.jit.si'),
            'room' => $room,
            'displayName' => $studentName,
            'isModerator' => false,
            'topic' => (string)($session['topic'] ?? 'Live Session'),
            'courseCode' => (string)($session['course_code'] ?? ''),
            'backUrl' => 'live_sessions.php',
            'jwt' => null,
        ]);
        exit;
    }

    header('Location: ' . $session['join_url'], true, 302);
    exit;
}

$meeting_code = $_GET['code'] ?? '';

if (empty($meeting_code)) {
    renderStudentMeetingPageStart('Invalid Meeting Link', $baseHref);
    ?>
    <div class="container py-5">
        <div class="alert alert-danger">
            <h4 class="alert-heading"><i class="fas fa-ban me-2"></i>Invalid Meeting Link</h4>
            <p>This meeting link is missing a secure token or legacy meeting code.</p>
            <a href="elearning/live_sessions.php" class="btn btn-primary">Back to Sessions</a>
        </div>
    </div>
    <?php
    renderStudentMeetingPageEnd();
    exit;
}

// Validate access
$validation = validateMeetingAccess($db, $meeting_code, $student_id);

if (!$validation['valid']) {
    // Show error page
    renderStudentMeetingPageStart('Access Denied', $baseHref);
    ?>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Access Denied</h4>
                    </div>
                    <div class="card-body text-center py-5">
                        <i class="fas fa-ban fa-5x text-danger mb-4"></i>
                        <h5>Cannot Join Meeting</h5>
                        <p class="text-muted mb-4"><?php echo htmlspecialchars($validation['reason']); ?></p>
                        
                        <div class="alert alert-warning">
                            <h6><i class="fas fa-shield-alt me-2"></i>Security Notice</h6>
                            <p class="small mb-0">Meeting links are personalized and cannot be shared. Each student receives their own unique, time-limited link.</p>
                        </div>
                        
                        <a href="elearning/live_sessions.php" class="btn btn-primary mt-3">
                            <i class="fas fa-arrow-left me-2"></i>Back to Sessions
                        </a>

                        <?php if ($validation['session_id']): ?>
                            <a href="elearning/live_sessions.php?session=<?php echo $validation['session_id']; ?>" class="btn btn-success mt-3">
                                <i class="fas fa-refresh me-2"></i>Generate New Link
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    renderStudentMeetingPageEnd();
    exit;
}

// Access granted - redirect to actual meeting
$actual_link = $validation['actual_link'];
$session_data = $validation['session_data'];

renderStudentMeetingPageStart('Joining Meeting', $baseHref);
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h4 class="mb-0"><i class="fas fa-check-circle me-2"></i>Access Granted</h4>
                </div>
                <div class="card-body text-center py-5">
                    <i class="fas fa-video fa-5x text-success mb-4"></i>
                    <h5>Joining Meeting...</h5>
                    <p class="text-muted mb-4">
                        <strong><?php echo htmlspecialchars($session_data['topic']); ?></strong><br>
                        <?php echo htmlspecialchars($session_data['course_code']); ?>
                    </p>
                    
                    <div class="spinner-border text-success mb-4" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    
                    <div class="alert alert-info">
                        <p class="mb-2"><strong>Security Features Active:</strong></p>
                        <ul class="list-unstyled small mb-0">
                            <li><i class="fas fa-check text-success me-2"></i>Personalized link verified</li>
                            <li><i class="fas fa-check text-success me-2"></i>Payment status confirmed</li>
                            <li><i class="fas fa-check text-success me-2"></i>Course registration validated</li>
                            <li><i class="fas fa-check text-success me-2"></i>Access logged for security</li>
                        </ul>
                    </div>
                    
                    <?php if ($actual_link): ?>
                        <a href="<?php echo htmlspecialchars($actual_link); ?>" 
                           class="btn btn-success btn-lg mt-3" 
                           id="joinButton"
                           target="_blank"
                           rel="noopener noreferrer">
                            <i class="fas fa-external-link-alt me-2"></i>Open Meeting
                        </a>
                        <p class="small text-muted mt-2">
                            Not redirected automatically? Click the button above.
                        </p>
                    <?php else: ?>
                        <p class="text-danger">Meeting link not available. Please contact your instructor.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="alert alert-warning mt-4">
                <h6><i class="fas fa-info-circle me-2"></i>Important Notes:</h6>
                <ul class="small mb-0">
                    <li>This link is <strong>unique to you</strong> and cannot be shared</li>
                    <li>The link will expire after <?php echo $validation['expiry_minutes'] ?? 120; ?> minutes</li>
                    <li>Once used, the link may become invalid</li>
                    <li>If you need to rejoin, return to the sessions page for a new link</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-redirect after 3 seconds if link exists
<?php if ($actual_link): ?>
setTimeout(function() {
    window.location.href = <?php echo json_encode($actual_link); ?>;
}, 3000);
<?php endif; ?>

// Track if user clicked the button
document.getElementById('joinButton')?.addEventListener('click', function() {
    // Send analytics or tracking
    fetch('../../api/track_meeting_join.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            session_id: <?php echo $validation['session_id']; ?>,
            meeting_code: <?php echo json_encode($meeting_code); ?>
        })
    });
});
</script>

<style>
.spinner-border {
    width: 3rem;
    height: 3rem;
}

.card {
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.list-unstyled li {
    padding: 0.25rem 0;
}
</style>

<?php renderStudentMeetingPageEnd(); ?>
