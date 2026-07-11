<?php
/** Shared JSON response contract for portal controllers. */

if (!function_exists('wuc_json_response')) {
    function wuc_json_response(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            http_response_code(500);
            $json = '{"success":false,"message":"Response encoding failed."}';
        }

        echo $json;
        exit;
    }
}

if (!function_exists('wuc_json_success')) {
    function wuc_json_success($data = [], int $statusCode = 200): void
    {
        wuc_json_response(['success' => true, 'data' => $data], $statusCode);
    }
}

if (!function_exists('wuc_json_error')) {
    function wuc_json_error(string $message, int $statusCode = 400, array $extra = []): void
    {
        $payload = ['success' => false, 'message' => $message];
        if ($extra) {
            $payload['extra'] = $extra;
        }
        wuc_json_response($payload, $statusCode);
    }
}
