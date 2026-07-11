<?php
require_once dirname(__DIR__) . '/includes/api_auth.php';

function api_check($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL: ' . $message . ' => ' . json_encode($actual) . "\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

api_check(
    wuc_api_path_segments('/wucportal/api/registration.php/check-status', '/wucportal/api/registration.php'),
    ['check-status'],
    'path-info route strips the script name'
);
api_check(
    wuc_api_path_segments('/wucportal/api/registration.php/courses/42', '/wucportal/api/registration.php'),
    ['courses', '42'],
    'path-info route preserves parameters'
);
api_check(
    wuc_api_path_segments('/wucportal/api/check-status', '/wucportal/api/registration.php'),
    ['check-status'],
    'rewritten route strips the API directory'
);
api_check(
    wuc_api_path_segments('/wucportal/api/registration.php', '/wucportal/api/registration.php'),
    [],
    'API root has no route segments'
);

echo "API helper regression checks passed.\n";
