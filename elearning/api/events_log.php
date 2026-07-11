<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';
header('Content-Type: application/json');

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) { echo json_encode(['success'=>true]); exit; }

$actorType = isset($_SESSION['Sid']) ? 'student' : (isset($_SESSION['staff_id']) ? 'staff' : 'guest');
$actorId = $actorType === 'student' ? (string)($_SESSION['Sid'] ?? '') : ($actorType === 'staff' ? (string)($_SESSION['staff_id'] ?? '') : '');

$eventType = substr(trim((string)($payload['event_type'] ?? '')), 0, 64);
$courseCode = substr(trim((string)($payload['course_code'] ?? '')), 0, 64);
$moduleId = isset($payload['module_id']) ? (int)$payload['module_id'] : null;
$contentId = isset($payload['content_id']) ? (int)$payload['content_id'] : null;
$sessionId = isset($payload['session_id']) ? (int)$payload['session_id'] : null;
$metadata = json_encode($payload['metadata'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($eventType === '') { echo json_encode(['success'=>false,'error'=>'Missing event_type']); exit; }

// Verify course access - only log events for courses the user has access to
if ($courseCode !== '') {
    $hasAccess = false;
    if ($actorType === 'student' && $actorId !== '') {
        $hasAccess = canStudentAccessElearningCourse($db, $actorId, $courseCode);
    } elseif ($actorType === 'staff' && $actorId !== '') {
        $hasAccess = canLecturerAccessElearningCourse($db, $actorId, $courseCode);
    }
    if (!$hasAccess && $actorType !== 'guest') {
        echo json_encode(['success'=>false,'error'=>'Access denied to course']); exit;
    }
}

$exists = $db->query("SHOW TABLES LIKE 'el_analytics_events'");
if ($exists && $exists->num_rows > 0) {
    $exists->free();
    if ($stmt = $db->prepare("INSERT INTO el_analytics_events (event_type, actor_type, actor_id, course_code, module_id, content_id, session_id, metadata_json, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")) {
        $stmt->bind_param('ssssiiis', $eventType, $actorType, $actorId, $courseCode, $moduleId, $contentId, $sessionId, $metadata);
        $stmt->execute();
        $stmt->close();
    }
}

echo json_encode(['success'=>true]);
exit;


