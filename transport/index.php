<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/wucportal/transport.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target);
exit;
