<?php
if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', '/wucportal');
}

// Module base URL constants (with trailing slash)
if (!defined('BASE_URL'))       { define('BASE_URL',       APP_BASE_PATH . '/'); }
if (!defined('ADMIN_URL'))      { define('ADMIN_URL',      APP_BASE_PATH . '/admin/'); }
if (!defined('ADMISSIONS_URL')) { define('ADMISSIONS_URL', APP_BASE_PATH . '/admissions/'); }
if (!defined('STUDENTS_URL'))   { define('STUDENTS_URL',   APP_BASE_PATH . '/students/'); }
if (!defined('STAFF_URL'))      { define('STAFF_URL',      APP_BASE_PATH . '/staff/'); }
if (!defined('LECTURERS_URL'))  { define('LECTURERS_URL',  APP_BASE_PATH . '/lecturers/'); }

// Institutional prefix for generated staff usernames / account IDs (e.g. ITC901).
// The generator and validator live in includes/id_helpers.php, which also
// guard-defines this so it works when included on its own. Change in one place.
if (!defined('STAFF_USERNAME_PREFIX')) { define('STAFF_USERNAME_PREFIX', 'ITC'); }

// Module dashboard landing pages
// The generic staff dashboard hub was retired: staff route through the portal
// selection page, which auto-forwards single-portal users to the module
// dashboard their role guard accepts.
if (!defined('STAFF_DASHBOARD'))     { define('STAFF_DASHBOARD',     APP_BASE_PATH . '/portal_selection.php'); }
if (!defined('ADMIN_DASHBOARD'))     { define('ADMIN_DASHBOARD',     APP_BASE_PATH . '/admin/index.php'); }
if (!defined('ADMISSIONS_DASHBOARD')){ define('ADMISSIONS_DASHBOARD',APP_BASE_PATH . '/admissions/index.php'); }
if (!defined('LECTURERS_DASHBOARD')) { define('LECTURERS_DASHBOARD', APP_BASE_PATH . '/lecturers/index.php'); }
if (!defined('HOD_DASHBOARD'))       { define('HOD_DASHBOARD',       APP_BASE_PATH . '/hod/index.php'); }
if (!defined('ACCOUNTS_DASHBOARD'))  { define('ACCOUNTS_DASHBOARD',  APP_BASE_PATH . '/accounts/index.php'); }
if (!defined('REGISTRAR_DASHBOARD')) { define('REGISTRAR_DASHBOARD', APP_BASE_PATH . '/registrar/index.php'); }
if (!defined('DEAN_DASHBOARD'))      { define('DEAN_DASHBOARD',      APP_BASE_PATH . '/dean/index.php'); }
if (!defined('LIBRARY_DASHBOARD'))   { define('LIBRARY_DASHBOARD',   APP_BASE_PATH . '/library/index.php'); }
if (!defined('TRANSPORT_DASHBOARD')) { define('TRANSPORT_DASHBOARD', APP_BASE_PATH . '/transport.php'); }
