<?php
/**
 * Admin transfer-student registration.
 *
 * Uses the SAME shared frontend (5-step single wizard + bulk panel + recent list)
 * and the SAME canonical backend handlers as admissions/regOldStud.php, so both
 * modules register transfer students identically. The only differences are the admin
 * auth/layout chrome and the 'Back to Students' target.
 */
$is_admin_module = true;
require_once __DIR__ . '/../admissions/regOldStud.php';

