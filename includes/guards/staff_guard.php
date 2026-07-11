<?php
declare(strict_types=1);
/**
 * staff_guard.php — alias of auth_guard.php.
 *
 * Provided for naming symmetry with the other guards (admin/admissions/student).
 * "Staff" and "any authenticated portal user" are the same requirement here, so
 * this simply delegates to the generic authenticated-staff guard.
 */

require_once __DIR__ . '/auth_guard.php';
