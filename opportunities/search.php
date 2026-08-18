<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$qs = $_GET;
header('Location: /wucportal/opportunities/index.php' . ($qs ? ('?' . http_build_query($qs)) : ''));
exit;
