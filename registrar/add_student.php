<?php
/**
 * Deprecated entry point.
 *
 * Manual student registration is restricted to system administrators and uses
 * the unified wizard in admin/regNewStud.php (same backend as admissions).
 * This redirect keeps old registrar bookmarks and direct links working.
 */
header('Location: ../admin/regNewStud.php');
exit;
