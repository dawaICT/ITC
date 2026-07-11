<?php
/** Real-time attendance polling endpoint. */
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../db/connect.php';

wuc_api_require_staff();

$sessionId = (int) ($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    wuc_json_error('Invalid session ID.', 422);
}

try {
    $stmt = $db->prepare(
        "SELECT l.student_id,
                s.Fname AS first_name,
                s.Lname AS last_name,
                l.used_at,
                l.expires_at,
                l.revoked_at,
                CASE
                    WHEN l.revoked_at IS NOT NULL THEN 'revoked'
                    WHEN l.used_at IS NOT NULL THEN 'active'
                    WHEN l.expires_at < NOW() THEN 'expired'
                    ELSE 'ready'
                END AS status
         FROM el_live_session_links l
         INNER JOIN students s ON s.SID = l.student_id
         WHERE l.session_id = ?"
    );
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $result = $stmt->get_result();

    $participants = [];
    $counts = ['active' => 0, 'ready' => 0, 'expired' => 0, 'revoked' => 0];
    while ($row = $result->fetch_assoc()) {
        $status = (string) $row['status'];
        $participants[] = [
            'student_id' => $row['student_id'],
            'name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
            'status' => $status,
            'joined_at' => $row['used_at'],
            'link_expires' => $row['expires_at'],
        ];
        $counts[$status]++;
    }
    $stmt->close();

    $stmt = $db->prepare('SELECT status, start_time FROM el_live_sessions WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $sessionStatus = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    wuc_json_response([
        'success' => true,
        'session_id' => $sessionId,
        'session_status' => $sessionStatus['status'] ?? 'scheduled',
        'started_at' => $sessionStatus['start_time'] ?? null,
        'participants' => $participants,
        'counts' => $counts,
        'total_active' => $counts['active'],
        'total_participants' => count($participants),
        'waiting_room_count' => 0,
        'timestamp' => time(),
    ]);
} catch (Throwable $e) {
    error_log('Attendance poll failed: ' . $e->getMessage());
    wuc_json_error('Unable to load attendance.', 500);
}
