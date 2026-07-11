<?php
/**
 * Lecturer / admin entry to a portal-hosted live room (moderator side).
 *
 * Renders the in-system Jitsi room for the session owner (or any systems admin). The room
 * name is the portal-generated identifier stored on the session — no external provider link
 * is involved. Students join the same room via students/elearning/join_meeting.php after
 * their per-student secure token is validated.
 */

require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/elearning_guard.php';
elearning_require_role(['systems_admin', 'lecturer']);

require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_live_sessions.php';
require_once __DIR__ . '/../../includes/elearning_room_embed.php';

$sessionId = (int)($_GET['id'] ?? 0);
$currentUserId = (string)($_SESSION['staff_id'] ?? '');
$isUserAdmin = !empty($isAdmin) || (($_SESSION['role'] ?? '') === 'systems_admin');

if ($sessionId <= 0) {
    header('Location: sessions.php');
    exit;
}

$stmt = $db->prepare("SELECT id, course_code, platform, topic, join_url, host_url, external_meeting_id, created_by, status FROM el_live_sessions WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $sessionId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    header('Location: sessions.php');
    exit;
}

// Only the lecturer who owns the session (or an admin) may host it.
if (!$isUserAdmin && (string)$session['created_by'] !== $currentUserId) {
    http_response_code(403);
    exit('You do not have permission to host this session.');
}

if ((string)$session['platform'] !== 'internal') {
    // Not a portal-hosted room — fall back to the external link if present.
    $external = trim((string)($session['host_url'] ?: $session['join_url']));
    if ($external !== '') {
        header('Location: ' . $external);
        exit;
    }
    http_response_code(400);
    exit('This session is not a portal-hosted live room.');
}

$room = trim((string)($session['external_meeting_id'] ?? ''));
if ($room === '') {
    // Fallback: derive the room from the canonical join URL.
    $room = ltrim((string)parse_url((string)$session['join_url'], PHP_URL_PATH), '/');
    $room = rawurldecode($room);
}
if ($room === '') {
    http_response_code(400);
    exit('This live room has no room identifier.');
}

$cfg = elearningLiveMeetingConfig();
$displayName = trim((string)(($_SESSION['Fname'] ?? '') . ' ' . ($_SESSION['Lname'] ?? '')));
if ($displayName === '') {
    $displayName = (string)($_SESSION['user_name'] ?? $currentUserId ?: 'Lecturer');
}

elearningRenderJitsiRoom([
    'domain' => (string)($cfg['domain'] ?? 'meet.jit.si'),
    'room' => $room,
    'displayName' => $displayName,
    'isModerator' => true,
    'topic' => (string)$session['topic'],
    'courseCode' => (string)$session['course_code'],
    'backUrl' => 'sessions.php',
    'jwt' => null, // Phase 2: signed moderator JWT for a self-hosted engine.
]);
