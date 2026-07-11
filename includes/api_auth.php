<?php
/** Shared authentication boundary for JSON API controllers. */

require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/json_response.php';

if (!function_exists('wuc_api_path_segments')) {
    function wuc_api_path_segments(string $requestUri, string $scriptName): array
    {
        $requestPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '');
        $scriptPath = str_replace('\\', '/', $scriptName);
        if ($scriptPath !== '' && strpos($requestPath, $scriptPath) === 0) {
            $relativePath = substr($requestPath, strlen($scriptPath));
        } else {
            $scriptDirectory = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/');
            $relativePath = ($scriptDirectory !== '' && strpos($requestPath, $scriptDirectory) === 0)
                ? substr($requestPath, strlen($scriptDirectory))
                : $requestPath;
        }

        $relativePath = trim((string) $relativePath, '/');
        return $relativePath === '' ? [] : array_values(array_filter(explode('/', $relativePath), 'strlen'));
    }
}

if (!function_exists('wuc_api_start_session')) {
    function wuc_api_start_session(string $context = 'api'): void
    {
        wuc_guard_start_session($context);
        $_SESSION['last_activity'] = time();
    }
}

if (!function_exists('wuc_api_require_student')) {
    function wuc_api_require_student(): string
    {
        wuc_api_start_session('student-api');
        $studentId = trim((string) ($_SESSION['Sid'] ?? ''));
        if ($studentId === '') {
            wuc_json_response([
                'success' => false,
                'message' => 'Session expired or invalid.',
                'session_expired' => true,
            ], 401);
        }
        if (!preg_match('/^[A-Za-z0-9\/\-_]+$/', $studentId)) {
            wuc_guard_clear_session();
            wuc_json_response([
                'success' => false,
                'message' => 'Invalid session.',
                'session_expired' => true,
            ], 401);
        }
        return $studentId;
    }
}

if (!function_exists('wuc_api_require_staff')) {
    function wuc_api_require_staff(): string
    {
        wuc_api_start_session('staff-api');
        wuc_guard_sync_session_aliases(['staff_id', 'user_id']);
        $staffId = trim((string) ($_SESSION['staff_id'] ?? ''));
        if ($staffId === '') {
            wuc_json_response([
                'success' => false,
                'message' => 'Session expired or invalid.',
                'session_expired' => true,
            ], 401);
        }
        return $staffId;
    }
}
