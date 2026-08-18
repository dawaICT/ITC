<?php
/**
 * Deprecated entry point.
 *
 * Legacy registrar payments wrote to the non-existent `student_payments` table
 * (real table is `payments`). Finance capture lives in the Admin/Accounts tools.
 * Keep bookmarks working by sending staff to the working payments screen.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Location: /wucportal/admin/payments.php', true, 302);
exit;
