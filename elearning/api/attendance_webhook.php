<?php
// Webhook receiver for Zoom/Teams participant join/leave events
// Configure the respective platform to call this endpoint with a shared secret
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/academic_risk_engine.php';

// HMAC verification. The endpoint stays closed until a real secret is configured.
$sharedSecret = getenv('ELEARN_WEBHOOK_SECRET') ?: '';
if ($sharedSecret === '') {
    error_log('attendance_webhook blocked because ELEARN_WEBHOOK_SECRET is not configured.');
    http_response_code(503);
    echo json_encode(['success'=>false,'error'=>'Webhook is not configured']);
    exit;
}
$sig = $_SERVER['HTTP_X_ELEARN_SIGNATURE'] ?? '';
$body = file_get_contents('php://input');
if (!hash_equals(hash_hmac('sha256', $body, $sharedSecret), $sig)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Bad signature']);
    exit;
}

$payload = json_decode($body, true) ?: [];
$event = (string)($payload['event'] ?? '');
$sessionId = (int)($payload['session_id'] ?? 0);
$actorType = (string)($payload['actor_type'] ?? 'student');
$actorId = (string)($payload['actor_id'] ?? '');
$timestamp = (string)($payload['timestamp'] ?? date('Y-m-d H:i:s'));

if (!$sessionId || !$actorId || !in_array($actorType, ['student','staff'], true)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Invalid payload']);
    exit;
}

if ($event === 'participant_joined') {
    $stmt = $db->prepare("INSERT INTO el_attendance (session_id, actor_type, actor_id, join_time) VALUES (?,?,?,?)");
    $stmt->bind_param('isss', $sessionId, $actorType, $actorId, $timestamp);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok && $actorType === 'student') {
        wuc_academic_risk_after_student_activity($db, $actorId, 'attendance_webhook_join');
    }
    echo json_encode(['success'=>$ok]);
    exit;
}

if ($event === 'participant_left') {
    // Update latest open attendance row for this participant
    $stmt = $db->prepare("UPDATE el_attendance SET leave_time=?, duration_secs=TIMESTAMPDIFF(SECOND, join_time, ?) WHERE session_id=? AND actor_type=? AND actor_id=? AND leave_time IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('ssiss', $timestamp, $timestamp, $sessionId, $actorType, $actorId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok && $actorType === 'student') {
        wuc_academic_risk_after_student_activity($db, $actorId, 'attendance_webhook_leave');
    }
    echo json_encode(['success'=>$ok]);
    exit;
}

http_response_code(400);
echo json_encode(['success'=>false,'error'=>'Unknown event']);
exit;


