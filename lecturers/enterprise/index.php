<?php
declare(strict_types=1);
/**
 * Legacy lecturer hub entry — redirected to Skills and Enterprise Portal reviewer area.
 */
require_once __DIR__ . '/../includes/guard.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_portal/legacy_redirect.php';
ep_legacy_hub_redirect('reviewer');
