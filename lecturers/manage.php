<?php
// Bridge redirect from lecturers/manage.php to elearning/manage.php
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$target = '../elearning/manage.php';
if ($queryString !== '') {
    $target .= '?' . $queryString;
}
header('Location: ' . $target);
exit;
