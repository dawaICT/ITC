<?php
/**
 * Redirect legacy student pages to canonical flows with a flash message.
 */
function wuc_legacy_student_redirect(string $target, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['reg_flash'] = ['type' => 'info', 'text' => $message];
    header('Location: ' . $target);
    exit;
}
