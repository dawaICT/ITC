<?php
declare(strict_types=1);
/**
 * Legacy academic-path hub entry — permanently redirected to Skills and Enterprise Portal.
 */
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_portal/legacy_redirect.php';
ep_legacy_hub_redirect('participant');
