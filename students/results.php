<?php
declare(strict_types=1);

/**
 * Results alias — canonical student results live on continuousAssessment.php.
 * Kept so notifications, bookmarks, and AI scope maps that use results.php resolve.
 */
require_once __DIR__ . '/includes/guard.php';

$query = [];
if (!empty($_GET['academic_year'])) {
    $query['academic_year'] = (string)$_GET['academic_year'];
}
$target = 'continuousAssessment.php';
if ($query !== []) {
    $target .= '?' . http_build_query($query);
}

header('Location: ' . $target, true, 302);
exit;
