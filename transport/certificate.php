<?php
declare(strict_types=1);
/**
 * Course-completion certificate for a transport enrolment (§15 / BR015).
 *
 * GET  = read-only: renders an already-issued certificate, or shows an
 *        eligibility/confirmation page when none exists yet.
 * POST = issues the certificate (CSRF-protected), then renders it.
 * Issuing records transport_certificates + transport_enrollments.certificate_issued.
 */
require_once __DIR__ . '/includes/transport.php';  // auth + $db
require_once __DIR__ . '/includes/teveta_helpers.php';
require_once __DIR__ . '/includes/transport_certificates.php'; // §15 gate (tc_can_issue / tc_issue)
require_once __DIR__ . '/includes/document_ui.php';

$enrollmentId = (int)($_POST['enrollment_id'] ?? $_GET['enrollment_id'] ?? 0);
if ($enrollmentId <= 0) {
    tdoc_message_page(400, 'Invalid enrolment', 'No trainee enrolment was specified. Open the certificate from the Assessments or Payments screen.', 'warning');
}

$stmt = $db->prepare("
    SELECT e.id, e.certificate_issued, e.enrollment_date,
           t.first_name, t.last_name, t.student_id,
           c.cohort_name, c.start_date, c.end_date,
           p.program_name, p.program_code, p.license_class, p.required_contact_hours,
           ca.campus_name,
           (SELECT COUNT(*) FROM transport_assessments a WHERE a.enrollment_id = e.id AND a.result='pass') AS passed_count,
           (SELECT COALESCE(SUM(s.contact_hours),0) FROM transport_sessions s WHERE s.cohort_id = c.id AND s.status='completed') AS delivered_hours
    FROM transport_enrollments e
    INNER JOIN transport_trainees t ON t.id = e.trainee_id
    INNER JOIN transport_cohorts c ON c.id = e.cohort_id
    INNER JOIN transport_programs p ON p.id = c.program_id
    LEFT JOIN transport_campuses ca ON ca.id = c.campus_id
    WHERE e.id = ? LIMIT 1
");
$stmt->bind_param('i', $enrollmentId);
$stmt->execute();
$rec = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rec) {
    tdoc_message_page(404, 'Enrolment not found', 'The requested trainee enrolment does not exist. It may have been withdrawn or removed.', 'warning');
}

if (empty($_SESSION['transport_csrf'])) {
    $_SESSION['transport_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['transport_csrf'];
$fullName  = trim($rec['first_name'] . ' ' . $rec['last_name']);

// Already issued -> render the stored certificate on GET or POST, no writes.
$existing = tc_existing_certificate($db, $enrollmentId);
if ($existing) {
    $issue = ['certificate_number' => (string)$existing['certificate_number'], 'reused' => true];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'issue_certificate') {
    // Issuing is a state change: POST + CSRF only.
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        tdoc_message_page(403, 'Security check failed', 'Your session token did not match. Go back, refresh the page, and try issuing the certificate again.', 'danger');
    }
    try {
        $issue = tc_issue($db, $enrollmentId);
    } catch (RuntimeException $e) {
        tdoc_message_page(403, 'Certificate blocked', $e->getMessage(), 'danger');
    }
} else {
    // GET with no certificate yet: show the eligibility / confirmation page.
    $gate = tc_can_issue($db, $enrollmentId);
    $who  = tdoc_h($fullName) . ' (' . tdoc_h($rec['student_id']) . ') &mdash; '
          . tdoc_h($rec['program_name']) . ', cohort ' . tdoc_h($rec['cohort_name']);
    if ($gate['allowed']) {
        $form = '<p style="margin-top:10px;">' . $who . '</p>'
              . '<form method="post" action="/wucportal/transport/certificate.php" style="margin-top:18px;">'
              . '<input type="hidden" name="action" value="issue_certificate">'
              . '<input type="hidden" name="enrollment_id" value="' . (int)$enrollmentId . '">'
              . '<input type="hidden" name="csrf_token" value="' . tdoc_h($csrfToken) . '">'
              . '<button type="submit" class="btn primary-action"><i class="fas fa-certificate" style="margin-right:6px;"></i>Issue certificate</button>'
              . '</form>';
        tdoc_message_page(200, 'Ready to issue certificate',
            'Training is complete and payment is verified. Issuing will assign a permanent certificate number and record it in the register.',
            'success', $form);
    }
    tdoc_message_page(403, 'Certificate not yet available', $gate['reason'], 'warning',
        '<p style="margin-top:10px;">' . $who . '</p>');
}
$ref = $issue['certificate_number'];
$issued = date('j F Y');
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$html = '
<style>
  @page { margin: 0; }
  body { font-family: Helvetica, Arial, sans-serif; color:#1f2a44; }
  .frame { border: 10px solid #1B2A4A; margin: 22px; padding: 40px 50px; text-align:center; }
  .inner { border: 2px solid #C9A24B; padding: 36px 30px; }
  .kicker { letter-spacing: 4px; font-size: 12px; color:#6b7280; text-transform:uppercase; }
  h1 { font-size: 30px; margin: 8px 0 4px; color:#1B2A4A; }
  .sub { color:#6b7280; font-size: 13px; margin-bottom: 26px; }
  .name { font-size: 30px; font-weight: bold; margin: 14px 0; color:#0B1530; }
  .line { width: 60%; margin: 6px auto 18px; border-bottom:1px solid #C9A24B; }
  .body { font-size: 14px; line-height: 1.7; }
  .prog { font-size: 18px; font-weight:bold; color:#1B2A4A; }
  .meta { margin-top: 30px; font-size: 11px; color:#4b5563; }
  .sig { margin-top: 46px; display:flex; }
  .compliance { margin-top: 18px; font-size: 10px; color:#6b7280; }
</style>
<div class="frame"><div class="inner">
  <div class="kicker">ITC Industrial Training Centre</div>
  <h1>Certificate of Completion</h1>
  <div class="sub">Transport Training &amp; Driver Education</div>
  <div class="body">This is to certify that</div>
  <div class="name">' . $h($fullName) . '</div>
  <div class="line"></div>
  <div class="body">has successfully completed the training programme</div>
  <div class="prog">' . $h($rec['program_name']) . ' (' . $h($rec['program_code']) . ')</div>
  <div class="body">' . ($rec['license_class'] ? 'Licence class: ' . $h($rec['license_class']) . '<br>' : '') . '
     Cohort: ' . $h($rec['cohort_name']) . '<br>
     Contact hours delivered: ' . $h(number_format((float)$rec['delivered_hours'], 1)) . 'h' .
     ((float)$rec['required_contact_hours'] > 0 ? ' of ' . $h(number_format((float)$rec['required_contact_hours'], 1)) . 'h required' : '') . '
  </div>
  <table style="width:100%; margin-top:46px;"><tr>
    <td style="text-align:center; font-size:11px; color:#4b5563;">______________________<br>Head of Section</td>
    <td style="text-align:center; font-size:11px; color:#4b5563;">______________________<br>Date: ' . $h($issued) . '</td>
  </tr></table>
  <div class="compliance">Delivered in line with TEVETA driving-instructor curriculum standards and RTSA requirements.</div>
  <div class="meta">Certificate Ref: ' . $h($ref) . ' &nbsp;|&nbsp; Trainee ID: ' . $h($rec['student_id']) . '</div>
</div></div>';

// Try dompdf for a true PDF; fall back to printable HTML if Composer/dompdf is
// unavailable (e.g. this XAMPP runs PHP 8.0 while vendor requires 8.1+, so the
// autoload platform check throws).
$dompdfReady = false;
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    try {
        require_once $autoloadPath;
        $dompdfReady = class_exists('\Dompdf\Dompdf');
    } catch (Throwable $e) {
        error_log('certificate.php: composer autoload unavailable: ' . $e->getMessage());
        $dompdfReady = false;
    }
}

if (!$dompdfReady) {
    if (!headers_sent()) { http_response_code(200); }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Certificate ' . $h($ref) . '</title>'
        . '<link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css">'
        . '<script src="/wucportal/js/wuc-print-fit.js"><\/script>'
        . '<style>@page{size:A4 portrait;margin:12mm}@media print{.noprint{display:none!important}body{background:#fff;margin:0;padding:0}}</style></head>'
        . '<body class="single-page-document no-auto-print">'
        . '<div class="noprint" style="text-align:center;font-family:Arial;padding:10px;background:#f1f1f1;">'
        . 'Use your browser\'s Print &rarr; Save as PDF to export this certificate.</div>'
        . '<div class="wuc-a4-sheet">' . $html . '</div>'
        . '<script>window.addEventListener("load",function(){if(window.wucPrintSinglePage){wucPrintSinglePage();}});<\/script>'
        . '</body></html>';
    exit;
}

try {
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Helvetica');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('WUC_Transport_Certificate_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ref) . '.pdf', ['Attachment' => false]);
    exit;
} catch (Throwable $e) {
    error_log('certificate.php: dompdf render failed: ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(200); }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Certificate ' . $h($ref) . '</title></head>'
        . '<body onload="setTimeout(function(){window.print();},300)">' . $html . '</body></html>';
    exit;
}
