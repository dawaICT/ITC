<?php
require_once __DIR__ . '/result_entry_helpers.php';
require_once __DIR__ . '/grading_helpers.php';

if (!function_exists('wuc_exam_upload_infer_period')) {
    function wuc_exam_upload_infer_period(mysqli $db, string $sid, string $courseCode, string $year, ?string $postedPeriod = null): string
    {
        $postedPeriod = trim((string)$postedPeriod);
        if (preg_match('/^[1-3]$/', $postedPeriod)) {
            return $postedPeriod;
        }

        if ($sid === '' || $courseCode === '' || !preg_match('/^\d{4}$/', $year) || !result_table_exists($db, 'course_registration')) {
            return '';
        }

        $activeSql = result_column_exists($db, 'course_registration', 'is_active')
            ? " AND COALESCE(is_active, 1) = 1"
            : '';
        $yearPred = result_cr_year_predicate($db);
        $stmt = $db->prepare(
            "SELECT DISTINCT semester
               FROM course_registration
              WHERE Sid COLLATE utf8mb4_general_ci = ?
                AND course_code COLLATE utf8mb4_general_ci = ?
                AND {$yearPred}
                {$activeSql}
              ORDER BY semester
              LIMIT 2"
        );
        if (!$stmt) {
            return '';
        }

        $stmt->bind_param('sss', $sid, $courseCode, $year);
        $stmt->execute();
        $res = $stmt->get_result();
        $periods = [];
        while ($row = $res->fetch_assoc()) {
            $period = trim((string)($row['semester'] ?? ''));
            if (preg_match('/^[1-3]$/', $period)) {
                $periods[] = $period;
            }
        }
        $stmt->close();

        return count($periods) === 1 ? $periods[0] : '';
    }
}

if (!function_exists('wuc_exam_upload_save_mark')) {
    function wuc_exam_upload_save_mark(
        mysqli $db,
        string $sid,
        string $courseCode,
        $examMarks,
        ?string $semester,
        string $year,
        string $actor,
        ?string $assessmentDate = null
    ): array {
        $sid = trim($sid);
        $courseCode = trim($courseCode);
        $year = trim($year);
        $rawMarks = trim((string)$examMarks);
        $semester = wuc_exam_upload_infer_period($db, $sid, $courseCode, $year, $semester);
        $assessmentDate = $assessmentDate ?: date('Y-m-d');

        if ($sid === '' || $courseCode === '' || $rawMarks === '' || $year === '') {
            return ['ok' => false, 'message' => 'Student ID, course, exam mark, period, and academic year are required.'];
        }
        if (!preg_match('/^\d{4}$/', $year)) {
            return ['ok' => false, 'message' => 'Academic year must be a 4-digit calendar year.'];
        }
        if (!preg_match('/^[1-3]$/', $semester)) {
            return ['ok' => false, 'message' => 'Academic period is required. Select a semester/term or use a course registration with one clear period.'];
        }
        if (!is_numeric($rawMarks)) {
            return ['ok' => false, 'message' => 'Exam mark must be numeric.'];
        }

        $exam = (float)$rawMarks;
        if ($exam < 0 || $exam > 100) {
            return ['ok' => false, 'message' => 'Exam mark must be between 0 and 100.'];
        }

        $eligibility = result_validate_entry($db, $sid, $courseCode, $semester, $year, $assessmentDate);
        if (empty($eligibility['ok'])) {
            return ['ok' => false, 'message' => (string)($eligibility['message'] ?? 'Student is not eligible for result entry.')];
        }

        $programType = (($eligibility['type'] ?? '') === 'short_course') ? 'short_course' : 'semester';
        $save = result_save_exam_mark($db, $sid, $courseCode, $semester, $year, $exam, $programType, $actor);
        $save['semester'] = $semester;
        return $save;
    }
}
?>
