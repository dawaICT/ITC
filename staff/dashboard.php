<?php
declare(strict_types=1);
/**
 * The generic staff dashboard hub was retired. All portal routing now goes
 * through portal_selection.php, which auto-forwards single-portal accounts to
 * the module dashboard their role guard accepts and only renders a chooser
 * when a selection is actually necessary. This stub only exists so stale
 * bookmarks and legacy links do not 404.
 */
require_once __DIR__ . '/../config/auth_constants.php';

header('Location: ' . APP_BASE_PATH . '/portal_selection.php', true, 302);
exit;
