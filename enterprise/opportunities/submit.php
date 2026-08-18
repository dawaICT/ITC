<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth_helpers.php';
$id = (int)($_GET['id'] ?? 0);
wuc_redirect('/wucportal/enterprise/opportunities/view.php?id=' . $id);
