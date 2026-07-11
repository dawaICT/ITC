<?php
declare(strict_types=1);
require __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request.');
}
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($id && $id > 0) {
    // Mapping rows live in program_courses (course_levels never existed)
    $stmt = $db->prepare('DELETE FROM program_courses WHERE id = ?');
    $stmt->bind_param('i', $id); $stmt->execute();
    $_SESSION[$stmt->affected_rows ? 'successMssg' : 'errorMssg'] = $stmt->affected_rows ? 'Course removed from program.' : 'Assignment was not found.';
    $stmt->close();
}
header('Location: courses.php', true, 303); exit;
