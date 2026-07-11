<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/elearning_access.php';
require_once __DIR__ . '/academic_risk_engine.php';

function elearningEnsureLiveSessionLinkTable(mysqli $db): void
{
    elearningEnsureLiveSessionSchema($db);

    if (!elearningTableExists($db, 'el_live_session_links')) {
        error_log('el_live_session_links table is missing; run migrations.');
        return;
    }

    elearningPurgeExpiredSessionLinks($db);
    elearningPurgeExpiredLiveSessions($db, 24);
}

function elearningEnsureLiveAttendanceTable(mysqli $db): void
{
    elearningEnsureLiveSessionSchema($db);

    if (!elearningTableExists($db, 'el_attendance')) {
        error_log('el_attendance table is missing; run migrations.');
    }
}

function elearningPurgeExpiredSessionLinks(mysqli $db): int
{
    if (!elearningTableExists($db, 'el_live_session_links')) {
        return 0;
    }

    try {
        if ($stmt = $db->prepare("DELETE FROM el_live_session_links WHERE expires_at <= NOW()")) {
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            return max(0, (int)$deleted);
        }
    } catch (Throwable $e) {
        error_log('elearningPurgeExpiredSessionLinks failed: ' . $e->getMessage());
    }

    return 0;
}

function elearningPurgeExpiredLiveSessions(mysqli $db, int $olderThanHours = 24): int
{
    if (!elearningTableExists($db, 'el_live_sessions')) {
        return 0;
    }

    $olderThanHours = max(1, $olderThanHours);
    $cutoff = date('Y-m-d H:i:s', time() - ($olderThanHours * 3600));
    $effectiveEndExpr = elearningColumnExists($db, 'el_live_sessions', 'end_time')
        ? 'COALESCE(end_time, DATE_ADD(start_time, INTERVAL 1 HOUR))'
        : 'DATE_ADD(start_time, INTERVAL 1 HOUR)';

    try {
        $ids = [];
        $sql = "SELECT id FROM el_live_sessions WHERE {$effectiveEndExpr} <= ?";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $cutoff);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int)$row['id'];
            }
            $stmt->close();
        }

        if (!$ids) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        foreach ([
            'el_live_session_links' => 'session_id',
            'el_student_notifications' => 'session_id',
            'el_attendance' => 'session_id',
        ] as $table => $column) {
            if (!elearningTableExists($db, $table) || !elearningColumnExists($db, $table, $column)) {
                continue;
            }
            if ($stmt = $db->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})")) {
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($stmt = $db->prepare("DELETE FROM el_live_sessions WHERE id IN ({$placeholders})")) {
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            return max(0, (int)$deleted);
        }
    } catch (Throwable $e) {
        error_log('elearningPurgeExpiredLiveSessions failed: ' . $e->getMessage());
    }

    return 0;
}

function elearningRecordLiveSessionAttendance(mysqli $db, int $sessionId, string $studentId): bool
{
    elearningEnsureLiveAttendanceTable($db);

    if (!elearningTableExists($db, 'el_attendance')) {
        return false;
    }

    $studentId = trim($studentId);
    if ($sessionId <= 0 || $studentId === '') {
        return false;
    }

    if ($stmt = $db->prepare("SELECT id FROM el_attendance WHERE session_id = ? AND actor_type = 'student' AND actor_id = ? ORDER BY id DESC LIMIT 1")) {
        $stmt->bind_param('is', $sessionId, $studentId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            return true;
        }
    }

    if ($stmt = $db->prepare("INSERT INTO el_attendance (session_id, actor_type, actor_id, join_time) VALUES (?, 'student', ?, NOW())")) {
        $stmt->bind_param('is', $sessionId, $studentId);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            wuc_academic_risk_after_student_activity($db, $studentId, 'live_session_attendance');
        }
        return $ok;
    }

    return false;
}

if (!function_exists('elearningTableExists')) {
    function elearningTableExists(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        try {
            $res = @$db->query("SHOW TABLES LIKE '{$safe}'");
        } catch (Throwable $e) {
            return false;
        }
        if (!$res) {
            return false;
        }
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
}

function elearningColumnExists(mysqli $db, string $table, string $column): bool
{
    $safeTable = str_replace('`', '', $table);
    $safeColumn = $db->real_escape_string($column);
    try {
        $res = @$db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    } catch (Throwable $e) {
        return false;
    }
    if (!$res) {
        return false;
    }
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
}

function elearningEnsureLiveSessionSchema(mysqli $db): void
{
    foreach (['el_live_sessions', 'el_student_notifications'] as $table) {
        if (!elearningTableExists($db, $table)) {
            error_log("{$table} table is missing; run migrations.");
        }
    }
}

function elearningAllowedMeetingHost(string $platform, string $url): bool
{
    if (!wuc_validate_external_http_url($url)) {
        return false;
    }

    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    if ($platform === 'internal') {
        // Portal-hosted room: the URL is system-generated, so it only has to match the
        // configured live-meeting engine domain and carry a room path.
        $cfg = elearningLiveMeetingConfig();
        $domain = strtolower(trim((string)($cfg['domain'] ?? 'meet.jit.si')));
        $path = (string)parse_url($url, PHP_URL_PATH);
        return $domain !== '' && $host === $domain && (bool)preg_match('#^/[A-Za-z0-9._=-]{6,}/?$#', $path);
    }

    if ($platform === 'google_meet') {
        $path = (string)parse_url($url, PHP_URL_PATH);
        return $host === 'meet.google.com' && preg_match('#^/[a-z0-9]{3}-[a-z0-9]{4}-[a-z0-9]{3}/?$#i', $path);
    }
    if ($platform === 'teams') {
        $path = (string)parse_url($url, PHP_URL_PATH);
        return $host === 'teams.microsoft.com' && strpos($path, '/l/meetup-join/') === 0;
    }
    if ($platform === 'zoom') {
        $path = (string)parse_url($url, PHP_URL_PATH);
        return ($host === 'zoom.us' || substr($host, -8) === '.zoom.us') && preg_match('#^/j/\d{9,12}#', $path);
    }

    return false;
}

/**
 * Auto-generate a Google Meet style join URL (https://meet.google.com/xxx-xxxx-xxx).
 * The 3-4-3 lowercase pattern satisfies elearningAllowedMeetingHost('google_meet', ...).
 * Used when a lecturer/admin creates a session without pasting their own meeting URL.
 */
function elearningGenerateGoogleMeetUrl(): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyz';
    $pick = static function (int $length) use ($alphabet): string {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, 25)];
        }
        return $out;
    };

    return 'https://meet.google.com/' . $pick(3) . '-' . $pick(4) . '-' . $pick(3);
}

/**
 * Configuration for the portal-hosted live meeting engine (config/elearning.php
 * 'live_meeting'). Cached for the request; falls back to public Jitsi.
 */
function elearningLiveMeetingConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $full = @include __DIR__ . '/../config/elearning.php';
        $cfg = (is_array($full) && isset($full['live_meeting']) && is_array($full['live_meeting']))
            ? $full['live_meeting']
            : ['engine' => 'jitsi', 'domain' => 'meet.jit.si', 'embed' => true, 'jwt' => ['enabled' => false]];
    }
    return $cfg;
}

/**
 * Generate an unguessable room name for a portal-hosted live session.
 * Shape: wuc-<sanitised course>-<random hex>. The portal owns this name end to end.
 */
function elearningGenerateInternalRoomName(string $courseCode): string
{
    $slug = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '', $courseCode));
    if ($slug === '') {
        $slug = 'class';
    }
    return 'wuc-' . substr($slug, 0, 16) . '-' . bin2hex(random_bytes(5));
}

/**
 * Canonical room URL for a portal-hosted live session, on the configured engine domain.
 */
function elearningInternalRoomUrl(string $roomName): string
{
    $cfg = elearningLiveMeetingConfig();
    $domain = trim((string)($cfg['domain'] ?? 'meet.jit.si'));
    if ($domain === '') {
        $domain = 'meet.jit.si';
    }
    return 'https://' . $domain . '/' . rawurlencode($roomName);
}

function elearningStudentPaidUpStatus(mysqli $db, string $studentId, ?string $courseCode = null, float $requiredPercent = 100.0): array
{
    $requiredPercent = max(0.0, min(100.0, $requiredPercent));

    if ($courseCode !== ''
        && $courseCode !== null
        && elearningTableExists($db, 'course_registration')
        && elearningColumnExists($db, 'course_registration', 'tuition_total')
        && elearningColumnExists($db, 'course_registration', 'amount_paid')) {
        $activeClause = elearningColumnExists($db, 'course_registration', 'is_active') ? ' AND is_active = 1' : '';
        $sql = "SELECT COALESCE(SUM(tuition_total),0) AS total_due, COALESCE(SUM(amount_paid),0) AS total_paid
                FROM course_registration
                WHERE Sid = ? AND course_code = ?{$activeClause}";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('ss', $studentId, $courseCode);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $due = (float)($row['total_due'] ?? 0);
            $paid = (float)($row['total_paid'] ?? 0);
            if ($due > 0) {
                $percent = round(($paid / $due) * 100, 2);
                return [
                    'allowed' => ($percent + 0.00001) >= $requiredPercent,
                    'percent' => $percent,
                    'total_due' => $due,
                    'total_paid' => $paid,
                    'balance' => max(0, $due - $paid),
                    'reason' => "{$percent}% paid for this course",
                ];
            }
        }
    }

    if (elearningTableExists($db, 'invoices') && elearningColumnExists($db, 'invoices', 'amount')) {
        $sidCol = elearningColumnExists($db, 'invoices', 'student_id') ? 'student_id' : (elearningColumnExists($db, 'invoices', 'Sid') ? 'Sid' : null);
        if ($sidCol !== null) {
            $statusExpr = elearningColumnExists($db, 'invoices', 'status') ? "LOWER(COALESCE(`status`, 'pending'))" : "'pending'";
            $invoiceHasPaidAmount = elearningColumnExists($db, 'invoices', 'amount_paid');
            $invoiceHasBalance = elearningColumnExists($db, 'invoices', 'balance');

            if ($invoiceHasPaidAmount || $invoiceHasBalance) {
                $paidExpr = $invoiceHasPaidAmount ? 'COALESCE(`amount_paid`,0)' : "CASE WHEN {$statusExpr} IN ('paid','completed','cleared','success') THEN `amount` ELSE 0 END";
                $balanceExpr = $invoiceHasBalance ? 'COALESCE(`balance`,0)' : "GREATEST(`amount` - {$paidExpr}, 0)";
                $sql = "SELECT COALESCE(SUM(`amount`),0) AS total_due,
                               COALESCE(SUM({$paidExpr}),0) AS total_paid,
                               COALESCE(SUM(CASE WHEN {$statusExpr} IN ('paid','completed','cleared','success') THEN 0 ELSE {$balanceExpr} END),0) AS balance
                        FROM invoices
                        WHERE `{$sidCol}` = ?";
                if ($stmt = $db->prepare($sql)) {
                    $stmt->bind_param('s', $studentId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc() ?: [];
                    $stmt->close();
                    $due = (float)($row['total_due'] ?? 0);
                    $paid = (float)($row['total_paid'] ?? 0);
                    $balance = (float)($row['balance'] ?? 0);
                    if ($due > 0) {
                        $percent = round(($paid / $due) * 100, 2);
                        return [
                            'allowed' => $balance <= 0.009 && ($percent + 0.00001) >= $requiredPercent,
                            'percent' => $percent,
                            'total_due' => $due,
                            'total_paid' => $paid,
                            'balance' => max(0, $balance),
                            'reason' => $balance <= 0.009 ? 'Paid up' : 'Outstanding invoice balance',
                        ];
                    }
                }
            } else {
                $sql = "SELECT COALESCE(SUM(`amount`),0) AS total_due,
                               COALESCE(SUM(CASE WHEN {$statusExpr} IN ('paid','completed','cleared','success') THEN `amount` ELSE 0 END),0) AS invoice_paid
                        FROM invoices
                        WHERE `{$sidCol}` = ?";
                if ($stmt = $db->prepare($sql)) {
                    $stmt->bind_param('s', $studentId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc() ?: [];
                    $stmt->close();
                    $due = (float)($row['total_due'] ?? 0);
                    if ($due > 0) {
                        $paid = (float)($row['invoice_paid'] ?? 0);
                        if (elearningTableExists($db, 'student_payments') && elearningColumnExists($db, 'student_payments', 'amount_paid')) {
                            $paySidCol = elearningColumnExists($db, 'student_payments', 'Sid') ? 'Sid' : (elearningColumnExists($db, 'student_payments', 'student_id') ? 'student_id' : null);
                            if ($paySidCol !== null) {
                                $paymentStatusExpr = elearningColumnExists($db, 'student_payments', 'payment_status')
                                    ? "LOWER(COALESCE(`payment_status`, 'completed'))"
                                    : "'completed'";
                                $paySql = "SELECT COALESCE(SUM(`amount_paid`),0) AS total_paid
                                           FROM student_payments
                                           WHERE `{$paySidCol}` = ?
                                             AND {$paymentStatusExpr} IN ('paid','paid up','paid-up','completed','complete','cleared','success','approved','sponsored')";
                                if ($payStmt = $db->prepare($paySql)) {
                                    $payStmt->bind_param('s', $studentId);
                                    $payStmt->execute();
                                    $payRow = $payStmt->get_result()->fetch_assoc() ?: [];
                                    $payStmt->close();
                                    $paid = max($paid, (float)($payRow['total_paid'] ?? 0));
                                }
                            }
                        }

                        $balance = max(0.0, $due - $paid);
                        $percent = round(($paid / $due) * 100, 2);
                        return [
                            'allowed' => $balance <= 0.009 && ($percent + 0.00001) >= $requiredPercent,
                            'percent' => $percent,
                            'total_due' => $due,
                            'total_paid' => min($paid, $due),
                            'balance' => $balance,
                            'reason' => $balance <= 0.009 ? 'Paid up' : 'Outstanding invoice balance',
                        ];
                    }
                }
            }
        }
    }

    if (elearningTableExists($db, 'semester_registration') && elearningColumnExists($db, 'semester_registration', 'financial_status')) {
        $sidCol = elearningColumnExists($db, 'semester_registration', 'student_id') ? 'student_id' : (elearningColumnExists($db, 'semester_registration', 'SID') ? 'SID' : null);
        if ($sidCol !== null && ($stmt = $db->prepare("SELECT financial_status FROM semester_registration WHERE `{$sidCol}` = ? ORDER BY id DESC LIMIT 1"))) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $status = strtolower(trim((string)$row['financial_status']));
                $paidStatuses = ['paid', 'paid up', 'paid-up', 'cleared', 'complete', 'completed', 'sponsored'];
                $unpaidStatuses = ['unpaid', 'owing', 'pending', 'partial', 'part-paid', 'blocked'];
                if (in_array($status, $paidStatuses, true)) {
                    return ['allowed' => true, 'percent' => 100.0, 'total_due' => 0.0, 'total_paid' => 0.0, 'balance' => 0.0, 'reason' => 'Financial status is paid up'];
                }
            }
        }
    }

    return ['allowed' => true, 'percent' => 100.0, 'total_due' => 0.0, 'total_paid' => 0.0, 'balance' => 0.0, 'reason' => 'No outstanding finance record found'];
}

function elearningNormalizeDateTimeInput(string $value): ?string
{
    $value = trim(str_replace('T', ' ', $value));
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) ? $value : null;
}

function elearningGetCourseStudentIds(mysqli $db, string $courseCode, ?int $courseOfferingId = null): array
{
    $students = [];

    if ($courseOfferingId !== null && $courseOfferingId > 0 && elearningOfferingStudentTablesReady($db)) {
        $sql = "SELECT DISTINCT sp.Sid AS student_id
                  FROM student_course_registrations scr
                  JOIN student_program sp ON sp.id = scr.student_programme_id
                  JOIN course_offerings co ON co.id = scr.course_offering_id
                  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                 WHERE scr.course_offering_id = ?
                   AND UPPER(TRIM(cc.course_code)) = UPPER(TRIM(?))
                   AND scr.registration_status IN ('REGISTERED','COMPLETED','REPEATING')
                   AND co.status IN ('planned','active','completed')";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('is', $courseOfferingId, $courseCode);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $sid = trim((string)($row['student_id'] ?? ''));
                if ($sid !== '') {
                    $students[$sid] = true;
                }
            }
            $stmt->close();
        }
        if (!empty($students)) {
            return array_keys($students);
        }
    }

    foreach (['course_registration', 'student_courses', 'registered_courses'] as $table) {
        if (!elearningTableExists($db, $table)) {
            continue;
        }
        $sidCol = elearningDetectColumn($db, $table, ['Sid', 'student_id', 'student']);
        $courseCol = elearningDetectColumn($db, $table, ['course_code', 'code']);
        if ($sidCol === null || $courseCol === null) {
            continue;
        }
        $activeCol = elearningDetectColumn($db, $table, ['is_active', 'status']);
        $activeSql = $activeCol === null ? '' : elearningActiveStatusSql($activeCol);
        $sql = "SELECT DISTINCT `{$sidCol}` AS student_id FROM `{$table}` WHERE UPPER(TRIM(`{$courseCol}`)) = UPPER(TRIM(?)){$activeSql}";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $courseCode);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $sid = trim((string)($row['student_id'] ?? ''));
                if ($sid !== '') {
                    $students[$sid] = true;
                }
            }
            $stmt->close();
        }
        if (!empty($students)) {
            break;
        }
    }
    return array_keys($students);
}

function elearningCreateStudentNotification(mysqli $db, string $studentId, string $courseCode, ?int $sessionId, string $type, string $title, string $body, string $url = 'elearning/live_sessions.php', ?int $courseOfferingId = null): bool
{
    elearningEnsureLiveSessionSchema($db);
    if (elearningTableHasCourseOffering($db, 'el_student_notifications')) {
        $stmt = $db->prepare("INSERT INTO el_student_notifications (course_offering_id, student_id, course_code, session_id, type, title, body, url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    } else {
        $stmt = $db->prepare("INSERT INTO el_student_notifications (student_id, course_code, session_id, type, title, body, url) VALUES (?, ?, ?, ?, ?, ?, ?)");
    }
    if (!$stmt) {
        return false;
    }
    $sid = $sessionId;
    if (elearningTableHasCourseOffering($db, 'el_student_notifications')) {
        $stmt->bind_param('ississss', $courseOfferingId, $studentId, $courseCode, $sid, $type, $title, $body, $url);
    } else {
        $stmt->bind_param('ssissss', $studentId, $courseCode, $sid, $type, $title, $body, $url);
    }
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        require_once __DIR__ . '/notification_integrations.php';
        $actionUrl = '/wucportal/students/elearning/' . ltrim($url, '/');
        wuc_notify_portal($db, [
            'user_id' => $studentId,
            'user_role' => 'student',
            'module' => 'elearning',
            'alert_type' => 'el_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($type)),
            'severity' => 'info',
            'title' => $title,
            'message' => $body,
            'entity_type' => 'el_student_notification',
            'action_url' => $actionUrl,
            'dedupe_days' => 1,
        ]);
    }
    return $ok;
}

function elearningNotifyCourseStudents(mysqli $db, string $courseCode, int $sessionId, string $type, string $title, string $body, ?int $courseOfferingId = null): int
{
    if (($courseOfferingId === null || $courseOfferingId <= 0)
        && $sessionId > 0
        && elearningTableHasCourseOffering($db, 'el_live_sessions')) {
        if ($stmt = $db->prepare("SELECT course_offering_id FROM el_live_sessions WHERE id = ? LIMIT 1")) {
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $candidate = (int)($row['course_offering_id'] ?? 0);
            if ($candidate > 0) {
                $courseOfferingId = $candidate;
            }
        }
    }

    $count = 0;
    foreach (elearningGetCourseStudentIds($db, $courseCode, $courseOfferingId) as $studentId) {
        if (elearningCreateStudentNotification($db, $studentId, $courseCode, $sessionId, $type, $title, $body, 'elearning/live_sessions.php', $courseOfferingId)) {
            $count++;
        }
    }
    return $count;
}

function elearningGenerateSecureSessionToken(mysqli $db, int $sessionId, string $studentId, int $ttlMinutes = 120): array
{
    elearningEnsureLiveSessionLinkTable($db);
    elearningPurgeExpiredSessionLinks($db);

    $stmt = $db->prepare("SELECT id, course_code, platform, join_url, end_time, status FROM el_live_sessions WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Unable to verify session.'];
    }
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exists) {
        return ['success' => false, 'error' => 'Live session was not found.'];
    }
    if (strtolower((string)($exists['status'] ?? 'scheduled')) === 'cancelled') {
        return ['success' => false, 'error' => 'This live session has been cancelled.'];
    }
    if (!empty($exists['end_time']) && strtotime((string)$exists['end_time']) < time()) {
        return ['success' => false, 'error' => 'This live session has ended.'];
    }
    if (!elearningAllowedMeetingHost((string)$exists['platform'], (string)$exists['join_url'])) {
        return ['success' => false, 'error' => 'Live session meeting URL is not valid.'];
    }
    // Only students enrolled in this session's course (i.e. assigned to the lecturer
    // who teaches it) may obtain a link. Validation re-checks this too, but enforce
    // it here so a non-enrolled student can never mint a token in the first place.
    if (!canStudentAccessElearningCourse($db, $studentId, (string)$exists['course_code'])) {
        return ['success' => false, 'error' => 'You are not assigned to this live session course.'];
    }
    $sessionOfferingId = (int)($exists['course_offering_id'] ?? 0);
    if ($sessionOfferingId > 0 && !in_array($sessionOfferingId, getStudentCourseOfferingIds($db, $studentId, (string)$exists['course_code']), true)) {
        return ['success' => false, 'error' => 'You are not assigned to this live session offering.'];
    }
    $paymentStatus = elearningStudentPaidUpStatus($db, $studentId, (string)$exists['course_code'], 100.0);
    if (empty($paymentStatus['allowed'])) {
        return ['success' => false, 'error' => 'Only paid-up students can access live session links. ' . ($paymentStatus['reason'] ?? '')];
    }

    if ($stmt = $db->prepare("UPDATE el_live_session_links SET revoked_at = NOW() WHERE session_id = ? AND student_id = ? AND revoked_at IS NULL AND expires_at > NOW()")) {
        $stmt->bind_param('is', $sessionId, $studentId);
        $stmt->execute();
        $stmt->close();
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + max(15, $ttlMinutes) * 60);
    $stmt = $db->prepare("INSERT INTO el_live_session_links (session_id, student_id, token_hash, expires_at) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Unable to prepare session link.'];
    }
    $stmt->bind_param('isss', $sessionId, $studentId, $hash, $expiresAt);
    $ok = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();

    if (!$ok) {
        return ['success' => false, 'error' => $error ?: 'Unable to create session link.'];
    }

    return ['success' => true, 'token' => $token, 'expires_at' => $expiresAt, 'reused' => false];
}

function elearningValidateSecureSessionToken(mysqli $db, string $token, string $studentId): array
{
    elearningEnsureLiveSessionLinkTable($db);
    elearningPurgeExpiredSessionLinks($db);
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return ['valid' => false, 'reason' => 'Invalid meeting link.', 'session' => null];
    }

    $hash = hash('sha256', $token);
    $sql = "SELECT l.id AS link_id, l.expires_at, s.*
            FROM el_live_session_links l
            INNER JOIN el_live_sessions s ON s.id = l.session_id
            WHERE l.token_hash = ?
              AND l.student_id = ?
              AND l.revoked_at IS NULL
            LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return ['valid' => false, 'reason' => 'Unable to verify meeting link.', 'session' => null];
    }
    $stmt->bind_param('ss', $hash, $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['valid' => false, 'reason' => 'Meeting link was not found.', 'session' => null];
    }
    if (strtolower((string)($row['status'] ?? 'scheduled')) === 'cancelled') {
        return ['valid' => false, 'reason' => 'This live session has been cancelled.', 'session' => $row];
    }
    if (!canStudentAccessElearningCourse($db, $studentId, (string)$row['course_code'])) {
        return ['valid' => false, 'reason' => 'You are not assigned to this live session course.', 'session' => $row];
    }
    $sessionOfferingId = (int)($row['course_offering_id'] ?? 0);
    if ($sessionOfferingId > 0 && !in_array($sessionOfferingId, getStudentCourseOfferingIds($db, $studentId, (string)$row['course_code']), true)) {
        return ['valid' => false, 'reason' => 'You are not assigned to this live session offering.', 'session' => $row];
    }
    $paymentStatus = elearningStudentPaidUpStatus($db, $studentId, (string)$row['course_code'], 100.0);
    if (empty($paymentStatus['allowed'])) {
        return ['valid' => false, 'reason' => 'Only paid-up students can access live session links. ' . ($paymentStatus['reason'] ?? ''), 'session' => $row];
    }
    if (strtotime((string)$row['expires_at']) <= time()) {
        return ['valid' => false, 'reason' => 'Meeting link has expired.', 'session' => $row];
    }
    if (!empty($row['end_time']) && strtotime((string)$row['end_time']) < time()) {
        return ['valid' => false, 'reason' => 'This live session has ended.', 'session' => $row];
    }
    if (!elearningAllowedMeetingHost((string)$row['platform'], (string)$row['join_url'])) {
        return ['valid' => false, 'reason' => 'Live session meeting URL is not valid.', 'session' => $row];
    }

    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    if ($stmt = $db->prepare("UPDATE el_live_session_links SET used_at = NOW(), last_ip = ?, last_user_agent = ? WHERE id = ?")) {
        $linkId = (int)$row['link_id'];
        $stmt->bind_param('ssi', $ip, $ua, $linkId);
        $stmt->execute();
        $stmt->close();
    }

    elearningRecordLiveSessionAttendance($db, (int)$row['id'], $studentId);

    return ['valid' => true, 'reason' => 'Access granted.', 'session' => $row];
}
