<?php
declare(strict_types=1);

/**
 * Bridges portal modules into the unified portal_alerts hub.
 *
 * External tables (el_student_notifications, lecturer_task_alerts, notifications)
 * are mirrored here on demand so notifications.php shows one consistent feed.
 */

require_once __DIR__ . '/portal_alerts.php';
require_once __DIR__ . '/schema_guard.php';

if (!function_exists('wuc_notify_portal')) {
    /**
     * Preferred entry point for modules creating an in-app notification.
     *
     * Keys: user_id, user_role, title, message.
     * Optional: alert_type, severity, module, entity_type, entity_id, action_url, dedupe_days.
     */
    function wuc_notify_portal(mysqli $db, array $payload): bool
    {
        $userId = trim((string)($payload['user_id'] ?? ''));
        $userRole = trim((string)($payload['user_role'] ?? ''));
        $title = trim((string)($payload['title'] ?? ''));
        $message = trim((string)($payload['message'] ?? ''));
        if ($userId === '' || $userRole === '' || $title === '' || $message === '') {
            return false;
        }

        $module = trim((string)($payload['module'] ?? ''));
        $alertType = trim((string)($payload['alert_type'] ?? ''));
        if ($alertType === '') {
            $alertType = $module !== '' ? preg_replace('/[^a-z0-9_]+/i', '_', strtolower($module)) : 'portal_event';
            $alertType = substr(trim($alertType, '_'), 0, 80) ?: 'portal_event';
        }

        $entityType = isset($payload['entity_type']) ? (string)$payload['entity_type'] : ($module !== '' ? $module : null);
        if ($entityType === '') {
            $entityType = null;
        }

        return wuc_portal_alert_create($db, [
            'user_id' => $userId,
            'user_role' => $userRole,
            'alert_type' => $alertType,
            'severity' => (string)($payload['severity'] ?? 'info'),
            'title' => $title,
            'message' => $message,
            'entity_type' => $entityType,
            'entity_id' => isset($payload['entity_id']) ? (string)$payload['entity_id'] : null,
            'action_url' => isset($payload['action_url']) ? (string)$payload['action_url'] : null,
            'dedupe_days' => isset($payload['dedupe_days']) ? (int)$payload['dedupe_days'] : 7,
        ]);
    }
}

if (!function_exists('wuc_notify_payment_confirmed')) {
    function wuc_notify_payment_confirmed(mysqli $db, string $studentId, float $amount, string $receiptNo, float $balance): void
    {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return;
        }

        $amountText = 'ZMW ' . number_format($amount, 2);
        $message = 'Payment of ' . $amountText . ' has been recorded';
        if ($receiptNo !== '') {
            $message .= ' (receipt ' . $receiptNo . ')';
        }
        $message .= '.';
        if ($balance > 0) {
            $message .= ' Outstanding balance: ZMW ' . number_format($balance, 2) . '.';
        } else {
            $message .= ' Your account is fully paid for this invoice.';
        }

        wuc_notify_portal($db, [
            'user_id' => $studentId,
            'user_role' => 'student',
            'module' => 'finance',
            'alert_type' => 'payment_confirmed',
            'severity' => $balance > 0 ? 'info' : 'info',
            'title' => 'Payment confirmed',
            'message' => $message,
            'entity_type' => 'payment',
            'entity_id' => $receiptNo !== '' ? $receiptNo : null,
            'action_url' => '/wucportal/students/fees.php',
            'dedupe_days' => 1,
        ]);

        wuc_notify_finance_staff_payment($db, $studentId, $amount, $receiptNo);
    }
}

if (!function_exists('wuc_notify_finance_staff_payment')) {
    function wuc_notify_finance_staff_payment(mysqli $db, string $studentId, float $amount, string $receiptNo): void
    {
        if (!wuc_table_exists($db, 'staff_positions')) {
            return;
        }

        $sql = "SELECT DISTINCT sp.staff_id
                FROM staff_positions sp
                INNER JOIN positions p ON p.id = sp.position_id
                WHERE LOWER(p.PosName) IN ('accountant', 'accounts', 'finance', 'finance officer', 'bursar', 'systems admin', 'system administrator', 'administrator')
                LIMIT 25";
        $res = $db->query($sql);
        if (!$res) {
            return;
        }

        $amountText = 'ZMW ' . number_format($amount, 2);
        while ($row = $res->fetch_assoc()) {
            $staffId = trim((string)($row['staff_id'] ?? ''));
            if ($staffId === '') {
                continue;
            }
            wuc_notify_portal($db, [
                'user_id' => $staffId,
                'user_role' => 'accountant',
                'module' => 'finance',
                'alert_type' => 'payment_received',
                'severity' => 'info',
                'title' => 'Student payment received',
                'message' => 'Student ' . $studentId . ' paid ' . $amountText . ($receiptNo !== '' ? ' (receipt ' . $receiptNo . ').' : '.'),
                'entity_type' => 'student',
                'entity_id' => $studentId,
                'action_url' => '/wucportal/accounts/fees_statement.php?student_id=' . rawurlencode($studentId),
                'dedupe_days' => 0,
            ]);
        }
        $res->free();
    }
}

if (!function_exists('wuc_notify_registration_submitted')) {
    function wuc_notify_registration_submitted(mysqli $db, string $studentId, string $periodLabel): void
    {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return;
        }

        wuc_notify_portal($db, [
            'user_id' => $studentId,
            'user_role' => 'student',
            'module' => 'registration',
            'alert_type' => 'registration_pending',
            'severity' => 'info',
            'title' => 'Registration submitted',
            'message' => 'Your ' . $periodLabel . ' registration has been submitted and is awaiting review.',
            'entity_type' => 'registration',
            'entity_id' => $studentId,
            'action_url' => '/wucportal/students/registration.php',
            'dedupe_days' => 3,
        ]);

        wuc_notify_registrar_staff($db, 'registration_pending', 'Registration pending approval', 'Student ' . $studentId . ' submitted ' . $periodLabel . ' registration for review.', '/wucportal/registrar/');
    }
}

if (!function_exists('wuc_notify_registration_decision')) {
    function wuc_notify_registration_decision(mysqli $db, string $studentId, bool $approved, string $periodLabel, string $note = ''): void
    {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return;
        }

        if ($approved) {
            wuc_notify_portal($db, [
                'user_id' => $studentId,
                'user_role' => 'student',
                'module' => 'registration',
                'alert_type' => 'registration_approved',
                'severity' => 'info',
                'title' => 'Registration approved',
                'message' => 'Your ' . $periodLabel . ' registration has been approved.' . ($note !== '' ? ' ' . $note : ''),
                'entity_type' => 'registration',
                'entity_id' => $studentId,
                'action_url' => '/wucportal/students/registration.php',
                'dedupe_days' => 7,
            ]);
            return;
        }

        wuc_notify_portal($db, [
            'user_id' => $studentId,
            'user_role' => 'student',
            'module' => 'registration',
            'alert_type' => 'registration_rejected',
            'severity' => 'warning',
            'title' => 'Registration requires attention',
            'message' => 'Your ' . $periodLabel . ' registration was not approved.' . ($note !== '' ? ' ' . $note : ' Please contact the registrar.'),
            'entity_type' => 'registration',
            'entity_id' => $studentId,
            'action_url' => '/wucportal/students/registration.php',
            'dedupe_days' => 3,
        ]);
    }
}

if (!function_exists('wuc_notify_course_assigned')) {
    function wuc_notify_course_assigned(mysqli $db, string $staffId, string $courseCode, string $assignedBy = ''): void
    {
        $staffId = trim($staffId);
        $courseCode = trim($courseCode);
        if ($staffId === '' || $courseCode === '') {
            return;
        }

        $message = 'You have been assigned to teach ' . $courseCode . '.';
        if ($assignedBy !== '') {
            $message .= ' Assigned by ' . $assignedBy . '.';
        }

        wuc_notify_portal($db, [
            'user_id' => $staffId,
            'user_role' => 'lecturer',
            'module' => 'academics',
            'alert_type' => 'course_assigned',
            'severity' => 'info',
            'title' => 'Course assignment',
            'message' => $message,
            'entity_type' => 'course',
            'entity_id' => $courseCode,
            'action_url' => '/wucportal/elearning/myCourses.php',
            'dedupe_days' => 30,
        ]);

        wuc_notify_hod_course_assigned($db, $staffId, $courseCode);
    }
}

if (!function_exists('wuc_notify_hod_course_assigned')) {
    function wuc_notify_hod_course_assigned(mysqli $db, string $staffId, string $courseCode): void
    {
        if (!wuc_table_exists($db, 'staff_positions')) {
            return;
        }

        $sql = "SELECT DISTINCT sp.staff_id
                FROM staff_positions sp
                INNER JOIN positions p ON p.id = sp.position_id
                WHERE LOWER(p.PosName) IN ('head of section', 'head of department', 'hod', 'hos', 'dean', 'systems admin', 'system administrator')
                LIMIT 15";
        $res = $db->query($sql);
        if (!$res) {
            return;
        }

        while ($row = $res->fetch_assoc()) {
            $hodId = trim((string)($row['staff_id'] ?? ''));
            if ($hodId === '' || $hodId === $staffId) {
                continue;
            }
            wuc_notify_portal($db, [
                'user_id' => $hodId,
                'user_role' => 'head_of_department',
                'module' => 'academics',
                'alert_type' => 'lecturer_course_assigned',
                'severity' => 'info',
                'title' => 'Lecturer course assignment',
                'message' => 'Lecturer ' . $staffId . ' was assigned to course ' . $courseCode . '.',
                'entity_type' => 'course',
                'entity_id' => $courseCode,
                'action_url' => '/wucportal/hod/courses.php',
                'dedupe_days' => 7,
            ]);
        }
        $res->free();
    }
}

if (!function_exists('wuc_notify_registrar_staff')) {
    function wuc_notify_registrar_staff(mysqli $db, string $alertType, string $title, string $message, string $actionUrl): void
    {
        if (!wuc_table_exists($db, 'staff_positions')) {
            return;
        }

        $sql = "SELECT DISTINCT sp.staff_id
                FROM staff_positions sp
                INNER JOIN positions p ON p.id = sp.position_id
                WHERE LOWER(p.PosName) IN ('registrar', 'admission officer', 'admissions officer', 'admissions', 'systems admin', 'system administrator')
                LIMIT 20";
        $res = $db->query($sql);
        if (!$res) {
            return;
        }

        while ($row = $res->fetch_assoc()) {
            $staffId = trim((string)($row['staff_id'] ?? ''));
            if ($staffId === '') {
                continue;
            }
            wuc_notify_portal($db, [
                'user_id' => $staffId,
                'user_role' => 'registrar',
                'module' => 'registrar',
                'alert_type' => $alertType,
                'severity' => 'info',
                'title' => $title,
                'message' => $message,
                'entity_type' => 'workflow',
                'action_url' => $actionUrl,
                'dedupe_days' => 1,
            ]);
        }
        $res->free();
    }
}

if (!function_exists('wuc_notify_student_document_available')) {
    function wuc_notify_student_document_available(mysqli $db, string $studentId, string $docType, string $title, string $message, string $actionUrl): void
    {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return;
        }

        wuc_portal_alert_upsert_current($db, [
            'user_id' => $studentId,
            'user_role' => 'student',
            'alert_type' => 'student_doc_' . $docType,
            'severity' => 'info',
            'title' => $title,
            'message' => $message,
            'entity_type' => 'student_document',
            'entity_id' => $docType,
            'action_url' => $actionUrl,
        ]);
    }
}

if (!function_exists('wuc_notify_material_published')) {
    function wuc_notify_material_published(mysqli $db, string $courseCode, string $title, string $materialId): void
    {
        $courseCode = trim($courseCode);
        if ($courseCode === '' || !wuc_table_exists($db, 'course_registration')) {
            return;
        }

        $stmt = $db->prepare(
            "SELECT DISTINCT student_id FROM course_registration
             WHERE course_code = ? AND COALESCE(registration_status, 'registered') IN ('registered', 'Registered', 'Active', 'active')
             LIMIT 200"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $res = $stmt->get_result();
        $body = $title !== '' ? $title : 'New learning material is available for ' . $courseCode . '.';
        while ($row = $res->fetch_assoc()) {
            $studentId = trim((string)($row['student_id'] ?? ''));
            if ($studentId === '') {
                continue;
            }
            wuc_notify_portal($db, [
                'user_id' => $studentId,
                'user_role' => 'student',
                'module' => 'elearning',
                'alert_type' => 'course_material',
                'severity' => 'info',
                'title' => 'Course material uploaded',
                'message' => $body,
                'entity_type' => 'repository_material',
                'entity_id' => $materialId,
                'action_url' => '/wucportal/students/elearning/index.php',
                'dedupe_days' => 3,
            ]);
        }
        $stmt->close();
    }
}

if (!function_exists('wuc_portal_alerts_sync_sources')) {
    /**
     * Mirror module-specific notification tables into portal_alerts for one user.
     */
    function wuc_portal_alerts_sync_sources(mysqli $db, string $userId, string $userRole): void
    {
        $userId = trim($userId);
        $userRole = trim($userRole);
        if ($userId === '' || $userRole === '' || !wuc_portal_alerts_ready($db)) {
            return;
        }

        if ($userRole === 'student') {
            wuc_portal_alerts_sync_el_student($db, $userId);
            wuc_portal_alerts_sync_announcements($db, $userId, 'student');
        }

        if (in_array($userRole, ['lecturer', 'head_of_department', 'dean', 'systems_admin', 'staff'], true)) {
            wuc_portal_alerts_sync_lecturer_tasks($db, $userId, $userRole);
        }

        wuc_portal_alerts_sync_enterprise_notifications($db, $userId, $userRole);
    }
}

if (!function_exists('wuc_portal_alerts_sync_el_student')) {
    function wuc_portal_alerts_sync_el_student(mysqli $db, string $studentId): void
    {
        if (!wuc_table_exists($db, 'el_student_notifications')) {
            return;
        }

        try {
            $stmt = $db->prepare(
                "SELECT id, course_code, type, title, body, url, is_read, created_at
                 FROM el_student_notifications
                 WHERE student_id = ?
                 ORDER BY created_at DESC
                 LIMIT 40"
            );
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $id = (int)($row['id'] ?? 0);
                $type = trim((string)($row['type'] ?? 'elearning'));
                $title = trim((string)($row['title'] ?? 'eLearning update'));
                $body = trim((string)($row['body'] ?? ''));
                $url = trim((string)($row['url'] ?? ''));
                if ($title === '' || $body === '') {
                    continue;
                }
                if ($url !== '' && $url[0] !== '/') {
                    $url = '/wucportal/students/elearning/' . ltrim($url, '/');
                }

                wuc_portal_alert_upsert_current($db, [
                    'user_id' => $studentId,
                    'user_role' => 'student',
                    'alert_type' => 'el_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($type)),
                    'severity' => 'info',
                    'title' => $title,
                    'message' => $body,
                    'entity_type' => 'el_student_notification',
                    'entity_id' => (string)$id,
                    'action_url' => $url !== '' ? $url : '/wucportal/students/elearning/index.php',
                ]);

                if ((int)($row['is_read'] ?? 0) === 1 && $id > 0) {
                    $mark = $db->prepare(
                        "UPDATE portal_alerts SET status = 'read', read_at = COALESCE(read_at, NOW())
                         WHERE user_id = ? AND user_role = 'student' AND entity_type = 'el_student_notification' AND entity_id = ? AND status = 'unread'"
                    );
                    if ($mark) {
                        $eid = (string)$id;
                        $mark->bind_param('ss', $studentId, $eid);
                        $mark->execute();
                        $mark->close();
                    }
                }
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('wuc_portal_alerts_sync_el_student failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wuc_portal_alerts_sync_lecturer_tasks')) {
    function wuc_portal_alerts_sync_lecturer_tasks(mysqli $db, string $staffId, string $userRole): void
    {
        if (!wuc_table_exists($db, 'lecturer_task_alerts')) {
            return;
        }

        try {
            $stmt = $db->prepare(
                "SELECT id, task_type, course_code, message, severity, status, created_at
                 FROM lecturer_task_alerts
                 WHERE staff_id = ? AND status = 'open'
                 ORDER BY created_at DESC
                 LIMIT 30"
            );
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $id = (int)($row['id'] ?? 0);
                $taskType = trim((string)($row['task_type'] ?? 'task'));
                $courseCode = trim((string)($row['course_code'] ?? ''));
                $message = trim((string)($row['message'] ?? ''));
                if ($message === '') {
                    continue;
                }

                $severity = (string)($row['severity'] ?? 'warning');
                if (!in_array($severity, ['info', 'warning', 'critical'], true)) {
                    $severity = 'warning';
                }

                $title = $taskType === 'missing_ca_upload' ? 'CA upload pending' : ucwords(str_replace('_', ' ', $taskType));
                if ($courseCode !== '') {
                    $title .= ' — ' . $courseCode;
                }

                wuc_portal_alert_upsert_current($db, [
                    'user_id' => $staffId,
                    'user_role' => $userRole,
                    'alert_type' => 'lecturer_' . $taskType,
                    'severity' => $severity,
                    'title' => $title,
                    'message' => $message,
                    'entity_type' => 'lecturer_task_alert',
                    'entity_id' => (string)$id,
                    'action_url' => '/wucportal/elearning/upload_ca.php',
                ]);
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('wuc_portal_alerts_sync_lecturer_tasks failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wuc_portal_alerts_sync_enterprise_notifications')) {
    function wuc_portal_alerts_sync_enterprise_notifications(mysqli $db, string $userId, string $userRole): void
    {
        if (!wuc_table_exists($db, 'notifications')) {
            return;
        }

        try {
            $sql = "SELECT id, module_name, title, message, status, metadata, created_at
                    FROM notifications
                    WHERE channel = 'internal'
                      AND status IN ('pending', 'sent', 'delivered')
                      AND (recipient_staff_id = ? OR recipient_student_id = ?)
                    ORDER BY created_at DESC
                    LIMIT 30";
            if (!$stmt = $db->prepare($sql)) {
                return;
            }
            $stmt->bind_param('ss', $userId, $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $id = (int)($row['id'] ?? 0);
                $module = trim((string)($row['module_name'] ?? 'system'));
                $title = trim((string)($row['title'] ?? 'Notification'));
                $message = trim((string)($row['message'] ?? ''));
                if ($title === '' || $message === '') {
                    continue;
                }

                $actionUrl = null;
                $metaRaw = (string)($row['metadata'] ?? '');
                if ($metaRaw !== '') {
                    $meta = json_decode($metaRaw, true);
                    if (is_array($meta) && !empty($meta['action_url'])) {
                        $actionUrl = (string)$meta['action_url'];
                    }
                }

                wuc_portal_alert_upsert_current($db, [
                    'user_id' => $userId,
                    'user_role' => $userRole,
                    'alert_type' => 'enterprise_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($module)),
                    'severity' => 'info',
                    'title' => $title,
                    'message' => $message,
                    'entity_type' => 'notifications',
                    'entity_id' => (string)$id,
                    'action_url' => $actionUrl,
                ]);
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('wuc_portal_alerts_sync_enterprise_notifications failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wuc_portal_alerts_sync_announcements')) {
    function wuc_portal_alerts_sync_announcements(mysqli $db, string $userId, string $userRole): void
    {
        if (!wuc_table_exists($db, 'announcement')) {
            return;
        }

        try {
            $res = $db->query("SELECT title, descript, created FROM announcement ORDER BY created DESC LIMIT 5");
            if (!$res) {
                return;
            }
            $idx = 0;
            while ($row = $res->fetch_assoc()) {
                $title = trim((string)($row['title'] ?? ''));
                $body = trim((string)($row['descript'] ?? ''));
                if ($title === '' || $body === '') {
                    continue;
                }
                $created = (string)($row['created'] ?? date('Y-m-d'));
                wuc_portal_alert_upsert_current($db, [
                    'user_id' => $userId,
                    'user_role' => $userRole,
                    'alert_type' => 'announcement',
                    'severity' => 'info',
                    'title' => $title,
                    'message' => $body,
                    'entity_type' => 'announcement',
                    'entity_id' => substr(sha1($title . $created), 0, 16),
                    'action_url' => $userRole === 'student' ? '/wucportal/students/index.php' : '/wucportal/portal_selection.php',
                ]);
                $idx++;
                if ($idx >= 3) {
                    break;
                }
            }
            $res->free();
        } catch (Throwable $e) {
            error_log('wuc_portal_alerts_sync_announcements failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wuc_portal_alerts_sync_student_documents')) {
    function wuc_portal_alerts_sync_student_documents(mysqli $db, string $studentId): void
    {
        require_once dirname(__DIR__) . '/students/includes/student_document_notifications.php';
        foreach (student_document_notification_items($db, $studentId) as $item) {
            $href = (string)($item['href'] ?? '');
            if ($href !== '' && $href[0] !== '/') {
                $href = '/wucportal/students/' . ltrim($href, '/');
            }
            $slug = strpos((string)($item['title'] ?? ''), 'Exam') !== false ? 'exam_slip' : 'test_docket';
            wuc_notify_student_document_available(
                $db,
                $studentId,
                $slug,
                (string)($item['title'] ?? 'Document available'),
                (string)($item['text'] ?? ''),
                $href
            );
        }
    }
}
