<?php
/**
 * Legacy compatibility route.
 *
 * The old semester registration report queried legacy registration columns
 * directly. Registrar academic reporting now runs through the normalized ITC
 * report helper, so direct bookmarks should land on the TEVETA-aligned report
 * surface instead of maintaining a second academic report path.
 */

$params = [
    'report_type' => 'STUDENT_REGISTRATION_REPORT',
];

$programCode = strtoupper(trim((string)($_GET['program_code'] ?? '')));
if ($programCode !== '' && preg_match('/^[A-Z0-9_-]{1,20}$/', $programCode)) {
    $params['program_code'] = $programCode;
}

if (isset($_GET['submit']) || isset($_GET['generate'])) {
    $params['generate'] = 1;
    $params['registration_status'] = 'REGISTERED';
}

header('Location: academic_reports.php?' . http_build_query($params), true, 302);
exit;
