<?php
declare(strict_types=1);
require __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request.');
}
$code = trim((string)($_POST['program_code'] ?? ''));
if ($code !== '') {
    $stmt = $db->prepare('DELETE FROM programs WHERE program_code = ?');
    $stmt->bind_param('s', $code); $stmt->execute();
    $_SESSION[$stmt->affected_rows ? 'successMssg' : 'errorMssg'] = $stmt->affected_rows ? 'Program deleted.' : 'Program was not found.';
    $stmt->close();
}
header('Location: programs.php', true, 303); exit;
