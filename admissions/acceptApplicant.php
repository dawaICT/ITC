<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';
require_once __DIR__ . '/../includes/guards/admissions_guard.php';
require_once __DIR__ . '/../includes/applicant_workflow.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $_SESSION['errorMssg'] = 'Invalid request method.';
    header('Location: processedApp.php', true, 303);
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
    $_SESSION['errorMssg'] = 'Your session token is invalid. Please try again.';
    header('Location: processedApp.php', true, 303);
    exit;
}

$onlineApplicantId = filter_var($_POST['mov'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($onlineApplicantId === false) {
    $_SESSION['errorMssg'] = 'Invalid application.';
    header('Location: processedApp.php', true, 303);
    exit;
}

$staffId = trim((string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? ''));
$result = wuc_accept_online_applicant($db, (int)$onlineApplicantId, $staffId);
if (!empty($result['success'])) {
    $_SESSION['successMssg'] = 'Application accepted and student account activated. Student ID: '
        . (string)$result['student_id'];
    if (!empty($result['warnings'])) {
        $_SESSION['warningMssg'] = implode(' ', array_map('strval', (array)$result['warnings']));
    }
} else {
    $_SESSION['errorMssg'] = 'The application was not converted: ' . (string)($result['message'] ?? 'Unknown error.');
}

header('Location: processedApp.php', true, 303);
exit;
