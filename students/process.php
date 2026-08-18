<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/guard.php';

$_SESSION['reg_flash'] = [
    'type' => 'info',
    'text' => 'The legacy registration link has been retired. Continue from the current registration page.',
];

header('Location: /wucportal/students/registration.php', true, 303);
exit;
