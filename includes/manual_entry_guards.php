<?php
/**
 * Shared existence guards for manual-entry forms across the portal.
 */
require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/helpers/academic_structure_helpers.php';

if (!function_exists('guard_student_exists')) {
    /**
     * @return array<string,mixed>|null
     */
    function guard_student_exists(mysqli $db, string $sid): ?array
    {
        return payment_guard_student_exists($db, $sid);
    }
}

if (!function_exists('guard_receipt_not_posted')) {
    function guard_receipt_not_posted(mysqli $db, string $studentId, string $reference): bool
    {
        return !payment_reference_posted($db, $studentId, $reference);
    }
}

if (!function_exists('guard_semester_not_registered')) {
    function guard_semester_not_registered(
        mysqli $db,
        string $studentId,
        string $programCode,
        int $periodNo,
        string $periodType,
        int $yearOfStudy,
        string $academicYear
    ): bool {
        $stmt = $db->prepare(
            'SELECT 1 FROM semester_registration
             WHERE student_id = ? AND program_code = ? AND semester = ? AND period_type = ?
               AND year_of_study = ? AND academic_year = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssisss', $studentId, $programCode, $periodNo, $periodType, $yearOfStudy, $academicYear);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return !$exists;
    }
}

if (!function_exists('guard_exemption_not_applied')) {
    function guard_exemption_not_applied(mysqli $db, string $sid, string $courseCode): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM exemption WHERE Sid = ? AND course_code = ? LIMIT 1');
        if (!$stmt) {
            return true;
        }
        $stmt->bind_param('ss', $sid, $courseCode);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return !$exists;
    }
}

if (!function_exists('guard_staff_assignment_unique')) {
    function guard_staff_assignment_unique(mysqli $db, string $courseCode, string $staffId, int $excludeId = 0): bool
    {
        if ($excludeId > 0) {
            $stmt = $db->prepare('SELECT 1 FROM course_lecturer WHERE course_code = ? AND staff_id = ? AND id <> ? LIMIT 1');
            if (!$stmt) {
                return true;
            }
            $stmt->bind_param('ssi', $courseCode, $staffId, $excludeId);
        } else {
            $stmt = $db->prepare('SELECT 1 FROM course_lecturer WHERE course_code = ? AND staff_id = ? LIMIT 1');
            if (!$stmt) {
                return true;
            }
            $stmt->bind_param('ss', $courseCode, $staffId);
        }
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return !$exists;
    }
}

if (!function_exists('wuc_gate_debug_endpoint')) {
    function wuc_gate_debug_endpoint(): void
    {
        require_once __DIR__ . '/portal_config.php';
        if (!defined('APP_ENV') || APP_ENV !== 'development') {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'This endpoint is only available in development.';
            exit;
        }
    }
}
