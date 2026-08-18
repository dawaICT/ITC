<?php
/**
 * Shared schema-flexible DB helpers for short-course features.
 *
 * Single definition consumed by both the admissions helpers
 * (admissions/includes/short_course_helpers.php) and the consolidated
 * enrollment action handler (includes/short_course_actions.php), so the two
 * short-course management modules no longer carry divergent copies.
 */

if (!function_exists('sc_identifier')) {
    function sc_identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }
        return '`' . $identifier . '`';
    }
}

if (!function_exists('sc_table_exists')) {
    function sc_table_exists(mysqli $db, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $cache[$table] = $exists;
    }
}

if (!function_exists('sc_columns')) {
    function sc_columns(mysqli $db, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        if (!sc_table_exists($db, $table)) {
            return $cache[$table] = [];
        }

        $columns = [];
        $result = $db->query('SHOW COLUMNS FROM ' . sc_identifier($table));
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }

        return $cache[$table] = $columns;
    }
}

if (!function_exists('sc_has_column')) {
    function sc_has_column(mysqli $db, string $table, string $column): bool
    {
        $columns = sc_columns($db, $table);
        return isset($columns[$column]);
    }
}

if (!function_exists('sc_insert')) {
    function sc_insert(mysqli $db, string $table, array $data): void
    {
        $columns = sc_columns($db, $table);
        $filtered = [];

        foreach ($data as $column => $value) {
            if (isset($columns[$column])) {
                $filtered[$column] = $value;
            }
        }

        if (!$filtered) {
            throw new RuntimeException("No compatible columns found for {$table}.");
        }

        $columnSql = implode(', ', array_map('sc_identifier', array_keys($filtered)));
        $placeholders = implode(', ', array_fill(0, count($filtered), '?'));
        $stmt = $db->prepare('INSERT INTO ' . sc_identifier($table) . " ({$columnSql}) VALUES ({$placeholders})");

        $values = array_values($filtered);
        $bindValues = [str_repeat('s', count($values))];
        foreach ($values as $index => $value) {
            $bindValues[] = &$values[$index];
        }

        $stmt->bind_param(...$bindValues);
        $stmt->execute();
        $stmt->close();
    }
}

if (!defined('SC_MAX_SHORT_COURSE_DAYS')) {
    define('SC_MAX_SHORT_COURSE_DAYS', 183);
}

if (!function_exists('sc_duration_to_days')) {
    function sc_duration_to_days($value, $unit): ?int
    {
        $value = (int)$value;
        if ($value <= 0) {
            return null;
        }

        switch (strtolower(trim((string)$unit))) {
            case 'day':
            case 'days':
                return $value;
            case 'week':
            case 'weeks':
                return $value * 7;
            case 'month':
            case 'months':
                return $value * 30;
            case 'year':
            case 'years':
                return $value * 365;
            default:
                return null;
        }
    }
}

if (!function_exists('sc_is_short_course_duration')) {
    function sc_is_short_course_duration($value, $unit): bool
    {
        $days = sc_duration_to_days($value, $unit);
        return $days !== null && sc_is_short_course_days($days);
    }
}

if (!function_exists('sc_is_short_course_days')) {
    function sc_is_short_course_days($days): bool
    {
        global $db;
        $days = (int)$days;
        
        $maxMonths = 6;
        if (isset($db) && $db instanceof mysqli) {
            require_once __DIR__ . '/academic_settings_helper.php';
            $maxMonths = (int)wuc_get_academic_setting($db, 'short_course_max_duration_months', '6');
        }
        $maxDays = $maxMonths * 30;
        if ($maxMonths === 6) {
            $maxDays = 183; // maintain precise legacy fallback for standard 6 months
        }
        
        return $days > 0 && $days <= $maxDays;
    }
}

/**
 * Canonical marker values for short-course rows written into course_lecturer.
 * Non-NULL so the unique key (staff, course, program, year, semester) works.
 */
if (!defined('SC_LECTURER_PROGRAM_CODE')) {
    define('SC_LECTURER_PROGRAM_CODE', 'SHORT');
}
if (!defined('SC_LECTURER_SEMESTER')) {
    define('SC_LECTURER_SEMESTER', 'SC');
}

if (!function_exists('sc_is_standalone_short_course')) {
    /** True when course_code exists in the standalone short_courses catalogue. */
    function sc_is_standalone_short_course(mysqli $db, string $courseCode): bool
    {
        $courseCode = trim($courseCode);
        if ($courseCode === '' || !sc_table_exists($db, 'short_courses')) {
            return false;
        }
        static $cache = [];
        if (array_key_exists($courseCode, $cache)) {
            return $cache[$courseCode];
        }
        $ok = false;
        if ($stmt = $db->prepare('SELECT 1 FROM short_courses WHERE course_code = ? LIMIT 1')) {
            $stmt->bind_param('s', $courseCode);
            $stmt->execute();
            $stmt->store_result();
            $ok = $stmt->num_rows > 0;
            $stmt->close();
        }
        return $cache[$courseCode] = $ok;
    }
}

if (!function_exists('sc_ensure_courses_mirror')) {
    /**
     * Mirror a short_courses row into the shared courses catalogue so
     * course_lecturer / CA / eLearning joins resolve consistently.
     * courses.course_code is varchar(20) — longer codes are skipped.
     *
     * @param array{course_code?:string,course_name?:string,fee?:float|int|string,status?:string,description?:string} $short
     */
    function sc_ensure_courses_mirror(mysqli $db, array $short): bool
    {
        if (!sc_table_exists($db, 'courses')) {
            return false;
        }
        $code = trim((string)($short['course_code'] ?? ''));
        $name = trim((string)($short['course_name'] ?? ''));
        if ($code === '' || $name === '') {
            return false;
        }
        if (strlen($code) > 20) {
            error_log('sc_ensure_courses_mirror: course_code exceeds courses.course_code(20): ' . $code);
            return false;
        }

        $fee = (float)($short['fee'] ?? 0);
        $status = strtolower(trim((string)($short['status'] ?? 'active')));
        $courseStatus = in_array($status, ['active', 'upcoming'], true) ? 'active' : 'inactive';
        $hasCourseType = sc_has_column($db, 'courses', 'course_type');
        $hasCourseFee = sc_has_column($db, 'courses', 'course_fee');

        $exists = false;
        if ($stmt = $db->prepare('SELECT 1 FROM courses WHERE course_code = ? LIMIT 1')) {
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();
        }

        if ($exists) {
            if ($hasCourseType && $hasCourseFee) {
                $sql = "UPDATE courses SET course_name = ?, course_fee = ?, status = ?, course_type = 'short course' WHERE course_code = ?";
                if ($stmt = $db->prepare($sql)) {
                    $stmt->bind_param('sdss', $name, $fee, $courseStatus, $code);
                    $ok = $stmt->execute();
                    $stmt->close();
                    return $ok;
                }
            }
            if ($stmt = $db->prepare('UPDATE courses SET course_name = ?, status = ? WHERE course_code = ?')) {
                $stmt->bind_param('sss', $name, $courseStatus, $code);
                $ok = $stmt->execute();
                $stmt->close();
                return $ok;
            }
            return false;
        }

        if ($hasCourseType && $hasCourseFee) {
            $sql = "INSERT INTO courses (course_code, course_name, course_fee, status, course_type)
                    VALUES (?, ?, ?, ?, 'short course')";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('ssds', $code, $name, $fee, $courseStatus);
                $ok = $stmt->execute();
                $stmt->close();
                return $ok;
            }
        }

        if ($hasCourseFee) {
            $sql = 'INSERT INTO courses (course_code, course_name, course_fee, status) VALUES (?, ?, ?, ?)';
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('ssds', $code, $name, $fee, $courseStatus);
                $ok = $stmt->execute();
                $stmt->close();
                return $ok;
            }
        }

        return false;
    }
}

if (!function_exists('sc_mirror_short_course_by_id')) {
    function sc_mirror_short_course_by_id(mysqli $db, int $shortCourseId): bool
    {
        if ($shortCourseId <= 0 || !sc_table_exists($db, 'short_courses')) {
            return false;
        }
        if ($stmt = $db->prepare('SELECT course_code, course_name, fee, status FROM short_courses WHERE id = ? LIMIT 1')) {
            $stmt->bind_param('i', $shortCourseId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return sc_ensure_courses_mirror($db, $row);
            }
        }
        return false;
    }
}

if (!function_exists('sc_assign_lecturer_to_short_course')) {
    /**
     * Assign a lecturer to a standalone short course via course_lecturer.
     * Ensures the courses catalogue mirror exists first.
     *
     * @return array{success:bool,message:string}
     */
    function sc_assign_lecturer_to_short_course(mysqli $db, int $shortCourseId, string $staffId, ?string $academicYear = null): array
    {
        $staffId = trim($staffId);
        if ($shortCourseId <= 0 || $staffId === '') {
            return ['success' => false, 'message' => 'Course and lecturer are required.'];
        }
        if (!sc_table_exists($db, 'short_courses') || !sc_table_exists($db, 'course_lecturer')) {
            return ['success' => false, 'message' => 'Short-course assignment tables are not available.'];
        }

        $row = null;
        if ($stmt = $db->prepare('SELECT id, course_code, course_name, fee, status FROM short_courses WHERE id = ? LIMIT 1')) {
            $stmt->bind_param('i', $shortCourseId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if (!$row) {
            return ['success' => false, 'message' => 'Short course not found.'];
        }
        $code = trim((string)$row['course_code']);
        if ($code === '') {
            return ['success' => false, 'message' => 'Short course has no course code.'];
        }
        if (strlen($code) > 20) {
            return ['success' => false, 'message' => 'Course code is too long to assign a lecturer (max 20 chars).'];
        }

        $okStaff = false;
        if ($stmt = $db->prepare('SELECT 1 FROM staff WHERE staff_id = ? LIMIT 1')) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $stmt->store_result();
            $okStaff = $stmt->num_rows > 0;
            $stmt->close();
        }
        if (!$okStaff) {
            return ['success' => false, 'message' => 'Unknown lecturer.'];
        }

        sc_ensure_courses_mirror($db, $row);

        $ay = trim((string)($academicYear ?? ''));
        if ($ay === '') {
            $ay = date('Y');
            if (sc_table_exists($db, 'portal_settings')) {
                if ($st = $db->prepare("SELECT setting_value FROM portal_settings WHERE setting_key = 'current_academic_year' LIMIT 1")) {
                    if ($st->execute()) {
                        $res = $st->get_result();
                        if ($res && ($r = $res->fetch_assoc()) && trim((string)$r['setting_value']) !== '') {
                            $ay = trim((string)$r['setting_value']);
                        }
                    }
                    $st->close();
                }
            }
        }

        $program = SC_LECTURER_PROGRAM_CODE;
        $semester = SC_LECTURER_SEMESTER;

        // Already assigned (any short-course marker or legacy null-context row).
        $exists = false;
        if ($stmt = $db->prepare(
            'SELECT id FROM course_lecturer
             WHERE course_code = ? AND staff_id = ?
               AND (
                    (program_code = ? AND semester = ?)
                    OR COALESCE(program_code, \'\') = \'\'
               )
             LIMIT 1'
        )) {
            $stmt->bind_param('ssss', $code, $staffId, $program, $semester);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($existing) {
                $id = (int)$existing['id'];
                if ($up = $db->prepare(
                    "UPDATE course_lecturer
                     SET program_code = ?, academic_year = ?, semester = ?, status = 'active'
                     WHERE id = ?"
                )) {
                    $up->bind_param('sssi', $program, $ay, $semester, $id);
                    $up->execute();
                    $up->close();
                }
                return ['success' => true, 'message' => 'Lecturer already assigned (assignment refreshed).'];
            }
        }

        try {
            if ($stmt = $db->prepare(
                "INSERT INTO course_lecturer (course_code, staff_id, program_code, academic_year, semester, status)
                 VALUES (?, ?, ?, ?, ?, 'active')"
            )) {
                $stmt->bind_param('sssss', $code, $staffId, $program, $ay, $semester);
                $ok = $stmt->execute();
                $err = $stmt->error;
                $stmt->close();
                if ($ok) {
                    return ['success' => true, 'message' => 'Lecturer assigned. They can now enter CA for this course.'];
                }
                if (str_contains(strtolower($err), 'duplicate')) {
                    return ['success' => false, 'message' => 'That lecturer is already assigned to this course.'];
                }
                return ['success' => false, 'message' => 'Could not assign lecturer.'];
            }
        } catch (Throwable $e) {
            error_log('sc_assign_lecturer_to_short_course: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not assign lecturer.'];
        }

        return ['success' => false, 'message' => 'Could not assign lecturer.'];
    }
}

if (!function_exists('sc_enroll_student_locked')) {
    /**
     * Capacity-safe enrollment insert under a transaction.
     *
     * @return array{success:bool,message:string}
     */
    function sc_enroll_student_locked(mysqli $db, int $courseId, string $studentId, string $staffId, string $notes = ''): array
    {
        $studentId = trim($studentId);
        $staffId = trim($staffId);
        if ($courseId <= 0 || $studentId === '') {
            return ['success' => false, 'message' => 'Course and student are required.'];
        }

        try {
            $db->begin_transaction();

            $capCheck = $db->prepare(
                "SELECT sc.max_capacity,
                        (SELECT COUNT(*) FROM short_course_enrollments
                         WHERE short_course_id = sc.id AND status IN ('enrolled','active')) AS enrolled
                 FROM short_courses sc WHERE sc.id = ? FOR UPDATE"
            );
            if (!$capCheck) {
                throw new RuntimeException('Capacity check failed.');
            }
            $capCheck->bind_param('i', $courseId);
            $capCheck->execute();
            $capRow = $capCheck->get_result()->fetch_assoc();
            $capCheck->close();
            if (!$capRow) {
                $db->rollback();
                return ['success' => false, 'message' => 'Short course not found.'];
            }
            $max = (int)($capRow['max_capacity'] ?? 0);
            $enrolled = (int)($capRow['enrolled'] ?? 0);
            if ($max > 0 && $enrolled >= $max) {
                $db->rollback();
                return ['success' => false, 'message' => 'Course is at full capacity.'];
            }

            $stmt = $db->prepare(
                "INSERT INTO short_course_enrollments (short_course_id, student_id, enrolled_by, notes, status)
                 VALUES (?, ?, ?, ?, 'enrolled')"
            );
            if (!$stmt) {
                throw new RuntimeException('Enrollment insert failed.');
            }
            $stmt->bind_param('isss', $courseId, $studentId, $staffId, $notes);
            if (!$stmt->execute()) {
                $errno = $db->errno;
                $stmt->close();
                $db->rollback();
                return [
                    'success' => false,
                    'message' => $errno === 1062
                        ? 'Student is already enrolled in this course.'
                        : 'Database error while enrolling student.',
                ];
            }
            $stmt->close();
            $db->commit();
            return ['success' => true, 'message' => 'Student enrolled successfully.'];
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $ignored) {}
            error_log('sc_enroll_student_locked: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Enrollment failed. Please try again.'];
        }
    }
}

