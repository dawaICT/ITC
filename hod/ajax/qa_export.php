<?php
/**
 * Quality Assurance Reports - CSV Export
 */

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/role_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/portal_access.php';
require_once dirname(__DIR__, 2) . '/includes/audit.php';
require_once dirname(__DIR__) . '/includes/hod_schema_helpers.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$isScript = defined('IS_SCRIPT') && IS_SCRIPT;
if (!$isScript && empty($_SESSION['staff_id'])) {
    http_response_code(403);
    exit('Unauthorized access.');
}

if (!$isScript) {
    wuc_require_portal_access($db, 'academic', 'You do not have permission to export Head of Section reports from the current portal.');
    if (!hasRole(ROLE_HEAD_OF_DEPARTMENT) && !isSystemsAdmin()) {
        http_response_code(403);
        exit('You do not have permission to export Head of Section reports.');
    }
    audit_log_current_user($db, 'reports.quality_assurance.export', [
        'format' => 'csv',
    ]);
}

// Scope the export to the courses of this HOS's section, matching
// hod/quality_assurance.php.
$hodStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$deptContext = hod_resolve_department($db, $hodStaffId);
$qaCourseCodes = [];
$qaDeptIds = (array)($deptContext['candidates'] ?? []);
if (empty($qaDeptIds) && (string)$deptContext['id'] !== '') {
    $qaDeptIds = [(string)$deptContext['id']];
}
foreach ($qaDeptIds as $qaDeptId) {
    $qaCourseCodes = array_merge($qaCourseCodes, hod_department_course_codes($db, (string)$qaDeptId, $hodStaffId));
}
$qaCourseCodes = array_values(array_unique(array_filter($qaCourseCodes)));

if (!headers_sent()) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="quality_assurance_report_' . date('Y-m-d') . '.csv"');
}

$output = fopen('php://output', 'w');
fputcsv($output, [
    'Course Code',
    'Course Name',
    'Total Evaluations',
    'Lecturer Rating Avg',
    'Content Rating Avg',
    'Facilities Rating Avg',
    'Support Services Avg',
    'Overall Rating Avg'
]);

if (!empty($qaCourseCodes)) {
    $placeholders = implode(',', array_fill(0, count($qaCourseCodes), '?'));
    $sql = "
        SELECT
            e.course_code,
            c.course_name,
            COUNT(e.id) as evaluations_count,
            AVG(e.rating_lecturer) as avg_lecturer,
            AVG(e.rating_content) as avg_content,
            AVG(e.rating_facilities) as avg_facilities,
            AVG(e.rating_services) as avg_services,
            (AVG(e.rating_lecturer) + AVG(e.rating_content) + AVG(e.rating_facilities) + AVG(e.rating_services)) / 4 as overall_average
        FROM course_evaluations e
        LEFT JOIN courses c ON e.course_code = c.course_code
        WHERE e.course_code IN ({$placeholders})
        GROUP BY e.course_code, c.course_name
        ORDER BY overall_average DESC";

    if ($stmt = @$db->prepare($sql)) {
        $stmt->bind_param(str_repeat('s', count($qaCourseCodes)), ...$qaCourseCodes);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                fputcsv($output, [
                    $row['course_code'],
                    $row['course_name'] ?? 'Unknown',
                    $row['evaluations_count'],
                    round((float)$row['avg_lecturer'], 2),
                    round((float)$row['avg_content'], 2),
                    round((float)$row['avg_facilities'], 2),
                    round((float)$row['avg_services'], 2),
                    round((float)$row['overall_average'], 2)
                ]);
            }
        }
        $stmt->close();
    }
}

fclose($output);
exit;
