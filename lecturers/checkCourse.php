<?php
/**
 * Check Course — Fee eligibility check for students viewing exam results
 * ─────────────────────────────────────────────────────────────────────
 * Legacy file: Used to restrict access to exam results based on payment.
 * Now uses prepared statements and the centralized finance guard.
 *
 * Expected variables: $db, $semester, $Year, $_SESSION['Sid']
 */

if (!isset($db) || !($db instanceof mysqli)) {
    require_once dirname(__DIR__) . '/db/connect.php';
}

// Load finance guard for fee checking
require_once dirname(__DIR__) . '/includes/finance_guard.php';

$studentSid = $_SESSION['Sid'] ?? '';
$checkSemester = $semester ?? '';
$checkYear = $Year ?? '';

if ($studentSid !== '' && $checkSemester !== '' && $checkYear !== '') {
    // Use centralized fee check instead of raw SQL
    $elig = is_student_allowed_ca($db, $studentSid, $checkYear, $checkSemester);
    
    if (!$elig['allowed']) {
        echo '<div class="alert alert-danger m-3">'
           . '<i class="fas fa-exclamation-triangle me-2"></i>'
           . '<strong>Access Restricted:</strong> Exam results are withheld due to outstanding tuition fees. '
           . 'You have paid ' . round($elig['percent'], 1) . '% (minimum 50% required).'
           . '</div>';
        echo '<script>setTimeout(function(){ window.location.href="exams.php"; }, 4000);</script>';
        die();
    }
}
?>