<?php
declare(strict_types=1);
/**
 * Legacy admin hub entry — redirected to Skills and Enterprise Portal management.
 */
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_portal/legacy_redirect.php';
ep_legacy_hub_redirect('management');
