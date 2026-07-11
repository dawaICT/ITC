<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema_guard.php';

if (!function_exists('wuc_lecturer_resolved_course_codes')) {
    /**
     * Assigned course codes that exist in the courses catalogue.
     * Matches lecturers/index.php "My Assigned Courses" (INNER JOIN courses,
     * non-inactive rows). Orphan codes in course_lecturer alone are excluded.
     */
    function wuc_lecturer_resolved_course_codes(mysqli $db, string $staffId): array
    {
        $staffId = trim($staffId);
        if ($staffId === '' || !wuc_table_exists($db, 'course_lecturer') || !wuc_table_exists($db, 'courses')) {
            return [];
        }

        $codes = [];
        $sql = "SELECT DISTINCT cl.course_code AS course_code
                FROM course_lecturer cl
                INNER JOIN courses c ON c.course_code = cl.course_code
                WHERE cl.staff_id = ?
                  AND COALESCE(cl.status, 'active') <> 'inactive'
                ORDER BY cl.course_code";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $code = trim((string)($row['course_code'] ?? ''));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
            $stmt->close();
        }

        return $codes;
    }
}

if (!function_exists('wuc_lecturer_setup_notice')) {
    function wuc_lecturer_setup_notice(mysqli $db, string $staffId): string
    {
        $staffId = trim($staffId);
        if ($staffId === '') {
            return '';
        }
        $codes = wuc_lecturer_resolved_course_codes($db, $staffId);
        if ($codes !== []) {
            return '';
        }
        return '<div class="alert alert-info border-0 shadow-sm mb-4">'
            . '<i class="fas fa-info-circle me-2"></i>'
            . '<strong>No courses assigned yet.</strong> '
            . 'Your lecturer dashboard, CA upload, student lists, and course reports will populate '
            . 'after an administrator assigns courses to your account. '
            . 'Contact your Head of Section or the Registrar if you expect assignments.'
            . '</div>';
    }
}
