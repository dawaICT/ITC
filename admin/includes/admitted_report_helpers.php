<?php
/**
 * Shared admitted-students report helpers.
 *
 * The report must cover both programme admissions (`student_program`) and
 * short-course enrollments (`short_course_enrollments`). Keep all query changes
 * here so the screen and CSV/PDF export stay aligned.
 */

if (!function_exists('admitted_report_h')) {
    function admitted_report_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admitted_report_table_exists')) {
    function admitted_report_table_exists(mysqli $db, string $table): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('admitted_report_table_columns')) {
    function admitted_report_table_columns(mysqli $db, string $table): array
    {
        static $cache = [];
        $key = spl_object_id($db) . ':' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $columns = [];
        $safeTable = str_replace('`', '', $table);
        if (admitted_report_table_exists($db, $safeTable) && ($result = @$db->query("SHOW COLUMNS FROM `{$safeTable}`"))) {
            while ($row = $result->fetch_assoc()) {
                $columns[$row['Field']] = true;
            }
            $result->free();
        }

        $cache[$key] = $columns;
        return $columns;
    }
}

if (!function_exists('admitted_report_column_exists')) {
    function admitted_report_column_exists(mysqli $db, string $table, string $column): bool
    {
        $columns = admitted_report_table_columns($db, $table);
        return isset($columns[$column]);
    }
}

if (!function_exists('admitted_report_sql_col')) {
    function admitted_report_sql_col(mysqli $db, string $table, string $alias, string $column, string $fallback = 'NULL'): string
    {
        return admitted_report_column_exists($db, $table, $column)
            ? $alias . '.`' . str_replace('`', '', $column) . '`'
            : $fallback;
    }
}

if (!function_exists('admitted_report_utf8_expr')) {
    function admitted_report_utf8_expr(string $expr): string
    {
        return "CONVERT({$expr} USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    }
}

if (!function_exists('admitted_report_mode_key')) {
    function admitted_report_mode_key($mode): string
    {
        return strtolower(trim(preg_replace('/[\s_-]+/', ' ', (string)$mode)));
    }
}

if (!function_exists('admitted_report_mode_label')) {
    function admitted_report_mode_label($mode): string
    {
        $mode = trim((string)$mode);
        if ($mode === '') {
            return 'Not set';
        }

        $key = admitted_report_mode_key($mode);
        $map = [
            'full time' => 'Full Time',
            'fulltime' => 'Full Time',
            'part time' => 'Part Time',
            'part time(evening)' => 'Part Time (Evening)',
            'distance' => 'Distance',
            'online' => 'Online',
            'blended' => 'Blended',
            'short course' => 'Short Course',
        ];

        return $map[$key] ?? ucwords(str_replace(['-', '_'], ' ', $mode));
    }
}

if (!function_exists('admitted_report_source_label')) {
    function admitted_report_source_label(string $source): string
    {
        return $source === 'short_course' ? 'Short Course' : 'Program';
    }
}

if (!function_exists('admitted_report_available_columns')) {
    function admitted_report_available_columns(): array
    {
        return [
            'row_number' => 'No.',
            'source' => 'Source',
            'sid' => 'Student ID',
            'name' => 'Student Name',
            'gender' => 'Gender',
            'program' => 'Program / Short Course',
            'year' => 'Year of Study',
            'mode' => 'Study Mode',
            'semester' => 'Semester',
            'term' => 'Term',
            'duration' => 'Duration',
            'academic_year' => 'Academic Year',
            'status' => 'Status',
            'admission_date' => 'Admission Date',
        ];
    }
}

if (!function_exists('admitted_report_default_columns')) {
    function admitted_report_default_columns(): array
    {
        return ['row_number', 'source', 'sid', 'name', 'gender', 'program', 'year', 'mode', 'status', 'admission_date'];
    }
}

if (!function_exists('admitted_report_normalise_columns')) {
    function admitted_report_normalise_columns($columns): array
    {
        if (is_string($columns)) {
            $columns = array_filter(array_map('trim', explode(',', $columns)));
        }
        if (!is_array($columns)) {
            $columns = admitted_report_default_columns();
        }

        $available = admitted_report_available_columns();
        $selected = [];
        foreach ($columns as $column) {
            $key = trim((string)$column);
            if (isset($available[$key]) && !in_array($key, $selected, true)) {
                $selected[] = $key;
            }
        }

        return !empty($selected) ? $selected : admitted_report_default_columns();
    }
}

if (!function_exists('admitted_report_default_filters')) {
    function admitted_report_default_filters(array $input = []): array
    {
        return [
            'report_type' => trim((string)($input['report_type'] ?? 'all')),
            'program_code' => trim((string)($input['program_code'] ?? '')),
            'short_course_id' => trim((string)($input['short_course_id'] ?? '')),
            'year_of_study' => trim((string)($input['year_of_study'] ?? 'all')),
            'academic_year' => trim((string)($input['academic_year'] ?? 'all')),
            'semester' => trim((string)($input['semester'] ?? 'all')),
            // Programme period split into semester (above) AND term: ITC programmes
            // are term-based (student_program.term), so both dimensions are offered.
            'term' => trim((string)($input['term'] ?? 'all')),
            // Short-course period is a duration (e.g. "10 days"), not a semester/term.
            'short_course_duration' => trim((string)($input['short_course_duration'] ?? 'all')),
            'gender' => trim((string)($input['gender'] ?? '')),
            'columns' => admitted_report_normalise_columns($input['columns'] ?? admitted_report_default_columns()),
        ];
    }
}

if (!function_exists('admitted_report_normalise_filters')) {
    /**
     * Drop filter values that conflict with the chosen report type so the screen,
     * the labels and the export URLs all agree. Must run before validation,
     * querying, label building and export-link generation.
     */
    function admitted_report_normalise_filters(array $filters): array
    {
        $type = $filters['report_type'] ?? 'all';

        if ($type === 'short_course') {
            // Programme-only dimensions do not apply to short courses.
            $filters['program_code'] = '';
            $filters['year_of_study'] = 'all';
            $filters['semester'] = 'all';
            $filters['term'] = 'all';
        }

        if ($type === 'program') {
            // Short-course-only dimensions do not apply to programmes.
            $filters['short_course_id'] = '';
            $filters['short_course_duration'] = 'all';
        }

        $filters['columns'] = admitted_report_normalise_columns($filters['columns'] ?? admitted_report_default_columns());

        return $filters;
    }
}

if (!function_exists('admitted_report_safe_date')) {
    /**
     * Format a date defensively. Empty, zero or unparseable values return the
     * fallback ('' by default) instead of the misleading 1970-01-01 epoch.
     */
    function admitted_report_safe_date($value, string $format = 'Y-m-d', string $fallback = ''): string
    {
        $value = trim((string)$value);
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return $fallback;
        }
        $ts = strtotime($value);
        if ($ts === false || $ts <= 0) {
            return $fallback;
        }
        return date($format, $ts);
    }
}

if (!function_exists('admitted_report_required_functions')) {
    /**
     * The helper functions the report page and export endpoint depend on.
     * Used to fail fast with a controlled message if this file is truncated
     * or partially overwritten.
     */
    function admitted_report_required_functions(): array
    {
        return [
            'admitted_report_default_filters',
            'admitted_report_normalise_filters',
            'admitted_report_summarise',
            'admitted_report_table_exists',
            'admitted_report_fetch_programs',
            'admitted_report_fetch_short_courses',
            'admitted_report_fetch_academic_years',
            'admitted_report_fetch_terms',
            'admitted_report_fetch_short_course_durations',
            'admitted_report_available_columns',
            'admitted_report_default_columns',
            'admitted_report_normalise_columns',
            'admitted_report_cell_text',
            'admitted_report_cell_html',
            'admitted_report_filter_label',
            'admitted_report_validate_filters',
            'admitted_report_fetch_rows',
            'admitted_report_h',
            'admitted_report_safe_date',
            'admitted_report_source_label',
            'admitted_report_mode_key',
            'admitted_report_mode_label',
        ];
    }
}

if (!function_exists('admitted_report_missing_functions')) {
    function admitted_report_missing_functions(): array
    {
        $missing = [];
        foreach (admitted_report_required_functions() as $fn) {
            if (!function_exists($fn)) {
                $missing[] = $fn;
            }
        }
        return $missing;
    }
}

if (!function_exists('admitted_report_missing_schema')) {
    /**
     * Returns "table" or "table.column" entries the CORE programme report needs
     * but that are absent from the live database. Short-course tables are
     * deliberately excluded: admitted_report_fetch_rows()/fetch_short_courses()/
     * fetch_academic_years() all guard their absence with table_exists checks,
     * so the report degrades gracefully to programme data only.
     */
    function admitted_report_missing_schema(mysqli $db): array
    {
        $required = [
            'students'        => ['SID'],
            'student_program' => ['Sid', 'program_code'],
        ];

        $missing = [];
        foreach ($required as $table => $cols) {
            if (!admitted_report_table_exists($db, $table)) {
                $missing[] = $table . ' (table)';
                continue;
            }
            $existing = [];
            $safeTable = str_replace('`', '', $table);
            if ($res = $db->query("SHOW COLUMNS FROM `{$safeTable}`")) {
                while ($row = $res->fetch_assoc()) {
                    $existing[$row['Field']] = true;
                }
                $res->free();
            }
            foreach ($cols as $col) {
                if (!isset($existing[$col])) {
                    $missing[] = $table . '.' . $col;
                }
            }
        }
        return $missing;
    }
}

if (!function_exists('admitted_report_validate_filters')) {
    function admitted_report_validate_filters(array $filters): array
    {
        $errors = [];

        if (!in_array($filters['report_type'], ['all', 'program', 'short_course'], true)) {
            $errors[] = 'Report type is invalid.';
        }
        if ($filters['short_course_id'] !== '' && !ctype_digit($filters['short_course_id'])) {
            $errors[] = 'Short course filter is invalid.';
        }
        if ($filters['year_of_study'] !== 'all' && ($filters['year_of_study'] === '' || !ctype_digit($filters['year_of_study']))) {
            $errors[] = 'Year of study must be a valid number or All.';
        }
        if ($filters['academic_year'] !== 'all' && ($filters['academic_year'] === '' || !ctype_digit($filters['academic_year']))) {
            $errors[] = 'Academic year must be a valid year or All.';
        }
        if ($filters['semester'] !== 'all' && ($filters['semester'] === '' || !ctype_digit($filters['semester']))) {
            $errors[] = 'Semester must be valid or All.';
        }
        if (($filters['term'] ?? 'all') !== 'all' && ($filters['term'] === '' || !ctype_digit($filters['term']))) {
            $errors[] = 'Term must be a valid number or All.';
        }
        if (($filters['short_course_duration'] ?? 'all') !== 'all'
            && !preg_match('/^\s*\d+\s+[A-Za-z]+\s*$/', (string)$filters['short_course_duration'])) {
            $errors[] = 'Short course duration filter is invalid.';
        }
        if ($filters['gender'] !== '' && !in_array($filters['gender'], ['M', 'F'], true)) {
            $errors[] = 'Gender filter is invalid.';
        }
        if (empty(admitted_report_normalise_columns($filters['columns'] ?? []))) {
            $errors[] = 'Select at least one table column.';
        }

        return $errors;
    }
}

if (!function_exists('admitted_report_bind')) {
    function admitted_report_bind(mysqli_stmt $stmt, string $types, array $params): void
    {
        if ($types !== '') {
            $bindValues = [$types];
            foreach ($params as $idx => $value) {
                $params[$idx] = $value;
                $bindValues[] = &$params[$idx];
            }
            call_user_func_array([$stmt, 'bind_param'], $bindValues);
        }
    }
}

if (!function_exists('admitted_report_fetch_programs')) {
    function admitted_report_fetch_programs(mysqli $db): array
    {
        if (!admitted_report_table_exists($db, 'programs')) {
            return [];
        }

        $rows = [];
        $where = admitted_report_column_exists($db, 'programs', 'is_active') ? 'WHERE COALESCE(is_active, 1) = 1' : '';
        $sql = "SELECT program_code, program_name
                  FROM programs
                  {$where}
                 ORDER BY program_name";
        if ($result = $db->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        return $rows;
    }
}

if (!function_exists('admitted_report_fetch_short_courses')) {
    function admitted_report_fetch_short_courses(mysqli $db): array
    {
        if (!admitted_report_table_exists($db, 'short_courses')) {
            return [];
        }

        $rows = [];
        $enrolledExpr = '0';
        if (admitted_report_table_exists($db, 'short_course_enrollments')
            && admitted_report_column_exists($db, 'short_course_enrollments', 'short_course_id')) {
            $enrolledExpr = '(SELECT COUNT(*) FROM short_course_enrollments e WHERE e.short_course_id = sc.id)';
        }
        $statusExpr = admitted_report_column_exists($db, 'short_courses', 'status') ? 'sc.status' : "''";
        $sql = "SELECT sc.id, sc.course_code, sc.course_name, {$statusExpr} AS status,
                       {$enrolledExpr} AS enrolled
                  FROM short_courses sc
                 ORDER BY sc.course_name";
        if ($result = $db->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        return $rows;
    }
}

if (!function_exists('admitted_report_fetch_academic_years')) {
    function admitted_report_fetch_academic_years(mysqli $db): array
    {
        $years = [];

        if (admitted_report_table_exists($db, 'student_program')) {
            $yearParts = [];
            if (admitted_report_column_exists($db, 'student_program', 'academic_year')) {
                $yearParts[] = "NULLIF(academic_year, '')";
            }
            if (admitted_report_column_exists($db, 'student_program', 'startYear')) {
                $yearParts[] = 'startYear';
            }
            $yearExpr = !empty($yearParts) ? 'COALESCE(' . implode(', ', $yearParts) . ')' : 'NULL';
            $sql = "SELECT DISTINCT {$yearExpr} AS year_value
                      FROM student_program
                     WHERE {$yearExpr} IS NOT NULL";
            if ($result = $db->query($sql)) {
                while ($row = $result->fetch_assoc()) {
                    if (ctype_digit((string)$row['year_value'])) {
                        $years[(int)$row['year_value']] = (int)$row['year_value'];
                    }
                }
                $result->free();
            }
        }

        if (admitted_report_table_exists($db, 'short_course_enrollments')) {
            $shortYearParts = [];
            if (admitted_report_column_exists($db, 'short_course_enrollments', 'enrollment_date')) {
                $shortYearParts[] = 'enrollment_date';
            }
            if (admitted_report_column_exists($db, 'short_course_enrollments', 'created_at')) {
                $shortYearParts[] = 'created_at';
            }
            $shortYearExpr = !empty($shortYearParts) ? 'COALESCE(' . implode(', ', $shortYearParts) . ')' : 'NULL';
            $sql = "SELECT DISTINCT YEAR({$shortYearExpr}) AS year_value
                      FROM short_course_enrollments
                     WHERE {$shortYearExpr} IS NOT NULL";
            if ($result = $db->query($sql)) {
                while ($row = $result->fetch_assoc()) {
                    if (ctype_digit((string)$row['year_value'])) {
                        $years[(int)$row['year_value']] = (int)$row['year_value'];
                    }
                }
                $result->free();
            }
        }

        // Academic-year options reflect ONLY the years actually present in the
        // data (programme registrations + short-course enrollments). The previous
        // synthetic current±range padding listed years with no admitted students,
        // which is misleading on a report screen.
        rsort($years, SORT_NUMERIC);
        return array_values($years);
    }
}

if (!function_exists('admitted_report_fetch_terms')) {
    /**
     * Distinct programme terms present in the data (student_program.term).
     * Falls back to the standard ITC three-term structure when the column is
     * empty so the filter is still usable on a fresh database.
     */
    function admitted_report_fetch_terms(mysqli $db): array
    {
        $terms = [];
        if (admitted_report_table_exists($db, 'student_program')
            && admitted_report_column_exists($db, 'student_program', 'term')) {
            $sql = "SELECT DISTINCT term FROM student_program
                     WHERE term IS NOT NULL AND TRIM(term) <> ''";
            if ($result = $db->query($sql)) {
                while ($row = $result->fetch_assoc()) {
                    if (ctype_digit((string)$row['term'])) {
                        $terms[(int)$row['term']] = (int)$row['term'];
                    }
                }
                $result->free();
            }
        }

        if (empty($terms)) {
            $terms = [1 => 1, 2 => 2, 3 => 3];
        }

        ksort($terms, SORT_NUMERIC);
        return array_values($terms);
    }
}

if (!function_exists('admitted_report_fetch_short_course_durations')) {
    /**
     * Distinct short-course durations present in the data, as
     * ['value' => '10 days', 'label' => '10 days'] entries built from
     * short_courses.duration_value + duration_unit.
     */
    function admitted_report_fetch_short_course_durations(mysqli $db): array
    {
        if (!admitted_report_table_exists($db, 'short_courses')
            || !admitted_report_column_exists($db, 'short_courses', 'duration_value')
            || !admitted_report_column_exists($db, 'short_courses', 'duration_unit')) {
            return [];
        }

        $durations = [];
        $sql = "SELECT DISTINCT
                       TRIM(CONCAT(duration_value, ' ', duration_unit)) AS duration,
                       CAST(duration_value AS UNSIGNED) AS sort_value,
                       duration_unit AS sort_unit
                  FROM short_courses
                 WHERE duration_value IS NOT NULL AND TRIM(duration_value) <> ''
                   AND duration_unit IS NOT NULL AND TRIM(duration_unit) <> ''
                 ORDER BY sort_unit, sort_value";
        if ($result = $db->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $value = trim((string)$row['duration']);
                if ($value !== '') {
                    $durations[$value] = ['value' => $value, 'label' => $value];
                }
            }
            $result->free();
        }

        return array_values($durations);
    }
}

if (!function_exists('admitted_report_fetch_rows')) {
    function admitted_report_fetch_rows(mysqli $db, array $filters): array
    {
        $queries = [];
        $types = '';
        $params = [];

        $needsProgram = in_array($filters['report_type'], ['all', 'program'], true)
            && admitted_report_table_exists($db, 'students')
            && admitted_report_table_exists($db, 'student_program')
            && admitted_report_column_exists($db, 'students', 'SID')
            && admitted_report_column_exists($db, 'student_program', 'Sid')
            && admitted_report_column_exists($db, 'student_program', 'program_code');

        if ($needsProgram) {
            $hasPrograms = admitted_report_table_exists($db, 'programs')
                && admitted_report_column_exists($db, 'programs', 'program_code');
            $hasSemester = admitted_report_table_exists($db, 'semester_registration')
                && admitted_report_column_exists($db, 'semester_registration', 'id')
                && admitted_report_column_exists($db, 'semester_registration', 'student_id');

            $sFname = admitted_report_sql_col($db, 'students', 's', 'Fname', "''");
            $sLname = admitted_report_sql_col($db, 'students', 's', 'Lname', "''");
            $sSex = admitted_report_sql_col($db, 'students', 's', 'sex', "''");
            $sMode = admitted_report_sql_col($db, 'students', 's', 'mode', "''");
            $sYear = admitted_report_sql_col($db, 'students', 's', 'year', 'NULL');
            $sStatus = admitted_report_sql_col($db, 'students', 's', 'status', "'active'");
            $sDateAdm = admitted_report_sql_col($db, 'students', 's', 'dte_adm', 'NULL');
            $sEnrollmentDate = admitted_report_sql_col($db, 'students', 's', 'enrollment_date', 'NULL');
            $sAcademicYear = admitted_report_sql_col($db, 'students', 's', 'academic_year', "''");
            $sProgram = admitted_report_sql_col($db, 'students', 's', 'program', "''");

            $spId = admitted_report_sql_col($db, 'student_program', 'sp', 'id', "CONCAT(sp.Sid, ':', sp.program_code)");
            $spMode = admitted_report_sql_col($db, 'student_program', 'sp', 'mode', "''");
            $spStatus = admitted_report_sql_col($db, 'student_program', 'sp', 'status', "'active'");
            $spAcademicYear = admitted_report_sql_col($db, 'student_program', 'sp', 'academic_year', "''");
            $spStartYear = admitted_report_sql_col($db, 'student_program', 'sp', 'startYear', 'NULL');
            $spTermStart = admitted_report_sql_col($db, 'student_program', 'sp', 'term_start_date', 'NULL');
            $spIntake = admitted_report_sql_col($db, 'student_program', 'sp', 'intake', "''");
            $spTerm = admitted_report_sql_col($db, 'student_program', 'sp', 'term', 'NULL');

            $pStudyMode = $hasPrograms ? admitted_report_sql_col($db, 'programs', 'p', 'study_mode', "''") : "''";
            $pProgramName = $hasPrograms ? admitted_report_sql_col($db, 'programs', 'p', 'program_name', 'NULL') : 'NULL';
            $pProgramType = $hasPrograms ? admitted_report_sql_col($db, 'programs', 'p', 'program_type', 'NULL') : 'NULL';

            $srYear = $hasSemester ? admitted_report_sql_col($db, 'semester_registration', 'sr', 'year_of_study', 'NULL') : 'NULL';
            $srSemester = $hasSemester ? admitted_report_sql_col($db, 'semester_registration', 'sr', 'semester', 'NULL') : 'NULL';
            $srCreated = $hasSemester ? admitted_report_sql_col($db, 'semester_registration', 'sr', 'created_at', 'NULL') : 'NULL';
            $srRegistered = $hasSemester ? admitted_report_sql_col($db, 'semester_registration', 'sr', 'date_registered', 'NULL') : 'NULL';
            $srStudentType = $hasSemester ? admitted_report_sql_col($db, 'semester_registration', 'sr', 'student_type', 'NULL') : 'NULL';
            $srFinancial = $hasSemester ? admitted_report_sql_col($db, 'semester_registration', 'sr', 'financial_status', 'NULL') : 'NULL';

            $programJoin = $hasPrograms
                ? "LEFT JOIN programs p ON TRIM(UPPER(p.program_code)) = TRIM(UPPER(sp.program_code))"
                : '';

            $semesterJoin = '';
            if ($hasSemester) {
                $srProgramCondition = admitted_report_column_exists($db, 'semester_registration', 'program_code')
                    ? "AND (sr2.program_code IS NULL OR TRIM(UPPER(sr2.program_code)) = TRIM(UPPER(sp.program_code)))"
                    : '';
                $orderParts = [];
                if (admitted_report_column_exists($db, 'semester_registration', 'created_at')) {
                    $orderParts[] = 'sr2.created_at DESC';
                }
                if (admitted_report_column_exists($db, 'semester_registration', 'date_registered')) {
                    $orderParts[] = 'sr2.date_registered DESC';
                }
                $orderParts[] = 'sr2.id DESC';
                $semesterJoin = "LEFT JOIN semester_registration sr
                  ON sr.id = (
                        SELECT sr2.id
                          FROM semester_registration sr2
                         WHERE TRIM(UPPER(sr2.student_id)) = TRIM(UPPER(sp.Sid))
                           {$srProgramCondition}
                         ORDER BY " . implode(', ', $orderParts) . "
                         LIMIT 1
                     )";
            }

            $programNameExpr = "COALESCE({$pProgramName}, sp.program_code, {$sProgram}, 'Unassigned Program')";
            $modeExpr = "COALESCE(NULLIF({$spMode}, ''), NULLIF({$sMode}, ''), NULLIF({$pStudyMode}, ''), 'Not set')";
            $studyYearExpr = "COALESCE({$srYear}, NULLIF({$sYear}, 0), 1)";
            $registrationDateExpr = "COALESCE({$spTermStart}, {$srCreated}, {$srRegistered}, {$sDateAdm}, {$sEnrollmentDate})";
            $studentTypeExpr = "COALESCE({$srStudentType}, {$pProgramType}, 'Program')";
            $statusExpr = "COALESCE({$spStatus}, {$sStatus}, 'active')";
            $academicYearExpr = "COALESCE(NULLIF({$spAcademicYear}, ''), NULLIF({$sAcademicYear}, ''), {$spStartYear}, YEAR({$registrationDateExpr}))";

            $programSql = "SELECT
                    " . admitted_report_utf8_expr("CONCAT('program:', {$spId})") . " AS row_key,
                    " . admitted_report_utf8_expr("'program'") . " AS report_source,
                    " . admitted_report_utf8_expr('s.SID') . " AS SID,
                    " . admitted_report_utf8_expr($sFname) . " AS Fname,
                    " . admitted_report_utf8_expr($sLname) . " AS Lname,
                    " . admitted_report_utf8_expr($sSex) . " AS sex,
                    " . admitted_report_utf8_expr($modeExpr) . " AS mode,
                    {$studyYearExpr} AS study_year,
                    " . admitted_report_utf8_expr($programNameExpr) . " AS program_name,
                    " . admitted_report_utf8_expr("COALESCE(sp.program_code, {$sProgram}, '')") . " AS program_code,
                    {$srSemester} AS semester,
                    {$registrationDateExpr} AS registration_date,
                    " . admitted_report_utf8_expr($studentTypeExpr) . " AS student_type,
                    " . admitted_report_utf8_expr($srFinancial) . " AS financial_status,
                    " . admitted_report_utf8_expr($statusExpr) . " AS admission_status,
                    " . admitted_report_utf8_expr($spIntake) . " AS intake,
                    " . admitted_report_utf8_expr($academicYearExpr) . " AS academic_year,
                    NULL AS short_course_id,
                    " . admitted_report_utf8_expr($spTerm) . " AS term,
                    NULL AS course_duration
                FROM student_program sp
                INNER JOIN students s ON TRIM(UPPER(s.SID)) = TRIM(UPPER(sp.Sid))
                {$programJoin}
                {$semesterJoin}
                WHERE COALESCE({$sStatus}, 'active') <> 'inactive'
                  AND COALESCE({$spStatus}, 'active') <> 'inactive'";

            if ($filters['program_code'] !== '') {
                $programSql .= " AND TRIM(UPPER(sp.program_code)) = TRIM(UPPER(?))";
                $types .= 's';
                $params[] = $filters['program_code'];
            }
            if ($filters['year_of_study'] !== 'all') {
                $programSql .= " AND {$studyYearExpr} = ?";
                $types .= 'i';
                $params[] = (int)$filters['year_of_study'];
            }
            if ($filters['academic_year'] !== 'all') {
                $programSql .= " AND {$academicYearExpr} = ?";
                $types .= 's';
                $params[] = $filters['academic_year'];
            }
            if ($filters['semester'] !== 'all') {
                $programSql .= " AND {$srSemester} = ?";
                $types .= 'i';
                $params[] = (int)$filters['semester'];
            }
            if (($filters['term'] ?? 'all') !== 'all') {
                $programSql .= " AND TRIM({$spTerm}) = ?";
                $types .= 's';
                $params[] = $filters['term'];
            }
            if ($filters['gender'] !== '') {
                $programSql .= " AND {$sSex} = ?";
                $types .= 's';
                $params[] = $filters['gender'];
            }

            $queries[] = $programSql;
        }

        $needsShortCourse = in_array($filters['report_type'], ['all', 'short_course'], true)
            && admitted_report_table_exists($db, 'short_courses')
            && admitted_report_table_exists($db, 'short_course_enrollments')
            && admitted_report_column_exists($db, 'short_courses', 'id')
            && admitted_report_column_exists($db, 'short_courses', 'course_code')
            && admitted_report_column_exists($db, 'short_courses', 'course_name')
            && admitted_report_column_exists($db, 'short_course_enrollments', 'id')
            && admitted_report_column_exists($db, 'short_course_enrollments', 'short_course_id')
            && admitted_report_column_exists($db, 'short_course_enrollments', 'student_id');

        if ($needsShortCourse) {
            $eEnrollmentDate = admitted_report_sql_col($db, 'short_course_enrollments', 'e', 'enrollment_date', 'NULL');
            $eCreatedAt = admitted_report_sql_col($db, 'short_course_enrollments', 'e', 'created_at', 'NULL');
            $eStatus = admitted_report_sql_col($db, 'short_course_enrollments', 'e', 'status', "'enrolled'");
            $scDeliveryMode = admitted_report_sql_col($db, 'short_courses', 'sc', 'delivery_mode', "''");
            $scStartDate = admitted_report_sql_col($db, 'short_courses', 'sc', 'start_date', 'NULL');
            $scDurationValue = admitted_report_sql_col($db, 'short_courses', 'sc', 'duration_value', 'NULL');
            $scDurationUnit = admitted_report_sql_col($db, 'short_courses', 'sc', 'duration_unit', "''");
            $shortSFname = admitted_report_sql_col($db, 'students', 's', 'Fname', "''");
            $shortSLname = admitted_report_sql_col($db, 'students', 's', 'Lname', "''");
            $shortSSex = admitted_report_sql_col($db, 'students', 's', 'sex', "''");
            $shortDateExpr = "COALESCE({$eEnrollmentDate}, {$eCreatedAt}, {$scStartDate})";
            $shortModeExpr = "COALESCE(NULLIF({$scDeliveryMode}, ''), 'Short Course')";
            // Human-readable duration, e.g. "10 days"; NULL when not captured.
            $shortDurationExpr = "NULLIF(TRIM(CONCAT(COALESCE({$scDurationValue}, ''), ' ', COALESCE({$scDurationUnit}, ''))), '')";

            $shortSql = "SELECT
                    " . admitted_report_utf8_expr("CONCAT('short_course:', e.id)") . " AS row_key,
                    " . admitted_report_utf8_expr("'short_course'") . " AS report_source,
                    " . admitted_report_utf8_expr('e.student_id') . " AS SID,
                    " . admitted_report_utf8_expr("COALESCE({$shortSFname}, '')") . " AS Fname,
                    " . admitted_report_utf8_expr("COALESCE({$shortSLname}, '')") . " AS Lname,
                    " . admitted_report_utf8_expr("COALESCE({$shortSSex}, '')") . " AS sex,
                    " . admitted_report_utf8_expr($shortModeExpr) . " AS mode,
                    NULL AS study_year,
                    " . admitted_report_utf8_expr('sc.course_name') . " AS program_name,
                    " . admitted_report_utf8_expr('sc.course_code') . " AS program_code,
                    NULL AS semester,
                    {$shortDateExpr} AS registration_date,
                    " . admitted_report_utf8_expr("'Short Course'") . " AS student_type,
                    NULL AS financial_status,
                    " . admitted_report_utf8_expr("COALESCE({$eStatus}, 'enrolled')") . " AS admission_status,
                    NULL AS intake,
                    " . admitted_report_utf8_expr("YEAR({$shortDateExpr})") . " AS academic_year,
                    sc.id AS short_course_id,
                    NULL AS term,
                    " . admitted_report_utf8_expr($shortDurationExpr) . " AS course_duration
                FROM short_course_enrollments e
                INNER JOIN short_courses sc ON sc.id = e.short_course_id
                LEFT JOIN students s ON TRIM(UPPER(s.SID)) = TRIM(UPPER(e.student_id))
                WHERE 1=1";

            if ($filters['short_course_id'] !== '') {
                $shortSql .= " AND sc.id = ?";
                $types .= 'i';
                $params[] = (int)$filters['short_course_id'];
            }
            if ($filters['academic_year'] !== 'all') {
                $shortSql .= " AND YEAR({$shortDateExpr}) = ?";
                $types .= 'i';
                $params[] = (int)$filters['academic_year'];
            }
            if (($filters['short_course_duration'] ?? 'all') !== 'all') {
                $shortSql .= " AND TRIM(CONCAT(COALESCE({$scDurationValue}, ''), ' ', COALESCE({$scDurationUnit}, ''))) = ?";
                $types .= 's';
                $params[] = $filters['short_course_duration'];
            }
            if ($filters['gender'] !== '') {
                $shortSql .= " AND {$shortSSex} = ?";
                $types .= 's';
                $params[] = $filters['gender'];
            }

            $queries[] = $shortSql;
        }

        if (empty($queries)) {
            return [];
        }

        $sql = implode("\nUNION ALL\n", $queries) . "\nORDER BY report_source, program_name, Lname, Fname, SID";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log('admitted_report_fetch_rows prepare failed: ' . $db->error);
            throw new RuntimeException('Unable to prepare admitted students report.');
        }

        admitted_report_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('admitted_report_cell_text')) {
    function admitted_report_cell_text(array $row, string $column, int $counter): string
    {
        $name = trim((string)($row['Fname'] ?? '') . ' ' . (string)($row['Lname'] ?? ''));
        $gender = (string)($row['sex'] ?? '');
        $dateValue = trim((string)($row['registration_date'] ?? ''));

        switch ($column) {
            case 'row_number':
                return (string)$counter;
            case 'source':
                return admitted_report_source_label((string)($row['report_source'] ?? 'program'));
            case 'sid':
                return (string)($row['SID'] ?? '');
            case 'name':
                return $name !== '' ? $name : 'Name not captured';
            case 'gender':
                return $gender === 'M' ? 'Male' : ($gender === 'F' ? 'Female' : 'Not set');
            case 'program':
                $code = trim((string)($row['program_code'] ?? ''));
                $label = trim((string)($row['program_name'] ?? ''));
                return trim($label . ($code !== '' ? ' (' . $code . ')' : ''));
            case 'year':
                return ($row['study_year'] ?? '') !== '' ? 'Year ' . (string)$row['study_year'] : 'N/A';
            case 'mode':
                return admitted_report_mode_label($row['mode'] ?? '');
            case 'semester':
                return ($row['semester'] ?? '') !== '' ? 'Semester ' . (string)$row['semester'] : 'N/A';
            case 'term':
                return (($row['term'] ?? '') !== '' && $row['term'] !== null) ? 'Term ' . (string)$row['term'] : 'N/A';
            case 'duration':
                $duration = trim((string)($row['course_duration'] ?? ''));
                return $duration !== '' ? ucfirst($duration) : 'N/A';
            case 'academic_year':
                return (string)($row['academic_year'] ?? '');
            case 'status':
                return ucwords(str_replace('_', ' ', (string)($row['admission_status'] ?? '')));
            case 'admission_date':
                return admitted_report_safe_date($dateValue);
            default:
                return '';
        }
    }
}

if (!function_exists('admitted_report_cell_html')) {
    function admitted_report_cell_html(array $row, string $column, int $counter): string
    {
        $text = admitted_report_cell_text($row, $column, $counter);

        if ($column === 'source') {
            $class = ($row['report_source'] ?? '') === 'short_course' ? 'warning text-dark' : 'primary';
            return '<span class="badge bg-' . $class . '">' . admitted_report_h($text) . '</span>';
        }
        if ($column === 'gender') {
            $class = ($row['sex'] ?? '') === 'M' ? 'info' : (($row['sex'] ?? '') === 'F' ? 'danger' : 'secondary');
            return '<span class="badge bg-' . $class . '">' . admitted_report_h($text) . '</span>';
        }
        if ($column === 'mode') {
            $class = admitted_report_mode_key($row['mode'] ?? '') === 'full time' ? 'success' : 'secondary';
            return '<span class="badge bg-' . $class . '">' . admitted_report_h($text) . '</span>';
        }
        if ($column === 'name') {
            $initial = strtoupper(substr((string)($row['Fname'] ?? $row['SID'] ?? '?'), 0, 1));
            return '<div class="d-flex align-items-center">'
                . '<div class="avatar-circle me-2 bg-primary text-white">' . admitted_report_h($initial) . '</div>'
                . '<div>' . admitted_report_h($text) . '</div>'
                . '</div>';
        }
        if ($column === 'program') {
            return '<div class="fw-semibold">' . admitted_report_h($row['program_name'] ?? '') . '</div>'
                . '<small class="text-muted">' . admitted_report_h($row['program_code'] ?? '') . '</small>';
        }
        if ($column === 'year' && $text === 'N/A') {
            return '<span class="text-muted">N/A</span>';
        }

        return admitted_report_h($text);
    }
}

if (!function_exists('admitted_report_summarise')) {
    function admitted_report_summarise(array $rows): array
    {
        $summary = [
            'total' => count($rows),
            'male' => 0,
            'female' => 0,
            'program' => 0,
            'short_course' => 0,
            'full_time' => 0,
            'part_time' => 0,
        ];

        foreach ($rows as $row) {
            if (($row['sex'] ?? '') === 'M') {
                $summary['male']++;
            } elseif (($row['sex'] ?? '') === 'F') {
                $summary['female']++;
            }

            $source = (string)($row['report_source'] ?? 'program');
            if (isset($summary[$source])) {
                $summary[$source]++;
            }

            $modeKey = admitted_report_mode_key($row['mode'] ?? '');
            if ($modeKey === 'full time' || $modeKey === 'fulltime') {
                $summary['full_time']++;
            } elseif (str_contains($modeKey, 'part time')) {
                $summary['part_time']++;
            }
        }

        return $summary;
    }
}

if (!function_exists('admitted_report_filter_label')) {
    function admitted_report_filter_label(array $filters, array $programs, array $shortCourses): array
    {
        $programName = 'All Programs';
        foreach ($programs as $program) {
            if (strcasecmp((string)$program['program_code'], $filters['program_code']) === 0) {
                $programName = $program['program_name'];
                break;
            }
        }

        $shortCourseName = 'All Short Courses';
        foreach ($shortCourses as $course) {
            if ((string)$course['id'] === (string)$filters['short_course_id']) {
                $shortCourseName = $course['course_name'];
                break;
            }
        }

        return [
            'Report Type' => $filters['report_type'] === 'all'
                ? 'All Admissions'
                : ($filters['report_type'] === 'short_course' ? 'Short Courses' : 'Programs'),
            'Program' => $programName,
            'Short Course' => $filters['report_type'] === 'program' ? 'Not included' : $shortCourseName,
            'Year of Study' => $filters['year_of_study'] === 'all' ? 'All Years' : ('Year ' . $filters['year_of_study']),
            'Academic Year' => $filters['academic_year'] === 'all' ? 'All Years' : $filters['academic_year'],
            'Semester' => $filters['semester'] === 'all' ? 'All Semesters' : ('Semester ' . $filters['semester']),
            'Term' => ($filters['report_type'] === 'short_course')
                ? 'Not included'
                : ((($filters['term'] ?? 'all') === 'all') ? 'All Terms' : ('Term ' . $filters['term'])),
            'Duration' => ($filters['report_type'] === 'program')
                ? 'Not included'
                : ((($filters['short_course_duration'] ?? 'all') === 'all') ? 'All Durations' : ucfirst((string)$filters['short_course_duration'])),
            'Gender' => $filters['gender'] === '' ? 'All' : ($filters['gender'] === 'M' ? 'Male' : 'Female'),
        ];
    }
}
