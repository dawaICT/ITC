<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../includes/portal_config.php';
require_once __DIR__ . '/../db/connect.php';

$sid = 'CSE26456789';
$errors = [];

$queries = [
    'students+program' => "SELECT s.SID, s.Fname, s.Lname, s.email, s.mobile, s.profile_image, sp.startYear, sp.endYear,
                   sp.program_code, p.program_name, p.program_duration, p.period_mode,
                   p.academic_structure, p.duration_value, p.duration_unit, p.uses_terms, p.uses_semesters, p.is_short_course, p.is_transport_exception, p.examination_type
            FROM students s
            INNER JOIN student_program sp ON s.SID = sp.Sid
            INNER JOIN programs p ON sp.program_code = p.program_code
            LEFT JOIN program_courses pc ON pc.program_code = sp.program_code
            WHERE s.SID = ?
              AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')
              AND COALESCE(p.is_active, 1) = 1
            GROUP BY s.SID, s.Fname, s.Lname, s.email, s.mobile, s.profile_image, sp.id, sp.startYear, sp.endYear,
                     sp.program_code, p.program_name, p.program_duration, p.period_mode, p.study_mode,
                     p.academic_structure, p.duration_value, p.duration_unit, p.uses_terms, p.uses_semesters, p.is_short_course, p.is_transport_exception, p.examination_type
            ORDER BY COUNT(pc.course_code) DESC, sp.id DESC
            LIMIT 1",
];

foreach ($queries as $label => $sql) {
    try {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo "OK: {$label}\n";
    } catch (Throwable $e) {
        echo "FAIL: {$label} -> " . $e->getMessage() . "\n";
        $errors[] = $label;
    }
}

// Tables index.php touches via helpers
$tables = ['announcement', 'semester_registration', 'course_registration', 'payments', 'fee_structure', 'ai_recommendations'];
foreach ($tables as $table) {
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    echo 'table ' . $table . ': ' . ($r && $r->num_rows > 0 ? 'exists' : 'missing') . "\n";
}

exit($errors === [] ? 0 : 1);
