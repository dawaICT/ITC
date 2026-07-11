<?php
declare(strict_types=1);
require __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/action_confirmation.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_GET['del'])) {
    wuc_render_action_confirmation('Drop program enrollment?', 'The student record remains, but the current program assignment will be removed.', 'deleteStudentProgram.php', ['sid' => trim((string)$_GET['del'])]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request.'); }
$sid = trim((string)($_POST['sid'] ?? ''));
if ($sid !== '') {
    $stmt = $db->prepare('DELETE FROM student_program WHERE Sid = ?');
    $stmt->bind_param('s', $sid); $stmt->execute();
    $_SESSION[$stmt->affected_rows ? 'successDrop' : 'errorMssg'] = $stmt->affected_rows ? 'Student removed from the program.' : 'Program assignment was not found.';
    $stmt->close();
}
header('Location: students_by_admin.php', true, 303); exit;
