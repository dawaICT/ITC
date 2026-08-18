<?php
declare(strict_types=1);

/**
 * Skills and Enterprise Portal — shared bootstrap.
 */

require_once dirname(__DIR__) . '/auth_helpers.php';
require_once dirname(__DIR__) . '/audit.php';
require_once dirname(__DIR__) . '/permissions.php';
require_once dirname(__DIR__) . '/role_helpers.php';
require_once dirname(__DIR__) . '/notification_integrations.php';
require_once dirname(__DIR__) . '/upload_validator.php';
require_once dirname(__DIR__) . '/qr_helper.php';
require_once dirname(__DIR__) . '/helpers/flash_helper.php';
require_once dirname(__DIR__) . '/portal_access.php';

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/deployment.php';
require_once __DIR__ . '/platform_identity.php';
require_once __DIR__ . '/organization_service.php';
require_once __DIR__ . '/platform_permissions.php';
require_once __DIR__ . '/adapters.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/membership_service.php';
require_once __DIR__ . '/consent_service.php';
require_once __DIR__ . '/profile_service.php';
require_once __DIR__ . '/opportunity_service.php';
require_once __DIR__ . '/pricing_service.php';
require_once __DIR__ . '/readiness_service.php';
require_once __DIR__ . '/interest_service.php';
require_once __DIR__ . '/outcome_service.php';
require_once __DIR__ . '/media_service.php';
require_once __DIR__ . '/stale_service.php';
require_once __DIR__ . '/ai_assistant.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/agriculture_farmer_service.php';
require_once __DIR__ . '/agriculture_price_service.php';
require_once __DIR__ . '/agriculture_marketplace_service.php';
require_once __DIR__ . '/agriculture_ussd_service.php';
require_once __DIR__ . '/agriculture_reports_service.php';
require_once __DIR__ . '/domain_services.php';
