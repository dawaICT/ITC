<?php
declare(strict_types=1);
require __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
wuc_require_systems_admin('/wucportal/registrar/search_student.php');
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/action_confirmation.php';
require_once dirname(__DIR__) . '/includes/exhibition_mode.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_GET['del'])) {
    wuc_render_action_confirmation('Delete student?', 'This permanently removes the student and their program/login records.', 'deleteStudent.php', ['sid' => trim((string)$_GET['del'])]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request.'); }
$sid = trim((string)($_POST['sid'] ?? ''));
if ($sid !== '') {
    try {
        wuc_exhibition_assert_destructive_target($db, $sid);
    } catch (DomainException $e) {
        $_SESSION['errorMssg'] = $e->getMessage();
        header('Location: students_by_admin.php', true, 303);
        exit;
    }
    $db->begin_transaction();
    try {
        foreach (['student_login', 'student_program'] as $table) {
            $column = $table === 'student_login' ? 'Sid' : 'Sid';
            $stmt = $db->prepare("DELETE FROM `{$table}` WHERE `{$column}` = ?");
            $stmt->bind_param('s', $sid); $stmt->execute(); $stmt->close();
        }
        $stmt = $db->prepare('DELETE FROM students WHERE SID = ?');
        $stmt->bind_param('s', $sid); $stmt->execute();
        $deleted = $stmt->affected_rows; $stmt->close();
        $db->commit();
        $_SESSION[$deleted ? 'successDel' : 'errorMssg'] = $deleted ? 'Student removed.' : 'Student was not found.';
    } catch (Throwable $e) {
        $db->rollback(); error_log('Registrar student deletion failed: ' . $e->getMessage()); $_SESSION['errorMssg'] = 'Student could not be removed.';
    }
}
header('Location: students_by_admin.php', true, 303); exit;
