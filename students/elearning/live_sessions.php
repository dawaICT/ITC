<?php
/**
 * Student live sessions for active e-learning schema.
 */

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';
require_once __DIR__ . '/../../includes/elearning_live_sessions.php';

$studentId = (string)$_SESSION['Sid'];
$err = null;
$ok = null;
$generatedLink = null;
elearningEnsureLiveSessionLinkTable($db);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!empty($_SESSION['live_session_flash']) && is_array($_SESSION['live_session_flash'])) {
    $ok = (string)($_SESSION['live_session_flash']['ok'] ?? '');
    $generatedLink = (string)($_SESSION['live_session_flash']['link'] ?? '');
    unset($_SESSION['live_session_flash']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'generate_link') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $err = 'Invalid request. Please refresh the page and try again.';
    }
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $courseCode = trim((string)($_POST['course_code'] ?? ''));
    $postedOfferingIds = $courseCode !== '' ? getStudentCourseOfferingIds($db, $studentId, $courseCode) : [];

    $sessionRecord = null;
    if (!$err && ($sessionId <= 0 || $courseCode === '' || !canStudentAccessElearningCourse($db, $studentId, $courseCode))) {
        $err = 'You are not allowed to access this session.';
    } elseif (!$err) {
        $sessionTypes = 'is';
        $sessionParams = [$sessionId, $courseCode];
        $sessionOfferingSql = elearningOfferingScopeCondition($db, 'el_live_sessions', null, $postedOfferingIds, $sessionTypes, $sessionParams);
        if ($stmt = $db->prepare("SELECT id, course_offering_id, course_code, platform, join_url FROM el_live_sessions WHERE id = ? AND course_code = ? {$sessionOfferingSql} LIMIT 1")) {
            $stmt->bind_param($sessionTypes, ...$sessionParams);
            $stmt->execute();
            $sessionRecord = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if (!$sessionRecord) {
            $err = 'Live session was not found.';
        } elseif (!elearningAllowedMeetingHost((string)$sessionRecord['platform'], (string)$sessionRecord['join_url'])) {
            $err = 'This live session needs a valid meeting URL before students can join.';
        } else {
            $paymentStatus = elearningStudentPaidUpStatus($db, $studentId, $courseCode, 100.0);
            if (empty($paymentStatus['allowed'])) {
                $err = 'Only paid-up students can access live session links. ' . ($paymentStatus['reason'] ?? 'Please clear your balance with finance.');
            }
        }
    }

    if (!$err) {
        $result = elearningGenerateSecureSessionToken($db, $sessionId, $studentId, 120);
        if (!empty($result['success'])) {
            if (!empty($result['token'])) {
                $_SESSION['live_session_flash'] = [
                    'ok' => 'Your secure meeting link has been generated.',
                    'link' => 'elearning/join_meeting.php?token=' . urlencode($result['token']),
                ];
                header('Location: live_sessions.php');
                exit;
            }
        } else {
            $err = $result['error'] ?? 'Failed to generate secure meeting link.';
        }
    }
}

$registeredCourses = getStudentEnrolledCourses($db, $studentId);
$registeredOfferingIds = getStudentCourseOfferingIds($db, $studentId);
$sessions = [];
if (!empty($registeredCourses)) {
    $placeholders = implode(',', array_fill(0, count($registeredCourses), '?'));
    $types = str_repeat('s', count($registeredCourses));
    $params = $registeredCourses;
    $offeringSql = elearningOfferingScopeCondition($db, 'el_live_sessions', null, $registeredOfferingIds, $types, $params);
    $sql = "SELECT * FROM el_live_sessions WHERE course_code IN ($placeholders) {$offeringSql} ORDER BY start_time DESC";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sessions[] = $row;
        }
        $stmt->close();
    }
}

$notifications = [];
if ($stmt = $db->prepare("SELECT id, course_code, type, title, body, url, created_at FROM el_student_notifications WHERE student_id = ? ORDER BY created_at DESC LIMIT 8")) {
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
}

$baseHref = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/wucportal/students/elearning/live_sessions.php'))), '/') . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Sessions - ITC</title>
    <base href="<?php echo htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/css/elearning-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="content-wrapper">
    <section class="elearning-shell">
        <div class="elearning-header">
            <div>
                <p class="elearning-kicker">Student eLearning</p>
                <h2><i class="fas fa-video me-2"></i>Live Sessions</h2>
                <p>Generate personal join links for eligible live classes.</p>
            </div>
            <div class="elearning-actions">
                <a class="btn btn-outline-secondary" href="elearning/index.php">
                    <i class="fas fa-arrow-left me-1"></i>My Courses
                </a>
            </div>
        </div>

    <?php if ($err): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>
    <?php if ($ok): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($ok); ?>
            <?php if ($generatedLink): ?>
                <a class="btn btn-sm btn-success ms-2" href="<?php echo htmlspecialchars($generatedLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Join now</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($notifications)): ?>
        <div class="elearning-card mb-3">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <p class="elearning-kicker mb-1">Notifications</p>
                    <h5 class="mb-0">Recent live-session updates</h5>
                </div>
                <span class="badge bg-primary"><?php echo count($notifications); ?></span>
            </div>
            <div class="elearning-notification-list">
                <?php foreach ($notifications as $note): ?>
                    <?php
                    $noteCourseCode = trim((string)($note['course_code'] ?? ''));
                    $noteCourseLabel = ($noteCourseCode === '' || strtoupper($noteCourseCode) === 'GENERAL') ? 'Academic support' : $noteCourseCode;
                    ?>
                    <a class="elearning-notification" href="<?php echo htmlspecialchars((string)($note['url'] ?: 'elearning/live_sessions.php'), ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="elearning-notification-icon"><i class="fas fa-bell"></i></span>
                        <span>
                            <strong><?php echo htmlspecialchars((string)$note['title']); ?></strong>
                            <small><?php echo htmlspecialchars($noteCourseLabel); ?> &middot; <?php echo htmlspecialchars(date('M d, h:i A', strtotime((string)$note['created_at']))); ?></small>
                            <?php if (!empty($note['body'])): ?>
                                <em><?php echo htmlspecialchars((string)$note['body']); ?></em>
                            <?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($sessions)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>No live sessions are available for your registered courses.
        </div>
    <?php else: ?>
        <div class="student-live-grid">
            <?php foreach ($sessions as $session): ?>
                <?php
                    $sessionId = (int)$session['id'];
                    $courseCode = (string)$session['course_code'];
                    $startTime = strtotime((string)$session['start_time']);
                    $endTime = !empty($session['end_time']) ? strtotime((string)$session['end_time']) : ($startTime + 3600);
                    $isLive = $startTime <= time() && $endTime >= time();
                    $isPast = $endTime < time() && !$isLive;
                    $status = strtolower((string)($session['status'] ?? 'scheduled'));
                    $isCancelled = $status === 'cancelled';
                    $isPostponed = $status === 'postponed';
                    $meetingUrlValid = elearningAllowedMeetingHost((string)$session['platform'], (string)$session['join_url']);
                    $paymentStatus = elearningStudentPaidUpStatus($db, $studentId, $courseCode, 100.0);
                    $isPaidUp = !empty($paymentStatus['allowed']);
                    $activeLink = null;
                    if ($stmt = $db->prepare("SELECT expires_at FROM el_live_session_links WHERE session_id = ? AND student_id = ? AND revoked_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1")) {
                        $stmt->bind_param('is', $sessionId, $studentId);
                        $stmt->execute();
                        $activeLink = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                    }
                ?>
                <article class="elearning-card student-live-card">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <h5 class="card-title mb-0"><?php echo htmlspecialchars((string)$session['topic']); ?></h5>
                                <span class="badge bg-info"><?php echo htmlspecialchars($session['platform'] === 'internal' ? 'Portal Room' : strtoupper((string)$session['platform'])); ?></span>
                            </div>
                            <p class="text-muted small mb-2"><i class="fas fa-book me-1"></i><?php echo htmlspecialchars($courseCode); ?></p>
                            <p class="text-muted small mb-1"><i class="fas fa-calendar me-1"></i>Starts <?php echo date('M d, Y - h:i A', $startTime); ?></p>
                            <p class="text-muted small mb-3"><i class="fas fa-hourglass-end me-1"></i>Ends <?php echo date('M d, Y - h:i A', $endTime); ?></p>
                            <?php if ($isCancelled): ?>
                                <span class="badge bg-danger mb-3">Cancelled</span>
                            <?php elseif ($isPostponed): ?>
                                <span class="badge bg-warning text-dark mb-3">Postponed</span>
                            <?php elseif ($isLive): ?>
                                <span class="badge bg-success mb-3">Live now</span>
                            <?php elseif ($isPast): ?>
                                <span class="badge bg-secondary mb-3">Completed</span>
                            <?php else: ?>
                                <span class="badge bg-primary mb-3">Upcoming</span>
                            <?php endif; ?>

                            <?php if ($activeLink): ?>
                                <div class="alert alert-light border small">
                                    Existing secure link expires <?php echo htmlspecialchars(date('h:i A', strtotime((string)$activeLink['expires_at']))); ?>.
                                    Generate a fresh link if you need to join again from this page.
                                </div>
                            <?php endif; ?>
                            <?php if (!$meetingUrlValid): ?>
                                <div class="alert alert-warning small">
                                    The lecturer needs to update this session with a real meeting URL before students can join.
                                </div>
                            <?php endif; ?>
                            <?php if ($isCancelled): ?>
                                <div class="alert alert-danger small">
                                    This live session was cancelled<?php echo !empty($session['status_reason']) ? ': ' . htmlspecialchars((string)$session['status_reason']) : '.'; ?>
                                </div>
                            <?php elseif ($isPostponed && !empty($session['status_reason'])): ?>
                                <div class="alert alert-warning small">
                                    Postponed: <?php echo htmlspecialchars((string)$session['status_reason']); ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!$isCancelled && !$isPast && $meetingUrlValid): ?>
                                <form method="post" class="student-live-action">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="generate_link">
                                    <input type="hidden" name="session_id" value="<?php echo $sessionId; ?>">
                                    <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button class="btn btn-primary w-100" type="submit">
                                        <i class="fas fa-shield-alt me-2"></i><?php echo $activeLink ? 'Generate Fresh Link' : 'Generate Secure Link'; ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-secondary w-100" type="button" disabled>
                                    <i class="fas fa-lock me-2"></i>Secure Link Unavailable
                                </button>
                            <?php endif; ?>
                        <footer class="student-live-card-footer">
                            <small class="text-muted">Links are personal, expire and are deleted after 120 minutes, and are verified before redirecting.</small>
                        </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </section>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
</body>
</html>
