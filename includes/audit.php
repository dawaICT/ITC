<?php
if (!function_exists('audit_log')) {
    function audit_table_exists(mysqli $db, string $table): bool
    {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
            if (!$stmt) {
                $cache[$table] = false;
            } else {
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $cache[$table] = $stmt->get_result()->num_rows > 0;
                $stmt->close();
            }
        }
        return $cache[$table];
    }

    function audit_column_exists(mysqli $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (!array_key_exists($key, $cache)) {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
            if (!$stmt) {
                $cache[$key] = false;
            } else {
                $stmt->bind_param('ss', $table, $column);
                $stmt->execute();
                $cache[$key] = $stmt->get_result()->num_rows > 0;
                $stmt->close();
            }
        }
        return $cache[$key];
    }

    function audit_record_id_from_details($details): ?string
    {
        if (!is_array($details)) {
            return null;
        }
        foreach (['record_id', 'id', 'student_id', 'staff_id', 'course_offering_id', 'student_course_registration_id', 'role_id', 'permission_id'] as $key) {
            if (isset($details[$key]) && $details[$key] !== '') {
                return substr((string)$details[$key], 0, 80);
            }
        }
        return null;
    }

    function audit_log(mysqli $db, string $userId, string $action, $details = null): void
    {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $path = $_SERVER['REQUEST_URI'] ?? '';
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $payload = '';

        if (is_array($details)) {
            $payloadArray = array_merge($details, [
                'ip' => $ip,
                'method' => $method,
                'path' => $path,
                'user_agent' => $ua,
            ]);
            $payload = json_encode($payloadArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $context = 'ip=' . $ip . ',method=' . $method . ',path=' . $path . ',ua=' . $ua;
            $payload = (string)$details;
            if ($payload !== '') {
                $payload .= ' | ';
            }
            $payload .= $context;
        }

        try {
            if (audit_table_exists($db, 'audit_log') && ($stmt = $db->prepare("INSERT INTO audit_log (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())"))) {
                $stmt->bind_param('sss', $userId, $action, $payload);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
        }

        try {
            if (audit_table_exists($db, 'audit_logs')) {
                $module = 'system';
                if (strpos($action, '.') !== false) {
                    $module = substr($action, 0, strpos($action, '.')) ?: 'system';
                } elseif (strpos($action, '_') !== false) {
                    $module = substr($action, 0, strpos($action, '_')) ?: 'system';
                }
                $module = substr($module, 0, 80);
                $recordId = audit_record_id_from_details($details);
                $oldValue = null;
                $newValue = $payload;
                if (is_array($details)) {
                    if (array_key_exists('old_value', $details)) {
                        $oldValue = is_scalar($details['old_value'])
                            ? (string)$details['old_value']
                            : json_encode($details['old_value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    if (array_key_exists('new_value', $details)) {
                        $newValue = is_scalar($details['new_value'])
                            ? (string)$details['new_value']
                            : json_encode($details['new_value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }

                $columns = ['user_id', 'action', 'module', 'record_id', 'old_value', 'new_value', 'ip_address', 'user_agent'];
                $values = [$userId, $action, $module, $recordId, $oldValue, $newValue, $ip, $ua];
                $types = 'ssssssss';

                if (audit_column_exists($db, 'audit_logs', 'role_name')) {
                    $columns[] = 'role_name';
                    $values[] = (string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
                    $types .= 's';
                }
                if (audit_column_exists($db, 'audit_logs', 'session_id')) {
                    $columns[] = 'session_id';
                    $values[] = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
                    $types .= 's';
                }
                if (audit_column_exists($db, 'audit_logs', 'browser')) {
                    $columns[] = 'browser';
                    $values[] = substr($ua, 0, 120);
                    $types .= 's';
                }
                if (audit_column_exists($db, 'audit_logs', 'previous_hash') || audit_column_exists($db, 'audit_logs', 'event_hash')) {
                    $previousHash = null;
                    if (audit_column_exists($db, 'audit_logs', 'event_hash')) {
                        $hashRes = $db->query("SELECT event_hash FROM audit_logs WHERE event_hash IS NOT NULL ORDER BY id DESC LIMIT 1");
                        if ($hashRes && ($hashRow = $hashRes->fetch_assoc())) {
                            $previousHash = (string)$hashRow['event_hash'];
                        }
                        if ($hashRes) {
                            $hashRes->free();
                        }
                    }
                    $eventHash = hash('sha256', implode('|', [
                        $previousHash ?? '',
                        $userId,
                        $action,
                        $module,
                        $recordId ?? '',
                        $oldValue ?? '',
                        $newValue ?? '',
                        $ip,
                        $ua,
                        microtime(true),
                    ]));
                    if (audit_column_exists($db, 'audit_logs', 'previous_hash')) {
                        $columns[] = 'previous_hash';
                        $values[] = $previousHash;
                        $types .= 's';
                    }
                    if (audit_column_exists($db, 'audit_logs', 'event_hash')) {
                        $columns[] = 'event_hash';
                        $values[] = $eventHash;
                        $types .= 's';
                    }
                }

                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $columnSql = '`' . implode('`,`', $columns) . '`';
                $stmt = $db->prepare("INSERT INTO audit_logs ({$columnSql}) VALUES ({$placeholders})");
                if ($stmt) {
                    $stmt->bind_param($types, ...$values);
                    $stmt->execute();
                    $stmt->close();
                    return;
                }
            }
        } catch (Throwable $e) {
        }

        $dir = rtrim((string)(getenv('WUC_LOG_DIR') ?: dirname(__DIR__, 3) . '/wucportal-var/logs'), '/\\');
        if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
        $line = sprintf("[%s] user=%s action=%s details=%s\n", date('Y-m-d H:i:s'), $userId, $action, str_replace(["\r","\n"], ' ', is_string($details) ? $details : json_encode($details)));
        @file_put_contents($dir . '/audit.log', $line, FILE_APPEND);
    }

    function audit_resolve_actor(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        return [
            'actor_user_id' => (int)($_SESSION['user_id_db'] ?? 0),
            'actor_username' => trim((string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? '')),
            'actor_staff_number' => trim((string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '')),
            'actor_role' => trim((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? '')),
            'portal' => trim((string)($_SESSION['current_portal'] ?? '')),
        ];
    }

    function audit_log_current_user(mysqli $db, string $action, $details = null): void
    {
        $actor = audit_resolve_actor();
        $context = is_array($details) ? $details : ['details' => $details];
        $context = array_merge($actor, $context);

        $logUserId = $actor['actor_user_id'] > 0
            ? (string)$actor['actor_user_id']
            : ($actor['actor_staff_number'] !== '' ? $actor['actor_staff_number'] : 'guest');

        // #region agent log
        @file_put_contents('C:\\Users\\THIS PC\\debug-4a673c.log', json_encode(['sessionId'=>'4a673c','runId'=>'run1','hypothesisId'=>'A/C/D','location'=>'audit.php:208','message'=>'audit_log_current_user resolved actor & logUserId','data'=>['action'=>$action,'computed_logUserId'=>$logUserId,'actor_user_id'=>$actor['actor_user_id'],'actor_username'=>$actor['actor_username'],'actor_staff_number'=>$actor['actor_staff_number'],'session_user_id_db'=>$_SESSION['user_id_db']??null,'session_user_id'=>$_SESSION['user_id']??null,'session_staff_id'=>$_SESSION['staff_id']??null],'timestamp'=>round(microtime(true)*1000)], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
        // #endregion

        audit_log($db, $logUserId, $action, $context);
    }

    function audit_log_page_view(mysqli $db): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }

        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        audit_log_current_user($db, 'page.view', ['referer' => $ref]);
    }
}
