<?php
/**
 * CA Upload Permission Check
 * ─────────────────────────────────────────────────────────────
 * Verifies that the logged-in lecturer is assigned to the course
 * they are attempting to upload CA marks for.
 *
 * This file is included (required) inside CSV processing loops.
 * Variables expected: $staff_id, $Course_Code, $db
 */

// Ensure guard has been loaded (provides $db and session)
if (!isset($db) || !($db instanceof mysqli)) {
    require_once __DIR__ . '/includes/guard.php';
}

// Get the values safely
$_perm_staff_id   = isset($_SESSION['staff_id']) ? $_SESSION['staff_id'] : '';
$_perm_course_code = '';

// The variable $Course_Code may come from the CSV processing loop
if (isset($Course_Code) && $Course_Code !== '') {
    $_perm_course_code = $Course_Code;
} elseif (isset($_POST['Course_Code'])) {
    $_perm_course_code = trim($_POST['Course_Code']);
} elseif (isset($_GET['Course_Code'])) {
    $_perm_course_code = trim($_GET['Course_Code']);
}

// Use prepared statement instead of string concatenation
$_perm_is_assigned = false;
if ($_perm_staff_id !== '' && $_perm_course_code !== '') {
    $stmt = $db->prepare("SELECT staff_id FROM course_lecturer WHERE staff_id = ? AND course_code = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ss', $_perm_staff_id, $_perm_course_code);
        $stmt->execute();
        $result = $stmt->get_result();
        $_perm_is_assigned = ($result && $result->num_rows > 0);
        $stmt->close();
    }
}

if (!$_perm_is_assigned) {
    echo '<div class="alert alert-danger m-3">'
       . '<i class="fas fa-exclamation-circle me-2"></i>'
       . 'Access Denied: Course <strong>' . htmlspecialchars($_perm_course_code) . '</strong> '
       . 'is not assigned to you. Please verify the course code or contact the administrator.'
       . '</div>';
    echo '<script>setTimeout(function(){ window.location.href="upload_ca.php"; }, 3000);</script>';
    die();
}

// Clean up temp variables to avoid polluting the caller's scope
unset($_perm_staff_id, $_perm_course_code, $_perm_is_assigned);
