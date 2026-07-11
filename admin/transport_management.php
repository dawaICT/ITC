<?php
$target = '/wucportal/transport.php';
$query = $_SERVER['QUERY_STRING'] ?? '';
$tabRoutes = [
    'trainee' => '/wucportal/transport/trainees.php',
    'session' => '/wucportal/transport/sessions.php',
    'check' => '/wucportal/transport/preuse_checks.php',
    'cohort' => '/wucportal/transport/cohorts.php',
    'fleet' => '/wucportal/transport/fleet.php',
    'instructor' => '/wucportal/transport/instructors.php',
    'client' => '/wucportal/transport/clients.php',
];
if (isset($_GET['tab'], $tabRoutes[(string)$_GET['tab']])) {
    $target = $tabRoutes[(string)$_GET['tab']];
    $remaining = $_GET;
    unset($remaining['tab']);
    if ($remaining) {
        $target .= '?' . http_build_query($remaining);
    }
} elseif ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
