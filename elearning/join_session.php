<?php
/**
 * Lecturer/staff live session join gateway.
 *
 * Verifies the staff member is assigned to the course/session before opening
 * the same provider meeting URL that student secure links resolve to.
 */

require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_live_sessions.php';

$staffId = (string)($_SESSION['staff_id'] ?? '');
$sessionId = (int)($_GET['session_id'] ?? 0);
$courseCode = trim((string)($_GET['course_code'] ?? ''));

function elearning_join_session_fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

if ($staffId === '' || $sessionId <= 0 || $courseCode === '') {
    elearning_join_session_fail(400, 'Invalid live session request.');
}

$canJoin = canLecturerAccessElearningCourse($db, $staffId, $courseCode)
    || hasPermission($staffId, 'elearn_schedule_sessions')
    || hasPermission($staffId, 'elearn_admin_all');
if (!$canJoin) {
    elearning_join_session_fail(403, 'You are not assigned to this live session course.');
}

$session = null;
if ($stmt = $db->prepare("SELECT id, course_code, platform, topic, join_url, external_meeting_id, end_time, status FROM el_live_sessions WHERE id = ? AND course_code = ? LIMIT 1")) {
    $stmt->bind_param('is', $sessionId, $courseCode);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$session) {
    elearning_join_session_fail(404, 'Live session was not found.');
}

if (strtolower((string)($session['status'] ?? 'scheduled')) === 'cancelled') {
    elearning_join_session_fail(410, 'This live session has been cancelled.');
}

if (!empty($session['end_time']) && strtotime((string)$session['end_time']) < time()) {
    elearning_join_session_fail(410, 'This live session has ended.');
}

if (!elearningAllowedMeetingHost((string)$session['platform'], (string)$session['join_url'])) {
    elearning_join_session_fail(400, 'Live session meeting URL is not valid.');
}

if ((string)$session['platform'] === 'internal') {
    // Portal-hosted room: render the in-system embed (lecturer = host) into the same room
    // students join. No external link is ever exposed.
    require_once __DIR__ . '/../includes/elearning_room_embed.php';
    $room = trim((string)($session['external_meeting_id'] ?? ''));
    if ($room === '') {
        $room = rawurldecode(ltrim((string)parse_url((string)$session['join_url'], PHP_URL_PATH), '/'));
    }
    $cfg = elearningLiveMeetingConfig();
    $displayName = trim((string)(($_SESSION['Fname'] ?? '') . ' ' . ($_SESSION['Lname'] ?? '')));
    if ($displayName === '') {
        $displayName = (string)($_SESSION['user_name'] ?? $staffId);
    }
    elearningRenderJitsiRoom([
        'domain' => (string)($cfg['domain'] ?? 'meet.jit.si'),
        'room' => $room,
        'displayName' => $displayName,
        'isModerator' => true,
        'topic' => (string)($session['topic'] ?? 'Live Session'),
        'courseCode' => (string)$session['course_code'],
        'backUrl' => 'sessions.php?course_code=' . rawurlencode((string)$session['course_code']),
        'jwt' => null,
    ]);
    exit;
}

header('Location: ' . $session['join_url'], true, 302);
exit;
