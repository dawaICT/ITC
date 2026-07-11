<?php
declare(strict_types=1);

require_once __DIR__ . '/assessment_weighting_helpers.php';

// Period-type resolver (term vs semester vs short course) — reused so the report
// can label "Term N" vs "Semester N" per the programme definition. Guarded include
// so a missing helper degrades gracefully (labels fall back to "Semester N").
$wuc_spr_period_helper = __DIR__ . '/../students/includes/period_mode_helper.php';
if (is_file($wuc_spr_period_helper)) {
    require_once $wuc_spr_period_helper;
}
unset($wuc_spr_period_helper);

if (!function_exists('spr_table_exists')) {
    function spr_table_exists(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        $res = $db->query("SHOW TABLES LIKE '{$safe}'");
        if ($res) {
            $exists = $res->num_rows > 0;
            $res->free();
            return $exists;
        }
        error_log("spr_table_exists query failed: " . $db->error);
        return false;
    }
}

if (!function_exists('spr_columns')) {
    function spr_columns(mysqli $db, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = [];
        if (spr_table_exists($db, $table)) {
            $res = $db->query("SHOW COLUMNS FROM `{$table}`");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
                }
                $res->free();
            } else {
                error_log("spr_columns query failed for table {$table}: " . $db->error);
            }
        }
        return $cache[$table] = $columns;
    }
}

if (!function_exists('spr_first_col')) {
    function spr_first_col(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($columns[$key])) {
                return $columns[$key];
            }
        }
        return null;
    }
}

if (!function_exists('spr_select_col')) {
    function spr_select_col(array $columns, array $candidates, string $alias, string $tableAlias, string $fallback = 'NULL'): string
    {
        $column = spr_first_col($columns, $candidates);
        if ($column === null) {
            return "{$fallback} AS `{$alias}`";
        }
        return "{$tableAlias}.`{$column}` AS `{$alias}`";
    }
}

if (!function_exists('spr_bind')) {
    function spr_bind(mysqli_stmt $stmt, string $types, array &$params): void
    {
        if ($types === '') {
            return;
        }
        $refs = [$types];
        foreach ($params as $idx => &$value) {
            $refs[] = &$params[$idx];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('spr_grade')) {
    function spr_grade(float $total): string
    {
        if ($total >= 90) return 'A+';
        if ($total >= 80) return 'A';
        if ($total >= 75) return 'B+';
        if ($total >= 70) return 'B';
        if ($total >= 65) return 'B-';
        if ($total >= 60) return 'C+';
        if ($total >= 50) return 'C';
        if ($total >= 45) return 'D';
        return 'E';
    }
}

if (!function_exists('spr_is_inactive_status')) {
    function spr_is_inactive_status($status): bool
    {
        $value = strtolower(trim((string)$status));
        if ($value === '') {
            return false;
        }
        return in_array($value, [
            'inactive',
            'suspended',
            'withdrawn',
            'deferred',
            'disabled',
            'blocked',
            'dropped',
            'dropout',
            'terminated',
        ], true);
    }
}

if (!function_exists('spr_course_key')) {
    function spr_course_key(string $sid, string $courseCode, string $academicYear = '', $semester = ''): string
    {
        return strtoupper(trim($sid)) . '|' . strtoupper(trim($courseCode)) . '|' . strtoupper(trim((string)$academicYear)) . '|' . strtoupper(trim((string)$semester));
    }
}

if (!function_exists('spr_latest_rank')) {
    function spr_latest_rank(array $row): array
    {
        return [
            (int)($row['year_of_study'] ?? 0),
            (int)($row['semester'] ?? 0),
            (int)($row['source_id'] ?? 0),
        ];
    }
}

if (!function_exists('spr_is_later')) {
    function spr_is_later(array $candidate, array $current): bool
    {
        $a = spr_latest_rank($candidate);
        $b = spr_latest_rank($current);
        return $a[0] !== $b[0] ? $a[0] > $b[0] : ($a[1] !== $b[1] ? $a[1] > $b[1] : $a[2] > $b[2]);
    }
}

if (!function_exists('spr_assigned_courses')) {
    function spr_assigned_courses(mysqli $db, string $staffId): array
    {
        if ($staffId === '' || !spr_table_exists($db, 'course_lecturer')) {
            return [];
        }

        $cols = spr_columns($db, 'course_lecturer');
        $staffCol = spr_first_col($cols, ['staff_id', 'lecturer_id', 'staffid', 'staff']);
        $courseCol = spr_first_col($cols, ['course_code', 'code', 'course']);
        if ($staffCol === null || $courseCol === null) {
            return [];
        }

        $statusCol = spr_first_col($cols, ['status', 'state']);
        $statusSql = $statusCol ? " AND LOWER(COALESCE(`{$statusCol}`, 'active')) IN ('active','assigned','current')" : '';
        $sql = "SELECT DISTINCT UPPER(TRIM(`{$courseCol}`)) AS course_code
                FROM course_lecturer
                WHERE `{$staffCol}` = ?{$statusSql}";
        $codes = [];
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            if ($res = $stmt->get_result()) {
                while ($row = $res->fetch_assoc()) {
                    $code = trim((string)($row['course_code'] ?? ''));
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
                $res->free();
            }
            $stmt->close();
        } else {
            error_log("spr_assigned_courses prepare failed: " . $db->error);
        }
        return array_keys($codes);
    }
}

if (!function_exists('spr_empty_row')) {
    function spr_empty_row(string $sid, string $courseCode): array
    {
        return [
            'student_id' => $sid,
            'student_name' => '',
            'student_status' => '',
            'program_code' => '',
            'program_status' => '',
            'period_mode' => '',
            'course_code' => strtoupper(trim($courseCode)),
            'course_name' => '',
            'academic_year' => '',
            'year_of_study' => null,
            'semester' => null,
            'course_registration_active' => null,
            'total_ca' => null,
            'total_exam' => null,
            'total_score' => null,
            'grade_letter' => '',
            'failed' => false,
            'inactive' => false,
            'inactive_reasons' => [],
            'flags' => [],
            'latest_source' => '',
            'source_id' => 0,
        ];
    }
}

if (!function_exists('spr_normalize_academic_year')) {
    function spr_normalize_academic_year(string $val): string
    {
        $val = trim($val);
        if ($val === '') {
            return '';
        }
        // Canonical form is the 4-digit start year ("2026"), whether input is "2026" or "2026/2027"
        if (preg_match('/^(\d{4})(\/\d{4})?$/', $val, $m)) {
            return $m[1];
        }
        return $val;
    }
}

if (!function_exists('spr_normalize_period')) {
    function spr_normalize_period(string $val): string
    {
        $val = trim(strtolower($val));
        if ($val === '') return '';
        if (strpos($val, 'semester') !== false || strpos($val, 'term') !== false) {
            if (preg_match('/\d+/', $val, $m)) {
                return $m[0];
            }
        }
        if ($val === 'short course' || $val === 'short course period' || $val === 'short_course') {
            return 'short_course';
        }
        if (preg_match('/^[123]$/', $val)) {
            return $val;
        }
        return $val;
    }
}

if (!function_exists('spr_merge_base')) {
    function spr_merge_base(array &$rows, array $base): void
    {
        $sid = trim((string)($base['student_id'] ?? ''));
        $course = strtoupper(trim((string)($base['course_code'] ?? '')));
        if ($sid === '' || $course === '') {
            return;
        }
        $ay = spr_normalize_academic_year((string)($base['academic_year'] ?? ''));
        $sem = spr_normalize_period((string)($base['semester'] ?? ''));

        $key = spr_course_key($sid, $course, $ay, $sem);
        if (!isset($rows[$key])) {
            $rows[$key] = spr_empty_row($sid, $course);
            $rows[$key]['academic_year'] = $ay;
            $rows[$key]['semester'] = $sem;
        }

        foreach ([
            'student_name',
            'student_status',
            'program_code',
            'program_status',
            'course_name',
            'academic_year',
            'year_of_study',
            'semester',
            'course_registration_active',
        ] as $field) {
            if (($rows[$key][$field] === '' || $rows[$key][$field] === null) && isset($base[$field]) && $base[$field] !== '') {
                if ($field === 'academic_year') {
                    $rows[$key][$field] = spr_normalize_academic_year((string)$base[$field]);
                } elseif ($field === 'semester') {
                    $rows[$key][$field] = spr_normalize_period((string)$base[$field]);
                } else {
                    $rows[$key][$field] = $base[$field];
                }
            }
        }

        $inactiveReasons = [];
        // NOTE: course_registration.is_active = 0 means the student is NOT
        // registered for that course (dropped/deactivated). Courses with no
        // active registration are filtered out before merging (see
        // student_progression_report()), so an inactive registration is never a
        // progression "alert" on its own — only inactive student/programme
        // records are.
        if (spr_is_inactive_status($base['student_status'] ?? '')) {
            $inactiveReasons[] = 'Inactive student record';
        }
        if (spr_is_inactive_status($base['program_status'] ?? '')) {
            $inactiveReasons[] = 'Inactive programme record';
        }

        if ($inactiveReasons) {
            $rows[$key]['inactive'] = true;
            $rows[$key]['inactive_reasons'] = array_values(array_unique(array_merge($rows[$key]['inactive_reasons'], $inactiveReasons)));
        }
    }
}

if (!function_exists('spr_registration_rows')) {
    function spr_registration_rows(mysqli $db): array
    {
        if (!spr_table_exists($db, 'course_registration')) {
            return [];
        }

        $cr = spr_columns($db, 'course_registration');
        $sidCol = spr_first_col($cr, ['Sid', 'student_id', 'SID', 'student']);
        $courseCol = spr_first_col($cr, ['course_code', 'Course_Code', 'course']);
        if ($sidCol === null || $courseCol === null) {
            return [];
        }

        $students = spr_columns($db, 'students');
        $sp = spr_columns($db, 'student_program');
        $courses = spr_columns($db, 'courses');

        $studentJoin = '';
        $studentSelect = [
            "'' AS student_name",
            "'' AS student_status",
        ];
        $studentSidCol = spr_first_col($students, ['SID', 'Sid', 'student_id']);
        if ($studentSidCol !== null) {
            $fname = spr_first_col($students, ['Fname', 'first_name']);
            $lname = spr_first_col($students, ['Lname', 'last_name', 'surname']);
            $status = spr_first_col($students, ['status', 'student_status']);
            $nameExpr = "TRIM(CONCAT(" . ($fname ? "COALESCE(s.`{$fname}`, '')" : "''") . ", ' ', " . ($lname ? "COALESCE(s.`{$lname}`, '')" : "''") . ")) AS student_name";
            $studentSelect = [
                $nameExpr,
                $status ? "s.`{$status}` AS student_status" : "'' AS student_status",
            ];
            $studentJoin = " LEFT JOIN students s ON UPPER(TRIM(s.`{$studentSidCol}`)) = UPPER(TRIM(cr.`{$sidCol}`))";
        }

        $spJoin = '';
        $spSelect = ["'' AS program_code", "'' AS program_status"];
        $spSidCol = spr_first_col($sp, ['Sid', 'SID', 'student_id']);
        if ($spSidCol !== null) {
            $spIdCol = spr_first_col($sp, ['id']);
            $spProgramCol = spr_first_col($sp, ['program_code', 'programme_code', 'program']);
            $spStatusCol = spr_first_col($sp, ['status', 'student_status']);
            if ($spIdCol !== null) {
                $spJoin = " LEFT JOIN student_program sp ON sp.`{$spIdCol}` = (
                    SELECT MAX(sp2.`{$spIdCol}`) FROM student_program sp2
                    WHERE UPPER(TRIM(sp2.`{$spSidCol}`)) = UPPER(TRIM(cr.`{$sidCol}`))
                )";
            } else {
                $spJoin = " LEFT JOIN student_program sp ON UPPER(TRIM(sp.`{$spSidCol}`)) = UPPER(TRIM(cr.`{$sidCol}`))";
            }
            $spSelect = [
                $spProgramCol ? "sp.`{$spProgramCol}` AS program_code" : "'' AS program_code",
                $spStatusCol ? "sp.`{$spStatusCol}` AS program_status" : "'' AS program_status",
            ];
        }

        $courseJoin = '';
        $courseNameSelect = "'' AS course_name";
        $courseCodeCol = spr_first_col($courses, ['course_code', 'Course_Code', 'code']);
        $courseNameCol = spr_first_col($courses, ['course_name', 'name', 'title']);
        if ($courseCodeCol !== null) {
            $courseJoin = " LEFT JOIN courses c ON UPPER(TRIM(c.`{$courseCodeCol}`)) = UPPER(TRIM(cr.`{$courseCol}`))";
            if ($courseNameCol !== null) {
                $courseNameSelect = "c.`{$courseNameCol}` AS course_name";
            }
        }

        $select = array_merge([
            "cr.`{$sidCol}` AS student_id",
            "cr.`{$courseCol}` AS course_code",
            spr_select_col($cr, ['id'], 'source_id', 'cr', '0'),
            spr_select_col($cr, ['academic_year'], 'academic_year', 'cr', "''"),
            spr_select_col($cr, ['Year', 'year', 'year_of_study'], 'year_of_study', 'cr', 'NULL'),
            spr_select_col($cr, ['semester', 'term'], 'semester', 'cr', 'NULL'),
            spr_select_col($cr, ['is_active', 'active'], 'course_registration_active', 'cr', '1'),
            $courseNameSelect,
        ], $studentSelect, $spSelect);

        $rows = [];
        $sql = 'SELECT ' . implode(', ', $select) . " FROM course_registration cr{$studentJoin}{$spJoin}{$courseJoin}";
        $res = $db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        } else {
            error_log('student_progression_report registration query failed: ' . $db->error);
        }
        return $rows;
    }
}

if (!function_exists('spr_exam_rows')) {
    function spr_exam_rows(mysqli $db): array
    {
        if (!spr_table_exists($db, 'exams')) {
            return [];
        }

        $ex = spr_columns($db, 'exams');
        $sidCol = spr_first_col($ex, ['Sid', 'SID', 'student_id', 'student']);
        $courseCol = spr_first_col($ex, ['Course_Code', 'course_code', 'course']);
        if ($sidCol === null || $courseCol === null) {
            return [];
        }

        $students = spr_columns($db, 'students');
        $courses = spr_columns($db, 'courses');
        $sp = spr_columns($db, 'student_program');
        $sa = spr_columns($db, 'semester_assessment');

        $studentJoin = '';
        $studentSelect = ["'' AS student_name", "'' AS student_status"];
        $studentSidCol = spr_first_col($students, ['SID', 'Sid', 'student_id']);
        if ($studentSidCol !== null) {
            $fname = spr_first_col($students, ['Fname', 'first_name']);
            $lname = spr_first_col($students, ['Lname', 'last_name', 'surname']);
            $status = spr_first_col($students, ['status', 'student_status']);
            $studentJoin = " LEFT JOIN students s ON UPPER(TRIM(s.`{$studentSidCol}`)) = UPPER(TRIM(e.`{$sidCol}`))";
            $studentSelect = [
                "TRIM(CONCAT(" . ($fname ? "COALESCE(s.`{$fname}`, '')" : "''") . ", ' ', " . ($lname ? "COALESCE(s.`{$lname}`, '')" : "''") . ")) AS student_name",
                $status ? "s.`{$status}` AS student_status" : "'' AS student_status",
            ];
        }

        $courseJoin = '';
        $courseNameSelect = "'' AS course_name";
        $courseCodeCol = spr_first_col($courses, ['course_code', 'Course_Code', 'code']);
        $courseNameCol = spr_first_col($courses, ['course_name', 'name', 'title']);
        if ($courseCodeCol !== null) {
            $courseJoin = " LEFT JOIN courses c ON UPPER(TRIM(c.`{$courseCodeCol}`)) = UPPER(TRIM(e.`{$courseCol}`))";
            if ($courseNameCol !== null) {
                $courseNameSelect = "c.`{$courseNameCol}` AS course_name";
            }
        }

        $spJoin = '';
        $spSelect = ["'' AS program_code", "'' AS program_status"];
        $spSidCol = spr_first_col($sp, ['Sid', 'SID', 'student_id']);
        if ($spSidCol !== null) {
            $spIdCol = spr_first_col($sp, ['id']);
            $spProgramCol = spr_first_col($sp, ['program_code', 'programme_code', 'program']);
            $spStatusCol = spr_first_col($sp, ['status', 'student_status']);
            if ($spIdCol !== null) {
                $spJoin = " LEFT JOIN student_program sp ON sp.`{$spIdCol}` = (
                    SELECT MAX(sp2.`{$spIdCol}`) FROM student_program sp2
                    WHERE UPPER(TRIM(sp2.`{$spSidCol}`)) = UPPER(TRIM(e.`{$sidCol}`))
                )";
            } else {
                $spJoin = " LEFT JOIN student_program sp ON UPPER(TRIM(sp.`{$spSidCol}`)) = UPPER(TRIM(e.`{$sidCol}`))";
            }
            $spSelect = [
                $spProgramCol ? "sp.`{$spProgramCol}` AS program_code" : "'' AS program_code",
                $spStatusCol ? "sp.`{$spStatusCol}` AS program_status" : "'' AS program_status",
            ];
        }

        $caJoin = '';
        $caSelect = '0 AS total_ca';
        $saSidCol = spr_first_col($sa, ['Sid', 'SID', 'student_id']);
        $saCourseCol = spr_first_col($sa, ['Course_Code', 'course_code', 'course']);
        $saYearCol = spr_first_col($sa, ['Year', 'year', 'year_of_study']);
        $saSemCol = spr_first_col($sa, ['semester', 'term']);
        $saTotalCol = spr_first_col($sa, ['Total_CA', 'total_ca']);
        $exYearCol = spr_first_col($ex, ['Year', 'year', 'year_of_study']);
        $exSemCol = spr_first_col($ex, ['semester', 'term']);
        if ($saSidCol && $saCourseCol && $saTotalCol) {
            $caJoin = " LEFT JOIN semester_assessment sa ON UPPER(TRIM(sa.`{$saSidCol}`)) = UPPER(TRIM(e.`{$sidCol}`))
                      AND UPPER(TRIM(sa.`{$saCourseCol}`)) = UPPER(TRIM(e.`{$courseCol}`))";
            if ($saYearCol && $exYearCol) {
                $caJoin .= " AND sa.`{$saYearCol}` = e.`{$exYearCol}`";
            }
            if ($saSemCol && $exSemCol) {
                $caJoin .= " AND sa.`{$saSemCol}` = e.`{$exSemCol}`";
            }
            $caSelect = "COALESCE(sa.`{$saTotalCol}`, 0) AS total_ca";
        }

        $examMarkCol = spr_first_col($ex, ['Exam_marks', 'exam_marks', 'Exam', 'Total_marks']);
        $totalMarkCol = spr_first_col($ex, ['Total_marks', 'total_marks']);
        $examExpr = $examMarkCol ? "COALESCE(e.`{$examMarkCol}`" . ($totalMarkCol ? ", e.`{$totalMarkCol}`" : '') . ', 0)' : '0';

        $select = array_merge([
            "e.`{$sidCol}` AS student_id",
            "e.`{$courseCol}` AS course_code",
            spr_select_col($ex, ['id'], 'source_id', 'e', '0'),
            spr_select_col($ex, ['Year', 'year', 'year_of_study'], 'year_of_study', 'e', 'NULL'),
            spr_select_col($ex, ['semester', 'term'], 'semester', 'e', 'NULL'),
            spr_select_col($ex, ['status'], 'exam_status', 'e', "''"),
            "{$examExpr} AS total_exam",
            "COALESCE(sp.academic_year, sp.startYear, '') AS academic_year",
            $caSelect,
            $courseNameSelect,
        ], $studentSelect, $spSelect);

        $rows = [];
        $sql = 'SELECT ' . implode(', ', $select) . " FROM exams e{$caJoin}{$studentJoin}{$spJoin}{$courseJoin}";
        $res = $db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        } else {
            error_log('student_progression_report exam query failed: ' . $db->error);
        }
        return $rows;
    }
}

if (!function_exists('spr_inactive_student_rows')) {
    function spr_inactive_student_rows(mysqli $db): array
    {
        if (!spr_table_exists($db, 'students')) {
            return [];
        }

        $students = spr_columns($db, 'students');
        $sidCol = spr_first_col($students, ['SID', 'Sid', 'student_id']);
        if ($sidCol === null) {
            return [];
        }

        $fname = spr_first_col($students, ['Fname', 'first_name']);
        $lname = spr_first_col($students, ['Lname', 'last_name', 'surname']);
        $studentStatusCol = spr_first_col($students, ['status', 'student_status']);
        $yearCol = spr_first_col($students, ['year', 'year_of_study']);
        $academicYearCol = spr_first_col($students, ['academic_year']);

        $sp = spr_columns($db, 'student_program');
        $spJoin = '';
        $programSelect = "'' AS program_code";
        $programStatusSelect = "'' AS program_status";
        $spSidCol = spr_first_col($sp, ['Sid', 'SID', 'student_id']);
        if ($spSidCol !== null) {
            $spIdCol = spr_first_col($sp, ['id']);
            $spProgramCol = spr_first_col($sp, ['program_code', 'programme_code', 'program']);
            $spStatusCol = spr_first_col($sp, ['status', 'student_status']);
            if ($spIdCol !== null) {
                $spJoin = " LEFT JOIN student_program sp ON sp.`{$spIdCol}` = (
                    SELECT MAX(sp2.`{$spIdCol}`) FROM student_program sp2
                    WHERE UPPER(TRIM(sp2.`{$spSidCol}`)) = UPPER(TRIM(s.`{$sidCol}`))
                )";
            } else {
                $spJoin = " LEFT JOIN student_program sp ON UPPER(TRIM(sp.`{$spSidCol}`)) = UPPER(TRIM(s.`{$sidCol}`))";
            }
            if ($spProgramCol !== null) {
                $programSelect = "sp.`{$spProgramCol}` AS program_code";
            }
            if ($spStatusCol !== null) {
                $programStatusSelect = "sp.`{$spStatusCol}` AS program_status";
            }
        }

        $studentStatusSelect = $studentStatusCol ? "s.`{$studentStatusCol}` AS student_status" : "'' AS student_status";
        $nameSelect = "TRIM(CONCAT(" . ($fname ? "COALESCE(s.`{$fname}`, '')" : "''") . ", ' ', " . ($lname ? "COALESCE(s.`{$lname}`, '')" : "''") . ")) AS student_name";
        $yearSelect = $yearCol ? "s.`{$yearCol}` AS year_of_study" : "NULL AS year_of_study";
        $academicYearSelect = $academicYearCol ? "s.`{$academicYearCol}` AS academic_year" : "'' AS academic_year";

        $sql = "SELECT s.`{$sidCol}` AS student_id,
                       'PROFILE' AS course_code,
                       0 AS source_id,
                       {$nameSelect},
                       {$studentStatusSelect},
                       {$programSelect},
                       {$programStatusSelect},
                       'Student profile' AS course_name,
                       {$academicYearSelect},
                       {$yearSelect},
                       NULL AS semester,
                       1 AS course_registration_active
                FROM students s
                {$spJoin}";

        $rows = [];
        $res = $db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (spr_is_inactive_status($row['student_status'] ?? '') || spr_is_inactive_status($row['program_status'] ?? '')) {
                    $rows[] = $row;
                }
            }
            $res->free();
        } else {
            error_log('student_progression_report inactive student query failed: ' . $db->error);
        }
        return $rows;
    }
}

if (!function_exists('student_progression_report')) {
    function student_progression_report(mysqli $db, array $filters = [], string $scope = 'admin', string $staffId = ''): array
    {
        $assignedCourses = $scope === 'lecturer' ? spr_assigned_courses($db, $staffId) : [];
        $assignedLookup = array_fill_keys($assignedCourses, true);

        // 1. Process registrations
        //
        // A (student, course) counts as REGISTERED only when it has at least one
        // active (is_active = 1) registration. Rows with is_active = 0 are
        // dropped/deactivated — i.e. the student is not registered for that
        // course — so courses with no active registration are excluded entirely.
        // This also collapses the common "one active + one stale inactive"
        // duplicate so an actively-registered course is never falsely flagged.
        $registrationRows = spr_registration_rows($db);

        $activeRegistrations = [];
        foreach ($registrationRows as $row) {
            if ((string)($row['course_registration_active'] ?? '1') !== '0') {
                $regKey = strtoupper(trim((string)($row['student_id'] ?? ''))) . '|' . strtoupper(trim((string)($row['course_code'] ?? '')));
                $activeRegistrations[$regKey] = true;
            }
        }

        $rows = [];
        foreach ($registrationRows as $row) {
            $sid = strtoupper(trim((string)($row['student_id'] ?? '')));
            $code = strtoupper(trim((string)($row['course_code'] ?? '')));
            if ($code === '' || !isset($activeRegistrations[$sid . '|' . $code])) {
                continue; // not actively registered for this course
            }
            if ($scope === 'lecturer' && !isset($assignedLookup[$code])) {
                continue;
            }
            spr_merge_base($rows, $row);
        }

        if ($scope === 'admin') {
            foreach (spr_inactive_student_rows($db) as $row) {
                spr_merge_base($rows, $row);
            }
        }

        // 2. Process exams and merge — exam records only ENRICH actively
        //    registered courses with CA/exam scores; they never introduce a
        //    course the student is not actively registered for.
        foreach (spr_exam_rows($db) as $row) {
            $sid = trim((string)$row['student_id']);
            $code = strtoupper(trim((string)$row['course_code']));
            if ($sid === '' || $code === '') {
                continue;
            }
            if (!isset($activeRegistrations[strtoupper($sid) . '|' . $code])) {
                continue; // no active registration → not part of this report
            }
            if ($scope === 'lecturer' && !isset($assignedLookup[$code])) {
                continue;
            }
            
            $ay = spr_normalize_academic_year((string)($row['academic_year'] ?? ''));
            $sem = spr_normalize_period((string)($row['semester'] ?? ''));
            $key = spr_course_key($sid, $code, $ay, $sem);
            
            if (!isset($rows[$key])) {
                $rows[$key] = spr_empty_row($sid, $code);
                $rows[$key]['academic_year'] = $ay;
                $rows[$key]['semester'] = $sem;
            }
            spr_merge_base($rows, $row);

            $total = assessment_weighting_total($db, $sid, $row['total_ca'] ?? 0, $row['total_exam'] ?? 0);
            $failed = $total < 45 || strtolower(trim((string)($row['exam_status'] ?? ''))) === 'failed';
            
            $rows[$key]['total_score'] = $total;
            $rows[$key]['grade_letter'] = spr_grade($total);
            $rows[$key]['total_ca'] = isset($row['total_ca']) ? (float)$row['total_ca'] : null;
            $rows[$key]['total_exam'] = isset($row['total_exam']) ? (float)$row['total_exam'] : null;
            $rows[$key]['source_id'] = (int)($row['source_id'] ?? 0);
            $rows[$key]['latest_source'] = 'exams';
            if ($failed) {
                $rows[$key]['failed'] = true;
            } else {
                $rows[$key]['failed'] = false;
            }
        }

        // 3. Mark flags and risk levels
        foreach ($rows as &$row) {
            $flags = [];
            if (!empty($row['failed'])) {
                $flags[] = 'Failed';
            }
            if (!empty($row['inactive'])) {
                $flags[] = 'Inactive';
            }
            $row['flags'] = $flags;
            if (!empty($row['failed']) && !empty($row['inactive'])) {
                $row['risk_level'] = 'Critical';
            } elseif (!empty($row['failed'])) {
                $row['risk_level'] = 'Academic risk';
            } elseif (!empty($row['inactive'])) {
                $row['risk_level'] = 'Inactive';
            } else {
                $row['risk_level'] = 'Clear';
            }
        }
        unset($row);

        // 4. Group by student + course to only keep the LATEST attempt overall!
        $latestOverall = [];
        foreach ($rows as $row) {
            $sid = $row['student_id'];
            $code = $row['course_code'];
            $groupKey = $sid . '|' . $code;
            if (!isset($latestOverall[$groupKey]) || spr_is_later($row, $latestOverall[$groupKey])) {
                $latestOverall[$groupKey] = $row;
            }
        }
        
        $rows = $latestOverall;

        // Stamp each row with its programme's academic period type (term /
        // semester / short course …) so the UI and CSV label "Term N" vs
        // "Semester N" correctly instead of assuming every numeric period is a
        // semester. Resolved once per programme code.
        if (function_exists('getProgramPeriodMode')) {
            $periodModeCache = [];
            foreach ($rows as &$rowRef) {
                $pcode = strtoupper(trim((string)($rowRef['program_code'] ?? '')));
                if ($pcode === '') {
                    $rowRef['period_mode'] = '';
                    continue;
                }
                if (!isset($periodModeCache[$pcode])) {
                    $periodModeCache[$pcode] = getProgramPeriodMode($db, $pcode);
                }
                $rowRef['period_mode'] = $periodModeCache[$pcode];
            }
            unset($rowRef);
        }

        $flag = strtolower(trim((string)($filters['flag'] ?? 'all')));
        $query = strtolower(trim((string)($filters['q'] ?? '')));
        $academicYear = spr_normalize_academic_year(trim((string)($filters['academic_year'] ?? '')));
        $semester = spr_normalize_period(trim((string)($filters['semester'] ?? '')));
        $course = strtoupper(trim((string)($filters['course_code'] ?? '')));
        $program = strtoupper(trim((string)($filters['program_code'] ?? '')));

        $flagged = array_values(array_filter($rows, static function (array $row): bool {
            return !empty($row['failed']) || !empty($row['inactive']);
        }));

        $flagged = array_values(array_filter($flagged, static function (array $row) use ($flag, $query, $academicYear, $semester, $course, $program): bool {
            if ($flag === 'failed' && empty($row['failed'])) return false;
            if ($flag === 'inactive' && empty($row['inactive'])) return false;
            if ($flag === 'both' && (empty($row['failed']) || empty($row['inactive']))) return false;
            
            if ($academicYear !== '') {
                $rowAy = spr_normalize_academic_year((string)($row['academic_year'] ?? ''));
                if ($rowAy !== $academicYear) return false;
            }
            if ($semester !== '') {
                $rowSem = spr_normalize_period((string)($row['semester'] ?? ''));
                if ($rowSem !== $semester) return false;
            }
            if ($course !== '' && strtoupper((string)($row['course_code'] ?? '')) !== $course) return false;
            if ($program !== '' && strtoupper((string)($row['program_code'] ?? '')) !== $program) return false;
            
            if ($query !== '') {
                $haystack = strtolower(implode(' ', [
                    (string)($row['student_id'] ?? ''),
                    (string)($row['student_name'] ?? ''),
                    (string)($row['course_code'] ?? ''),
                    (string)($row['course_name'] ?? ''),
                    (string)($row['program_code'] ?? ''),
                ]));
                if (strpos($haystack, $query) === false) {
                    return false;
                }
            }
            return true;
        }));

        usort($flagged, static function (array $a, array $b): int {
            $riskOrder = ['Critical' => 0, 'Academic risk' => 1, 'Inactive' => 2, 'Clear' => 3];
            $risk = ($riskOrder[$a['risk_level'] ?? 'Clear'] ?? 3) <=> ($riskOrder[$b['risk_level'] ?? 'Clear'] ?? 3);
            if ($risk !== 0) return $risk;
            $name = strcmp((string)$a['student_name'], (string)$b['student_name']);
            if ($name !== 0) return $name;
            return strcmp((string)$a['course_code'], (string)$b['course_code']);
        });

        $statsRows = array_values($rows);
        $stats = [
            'flagged' => count(array_filter($statsRows, static fn(array $row): bool => !empty($row['failed']) || !empty($row['inactive']))),
            'failed' => count(array_filter($statsRows, static fn(array $row): bool => !empty($row['failed']))),
            'inactive' => count(array_filter($statsRows, static fn(array $row): bool => !empty($row['inactive']))),
            'both' => count(array_filter($statsRows, static fn(array $row): bool => !empty($row['failed']) && !empty($row['inactive']))),
        ];

        return [
            'rows' => $flagged,
            'stats' => $stats,
            'assigned_courses' => $assignedCourses,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('student_progression_report_csv')) {
    function student_progression_report_csv(array $rows, string $filename): void
    {
        if (ob_get_length() !== false && ob_get_length() > 0) {
            ob_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Student ID', 'Student', 'Program', 'Course', 'Year', 'Academic Period', 'Academic Year', 'Score', 'Grade', 'Flags', 'Inactive Reasons', 'Risk Level']);
        foreach ($rows as $row) {
            $semLabel = (string)($row['semester'] ?? '');
            if ($semLabel === 'short_course') {
                $semLabel = 'Short Course';
            } elseif (preg_match('/^[123]$/', $semLabel)) {
                $prefix = (($row['period_mode'] ?? '') === 'term') ? 'Term ' : 'Semester ';
                $semLabel = $prefix . $semLabel;
            }
            fputcsv($out, [
                $row['student_id'] ?? '',
                $row['student_name'] ?? '',
                $row['program_code'] ?? '',
                trim((string)($row['course_code'] ?? '') . ' ' . (string)($row['course_name'] ?? '')),
                $row['year_of_study'] ?? '',
                $semLabel,
                $row['academic_year'] ?? '',
                $row['total_score'] === null ? '' : number_format((float)$row['total_score'], 2),
                $row['grade_letter'] ?? '',
                implode(', ', $row['flags'] ?? []),
                implode('; ', $row['inactive_reasons'] ?? []),
                $row['risk_level'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
}
