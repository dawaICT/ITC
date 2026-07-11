<?php
/**
 * Compatibility shim.
 *
 * The real handler lives at students/elearning/join_meeting.php. This file
 * forwards old or external links of the form
 *   /wucportal/students/join_meeting.php?token=...
 * to the correct subdirectory while preserving the query string.
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = 'elearning/join_meeting.php' . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $target, true, 302);
exit;
