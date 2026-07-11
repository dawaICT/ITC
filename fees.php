<?php
/**
 * Compatibility shim: the student fees page lives at students/fees.php, but
 * bookmarks and hand-typed URLs frequently use /wucportal/fees.php. Redirect
 * permanently, preserving any query string (?invoice=…, ?payment_status=…).
 */
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: /wucportal/students/fees.php' . ($query !== '' ? '?' . $query : ''), true, 301);
exit;
