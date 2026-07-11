<?php
declare(strict_types=1);
define('WUC_PUBLIC_API', true);
require __DIR__ . '/_bootstrap.php';

// Service descriptor — lists the read-only public endpoints the ITC website
// (or any approved consumer) may call.
wuc_public_json([
    'service' => 'ITC Public Data API',
    'access'  => 'read-only, public data only',
    'endpoints' => [
        'GET programmes.php'              => 'Active academic programmes',
        'GET short-courses.php'           => 'Active short courses',
        'GET fees.php'                    => 'Programme-level fees (optional ?program_code=)',
        'GET admission-requirements.php'  => 'General + per-level entry requirements',
        'GET intakes.php'                 => 'Intake windows with applications_open flag',
        'GET news.php'                    => 'Published news (empty until table exists)',
        'GET events.php'                  => 'Published events (empty until table exists)',
    ],
]);
