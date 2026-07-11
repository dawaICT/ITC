<?php
declare(strict_types=1);
/**
 * Admin — Transport training reports (all campuses) with AI summaries.
 * Reuses the shared training-reports engine.
 */
$page_title = 'Training Reports';
require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/portal_access.php';
require_once dirname(__DIR__) . '/includes/training_reports_engine.php';

wuc_require_portal_access($db, 'academic');

$opts = [
    'allowed'  => ['recruitment_funnel', 'training_throughput', 'instructor_allocation', 'cohort_progress'],
    'base_url' => 'training_reports.php',
    'role'     => 'admin',
    'heading'  => 'Transport Training Reports',
];

// CSV export must run before any HTML output.
trx_handle_csv($db, $opts);

require __DIR__ . '/includes/nav.php';
trx_render($db, $opts);
require __DIR__ . '/includes/footer.php';
