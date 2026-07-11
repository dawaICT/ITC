<?php
/**
 * Shared helpers for admissions short course management.
 *
 * The schema-flexible DB helpers (sc_identifier, sc_table_exists, sc_columns,
 * sc_has_column, sc_insert) now live in the neutral includes/short_course_db.php
 * so the admin and admissions modules share one definition.
 */

require_once dirname(__DIR__, 2) . '/includes/short_course_db.php';
require_once dirname(__DIR__, 2) . '/includes/json_response.php';

function sc_trim_width(string $value, int $width = 60): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $width, '...');
    }

    return strlen($value) > $width ? substr($value, 0, max(0, $width - 3)) . '...' : $value;
}

function sc_ensure_csrf_token(): string
{
    if (empty($_SESSION['short_course_csrf'])) {
        $_SESSION['short_course_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['short_course_csrf'];
}

function sc_valid_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['short_course_csrf'])
        && hash_equals($_SESSION['short_course_csrf'], $token);
}

function sc_json(array $payload, int $statusCode = 200): void
{
    wuc_json_response($payload, $statusCode);
}
