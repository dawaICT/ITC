<?php
/**
 * Compatibility route for legacy/root materials links.
 *
 * The active materials pages live under module folders. Keep this root entry
 * point so stale redirects such as /wucportal/materials.php?code=GEN101 do not
 * fall through to Apache's 404 page.
 */

require_once __DIR__ . '/includes/security.php';
wuc_configure_session_cookie();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$query = $_SERVER['QUERY_STRING'] ?? '';
$suffix = $query !== '' ? '?' . $query : '';

if (!empty($_SESSION['Sid'])) {
    header('Location: students/materials.php' . $suffix);
    exit;
}

header('Location: lecturers/materials.php' . $suffix);
exit;
