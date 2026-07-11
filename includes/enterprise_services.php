<?php
declare(strict_types=1);

require_once __DIR__ . '/audit.php';

if (!function_exists('wuc_ent_table_exists')) {
    function wuc_ent_table_exists(mysqli $db, string $table): bool
    {
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        if (!$stmt) {
            return $cache[$key] = false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $cache[$key] = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $cache[$key];
    }
}

if (!function_exists('wuc_ent_column_exists')) {
    function wuc_ent_column_exists(mysqli $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        if (!$stmt) {
            return $cache[$key] = false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $cache[$key] = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $cache[$key];
    }
}

if (!function_exists('wuc_enterprise_ready')) {
    function wuc_enterprise_ready(mysqli $db): bool
    {
        $requiredTables = [
            'business_rules',
            'academic_calendar_events',
            'notifications',
            'approval_workflows',
            'approval_requests',
            'approval_actions',
            'document_repository',
            'data_integrity_exceptions',
            'background_jobs',
            'academic_record_versions',
        ];
        foreach ($requiredTables as $table) {
            if (!wuc_ent_table_exists($db, $table)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('wuc_business_rule')) {
    function wuc_business_rule(mysqli $db, string $ruleKey, $default = null, string $module = 'system', ?string $scope = null)
    {
        if (!wuc_ent_table_exists($db, 'business_rules')) {
            return $default;
        }

        $today = date('Y-m-d');
        $lookupScope = $scope ?? 'default';
        $stmt = $db->prepare(
            "SELECT rule_value
               FROM business_rules
              WHERE rule_key = ?
                AND status = 'active'
                AND (module_name = ? OR module_name = 'system')
                AND (scope_key <=> ? OR scope_key = 'default' OR scope_key IS NULL)
                AND (effective_from IS NULL OR effective_from <= ?)
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY (scope_key <=> ?) DESC,
                       (scope_key = 'default') DESC,
                       (scope_key IS NULL) ASC,
                       (module_name = ?) DESC,
                       id DESC
              LIMIT 1"
        );
        if (!$stmt) {
            return $default;
        }
        $stmt->bind_param('sssssss', $ruleKey, $module, $lookupScope, $today, $today, $lookupScope, $module);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || $row['rule_value'] === null || $row['rule_value'] === '') {
            return $default;
        }

        $decoded = json_decode((string)$row['rule_value'], true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }
}

if (!function_exists('wuc_calendar_is_open')) {
    function wuc_calendar_is_open(mysqli $db, string $eventType, ?string $academicYear = null, ?DateTimeInterface $when = null): bool
    {
        if (!wuc_ent_table_exists($db, 'academic_calendar_events')) {
            return true;
        }

        $moment = ($when ?: new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $db->prepare(
            "SELECT 1
               FROM academic_calendar_events
              WHERE event_type = ?
                AND status = 'active'
                AND (? IS NULL OR academic_year = ? OR academic_year IS NULL)
                AND starts_at <= ?
                AND ends_at >= ?
              LIMIT 1"
        );
        if (!$stmt) {
            return true;
        }
        $stmt->bind_param('sssss', $eventType, $academicYear, $academicYear, $moment, $moment);
        $stmt->execute();
        $open = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $open;
    }
}

if (!function_exists('wuc_notify')) {
    function wuc_notify(mysqli $db, array $notification): ?int
    {
        if (!wuc_ent_table_exists($db, 'notifications')) {
            return null;
        }

        $recipientUserId = isset($notification['recipient_user_id']) ? (int)$notification['recipient_user_id'] : null;
        $studentId = isset($notification['student_id']) ? (string)$notification['student_id'] : null;
        $staffId = isset($notification['staff_id']) ? (string)$notification['staff_id'] : null;
        $channel = substr((string)($notification['channel'] ?? 'internal'), 0, 30);
        $module = substr((string)($notification['module'] ?? 'system'), 0, 80);
        $title = substr((string)($notification['title'] ?? 'Notification'), 0, 180);
        $message = (string)($notification['message'] ?? '');
        $status = substr((string)($notification['status'] ?? 'pending'), 0, 30);
        $scheduledAt = $notification['scheduled_at'] ?? null;
        $createdBy = (string)($notification['created_by'] ?? ($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? $_SESSION['Sid'] ?? 'system'));
        $metadata = json_encode($notification['metadata'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare(
            "INSERT INTO notifications
                (recipient_user_id, recipient_student_id, recipient_staff_id, channel, module_name, title, message, status, scheduled_at, created_by, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('issssssssss', $recipientUserId, $studentId, $staffId, $channel, $module, $title, $message, $status, $scheduledAt, $createdBy, $metadata);
        $stmt->execute();
        $id = (int)$db->insert_id;
        $stmt->close();

        audit_log_current_user($db, 'notification.created', ['notification_id' => $id, 'module' => $module, 'channel' => $channel]);

        if ($channel === 'internal' && in_array($status, ['pending', 'sent', 'delivered'], true)) {
            require_once __DIR__ . '/notification_integrations.php';
            $portalUserId = trim((string)($staffId ?? ''));
            $portalRole = 'staff';
            if ($portalUserId === '') {
                $portalUserId = trim((string)($studentId ?? ''));
                $portalRole = 'student';
            }
            if ($portalUserId !== '') {
                $meta = is_string($metadata) ? json_decode($metadata, true) : [];
                wuc_notify_portal($db, [
                    'user_id' => $portalUserId,
                    'user_role' => $portalRole,
                    'module' => $module,
                    'alert_type' => 'enterprise_' . $module,
                    'title' => $title,
                    'message' => $message,
                    'entity_type' => 'notifications',
                    'entity_id' => (string)$id,
                    'action_url' => is_array($meta) ? (string)($meta['action_url'] ?? '') : '',
                    'dedupe_days' => 0,
                ]);
            }
        }

        return $id;
    }
}

if (!function_exists('wuc_approval_request')) {
    function wuc_approval_request(mysqli $db, string $workflowKey, string $entityType, string $entityId, array $payload = []): ?int
    {
        if (!wuc_ent_table_exists($db, 'approval_workflows') || !wuc_ent_table_exists($db, 'approval_requests')) {
            return null;
        }
        $stmt = $db->prepare("SELECT id FROM approval_workflows WHERE workflow_key = ? AND status = 'active' LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $workflowKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        $workflowId = (int)$row['id'];
        $requestedBy = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? $_SESSION['Sid'] ?? 'system');
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare(
            "INSERT INTO approval_requests (workflow_id, entity_type, entity_id, requested_by, payload)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = NOW(), id = LAST_INSERT_ID(id)"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('issss', $workflowId, $entityType, $entityId, $requestedBy, $payloadJson);
        $stmt->execute();
        $id = (int)($db->insert_id ?: 0);
        $stmt->close();

        audit_log_current_user($db, 'approval.requested', ['workflow' => $workflowKey, 'entity_type' => $entityType, 'entity_id' => $entityId]);
        return $id ?: null;
    }
}

if (!function_exists('wuc_document_record')) {
    function wuc_document_record(mysqli $db, array $document): ?int
    {
        if (!wuc_ent_table_exists($db, 'document_repository')) {
            return null;
        }
        $ownerType = substr((string)($document['owner_type'] ?? ''), 0, 40);
        $ownerId = substr((string)($document['owner_id'] ?? ''), 0, 80);
        $documentType = substr((string)($document['document_type'] ?? ''), 0, 80);
        $filePath = (string)($document['file_path'] ?? '');
        if ($ownerType === '' || $ownerId === '' || $documentType === '' || $filePath === '') {
            return null;
        }

        $originalName = isset($document['original_name']) ? (string)$document['original_name'] : null;
        $mimeType = isset($document['mime_type']) ? (string)$document['mime_type'] : null;
        $fileSize = isset($document['file_size']) ? (int)$document['file_size'] : null;
        $checksum = isset($document['checksum_sha256']) ? (string)$document['checksum_sha256'] : null;
        $visibility = (string)($document['visibility'] ?? 'private');
        $uploadedBy = (string)($document['uploaded_by'] ?? ($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? $_SESSION['Sid'] ?? 'system'));
        $metadata = json_encode($document['metadata'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare(
            "INSERT INTO document_repository
                (owner_type, owner_id, document_type, original_name, file_path, mime_type, file_size, checksum_sha256, visibility, uploaded_by, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ssssssissss', $ownerType, $ownerId, $documentType, $originalName, $filePath, $mimeType, $fileSize, $checksum, $visibility, $uploadedBy, $metadata);
        $stmt->execute();
        $id = (int)$db->insert_id;
        $stmt->close();
        audit_log_current_user($db, 'document.recorded', ['document_id' => $id, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'document_type' => $documentType]);
        return $id;
    }
}

if (!function_exists('wuc_record_version')) {
    function wuc_record_version(mysqli $db, string $entityType, string $entityId, $oldValue, $newValue, string $reason = ''): ?int
    {
        if (!wuc_ent_table_exists($db, 'academic_record_versions')) {
            return null;
        }
        $stmt = $db->prepare("SELECT COALESCE(MAX(version_no), 0) + 1 AS next_version FROM academic_record_versions WHERE entity_type = ? AND entity_id = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $entityType, $entityId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $versionNo = (int)($row['next_version'] ?? 1);
        $oldJson = json_encode($oldValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $newJson = json_encode($newValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $changedBy = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'system');
        $stmt = $db->prepare(
            "INSERT INTO academic_record_versions
                (entity_type, entity_id, version_no, change_reason, old_value, new_value, changed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ssissss', $entityType, $entityId, $versionNo, $reason, $oldJson, $newJson, $changedBy);
        $stmt->execute();
        $id = (int)$db->insert_id;
        $stmt->close();
        audit_log_current_user($db, 'academic_record.versioned', ['version_id' => $id, 'entity_type' => $entityType, 'entity_id' => $entityId, 'version_no' => $versionNo]);
        return $id;
    }
}

if (!function_exists('wuc_integrity_record_exception')) {
    function wuc_integrity_record_exception(mysqli $db, string $key, string $module, string $severity, string $entityType, ?string $entityId, string $message, array $metadata = []): void
    {
        if (!wuc_ent_table_exists($db, 'data_integrity_exceptions')) {
            return;
        }
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare(
            "INSERT INTO data_integrity_exceptions
                (exception_key, module_name, severity, entity_type, entity_id, message, status, metadata, detected_at)
             VALUES (?, ?, ?, ?, ?, ?, 'open', ?, NOW())
             ON DUPLICATE KEY UPDATE message = VALUES(message), severity = VALUES(severity), metadata = VALUES(metadata), status = 'open', detected_at = NOW()"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('sssssss', $key, $module, $severity, $entityType, $entityId, $message, $metadataJson);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('wuc_integrity_run_basic')) {
    function wuc_integrity_run_basic(mysqli $db): array
    {
        $summary = [];
        if (!wuc_ent_table_exists($db, 'data_integrity_exceptions')) {
            return $summary;
        }

        if (wuc_ent_table_exists($db, 'students')) {
            foreach (['NRC', 'nrc', 'nrc_no', 'student_number', 'SID'] as $column) {
                if (!wuc_ent_column_exists($db, 'students', $column)) {
                    continue;
                }
                $sql = "SELECT `{$column}` AS value, COUNT(*) AS total FROM students WHERE `{$column}` IS NOT NULL AND TRIM(`{$column}`) <> '' GROUP BY `{$column}` HAVING total > 1 LIMIT 100";
                if ($res = $db->query($sql)) {
                    while ($row = $res->fetch_assoc()) {
                        $value = (string)$row['value'];
                        $key = 'students.duplicate.' . strtolower($column) . '.' . sha1($value);
                        wuc_integrity_record_exception($db, $key, 'students', 'high', 'student', $value, "Duplicate {$column} detected for {$value}.", ['count' => (int)$row['total']]);
                        $summary[] = $key;
                    }
                    $res->free();
                }
            }

            if (wuc_ent_column_exists($db, 'students', 'program') && wuc_ent_table_exists($db, 'programs') && wuc_ent_column_exists($db, 'programs', 'program_code')) {
                $sql = "SELECT s.SID, s.program FROM students s LEFT JOIN programs p ON p.program_code = s.program WHERE s.program IS NOT NULL AND TRIM(s.program) <> '' AND p.program_code IS NULL LIMIT 100";
                if ($res = $db->query($sql)) {
                    while ($row = $res->fetch_assoc()) {
                        $sid = (string)$row['SID'];
                        $key = 'students.missing_program.' . sha1($sid . ':' . (string)$row['program']);
                        wuc_integrity_record_exception($db, $key, 'students', 'high', 'student', $sid, "Student {$sid} references missing programme {$row['program']}.", ['program' => (string)$row['program']]);
                        $summary[] = $key;
                    }
                    $res->free();
                }
            }
        }

        if (wuc_ent_table_exists($db, 'course_lecturer') && wuc_ent_table_exists($db, 'courses')) {
            $sql = "SELECT DISTINCT cl.course_code FROM course_lecturer cl LEFT JOIN courses c ON c.course_code = cl.course_code WHERE cl.course_code IS NOT NULL AND c.course_code IS NULL LIMIT 100";
            if ($res = $db->query($sql)) {
                while ($row = $res->fetch_assoc()) {
                    $code = (string)$row['course_code'];
                    $key = 'course_lecturer.missing_course.' . sha1($code);
                    wuc_integrity_record_exception($db, $key, 'academics', 'high', 'course', $code, "Lecturer assignment references missing course {$code}.", []);
                    $summary[] = $key;
                }
                $res->free();
            }
        }

        audit_log_current_user($db, 'integrity.basic_scan', ['exceptions_detected' => count($summary)]);
        return $summary;
    }
}
