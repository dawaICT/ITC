<?php
// Bridge redirect from lecturers/sessions.php to elearning/sessions.php
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$target = '../elearning/sessions.php';
if ($queryString !== '') {
    $target .= '?' . $queryString;
}
header('Location: ' . $target);
exit;
