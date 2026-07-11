<?php
/**
 * Payment Verification Helper Functions
 * Verifies student payment status and calculates payment percentage
 */

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/finance_guard.php';

function payment_verification_table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    if ($res = $db->query("SHOW TABLES LIKE '{$safe}'")) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
}

function payment_verification_column_exists(mysqli $db, string $table, string $column): bool {
    if (!payment_verification_table_exists($db, $table)) {
        return false;
    }
    $safe = $db->real_escape_string($column);
    if ($res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safe}'")) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
}

/**
 * Get student's payment percentage for current academic year/semester
 * 
 * @param mysqli $db Database connection
 * @param string $student_id Student ID
 * @param int $academic_year Academic year (optional, defaults to current)
 * @param int $semester Semester (optional, defaults to current)
 * @return array ['percentage' => float, 'total_paid' => float, 'total_due' => float, 'eligible' => bool]
 */
function getStudentPaymentPercentage($db, $student_id, $academic_year = null, $semester = null) {
    // Default to current academic year and semester if not provided
    if ($academic_year === null) {
        $current_month = (int)date('n');
        $academic_year = (int)date('Y');
    }
    
    if ($semester === null) {
        $current_month = (int)date('n');
        $semester = ($current_month >= 1 && $current_month <= 6) ? 1 : 2;
    }
    
    $total_due = wuc_get_student_term_due($db, (string)$student_id, (string)$academic_year, (string)$semester);
    $total_paid = wuc_get_student_term_paid($db, (string)$student_id, (string)$academic_year, (string)$semester);
    
    // Calculate percentage
    $percentage = 0;
    if ($total_due > 0) {
        $percentage = ($total_paid / $total_due) * 100;
    } else if ($total_paid > 0) {
        // If no invoice but has payments, consider as 100%
        $percentage = 100;
    } else {
        // No configured payable amount should not block access.
        $percentage = 100;
    }
    
    return [
        'percentage' => round($percentage, 2),
        'total_paid' => $total_paid,
        'total_due' => $total_due,
        'eligible' => $percentage >= 50.00 // Default 50% threshold
    ];
}

/**
 * Check if student is registered for a course
 * 
 * @param mysqli $db Database connection
 * @param string $student_id Student ID
 * @param string $course_code Course code
 * @return bool True if registered
 */
// Renamed from isStudentRegisteredForCourse to avoid a fatal name collision with
// the general-purpose isStudentRegisteredForCourse() in includes/auth.php (which
// has a different signature). This finance-module helper keeps the module prefix
// used by the other payment_verification_* helpers in this file.
function payment_verification_is_student_registered($db, $student_id, $course_code) {
    $is_registered = false;

    if (payment_verification_table_exists($db, 'course_registration')) {
        $sidCol = payment_verification_column_exists($db, 'course_registration', 'Sid') ? 'Sid' : (payment_verification_column_exists($db, 'course_registration', 'student_id') ? 'student_id' : null);
        $courseCol = payment_verification_column_exists($db, 'course_registration', 'course_code') ? 'course_code' : null;
        if ($sidCol && $courseCol) {
            $stmt = $db->prepare("SELECT 1 FROM course_registration WHERE `{$sidCol}` = ? AND `{$courseCol}` = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ss', $student_id, $course_code);
                $stmt->execute();
                $stmt->store_result();
                $is_registered = $stmt->num_rows > 0;
                $stmt->close();
            }
        }
    }

    if (!$is_registered && payment_verification_table_exists($db, 'student_courses')) {
        $sidCol = payment_verification_column_exists($db, 'student_courses', 'student_id') ? 'student_id' : (payment_verification_column_exists($db, 'student_courses', 'Sid') ? 'Sid' : null);
        $courseCol = payment_verification_column_exists($db, 'student_courses', 'course_code') ? 'course_code' : null;
        if ($sidCol && $courseCol) {
            $stmt = $db->prepare("SELECT 1 FROM student_courses WHERE `{$sidCol}` = ? AND `{$courseCol}` = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ss', $student_id, $course_code);
                $stmt->execute();
                $stmt->store_result();
                $is_registered = $stmt->num_rows > 0;
                $stmt->close();
            }
        }
    }
    
    return $is_registered;
}

/**
 * Verify student's access to a live session
 * 
 * @param mysqli $db Database connection
 * @param string $student_id Student ID
 * @param int $session_id Session ID
 * @param float $min_payment_percentage Minimum payment percentage required (default 50%)
 * @return array ['allowed' => bool, 'reason' => string, 'payment_info' => array]
 */
function verifySessionAccess($db, $student_id, $session_id, $min_payment_percentage = 50.00) {
    // Get session details
    $stmt = $db->prepare("
        SELECT course_code, access_requires_payment, min_payment_percentage 
        FROM lms_sessions 
        WHERE id = ? 
        LIMIT 1
    ");
    $stmt->bind_param('i', $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        return [
            'allowed' => false,
            'reason' => 'Session not found',
            'payment_info' => null
        ];
    }
    
    $session = $result->fetch_assoc();
    $stmt->close();
    
    // Check if student is registered for this course
    if (!payment_verification_is_student_registered($db, $student_id, $session['course_code'])) {
        return [
            'allowed' => false,
            'reason' => 'Not registered for this course',
            'payment_info' => null
        ];
    }
    
    // If payment is not required, grant access
    if ($session['access_requires_payment'] == 0) {
        return [
            'allowed' => true,
            'reason' => 'Access granted (no payment required)',
            'payment_info' => null
        ];
    }
    
    // Check payment status
    $payment_info = getStudentPaymentPercentage($db, $student_id);
    $required_percentage = $session['min_payment_percentage'] ?? $min_payment_percentage;
    
    if ($payment_info['percentage'] >= $required_percentage) {
        return [
            'allowed' => true,
            'reason' => "Access granted ({$payment_info['percentage']}% paid)",
            'payment_info' => $payment_info
        ];
    } else {
        return [
            'allowed' => false,
            'reason' => "Insufficient payment ({$payment_info['percentage']}% paid, {$required_percentage}% required)",
            'payment_info' => $payment_info
        ];
    }
}

/**
 * Log session access attempt
 * 
 * @param mysqli $db Database connection
 * @param int $session_id Session ID
 * @param string $student_id Student ID
 * @param bool $access_granted Whether access was granted
 * @param string $denial_reason Reason if access denied
 * @param float $payment_percentage Student's payment percentage
 */
function logSessionAccess($db, $session_id, $student_id, $access_granted, $denial_reason = null, $payment_percentage = null) {
    $stmt = $db->prepare("
        INSERT INTO lms_session_access_log 
        (session_id, student_id, access_granted, denial_reason, payment_percentage) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $access_int = $access_granted ? 1 : 0;
    $stmt->bind_param('isisd', $session_id, $student_id, $access_int, $denial_reason, $payment_percentage);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get payment summary for display
 * 
 * @param array $payment_info Payment information from getStudentPaymentPercentage()
 * @return string HTML formatted payment summary
 */
function formatPaymentSummary($payment_info) {
    $percentage = $payment_info['percentage'];
    $badge_class = $percentage >= 50 ? 'success' : 'danger';
    
    $html = '<div class="payment-summary">';
    $html .= '<span class="badge bg-' . $badge_class . '">' . number_format($percentage, 1) . '% Paid</span>';
    $html .= '<div class="mt-2 small">';
    $html .= 'Paid: K ' . number_format($payment_info['total_paid'], 2) . ' / ';
    $html .= 'Due: K ' . number_format($payment_info['total_due'], 2);
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}
