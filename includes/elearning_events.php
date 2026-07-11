<?php
require_once __DIR__ . '/../db/connect.php';

if (!function_exists('log_lms_event')) {
    function log_lms_event(mysqli $db, ?string $Sid, ?string $actorRole, ?string $courseCode, ?int $moduleId, string $eventType, array $eventData = []): void {
        $eventJson = json_encode($eventData, JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare("INSERT INTO lms_events (Sid, actor_role, course_code, module_id, event_type, event_data) VALUES (?,?,?,?,?,?)");
        $sid = $Sid; $role = $actorRole; $course = $courseCode; $mid = $moduleId; $etype = $eventType; $edata = $eventJson;
        if ($stmt) { $stmt->bind_param('sssiss', $sid, $role, $course, $mid, $etype, $edata); $stmt->execute(); }
    }
}

if (!function_exists('get_recent_event_counts')) {
    function get_recent_event_counts(mysqli $db, int $days = 7): array {
        $counts = [];
        $stmt = $db->prepare("SELECT event_type, COUNT(*) AS cnt FROM lms_events WHERE created_at >= (NOW() - INTERVAL ? DAY) GROUP BY event_type");
        if ($stmt) { $stmt->bind_param('i', $days); $stmt->execute(); $res = $stmt->get_result(); while ($r = $res->fetch_assoc()) { $counts[$r['event_type']] = (int)$r['cnt']; } }
        return $counts;
    }
}

if (!function_exists('get_inactive_students')) {
    function get_inactive_students(mysqli $db, int $days = 14, int $limit = 50): array {
        // Students without any events in the last N days
        $sql = "SELECT s.SID AS Sid, s.Fname, s.Lname FROM students s
                LEFT JOIN lms_events e ON e.Sid = s.SID AND e.created_at >= (NOW() - INTERVAL ? DAY)
                WHERE e.id IS NULL
                ORDER BY s.SID DESC LIMIT ?";
        $list = [];
        if ($stmt = $db->prepare($sql)) { $stmt->bind_param('ii', $days, $limit); $stmt->execute(); $res = $stmt->get_result(); while ($r = $res->fetch_assoc()) { $list[] = $r; } }
        return $list;
    }
}


