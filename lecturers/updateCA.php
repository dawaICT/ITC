<?php
/**
 * Update CA Marks — POST handler
 * Receives form data and updates semester_assessment using prepared statements.
 */
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';
require_once dirname(__DIR__) . '/includes/ca_helpers.php';

ca_ensure_schema($db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['update'])) {
    wuc_safe_redirect('upload_ca.php');
}

$Sid         = wuc_input('Sid');
$Course_Code = wuc_input('Course_Code');
$A1          = floatval(wuc_input('A1', '0'));
$A2          = floatval(wuc_input('A2', '0'));
$T1          = floatval(wuc_input('T1', '0'));
$T2          = floatval(wuc_input('T2', '0'));
$semester    = wuc_input('semester');
$Year        = wuc_input('Year');

// Validate required fields
if ($Sid === '' || $Course_Code === '' || $semester === '' || $Year === '') {
    wuc_flash('danger', 'All fields are required to update CA marks.');
    wuc_safe_redirect('upload_ca.php');
}

// Year must be the 4-digit calendar academic year, matching the rest of the CA flow.
$periodCheck = ca_validate_period($semester, $Year);
if (!$periodCheck['ok']) {
    wuc_flash('danger', $periodCheck['message']);
    wuc_safe_redirect('upload_ca.php');
}

foreach (['A1' => $A1, 'A2' => $A2, 'T1' => $T1, 'T2' => $T2] as $label => $mark) {
    if ($mark < 0 || $mark > 100) {
        wuc_flash('danger', $label . ' must be between 0 and 100.');
        wuc_safe_redirect('upload_ca.php');
    }
}

// Enforce 50% fee payment rule
$elig = is_student_allowed_ca($db, $Sid, $Year, $semester);
if (!$elig['allowed']) {
    wuc_flash('warning', 'Student ' . $Sid . ' is not eligible: ' . round($elig['percent'], 1) . '% paid (minimum 50% required).');
    wuc_safe_redirect('upload_ca.php');
}

$save = ca_save_components($db, $Sid, $Course_Code, $semester, $Year, 'manual', [
    'A1' => $A1,
    'A2' => $A2,
    'T1' => $T1,
    'T2' => $T2,
    'Exam' => null,
], $_SESSION['staff_id'] ?? '');

if ($save['ok']) {
    wuc_flash('success', 'CA marks for student ' . $Sid . ' updated successfully. Total CA: ' . $save['total_ca'] . '%.');
} else {
    wuc_flash('danger', $save['message']);
}

wuc_safe_redirect('upload_ca.php');
