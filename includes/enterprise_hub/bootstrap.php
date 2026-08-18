<?php
declare(strict_types=1);

/**
 * Skills-to-Trade and Investment Hub — shared bootstrap.
 */

require_once dirname(__DIR__) . '/auth_helpers.php';
require_once dirname(__DIR__) . '/audit.php';
require_once dirname(__DIR__) . '/permissions.php';
require_once dirname(__DIR__) . '/role_helpers.php';
require_once dirname(__DIR__) . '/notification_integrations.php';
require_once dirname(__DIR__) . '/upload_validator.php';
require_once dirname(__DIR__) . '/qr_helper.php';
require_once dirname(__DIR__) . '/helpers/flash_helper.php';
require_once dirname(__DIR__) . '/exhibition_mode.php';

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/status_service.php';
require_once __DIR__ . '/cost_calculator.php';
require_once __DIR__ . '/readiness.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/media_service.php';
require_once __DIR__ . '/interest_service.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/ai_assistant.php';
require_once __DIR__ . '/reports.php';
