<?php
/**
 * Retired legacy Airtel configuration page.
 *
 * The page previously depended on obsolete authentication/database wrappers
 * and stored gateway secrets in general portal settings. Payment configuration
 * is managed by the active, guarded gateway settings controller.
 */

header('Location: /wucportal/admin/payment_gateway_settings.php', true, 302);
exit;
