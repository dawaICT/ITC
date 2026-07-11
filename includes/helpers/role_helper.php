<?php
declare(strict_types=1);
/**
 * Role helper aggregator.
 *
 * Provides the single, canonical role API the rest of the app should use:
 *   - role_helpers.php       : hasRole(), hasAnyRole(), canAccess*(),
 *                              requireRole(), ROLE_* constants
 *   - staff_role_helpers.php : wuc_normalize_staff_role(), wuc_staff_role_map(),
 *                              wuc_hydrate_staff_roles()
 *
 * This file exists so callers can `require_once includes/helpers/role_helper.php`
 * once and get the whole role surface, without needing to know which underlying
 * file each function lives in. It does NOT redefine any logic — it only wires the
 * two authoritative sources together so there is exactly one source of truth.
 */

require_once __DIR__ . '/../role_helpers.php';
require_once __DIR__ . '/../staff_role_helpers.php';
