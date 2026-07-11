<?php
declare(strict_types=1);
/**
 * Transport / Head of Section — training reports with AI summaries.
 * Reuses the shared training-reports engine. Gated by canAccessTransport
 * (systems admin + transport section heads).
 */
require_once __DIR__ . '/includes/transport.php';   // auth + $db
require_once dirname(__DIR__) . '/includes/training_reports_engine.php';

$page_title = 'Training Reports';
$opts = [
    'allowed'  => ['recruitment_funnel', 'training_throughput', 'instructor_allocation', 'cohort_progress'],
    'base_url' => 'training_reports.php',
    'role'     => 'head of section',
    'heading'  => 'Training Reports',
];

trx_handle_csv($db, $opts);   // before HTML output

require __DIR__ . '/includes/nav.php';
trx_render($db, $opts);
require __DIR__ . '/includes/footer.php';
