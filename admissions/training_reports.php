<?php
declare(strict_types=1);
/**
 * Admissions — recruitment & training reports (recruited / reported / trained)
 * with AI summaries. Reuses the shared training-reports engine.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';
require_once dirname(__DIR__) . '/includes/training_reports_engine.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (!checkSessionTimeout(30) || !isAdminAuthenticated()) {
    setFlashMessage('error', 'Session expired or unauthorized access');
    header('Location: /wucportal/staff_login.php');
    exit;
}

$page_title = 'Recruitment & Training Reports';
$opts = [
    'allowed'  => ['recruitment_funnel', 'training_throughput', 'cohort_progress'],
    'base_url' => 'training_reports.php',
    'role'     => 'admissions',
    'heading'  => 'Recruitment & Training Reports',
];

// CSV export must run before any HTML output.
trx_handle_csv($db, $opts);

require "includes/nav.php";   // enforces canAccessAdmissions via nav_unified
trx_render($db, $opts);
require "includes/footer.php";
