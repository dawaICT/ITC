<?php
declare(strict_types=1);

/**
 * Unified portal alert hub (zero-cost AI foundations).
 *
 * Rule-based dashboard alerts stored in `portal_alerts` — no SMS/email needed.
 * Every helper is schema-guarded and best-effort: a missing table or a DDL
 * mismatch degrades to "no alerts" instead of taking the page down.
 *
 * Companion audit trail: `ai_decision_logs` records every automated decision
 * (batch risk runs, alert creation) so rule outcomes stay explainable.
 */

require_once __DIR__ . '/schema_guard.php';

if (!function_exists('wuc_portal_alerts_ready')) {
    function wuc_portal_alerts_ready(mysqli $db): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = wuc_table_exists($db, 'portal_alerts');
        }
        return $ready;
    }
}

if (!function_exists('wuc_portal_alert_normalize_role')) {
    function wuc_portal_alert_normalize_role(string $role): string
    {
        $role = strtolower(trim($role));
        if ($role === '') {
            return 'staff';
        }
        if (!function_exists('wuc_normalize_staff_role')) {
            require_once __DIR__ . '/staff_role_helpers.php';
        }
        if (function_exists('wuc_normalize_staff_role')) {
            return wuc_normalize_staff_role($role, false);
        }
        return $role;
    }
}

if (!function_exists('wuc_portal_alert_portal_from_url')) {
    function wuc_portal_alert_portal_from_url(?string $url, string $fallback = 'academic'): string
    {
        $url = strtolower(trim((string)$url));
        $allowed = ['academic', 'elearning', 'applicant', 'alumni', 'employer', 'library', 'enterprise'];
        $fallback = in_array($fallback, $allowed, true) ? $fallback : 'academic';
        if (str_contains($url, '/enterprise/') || str_contains($url, '/opportunities/')) {
            return 'enterprise';
        }
        foreach (['elearning', 'employer', 'alumni', 'library'] as $portal) {
            if (str_contains($url, '/' . $portal . '/')) {
                return $portal;
            }
        }
        return str_contains($url, 'applicant_portal.php') ? 'applicant' : $fallback;
    }
}

if (!function_exists('wuc_portal_alert_normalize_portal')) {
    function wuc_portal_alert_normalize_portal(?string $portal, string $fallback = 'academic'): string
    {
        $allowed = ['academic', 'elearning', 'applicant', 'alumni', 'employer', 'library', 'enterprise'];
        $fallback = in_array($fallback, $allowed, true) ? $fallback : 'academic';
        $portal = strtolower(trim((string)$portal));
        return in_array($portal, $allowed, true) ? $portal : $fallback;
    }
}

if (!function_exists('wuc_portal_alert_action_url')) {
    function wuc_portal_alert_action_url(?string $url): ?string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }
        if (!str_starts_with($url, '/wucportal/') || preg_match('/[\r\n]/', $url)) {
            return null;
        }
        return substr($url, 0, 500);
    }
}

if (!function_exists('wuc_portal_alert_viewer_context')) {
    /**
     * Resolve the logged-in viewer for notification queries (session-aware).
     *
     * @return array{user_id:string,user_role:string,kind:string,dashboard_url:string,display_name:string}|null
     */
    function wuc_portal_alert_viewer_context(mysqli $db): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            return null;
        }

        $studentId = trim((string)($_SESSION['Sid'] ?? ''));
        if ($studentId !== '' && preg_match('/^[A-Za-z0-9\/\-_]+$/', $studentId)) {
            return [
                'user_id' => $studentId,
                'user_role' => 'student',
                'kind' => 'student',
                'dashboard_url' => '/wucportal/students/index.php',
                'display_name' => trim((string)($_SESSION['user_name'] ?? $studentId)),
            ];
        }

        $staffId = trim((string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? ''));
        if ($staffId === '' || !preg_match('/^[A-Za-z0-9\/\-_]+$/', $staffId)) {
            return null;
        }

        if (!function_exists('wuc_hydrate_staff_roles')) {
            require_once __DIR__ . '/staff_role_helpers.php';
        }
        if (function_exists('wuc_hydrate_staff_roles')) {
            wuc_hydrate_staff_roles($db, $staffId);
        }

        $role = wuc_portal_alert_normalize_role((string)($_SESSION['role'] ?? 'staff'));
        if (!function_exists('wuc_staff_landing_url')) {
            require_once __DIR__ . '/helpers/redirect_helper.php';
        }
        $dashboardUrl = function_exists('wuc_staff_landing_url') ? wuc_staff_landing_url($role) : '/wucportal/portal_selection.php';

        $displayName = trim((string)($_SESSION['user_name'] ?? ''));
        if ($displayName === '' && ($stmt = $db->prepare('SELECT Fname, Lname, title FROM staff WHERE staff_id = ? LIMIT 1'))) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $displayName = trim((string)($row['title'] ?? '') . ' ' . (string)($row['Fname'] ?? '') . ' ' . (string)($row['Lname'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = $staffId;
        }

        return [
            'user_id' => $staffId,
            'user_role' => $role,
            'kind' => 'staff',
            'dashboard_url' => $dashboardUrl,
            'display_name' => $displayName,
        ];
    }
}

if (!function_exists('wuc_portal_alert_role_sql')) {
    /**
     * Role scope for SELECT/UPDATE: exact role plus shared broadcast rows.
     */
    function wuc_portal_alert_role_sql(string $userRole, string &$types, array &$params): string
    {
        $userRole = wuc_portal_alert_normalize_role($userRole);
        $types .= 'sss';
        $params[] = $userRole;
        $params[] = 'all';
        $params[] = 'broadcast';
        return 'user_role IN (?, ?, ?)';
    }
}

if (!function_exists('wuc_portal_alert_get_owned')) {
    /** Fetch one alert owned by user + role scope. */
    function wuc_portal_alert_get_owned(mysqli $db, string $userId, string $userRole, int $alertId): ?array
    {
        $userId = trim($userId);
        if ($userId === '' || $alertId <= 0 || !wuc_portal_alerts_ready($db)) {
            return null;
        }

        $types = 'si';
        $params = [$userId, $alertId];
        $roleSql = wuc_portal_alert_role_sql($userRole, $types, $params);

        try {
            $sql = "SELECT id, alert_type, severity, title, message, entity_type, entity_id, action_url,
                           source_portal, target_portal, target_page, expires_at, status, created_at
                    FROM portal_alerts
                    WHERE user_id = ? AND id = ? AND {$roleSql}
                      AND (expires_at IS NULL OR expires_at > NOW())
                    LIMIT 1";
            if (!$stmt = $db->prepare($sql)) {
                return null;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            return $row;
        } catch (Throwable $e) {
            error_log('wuc_portal_alert_get_owned failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wuc_portal_alert_create')) {
    /**
     * Insert an alert unless an equivalent one is already pending for the user.
     *
     * Required keys: user_id, user_role, alert_type, title, message.
     * Optional: severity (info|warning|critical), entity_type, entity_id,
     * action_url, dedupe_days (default 7; 0 disables deduplication).
     */
    function wuc_portal_alert_create(mysqli $db, array $alert): bool
    {
        if (!wuc_portal_alerts_ready($db)) {
            return false;
        }

        $userId = trim((string)($alert['user_id'] ?? ''));
        $userRole = trim((string)($alert['user_role'] ?? ''));
        $type = trim((string)($alert['alert_type'] ?? ''));
        $title = trim((string)($alert['title'] ?? ''));
        $message = trim((string)($alert['message'] ?? ''));
        if ($userId === '' || $userRole === '' || $type === '' || $title === '' || $message === '') {
            return false;
        }

        $severity = (string)($alert['severity'] ?? 'info');
        if (!in_array($severity, ['info', 'warning', 'critical'], true)) {
            $severity = 'info';
        }
        $entityType = isset($alert['entity_type']) && $alert['entity_type'] !== '' ? (string)$alert['entity_type'] : null;
        $entityId = isset($alert['entity_id']) && $alert['entity_id'] !== '' ? (string)$alert['entity_id'] : null;
        $actionUrl = wuc_portal_alert_action_url(isset($alert['action_url']) ? (string)$alert['action_url'] : null);
        $sourcePortal = wuc_portal_alert_normalize_portal(isset($alert['source_portal']) ? (string)$alert['source_portal'] : null);
        $targetPortal = wuc_portal_alert_portal_from_url($actionUrl, strtolower(trim((string)($alert['target_portal'] ?? $sourcePortal))));
        $targetPage = $actionUrl;
        $expiresAt = isset($alert['expires_at']) && trim((string)$alert['expires_at']) !== '' ? trim((string)$alert['expires_at']) : null;
        $dedupeDays = isset($alert['dedupe_days']) ? max(0, (int)$alert['dedupe_days']) : 7;

        try {
            if ($dedupeDays > 0) {
                $sql = "SELECT COUNT(*) AS total FROM portal_alerts
                        WHERE user_id = ? AND alert_type = ?
                          AND COALESCE(entity_id, '') = COALESCE(?, '')
                          AND status IN ('unread', 'read')
                          AND (expires_at IS NULL OR expires_at > NOW())
                          AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
                if ($stmt = $db->prepare($sql)) {
                    $stmt->bind_param('sssi', $userId, $type, $entityId, $dedupeDays);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc() ?: [];
                    $stmt->close();
                    if ((int)($row['total'] ?? 0) > 0) {
                        return false;
                    }
                }
            }

            $stmt = $db->prepare(
                'INSERT INTO portal_alerts
                 (user_id, user_role, source_portal, target_portal, alert_type, severity, title, message,
                  entity_type, entity_id, action_url, target_page, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sssssssssssss', $userId, $userRole, $sourcePortal, $targetPortal, $type, $severity, $title, $message, $entityType, $entityId, $actionUrl, $targetPage, $expiresAt);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } catch (Throwable $e) {
            error_log('wuc_portal_alert_create failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wuc_portal_alert_upsert_current')) {
    /**
     * Keep one current alert per user/type/entity fresh.
     *
     * Required keys: user_id, user_role, alert_type, title, message.
     * Optional: severity, entity_type, entity_id, action_url.
     */
    function wuc_portal_alert_upsert_current(mysqli $db, array $alert): bool
    {
        if (!wuc_portal_alerts_ready($db)) {
            return false;
        }

        $userId = trim((string)($alert['user_id'] ?? ''));
        $userRole = trim((string)($alert['user_role'] ?? ''));
        $type = trim((string)($alert['alert_type'] ?? ''));
        $title = trim((string)($alert['title'] ?? ''));
        $message = trim((string)($alert['message'] ?? ''));
        if ($userId === '' || $userRole === '' || $type === '' || $title === '' || $message === '') {
            return false;
        }

        $severity = (string)($alert['severity'] ?? 'info');
        if (!in_array($severity, ['info', 'warning', 'critical'], true)) {
            $severity = 'info';
        }
        $entityType = isset($alert['entity_type']) && $alert['entity_type'] !== '' ? (string)$alert['entity_type'] : null;
        $entityId = isset($alert['entity_id']) && $alert['entity_id'] !== '' ? (string)$alert['entity_id'] : null;
        $actionUrl = wuc_portal_alert_action_url(isset($alert['action_url']) ? (string)$alert['action_url'] : null);
        $sourcePortal = wuc_portal_alert_normalize_portal(isset($alert['source_portal']) ? (string)$alert['source_portal'] : null);
        $targetPortal = wuc_portal_alert_portal_from_url($actionUrl, strtolower(trim((string)($alert['target_portal'] ?? $sourcePortal))));
        $targetPage = $actionUrl;
        $expiresAt = isset($alert['expires_at']) && trim((string)$alert['expires_at']) !== '' ? trim((string)$alert['expires_at']) : null;

        try {
            $existingId = 0;
            $sql = "SELECT id FROM portal_alerts
                    WHERE user_id = ? AND alert_type = ?
                      AND COALESCE(entity_id, '') = COALESCE(?, '')
                      AND status IN ('unread', 'read')
                      AND (expires_at IS NULL OR expires_at > NOW())
                    ORDER BY id DESC
                    LIMIT 1";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('sss', $userId, $type, $entityId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $existingId = (int)($row['id'] ?? 0);
                $stmt->close();
            }

            if ($existingId > 0) {
                $stmt = $db->prepare(
                    "UPDATE portal_alerts
                     SET user_role = ?, source_portal = ?, target_portal = ?, severity = ?, title = ?, message = ?, entity_type = ?, action_url = ?, target_page = ?, expires_at = ?,
                         status = IF(status = 'dismissed', 'unread', status)
                     WHERE id = ? AND user_id = ?"
                );
                if (!$stmt) {
                    return false;
                }
                $updateTypes = str_repeat('s', 10) . 'is';
                $stmt->bind_param($updateTypes, $userRole, $sourcePortal, $targetPortal, $severity, $title, $message, $entityType, $actionUrl, $targetPage, $expiresAt, $existingId, $userId);
                $ok = $stmt->execute();
                $stmt->close();
                return $ok;
            }

            $stmt = $db->prepare(
                'INSERT INTO portal_alerts
                 (user_id, user_role, source_portal, target_portal, alert_type, severity, title, message,
                  entity_type, entity_id, action_url, target_page, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sssssssssssss', $userId, $userRole, $sourcePortal, $targetPortal, $type, $severity, $title, $message, $entityType, $entityId, $actionUrl, $targetPage, $expiresAt);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } catch (Throwable $e) {
            error_log('wuc_portal_alert_upsert_current failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wuc_portal_alerts_for_user')) {
    /** Latest alerts for one user. $unreadOnly=false also includes read (not dismissed). */
    function wuc_portal_alerts_for_user(mysqli $db, string $userId, int $limit = 8, bool $unreadOnly = true, string $userRole = ''): array
    {
        $userId = trim($userId);
        if ($userId === '' || !wuc_portal_alerts_ready($db)) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $statusFilter = $unreadOnly ? "status = 'unread'" : "status IN ('unread', 'read')";
        $types = 's';
        $params = [$userId];
        $roleSql = '';
        if (trim($userRole) !== '') {
            $roleSql = ' AND ' . wuc_portal_alert_role_sql($userRole, $types, $params);
        }

        try {
            $sql = "SELECT id, alert_type, severity, title, message, entity_type, entity_id, action_url,
                           source_portal, target_portal, target_page, expires_at, status, created_at
                    FROM portal_alerts
                    WHERE user_id = ? AND {$statusFilter}{$roleSql}
                      AND (expires_at IS NULL OR expires_at > NOW())
                    ORDER BY FIELD(severity, 'critical', 'warning', 'info'), created_at DESC
                    LIMIT {$limit}";
            if (!$stmt = $db->prepare($sql)) {
                return [];
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $alerts = [];
            while ($row = $res->fetch_assoc()) {
                $alerts[] = $row;
            }
            $stmt->close();
            return $alerts;
        } catch (Throwable $e) {
            error_log('wuc_portal_alerts_for_user failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('wuc_portal_alerts_unread_count')) {
    function wuc_portal_alerts_unread_count(mysqli $db, string $userId, string $userRole = ''): int
    {
        $userId = trim($userId);
        if ($userId === '' || !wuc_portal_alerts_ready($db)) {
            return 0;
        }
        try {
            $types = 's';
            $params = [$userId];
            $roleSql = '';
            if (trim($userRole) !== '') {
                $roleSql = ' AND ' . wuc_portal_alert_role_sql($userRole, $types, $params);
            }
            $sql = "SELECT COUNT(*) AS total FROM portal_alerts WHERE user_id = ? AND status = 'unread'{$roleSql} AND (expires_at IS NULL OR expires_at > NOW())";
            if (!$stmt = $db->prepare($sql)) {
                return 0;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            return (int)($row['total'] ?? 0);
        } catch (Throwable $e) {
            error_log('wuc_portal_alerts_unread_count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('wuc_portal_alert_set_status')) {
    /** Owner-scoped status transition (read|dismissed). */
    function wuc_portal_alert_set_status(mysqli $db, string $userId, int $alertId, string $status, string $userRole = ''): bool
    {
        $userId = trim($userId);
        if ($userId === '' || $alertId <= 0 || !in_array($status, ['read', 'dismissed'], true) || !wuc_portal_alerts_ready($db)) {
            return false;
        }
        try {
            $types = 'sis';
            $params = [$status, $alertId, $userId];
            $roleSql = '';
            if (trim($userRole) !== '') {
                $roleSql = ' AND ' . wuc_portal_alert_role_sql($userRole, $types, $params);
            }
            $sql = 'UPDATE portal_alerts SET status = ?, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?' . $roleSql;
            if (!$stmt = $db->prepare($sql)) {
                return false;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
            return $changed;
        } catch (Throwable $e) {
            error_log('wuc_portal_alert_set_status failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wuc_portal_alerts_mark_all_read')) {
    function wuc_portal_alerts_mark_all_read(mysqli $db, string $userId, string $userRole = ''): int
    {
        $userId = trim($userId);
        if ($userId === '' || !wuc_portal_alerts_ready($db)) {
            return 0;
        }
        try {
            $types = 's';
            $params = [$userId];
            $roleSql = '';
            if (trim($userRole) !== '') {
                $roleSql = ' AND ' . wuc_portal_alert_role_sql($userRole, $types, $params);
            }
            $sql = "UPDATE portal_alerts SET status = 'read', read_at = COALESCE(read_at, NOW()) WHERE user_id = ? AND status = 'unread'{$roleSql} AND (expires_at IS NULL OR expires_at > NOW())";
            if (!$stmt = $db->prepare($sql)) {
                return 0;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $count = $stmt->affected_rows;
            $stmt->close();
            return $count;
        } catch (Throwable $e) {
            error_log('wuc_portal_alerts_mark_all_read failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('wuc_portal_alerts_for_center')) {
    /**
     * List alerts for the full notifications center.
     *
     * @param array<string,string> $filters status|severity|type
     * @return array<int,array<string,mixed>>
     */
    function wuc_portal_alerts_for_center(mysqli $db, string $userId, array $filters = [], int $limit = 100, string $userRole = ''): array
    {
        $userId = trim($userId);
        if ($userId === '' || !wuc_portal_alerts_ready($db)) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $where = ['user_id = ?'];
        $where[] = '(expires_at IS NULL OR expires_at > NOW())';
        $types = 's';
        $params = [$userId];
        if (trim($userRole) !== '') {
            $where[] = wuc_portal_alert_role_sql($userRole, $types, $params);
        }

        $status = (string)($filters['status'] ?? 'active');
        if ($status === 'unread') {
            $where[] = "status = 'unread'";
        } elseif ($status === 'read') {
            $where[] = "status = 'read'";
        } elseif ($status === 'dismissed') {
            $where[] = "status = 'dismissed'";
        } elseif ($status !== 'all') {
            $where[] = "status IN ('unread', 'read')";
        }

        $severity = (string)($filters['severity'] ?? '');
        if (in_array($severity, ['info', 'warning', 'critical'], true)) {
            $where[] = 'severity = ?';
            $types .= 's';
            $params[] = $severity;
        }

        $type = trim((string)($filters['type'] ?? ''));
        if ($type !== '' && preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $type)) {
            $where[] = 'alert_type = ?';
            $types .= 's';
            $params[] = $type;
        }

        try {
            $sql = "SELECT id, alert_type, severity, title, message, entity_type, entity_id, action_url,
                           source_portal, target_portal, target_page, expires_at, status, created_at, read_at
                    FROM portal_alerts
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY FIELD(status, 'unread', 'read', 'dismissed'),
                             FIELD(severity, 'critical', 'warning', 'info'),
                             created_at DESC
                    LIMIT {$limit}";
            if (!$stmt = $db->prepare($sql)) {
                return [];
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $alerts = [];
            while ($row = $res->fetch_assoc()) {
                $alerts[] = $row;
            }
            $stmt->close();
            return $alerts;
        } catch (Throwable $e) {
            error_log('wuc_portal_alerts_for_center failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('wuc_portal_alerts_counts')) {
    /**
     * Severity counts (critical/warning/info) and `active` cover only live
     * alerts (unread + read) so the summary matches what the center lists;
     * `total` includes dismissed rows as well.
     *
     * @return array<string,int>
     */
    function wuc_portal_alerts_counts(mysqli $db, string $userId, string $userRole = ''): array
    {
        $counts = [
            'total' => 0,
            'active' => 0,
            'unread' => 0,
            'read' => 0,
            'dismissed' => 0,
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
        ];

        $userId = trim($userId);
        if ($userId === '' || !wuc_portal_alerts_ready($db)) {
            return $counts;
        }

        try {
            $types = 's';
            $params = [$userId];
            $roleSql = '';
            if (trim($userRole) !== '') {
                $roleSql = ' AND ' . wuc_portal_alert_role_sql($userRole, $types, $params);
            }
            $sql = 'SELECT status, severity, COUNT(*) AS total FROM portal_alerts WHERE user_id = ?' . $roleSql . ' AND (expires_at IS NULL OR expires_at > NOW()) GROUP BY status, severity';
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $total = (int)($row['total'] ?? 0);
                    $status = (string)($row['status'] ?? '');
                    $severity = (string)($row['severity'] ?? '');
                    $counts['total'] += $total;
                    if (array_key_exists($status, $counts)) {
                        $counts[$status] += $total;
                    }
                    if ($status !== 'dismissed') {
                        $counts['active'] += $total;
                        if (array_key_exists($severity, $counts)) {
                            $counts[$severity] += $total;
                        }
                    }
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('wuc_portal_alerts_counts failed: ' . $e->getMessage());
        }

        return $counts;
    }
}

if (!function_exists('wuc_ai_decision_log')) {
    /**
     * Best-effort audit entry in ai_decision_logs.
     * Keys: feature, decision_type, outcome (required); entity_type, entity_id,
     * input_summary, reasons (array — stored as JSON), user_id (optional).
     */
    function wuc_ai_decision_log(mysqli $db, array $entry): void
    {
        if (!wuc_table_exists($db, 'ai_decision_logs')) {
            return;
        }
        $feature = trim((string)($entry['feature'] ?? ''));
        $decisionType = trim((string)($entry['decision_type'] ?? ''));
        $outcome = trim((string)($entry['outcome'] ?? ''));
        if ($feature === '' || $decisionType === '' || $outcome === '') {
            return;
        }
        $entityType = isset($entry['entity_type']) && $entry['entity_type'] !== '' ? (string)$entry['entity_type'] : null;
        $entityId = isset($entry['entity_id']) && $entry['entity_id'] !== '' ? (string)$entry['entity_id'] : null;
        $inputSummary = isset($entry['input_summary']) ? substr((string)$entry['input_summary'], 0, 500) : null;
        $reasonsJson = null;
        if (isset($entry['reasons']) && is_array($entry['reasons'])) {
            $reasonsJson = json_encode(array_values($entry['reasons']), JSON_UNESCAPED_UNICODE) ?: null;
        }
        $userId = isset($entry['user_id']) && $entry['user_id'] !== '' ? (string)$entry['user_id'] : null;

        try {
            $stmt = $db->prepare(
                'INSERT INTO ai_decision_logs
                 (feature, decision_type, entity_type, entity_id, input_summary, outcome, reasons_json, user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt) {
                $stmt->bind_param('ssssssss', $feature, $decisionType, $entityType, $entityId, $inputSummary, $outcome, $reasonsJson, $userId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('wuc_ai_decision_log failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wuc_portal_alert_severity_class')) {
    function wuc_portal_alert_severity_class(string $severity): string
    {
        if ($severity === 'critical') {
            return 'danger';
        }
        if ($severity === 'warning') {
            return 'warning';
        }
        return 'info';
    }
}

if (!function_exists('wuc_render_portal_alerts_stack')) {
    /**
     * Render the dashboard alert stack for the logged-in user. Returns '' when
     * there is nothing unread, so callers can echo unconditionally.
     */
    function wuc_render_portal_alerts_stack(mysqli $db, string $userId, string $userRole, array $opts = []): string
    {
        if (!empty($opts['sync_sources']) && function_exists('wuc_portal_alerts_sync_sources')) {
            require_once __DIR__ . '/notification_integrations.php';
            wuc_portal_alerts_sync_sources($db, $userId, wuc_portal_alert_normalize_role($userRole));
        }
        $alerts = wuc_portal_alerts_for_user($db, $userId, (int)($opts['limit'] ?? 6), true, wuc_portal_alert_normalize_role($userRole));
        if (!$alerts) {
            return '';
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
            }
        }
        $csrf = (string)$_SESSION['csrf_token'];
        $endpoint = '/wucportal/api/portal_alerts.php';

        ob_start();
        ?>
        <div class="card border-0 shadow-sm mb-4 wuc-portal-alerts" data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 text-primary"><i class="fas fa-bell me-2"></i>Portal Alerts
                    <span class="badge bg-danger ms-1"><?= htmlspecialchars((string)count($alerts)) ?></span>
                </h6>
                <button type="button" class="btn btn-sm btn-outline-secondary wuc-alerts-mark-all">Mark all read</button>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($alerts as $alert): ?>
                    <?php $cls = wuc_portal_alert_severity_class((string)$alert['severity']); ?>
                    <div class="list-group-item d-flex align-items-start gap-2 py-2" data-alert-id="<?= (int)$alert['id'] ?>">
                        <span class="badge bg-<?= htmlspecialchars($cls) ?> mt-1"><?= htmlspecialchars(ucfirst((string)$alert['severity'])) ?></span>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?= htmlspecialchars((string)$alert['title']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars((string)$alert['message']) ?></div>
                            <?php if (!empty($alert['action_url'])): ?>
                                <a class="small" href="<?= htmlspecialchars((string)$alert['action_url'], ENT_QUOTES, 'UTF-8') ?>">Open</a>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn-close mt-1 wuc-alert-dismiss" aria-label="Dismiss alert"></button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (empty($GLOBALS['wuc_portal_alerts_js_emitted'])): $GLOBALS['wuc_portal_alerts_js_emitted'] = true; ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.wuc-portal-alerts').forEach(function (box) {
                var endpoint = box.getAttribute('data-endpoint');
                var csrf = box.getAttribute('data-csrf');
                function post(action, alertId) {
                    var body = new URLSearchParams({action: action, csrf_token: csrf});
                    if (alertId) { body.set('alert_id', alertId); }
                    return fetch(endpoint, {method: 'POST', headers: {'X-CSRF-Token': csrf}, body: body, credentials: 'same-origin'});
                }
                box.querySelectorAll('.wuc-alert-dismiss').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var item = btn.closest('[data-alert-id]');
                        if (!item) { return; }
                        post('dismiss', item.getAttribute('data-alert-id')).then(function () {
                            item.remove();
                            if (!box.querySelector('[data-alert-id]')) { box.remove(); }
                        });
                    });
                });
                var markAll = box.querySelector('.wuc-alerts-mark-all');
                if (markAll) {
                    markAll.addEventListener('click', function () {
                        post('mark_all_read').then(function () { box.remove(); });
                    });
                }
            });
        });
        </script>
        <?php endif; ?>
        <?php
        return (string)ob_get_clean();
    }
}
