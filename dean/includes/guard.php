<?php
// Shared staff session/authentication guard for dean pages.
require_once dirname(__DIR__, 2) . '/includes/session_guard.php';

wuc_enforce_session_guard([
    'context' => 'dean',
    'session_keys' => ['staff_id', 'user_id'],
    'activity_keys' => ['last_activity', 'last_active_time'],
    'timeout' => 1800,
    'post_grace' => 30,
    'login_path' => '/wucportal/staff_login.php',
    'flash_key' => 'errorMessage',
    'timeout_message' => 'Your session has expired. Please log in again.',
    'login_message' => 'Please log in to continue.',
]);

require_once dirname(__DIR__, 2) . '/db/connect.php';
