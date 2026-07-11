<?php
// Student report export (CSV/PDF)
// Accepts filters matching admin/admittedStud_report.php and outputs CSV or PDF

// finance_helpers bootstraps the session, security headers and $db (via db/connect.php)
// and provides ensure_roles()/log_audit(). It MUST load for auth to work, so do not
// suppress its failure — a missing guard must fail closed, never open.
require_once __DIR__ . '/../../includes/finance_helpers.php';
require_once __DIR__ . '/../../includes/portal_access.php';
require_once __DIR__ . '/../../includes/audit.php';
// PHP 8.0 — vendor/ requires ≥8.2 and throws RuntimeException on the platform
// check. @-silencing suppresses warnings but NOT exceptions, so we must catch.
// The TCPDF/dompdf availability check below handles the fallback gracefully.
try {
    require_once __DIR__ . '/../../vendor/autoload.php';
} catch (Throwable $e) {
    error_log('student_report: vendor/autoload.php skipped (' . $e->getMessage() . ')');
}
require_once __DIR__ . '/../includes/admitted_report_helpers.php';

// Helper to send error and exit (no SQL/internal detail ever reaches the browser)
function send_error($message, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Fail closed if the shared helper library was truncated/partially loaded.
if (!function_exists('admitted_report_missing_functions') || !empty(admitted_report_missing_functions())) {
    error_log('student_report export: report helper functions missing');
    send_error('Report module is unavailable. Please contact support.', 500);
}

// Authentication + export permission. ensure_roles() calls ensure_logged_in()
// (401 for guests) then enforces the role allow-list (systems_admin bypasses).
if (!function_exists('ensure_roles')) {
    error_log('student_report export: ensure_roles() unavailable; refusing to serve');
    send_error('Authorization service unavailable', 500);
}
ensure_roles($db, ['Systems Admin', 'Registrar', 'Dean']);

// Validate DB connection
if (!isset($db) || !($db instanceof mysqli)) {
    send_error('Database connection unavailable', 500);
}

wuc_require_portal_access($db, 'academic', 'You do not have permission to export academic reports from the current portal.');

// Collect inputs (normalise BEFORE validation so the export matches the screen report)
$format = strtolower($_GET['format'] ?? 'csv');
$filters = admitted_report_normalise_filters(admitted_report_default_filters($_GET));

if (!in_array($format, ['csv', 'pdf'], true)) {
    send_error('Invalid export format', 422);
}

$errors = admitted_report_validate_filters($filters);
if (!empty($errors)) {
    send_error(implode(' ', $errors), 422);
}

try {
    $rows = admitted_report_fetch_rows($db, $filters);
} catch (Throwable $e) {
    error_log('student_report export failed: ' . $e->getMessage());
    send_error('Failed to prepare report query', 500);
}

// Audit the export (best effort — never let logging failure block the download)
if (function_exists('log_audit')) {
    $auditUser = $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'unknown';
    try {
        log_audit($db, $auditUser, 'admitted_report_export', json_encode([
            'format'  => $format,
            'filters' => $filters,
            'rows'    => is_array($rows) ? count($rows) : 0,
        ]));
    } catch (Throwable $e) {
        error_log('student_report export audit failed: ' . $e->getMessage());
    }
}
if (function_exists('audit_log_current_user')) {
    audit_log_current_user($db, 'reports.admitted_students.export', [
        'format' => $format,
        'filters' => $filters,
        'rows' => is_array($rows) ? count($rows) : 0,
    ]);
}

// If no rows, return friendly message
if (empty($rows)) {
    send_error('No records found for the specified filters', 404);
}

// Common filename stem
$filenameStem = 'admitted_students_report_' . date('Ymd_His');
$selectedColumns = admitted_report_normalise_columns($filters['columns'] ?? admitted_report_default_columns());
$availableColumns = admitted_report_available_columns();
$exportHeaders = [];
foreach ($selectedColumns as $columnKey) {
    $exportHeaders[] = $availableColumns[$columnKey] ?? $columnKey;
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameStem . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $exportHeaders);
    $counter = 1;
    foreach ($rows as $r) {
        $line = [];
        foreach ($selectedColumns as $columnKey) {
            $line[] = admitted_report_cell_text($r, $columnKey, $counter);
        }
        fputcsv($out, $line);
        $counter++;
    }
    fclose($out);
    exit;
}

// Attempt PDF; if TCPDF not available, fallback to CSV
if (!class_exists('TCPDF')) {
    $tcpdfPath = __DIR__ . '/../../lib/tcpdf/tcpdf.php';
    if (file_exists($tcpdfPath)) {
        @require_once $tcpdfPath;
    }
}

if (!class_exists('TCPDF')) {
    // Fallback to CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameStem . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $exportHeaders);
    $counter = 1;
    foreach ($rows as $r) {
        $line = [];
        foreach ($selectedColumns as $columnKey) {
            $line[] = admitted_report_cell_text($r, $columnKey, $counter);
        }
        fputcsv($out, $line);
        $counter++;
    }
    fclose($out);
    exit;
}

// Generate PDF with TCPDF
$pdf = new TCPDF();
$pdf->SetCreator('ITC Portal');
$pdf->SetAuthor('ITC Portal');
$pdf->SetTitle('Admitted Students Report');
$pdf->SetMargins(10, 10, 10);
$pdf->AddPage();

$heading = '<h2 style="text-align:center; margin:0;">Industrial training college</h2>' .
           '<h3 style="text-align:center; margin:4px 0 12px;">Admitted Students Report</h3>';

$meta = '<p style="text-align:center; font-size:11px;">' .
        'Type: ' . htmlspecialchars($filters['report_type'] === 'all' ? 'All Admissions' : ($filters['report_type'] === 'short_course' ? 'Short Courses' : 'Programs')) . ' &nbsp; | &nbsp; ' .
        'Program: ' . htmlspecialchars($filters['program_code'] === '' ? 'All' : $filters['program_code']) . ' &nbsp; | &nbsp; ' .
        'Year of Study: ' . htmlspecialchars($filters['year_of_study']) . ' &nbsp; | &nbsp; ' .
        'Academic Year: ' . htmlspecialchars($filters['academic_year']) . ' &nbsp; | &nbsp; ' .
        'Semester: ' . htmlspecialchars($filters['semester'] === 'all' ? 'All' : $filters['semester']) . ' &nbsp; | &nbsp; ' .
        'Gender: ' . htmlspecialchars($filters['gender'] === '' ? 'All' : $filters['gender']) .
        '</p>';

$html = $heading . $meta;

$html .= '<table border="1" cellpadding="4" cellspacing="0" width="100%">'
       . '<thead class="table-light">'
       . '<tr style="background-color:#f0f0f0;">'
       . implode('', array_map(function ($header) {
           return '<th><b>' . htmlspecialchars($header) . '</b></th>';
       }, $exportHeaders))
       . '</tr></thead><tbody>';

$counter = 1;
foreach ($rows as $r) {
    $html .= '<tr>';
    foreach ($selectedColumns as $columnKey) {
        $html .= '<td>' . htmlspecialchars(admitted_report_cell_text($r, $columnKey, $counter)) . '</td>';
    }
    $html .= '</tr>';
    $counter++;
}

$html .= '</tbody></table>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output($filenameStem . '.pdf', 'I');
exit;
