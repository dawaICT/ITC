<?php
/**
 * Legacy entry point — semester registration moved to semester_registration.php.
 */
require_once __DIR__ . '/includes/admin.php';
header('Location: semester_registration.php', true, 302);
exit;
