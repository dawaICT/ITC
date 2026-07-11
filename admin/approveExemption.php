<?php
declare(strict_types=1);
require __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request.');
}
$id = filter_input(INPUT_POST, 'course_registration_id', FILTER_VALIDATE_INT);
if ($id && $id > 0) {
    $db->begin_transaction();
    try {
        $update = $db->prepare('UPDATE exemption SET status = 1 WHERE CoRegID = ?');
        $update->bind_param('i', $id); $update->execute(); $update->close();
        // course_registration is keyed by `id`; soft-deactivate rather than delete
        // so the academic history survives the exemption.
        $delete = $db->prepare("UPDATE course_registration SET is_active = 0, status = 'exempted' WHERE id = ?");
        $delete->bind_param('i', $id); $delete->execute(); $delete->close();
        $db->commit();
        $_SESSION['successMssg'] = 'Exemption processed.';
    } catch (Throwable $e) {
        $db->rollback();
        error_log('Exemption approval failed: ' . $e->getMessage());
        $_SESSION['errorMssg'] = 'The exemption could not be processed.';
    }
}
header('Location: exemption.php', true, 303); exit;
