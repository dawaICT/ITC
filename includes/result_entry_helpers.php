<?php
require_once __DIR__ . '/academic_risk_engine.php';
require_once __DIR__ . '/finance_guard.php';

/**
 * Shared validation for final result entry.
 *
 * The legacy portal has used both student_courses and course_registration.
 * Result entry must use the active registration source and fail visibly when
 * a student/course/period is not eligible.
 */

if (!function_exists('result_table_exists')) {
    function result_table_exists(mysqli $db, string $table): bool
    {
        if ($res = @$db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")) {
            $ok = $res->num_rows > 0;
            $res->free();
            return $ok;
        }
        return false;
    }
}

if (!function_exists('result_column_exists')) {
    function result_column_exists(mysqli $db, string $table, string $column): bool
    {
        if (!result_table_exists($db, $table)) {
            return false;
        }
        if ($res = @$db->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $db->real_escape_string($column) . "'")) {
            $ok = $res->num_rows > 0;
            $res->free();
            return $ok;
        }
        return false;
    }
}

if (!function_exists('result_cr_year_predicate')) {
    /**
     * SQL predicate matching a course_registration row by CALENDAR academic year.
     * course_registration.Year is the year-of-study; the calendar year lives in
     * academic_year, so prefer that (tolerating NULL for un-backfilled rows) and
     * fall back to Year only when the column is absent. The bound value is always
     * the calendar academic year; exactly one placeholder is emitted.
     */
    function result_cr_year_predicate(mysqli $db, string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        if (result_column_exists($db, 'course_registration', 'academic_year')) {
            return "({$p}academic_year = ? OR {$p}academic_year IS NULL)";
        }
        return "{$p}Year = ?";
    }
}

if (!function_exists('result_fetch_student')) {
    function result_fetch_student(mysqli $db, string $sid): ?array
    {
        if (!result_table_exists($db, 'students')) {
            return null;
        }
        $sidCol = result_column_exists($db, 'students', 'SID') ? 'SID' : (result_column_exists($db, 'students', 'Sid') ? 'Sid' : null);
        if (!$sidCol) {
            return null;
        }
        $stmt = $db->prepare("SELECT * FROM students WHERE `{$sidCol}` COLLATE utf8mb4_general_ci = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('result_student_active')) {
    function result_student_active(?array $student): bool
    {
        if (!$student) {
            return false;
        }
        $status = strtolower(trim((string)($student['status'] ?? 'active')));
        return $status === '' || in_array($status, ['active', 'enrolled', 'registered', 'admitted', 'current'], true);
    }
}

if (!function_exists('result_student_program_codes')) {
    function result_student_program_codes(mysqli $db, string $sid, ?array $student = null): array
    {
        $codes = [];
        foreach (['program', 'program_code'] as $col) {
            if (!empty($student[$col])) {
                $codes[] = trim((string)$student[$col]);
            }
        }

        if (result_table_exists($db, 'student_program') && result_column_exists($db, 'student_program', 'program_code')) {
            $sidCol = result_column_exists($db, 'student_program', 'Sid') ? 'Sid' : (result_column_exists($db, 'student_program', 'SID') ? 'SID' : null);
            if ($sidCol && ($stmt = $db->prepare("SELECT DISTINCT program_code FROM student_program WHERE `{$sidCol}` COLLATE utf8mb4_general_ci = ? AND COALESCE(status, 'active') NOT IN ('inactive','withdrawn','suspended')"))) {
                $stmt->bind_param('s', $sid);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    if (!empty($row['program_code'])) {
                        $codes[] = trim((string)$row['program_code']);
                    }
                }
                $stmt->close();
            }
        }

        return array_values(array_unique(array_filter($codes, fn($v) => $v !== '')));
    }
}

if (!function_exists('result_detect_programme_type')) {
    function result_detect_programme_type(mysqli $db, string $sid, string $courseCode): string
    {
        if (result_table_exists($db, 'short_courses') && result_table_exists($db, 'short_course_enrollments')) {
            $sql = "SELECT sce.id
                    FROM short_course_enrollments sce
                    INNER JOIN short_courses sc ON sc.id = sce.short_course_id
                    WHERE sce.student_id COLLATE utf8mb4_general_ci = ?
                      AND sc.course_code COLLATE utf8mb4_general_ci = ?
                      AND COALESCE(sce.status, 'enrolled') IN ('enrolled','active','completed')
                    LIMIT 1";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('ss', $sid, $courseCode);
                $stmt->execute();
                $stmt->store_result();
                $isShort = $stmt->num_rows > 0;
                $stmt->close();
                if ($isShort) {
                    return 'short_course';
                }
            }
        }
        return 'yearly';
    }
}

if (!function_exists('result_validate_short_course_entry')) {
    function result_validate_short_course_entry(mysqli $db, string $sid, string $courseCode, string $assessmentDate): array
    {
        $student = result_fetch_student($db, $sid);
        if (!$student) {
            return ['ok' => false, 'message' => 'Student not enrolled.', 'type' => 'short_course'];
        }
        if (!result_student_active($student)) {
            return ['ok' => false, 'message' => 'Student is not active/enrolled during this period.', 'type' => 'short_course'];
        }

        if (!result_table_exists($db, 'short_courses') || !result_table_exists($db, 'short_course_enrollments')) {
            return ['ok' => false, 'message' => 'Student not enrolled.', 'type' => 'short_course'];
        }

        $sql = "SELECT sc.*, sce.status AS enrollment_status, sce.enrollment_date AS enrollment_date
                FROM short_course_enrollments sce
                INNER JOIN short_courses sc ON sc.id = sce.short_course_id
                WHERE sce.student_id COLLATE utf8mb4_general_ci = ?
                  AND sc.course_code COLLATE utf8mb4_general_ci = ?
                LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Unable to validate short-course enrollment.', 'type' => 'short_course'];
        }
        $stmt->bind_param('ss', $sid, $courseCode);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$course || !in_array(strtolower((string)($course['enrollment_status'] ?? '')), ['enrolled', 'active', 'completed'], true)) {
            return ['ok' => false, 'message' => 'Student not enrolled.', 'type' => 'short_course'];
        }

        $durationValue = (int)($course['duration_value'] ?? 0);
        $durationUnit  = strtolower((string)($course['duration_unit'] ?? ''));
        $validUnits    = ['days', 'weeks', 'months'];
        if ($durationValue > 0 && !in_array($durationUnit, $validUnits, true)) {
            return ['ok' => false, 'message' => 'Short course has an invalid duration unit.', 'type' => 'short_course'];
        }

        // Establish the assessment window. Prefer the course's explicit dates;
        // when they are not configured, fall back to the student's enrolment date
        // plus the course duration so result entry is not blocked by missing
        // scheduling metadata.
        $start = !empty($course['start_date']) ? strtotime((string)$course['start_date']) : false;
        if (!$start && !empty($course['enrollment_date'])) {
            $start = strtotime((string)$course['enrollment_date']);
        }
        if ($start) {
            // Normalise to start-of-day so a same-day assessment is not rejected
            // by the time component of a timestamp-based enrolment date.
            $start = strtotime(date('Y-m-d', $start));
        }
        $assessmentTs = strtotime($assessmentDate);
        if (!$assessmentTs) {
            return ['ok' => false, 'message' => 'A valid assessment date is required.', 'type' => 'short_course'];
        }

        // Final results are recorded AT OR AFTER a short course ends, so only the
        // lower bound is enforced: a result cannot pre-date the course start (or,
        // when no schedule is configured, the student's enrolment date). The
        // course-end upper bound is deliberately NOT enforced — previously the
        // window was start + duration, which made it impossible to enter results
        // once a short course had finished (e.g. a 10-day course assessed weeks
        // later), the exact case that blocked short-course result entry.
        if ($start && $assessmentTs < $start) {
            return ['ok' => false, 'message' => 'Assessment date is before the short course start date.', 'type' => 'short_course'];
        }

        return ['ok' => true, 'message' => 'Eligible short-course result entry.', 'type' => 'short_course'];
    }
}

if (!function_exists('result_validate_yearly_entry')) {
    function result_validate_yearly_entry(mysqli $db, string $sid, string $courseCode, string $semester, string $year): array
    {
        $student = result_fetch_student($db, $sid);
        if (!$student) {
            return ['ok' => false, 'message' => 'Student not enrolled.', 'type' => 'yearly'];
        }
        if (!result_student_active($student)) {
            return ['ok' => false, 'message' => 'Student is not active.', 'type' => 'yearly'];
        }
        if (!result_table_exists($db, 'course_registration')) {
            return ['ok' => false, 'message' => 'No registered term found.', 'type' => 'yearly'];
        }

        $activeSql = result_column_exists($db, 'course_registration', 'is_active') ? " AND COALESCE(is_active, 1) = 1" : '';
        $yearPred = result_cr_year_predicate($db);
        $yearStmt = $db->prepare("SELECT 1 FROM course_registration WHERE Sid COLLATE utf8mb4_general_ci = ? AND {$yearPred}{$activeSql} LIMIT 1");
        if (!$yearStmt) {
            return ['ok' => false, 'message' => 'Unable to validate academic-year registration.', 'type' => 'yearly'];
        }
        $yearStmt->bind_param('ss', $sid, $year);
        $yearStmt->execute();
        $yearStmt->store_result();
        $hasYear = $yearStmt->num_rows > 0;
        $yearStmt->close();
        if (!$hasYear) {
            return ['ok' => false, 'message' => 'No registered term found.', 'type' => 'yearly'];
        }

        $termStmt = $db->prepare("SELECT 1 FROM course_registration WHERE Sid COLLATE utf8mb4_general_ci = ? AND course_code COLLATE utf8mb4_general_ci = ? AND {$yearPred}{$activeSql} LIMIT 1");
        if (!$termStmt) {
            return ['ok' => false, 'message' => 'Unable to validate academic-year course registration.', 'type' => 'yearly'];
        }
        $termStmt->bind_param('sss', $sid, $courseCode, $year);
        $termStmt->execute();
        $termStmt->store_result();
        $hasTermCourse = $termStmt->num_rows > 0;
        $termStmt->close();
        if (!$hasTermCourse) {
            return ['ok' => false, 'message' => 'Results cannot be entered because the student is not registered for this course in the academic year.', 'type' => 'yearly'];
        }

        if (result_table_exists($db, 'program_courses')) {
            $programCodes = result_student_program_codes($db, $sid, $student);
            if (!empty($programCodes)) {
                $placeholders = implode(',', array_fill(0, count($programCodes), '?'));
                $types = str_repeat('s', count($programCodes) + 1);
                $values = array_merge([$courseCode], $programCodes);
                $sql = "SELECT semester FROM program_courses
                        WHERE course_code COLLATE utf8mb4_general_ci = ?
                          AND program_code COLLATE utf8mb4_general_ci IN ({$placeholders})";
                $stmt = $db->prepare($sql);
                if ($stmt) {
                    $bind = [$types];
                    foreach ($values as $i => $value) {
                        $bind[] = &$values[$i];
                    }
                    call_user_func_array([$stmt, 'bind_param'], $bind);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $hasProgramCourse = false;
                    $hasTermSetup = true;
                    while ($row = $res->fetch_assoc()) {
                        $hasProgramCourse = true;
                    }
                    $stmt->close();
                    if (!$hasProgramCourse) {
                        return ['ok' => false, 'message' => 'Course not assigned to programme.', 'type' => 'yearly'];
                    }
                }
            }
        }

        return ['ok' => true, 'message' => 'Eligible yearly result entry.', 'type' => 'yearly'];
    }
}

if (!function_exists('result_validate_entry')) {
    function result_validate_entry(mysqli $db, string $sid, string $courseCode, string $semester, string $year, ?string $assessmentDate = null): array
    {
        $sid = trim($sid);
        $courseCode = trim($courseCode);
        $semester = trim($semester);
        $year = trim($year);
        $assessmentDate = $assessmentDate ?: date('Y-m-d');

        if ($sid === '' || $courseCode === '' || $semester === '' || $year === '') {
            return ['ok' => false, 'message' => 'All result-entry fields are required.', 'type' => 'unknown'];
        }
        // The academic year is the calendar year (course_registration.academic_year),
        // so a 4-digit year is required.
        if (!preg_match('/^[1-3]$/', $semester) || !preg_match('/^\d{4}$/', $year)) {
            return ['ok' => false, 'message' => 'Invalid academic period selected.', 'type' => 'unknown'];
        }

        $type = result_detect_programme_type($db, $sid, $courseCode);
        if ($type === 'short_course') {
            return result_validate_short_course_entry($db, $sid, $courseCode, $assessmentDate);
        }
        return result_validate_yearly_entry($db, $sid, $courseCode, $semester, $year);
    }
}

if (!function_exists('result_exam_courses_for_period')) {
    function result_exam_courses_for_period(mysqli $db, string $semester, string $year): array
    {
        $courses = [];
        if (result_table_exists($db, 'course_registration')) {
            $activeSql = result_column_exists($db, 'course_registration', 'is_active') ? " AND COALESCE(cr.is_active, 1) = 1" : '';
            $yearPred = result_cr_year_predicate($db, 'cr');
            $sql = "SELECT DISTINCT c.course_code, c.course_name
                    FROM course_registration cr
                    INNER JOIN courses c ON c.course_code COLLATE utf8mb4_general_ci = cr.course_code COLLATE utf8mb4_general_ci
                    WHERE {$yearPred}{$activeSql}
                    ORDER BY c.course_code ASC";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $year);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $courses[] = $row;
                }
                $stmt->close();
            }
        }

        if (empty($courses) && result_table_exists($db, 'student_courses')) {
            $sql = "SELECT DISTINCT c.course_code, c.course_name
                    FROM courses c
                    INNER JOIN student_courses sc ON c.course_code COLLATE utf8mb4_general_ci = sc.course_code COLLATE utf8mb4_general_ci
                    ORDER BY c.course_code ASC";
            if ($res = $db->query($sql)) {
                while ($row = $res->fetch_assoc()) {
                    $courses[] = $row;
                }
                $res->free();
            }
        }

        return $courses;
    }
}

if (!function_exists('result_update_exam_metadata')) {
    function result_update_exam_metadata(mysqli $db, string $sid, string $courseCode, string $semester, string $year, string $programmeType, string $assessmentDate): void
    {
        if (!result_table_exists($db, 'exams')) {
            return;
        }

        $sets = [];
        $types = '';
        $values = [];
        if (result_column_exists($db, 'exams', 'programme_type')) {
            $sets[] = 'programme_type = ?';
            $types .= 's';
            $values[] = $programmeType === 'short_course' ? 'short_course' : 'yearly';
        }
        if (result_column_exists($db, 'exams', 'assessment_date')) {
            $sets[] = 'assessment_date = ?';
            $types .= 's';
            $values[] = $assessmentDate;
        }
        if (empty($sets)) {
            return;
        }

        $sql = 'UPDATE exams SET ' . implode(', ', $sets) . ' WHERE Sid = ? AND Course_Code = ? AND semester = ? AND Year = ?';
        $types .= 'ssss';
        array_push($values, $sid, $courseCode, $semester, $year);
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        $bind = [$types];
        foreach ($values as $i => $value) {
            $bind[] = &$values[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
        $stmt->close();
        wuc_academic_risk_after_student_activity($db, $sid, 'exam_metadata_updated');
    }
}

if (!function_exists('result_students_for_course_period')) {
    function result_students_for_course_period(mysqli $db, string $courseCode, string $semester, string $year): array
    {
        return array_values(array_filter(
            result_students_for_course_period_status($db, $courseCode, $semester, $year),
            static fn(array $row): bool => !empty($row['eligible'])
        ));
    }
}

if (!function_exists('result_students_for_course_period_status')) {
    function result_students_for_course_period_status(mysqli $db, string $courseCode, string $semester, string $year): array
    {
        $students = [];
        if (result_table_exists($db, 'course_registration')) {
            $activeSql = result_column_exists($db, 'course_registration', 'is_active') ? " AND COALESCE(cr.is_active, 1) = 1" : '';
            $yearPred = result_cr_year_predicate($db, 'cr');
            $sql = "SELECT DISTINCT s.SID, s.Fname, s.Lname
                    FROM course_registration cr
                    INNER JOIN students s ON s.SID COLLATE utf8mb4_general_ci = cr.Sid COLLATE utf8mb4_general_ci
                    WHERE cr.course_code COLLATE utf8mb4_general_ci = ?
                      AND {$yearPred}
                      AND COALESCE(s.status, 'active') NOT IN ('inactive','withdrawn','suspended')
                      {$activeSql}
                    ORDER BY s.Lname ASC, s.Fname ASC";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('ss', $courseCode, $year);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $check = result_validate_entry($db, (string)$row['SID'], $courseCode, $semester, $year);
                    $eligible = !empty($check['ok']);
                    $reason = $eligible ? 'Eligible for exam result entry.' : (string)($check['message'] ?? 'Student is not eligible for result entry.');
                    $percent = null;

                    if ($eligible && function_exists('is_student_allowed_exam')) {
                        $payment = is_student_allowed_exam($db, (string)$row['SID'], $year, $semester);
                        $percent = isset($payment['percent']) ? (float)$payment['percent'] : null;
                        if (empty($payment['allowed'])) {
                            $eligible = false;
                            $reason = sprintf(
                                'Full payment is required before exam or test marks can be entered. Current payment: %s%%.',
                                rtrim(rtrim(number_format((float)($payment['percent'] ?? 0), 2), '0'), '.')
                            );
                        }
                    }

                    $row['eligible'] = $eligible;
                    $row['reason'] = $reason;
                    $row['payment_percent'] = $percent;
                    $students[] = $row;
                }
                $stmt->close();
            }
        }

        if (empty($students) && result_table_exists($db, 'student_courses')) {
            $sql = "SELECT s.SID, s.Fname, s.Lname
                    FROM students s
                    INNER JOIN student_courses sc ON s.SID COLLATE utf8mb4_general_ci = sc.student_id COLLATE utf8mb4_general_ci
                    WHERE sc.course_code COLLATE utf8mb4_general_ci = ?
                    ORDER BY s.Lname ASC, s.Fname ASC";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $courseCode);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $row['eligible'] = true;
                    $row['reason'] = 'Eligible for exam result entry.';
                    $row['payment_percent'] = null;
                    $students[] = $row;
                }
                $stmt->close();
            }
        }

        return $students;
    }
}
