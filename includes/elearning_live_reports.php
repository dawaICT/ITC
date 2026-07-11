<?php
require_once __DIR__ . '/elearning_live_sessions.php';

function elearning_live_report_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function elearning_live_report_date($value, string $default): string
{
    $value = trim((string)$value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : $default;
}

function elearning_live_report_bind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || empty($params)) {
        return;
    }
    $stmt->bind_param($types, ...$params);
}

function elearning_live_report_fetch_sessions(mysqli $db, string $dateFrom, string $dateTo, array $courseCodes = []): array
{
    elearningEnsureLiveSessionLinkTable($db);
    elearningEnsureLiveAttendanceTable($db);

    $params = [$dateFrom, $dateTo];
    $types = 'ss';
    $courseSql = '';
    if (!empty($courseCodes)) {
        $courseCodes = array_values(array_unique(array_filter(array_map('strval', $courseCodes))));
        if (!empty($courseCodes)) {
            $courseSql = ' AND ls.course_code IN (' . implode(',', array_fill(0, count($courseCodes), '?')) . ')';
            foreach ($courseCodes as $code) {
                $params[] = $code;
                $types .= 's';
            }
        }
    }

    $sql = "SELECT ls.id, ls.course_code, COALESCE(c.course_name, ls.course_code) AS course_name,
                   ls.platform, ls.topic, ls.start_time, ls.end_time, ls.status, ls.created_by,
                   TRIM(CONCAT(COALESCE(st.Fname, ''), ' ', COALESCE(st.Lname, ''))) AS created_by_name,
                   COUNT(DISTINCT CASE WHEN a.actor_type = 'student' THEN a.actor_id END) AS attended_count
            FROM el_live_sessions ls
            LEFT JOIN courses c ON c.course_code COLLATE utf8mb4_general_ci = ls.course_code COLLATE utf8mb4_general_ci
            LEFT JOIN staff st ON st.staff_id COLLATE utf8mb4_general_ci = ls.created_by COLLATE utf8mb4_general_ci
            LEFT JOIN el_attendance a ON a.session_id = ls.id
            WHERE DATE(ls.start_time) BETWEEN ? AND ?
              AND ls.start_time <= NOW()
              AND LOWER(COALESCE(ls.status, 'scheduled')) <> 'cancelled'
              {$courseSql}
            GROUP BY ls.id
            ORDER BY ls.start_time DESC, ls.id DESC";

    if (!$stmt = $db->prepare($sql)) {
        error_log('elearning_live_report_fetch_sessions prepare failed: ' . $db->error);
        return [];
    }
    elearning_live_report_bind($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function elearning_live_report_fetch_attendees(mysqli $db, array $sessionIds): array
{
    elearningEnsureLiveAttendanceTable($db);
    $sessionIds = array_values(array_unique(array_filter(array_map('intval', $sessionIds), static fn($id) => $id > 0)));
    if (empty($sessionIds)) {
        return [];
    }

    $sql = "SELECT a.session_id, a.actor_id AS student_id,
                   COALESCE(s.Fname, '') AS Fname, COALESCE(s.Lname, '') AS Lname,
                   MIN(a.join_time) AS first_join_time,
                   MAX(a.leave_time) AS last_leave_time,
                   SUM(COALESCE(a.duration_secs, 0)) AS duration_secs
            FROM el_attendance a
            LEFT JOIN students s ON s.SID COLLATE utf8mb4_general_ci = a.actor_id COLLATE utf8mb4_general_ci
            WHERE a.actor_type = 'student'
              AND a.session_id IN (" . implode(',', array_fill(0, count($sessionIds), '?')) . ")
            GROUP BY a.session_id, a.actor_id, s.Fname, s.Lname
            ORDER BY first_join_time ASC, a.actor_id ASC";

    if (!$stmt = $db->prepare($sql)) {
        error_log('elearning_live_report_fetch_attendees prepare failed: ' . $db->error);
        return [];
    }
    $types = str_repeat('i', count($sessionIds));
    elearning_live_report_bind($stmt, $types, $sessionIds);
    $stmt->execute();
    $res = $stmt->get_result();
    $grouped = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $grouped[(int)$row['session_id']][] = $row;
    }
    $stmt->close();
    return $grouped;
}

function elearning_live_report_status(array $session): string
{
    $status = strtolower(trim((string)($session['status'] ?? 'scheduled')));
    if ($status === 'completed') {
        return 'Completed';
    }
    if ($status === 'live') {
        return 'Live';
    }
    if (!empty($session['end_time']) && strtotime((string)$session['end_time']) < time()) {
        return 'Conducted';
    }
    if (strtotime((string)$session['start_time']) <= time()) {
        return 'Conducted';
    }
    return ucfirst($status ?: 'Scheduled');
}

function elearning_live_report_duration(array $attendee): string
{
    $seconds = (int)($attendee['duration_secs'] ?? 0);
    if ($seconds <= 0) {
        return '-';
    }
    $minutes = (int)floor($seconds / 60);
    return $minutes . ' min';
}
