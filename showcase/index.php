<?php
declare(strict_types=1);
/**
 * Legacy public showcase → permanent Skills and Enterprise Directory.
 */
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/enterprise_portal/legacy_redirect.php';
wuc_secure_session_start();
ep_legacy_hub_redirect('public');
