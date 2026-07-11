<?php
/**
 * Admin new-student registration.
 *
 * Uses the SAME shared frontend (5-step single wizard + bulk panel + recent list)
 * and the SAME canonical backend handlers as admissions/regNewStud.php, so both
 * modules register students identically. The only differences are the admin
 * auth/layout chrome and the "Back to Students" target.
 */

require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/../config/auth_check.php';
checkAdminAuth();

// Shared registration bootstrap: CSRF token + period-aware $programs_data, and
// pulls in the canonical registration_handlers used by the AJAX dispatcher.
require_once __DIR__ . '/../admissions/includes/registration_bootstrap.php';

// Shared AJAX dispatch (register / bulk_register / get_recent). Exits on POST.
require __DIR__ . '/../admissions/includes/registration_ajax.php';

$page_title = 'Student Registration';
require_once "includes/header.php";

// Render the shared single + bulk + recent registration UI.
$reg_back_url = 'students_by_admin.php';
require __DIR__ . '/../admissions/includes/registration_panel.php';

require 'includes/footer.php';
