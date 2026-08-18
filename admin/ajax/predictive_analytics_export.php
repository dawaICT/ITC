<?php
/**
 * CSV export for administrator admission and fee-collection forecasts.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/predictive_analytics.php';
require_once dirname(__DIR__, 2) . '/includes/audit.php';

wuc_require_any_permission(
    ['reports.view', 'reports.manage'],
    'You do not have permission to export predictive analytics.',
    '/wucportal/portal_selection.php'
);

$gender = in_array((string)($_GET['gender'] ?? ''), ['M', 'F'], true) ? (string)$_GET['gender'] : '';
$horizon = (int)($_GET['horizon'] ?? 3);
if (!in_array($horizon, [3, 6, 12], true)) {
    $horizon = 3;
}
$filters = [
    'program' => substr(trim((string)($_GET['program'] ?? '')), 0, 20),
    'intake' => substr(trim((string)($_GET['intake'] ?? '')), 0, 50),
    'academic_year' => substr(trim((string)($_GET['academic_year'] ?? '')), 0, 10),
    'gender' => $gender,
];
$predictive = wuc_predictive_analytics_build($db, $filters, $horizon, 12);

if (function_exists('audit_log_current_user')) {
    audit_log_current_user($db, 'reports.predictive_analytics.export', [
        'horizon' => $horizon,
        'filters' => $filters,
        'admissions_confidence' => $predictive['admissions']['confidence_score'],
        'collections_confidence' => $predictive['collections']['confidence_score'],
    ]);
}

if (!headers_sent()) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="predictive_analytics_' . date('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
}

$output = fopen('php://output', 'w');
if ($output === false) {
    http_response_code(500);
    exit;
}

fputcsv($output, ['WUC Portal Predictive Analysis']);
fputcsv($output, ['Generated at', $predictive['generated_at']]);
fputcsv($output, ['Completed data through', $predictive['through_period']]);
fputcsv($output, ['Forecast horizon (months)', $predictive['horizon']]);
foreach ($filters as $name => $value) {
    if ($value !== '') {
        fputcsv($output, ['Filter: ' . str_replace('_', ' ', ucfirst($name)), $value]);
    }
}
fputcsv($output, []);

foreach (['Admissions' => $predictive['admissions'], 'Fee collections (ZMW)' => $predictive['collections']] as $label => $model) {
    fputcsv($output, [$label]);
    fputcsv($output, ['Confidence', $model['confidence_label'], $model['confidence_score'] . '/100']);
    fputcsv($output, ['Evidence records', $model['sample_records']]);
    fputcsv($output, ['Period', 'Series', 'Value', 'Lower estimate', 'Upper estimate']);
    foreach ($model['history'] as $row) {
        fputcsv($output, [$row['period'], 'Actual', $row['value'], '', '']);
    }
    foreach ($model['forecast'] as $row) {
        fputcsv($output, [$row['period'], 'Forecast', $row['value'], $row['lower'], $row['upper']]);
    }
    fputcsv($output, ['Forecast total', '', $model['expected_total'], '', '']);
    fputcsv($output, []);
}

fputcsv($output, ['Important', $predictive['disclaimer']]);
fclose($output);
exit;
