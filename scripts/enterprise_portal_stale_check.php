<?php
declare(strict_types=1);

/**
 * CLI / cron: process stale published enterprise opportunities.
 * c:\xampp\php\php.exe scripts\enterprise_portal_stale_check.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_portal/bootstrap.php';

$result = ep_process_stale_opportunities($db);
echo 'Marked update_required: ' . (int)$result['marked_update'] . PHP_EOL;
echo 'Unpublished: ' . (int)$result['unpublished'] . PHP_EOL;
