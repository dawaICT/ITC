<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema_guard.php';
require_once dirname(__DIR__) . '/audit.php';
require_once dirname(__DIR__) . '/notification_integrations.php';

if (!function_exists('tp_h')) {
    function tp_h(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('tp_actor_id')) {
    function tp_actor_id(): string
    {
        return trim((string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? ''));
    }
}

if (!function_exists('tp_csrf_token')) {
    function tp_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }
}

if (!function_exists('tp_require_csrf')) {
    function tp_require_csrf(): void
    {
        $token = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
            http_response_code(403);
            throw new RuntimeException('Your session token is invalid. Refresh the page and try again.');
        }
    }
}

if (!function_exists('tp_storage_root')) {
    function tp_storage_root(): string
    {
        $configured = function_exists('wuc_portal_env') ? trim((string)wuc_portal_env('WUC_TEACHING_PLANNER_STORAGE', '')) : '';
        $root = $configured !== '' ? $configured : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'wucportal-var' . DIRECTORY_SEPARATOR . 'teaching-planner';
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
            throw new RuntimeException('Teaching Planner secure storage is not writable.');
        }
        $resolved = realpath($root);
        if ($resolved === false) {
            throw new RuntimeException('Teaching Planner secure storage could not be resolved.');
        }
        return $resolved;
    }
}

if (!function_exists('tp_safe_storage_path')) {
    function tp_safe_storage_path(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || preg_match('#(^|[\\/])\.\.([\\/]|$)#', $relativePath)) {
            throw new RuntimeException('Invalid stored file reference.');
        }
        $root = tp_storage_root();
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
        $parent = realpath(dirname($candidate));
        if ($parent === false || !str_starts_with(strtolower($parent), strtolower($root))) {
            throw new RuntimeException('Stored file is outside the protected storage area.');
        }
        return $candidate;
    }
}

if (!function_exists('tp_json')) {
    function tp_json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

if (!function_exists('tp_decode_json')) {
    function tp_decode_json(?string $value, array $default = []): array
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : $default;
        } catch (Throwable) {
            return $default;
        }
    }
}

if (!function_exists('tp_audit')) {
    function tp_audit(mysqli $db, ?int $planId, string $action, string $recordType, ?string $recordId, array $summary = []): void
    {
        $actor = tp_actor_id();
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $json = tp_json($summary);
        $stmt = $db->prepare('INSERT INTO teaching_plan_audit_logs (teaching_plan_id, actor_staff_id, action, record_type, record_id, change_summary, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issssss', $planId, $actor, $action, $recordType, $recordId, $json, $ip);
        $stmt->execute();
        $stmt->close();
        if (function_exists('audit_log_current_user')) {
            audit_log_current_user($db, 'teaching_planner.' . $action, ['plan_id' => $planId, 'record_type' => $recordType, 'record_id' => $recordId]);
        }
    }
}

if (!function_exists('tp_schema_ready')) {
    function tp_schema_ready(mysqli $db): bool
    {
        foreach (['document_templates', 'document_template_versions', 'syllabus_versions', 'syllabus_topics', 'teaching_plans', 'teaching_plan_items'] as $table) {
            if (!wuc_table_exists($db, $table)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('tp_flash')) {
    function tp_flash(string $type, string $message): void
    {
        $_SESSION['tp_flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('tp_take_flash')) {
    function tp_take_flash(): ?array
    {
        $flash = $_SESSION['tp_flash'] ?? null;
        unset($_SESSION['tp_flash']);
        return is_array($flash) ? $flash : null;
    }
}

