<?php
declare(strict_types=1);

if (!function_exists('student_ca_h')) {
    function student_ca_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('student_ca_table_exists')) {
    function student_ca_table_exists(mysqli $db, string $table): bool
    {
        if ($res = @$db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")) {
            $exists = $res->num_rows > 0;
            $res->free();
            return $exists;
        }
        return false;
    }
}

if (!function_exists('student_ca_table_columns')) {
    function student_ca_table_columns(mysqli $db, string $table): array
    {
        $columns = [];
        if (!student_ca_table_exists($db, $table)) {
            return $columns;
        }
        if ($meta = @$db->query("SHOW COLUMNS FROM `{$table}`")) {
            while ($column = $meta->fetch_assoc()) {
                $columns[strtolower((string)$column['Field'])] = (string)$column['Field'];
            }
            $meta->free();
        }
        return $columns;
    }
}

if (!function_exists('student_ca_pick_column')) {
    function student_ca_pick_column(array $columns, array $candidates, string $fallback = ''): string
    {
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($columns[$key])) {
                return $columns[$key];
            }
        }
        return $fallback;
    }
}

if (!function_exists('student_ca_bind')) {
    function student_ca_bind(mysqli_stmt $stmt, string $types, array &$params): void
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

if (!function_exists('student_ca_add_year_option')) {
    function student_ca_add_year_option(array &$years, string $value): void
    {
        $value = trim($value);
        if (preg_match('/^\d{4}(\/\d{4})?$/', $value) && !in_array($value, $years, true)) {
            $years[] = $value;
        }
    }
}

if (!function_exists('student_ca_add_period_option')) {
    function student_ca_add_period_option(array &$periods, string $value): void
    {
        $value = trim($value);
        if ($value !== '' && !in_array($value, $periods, true)) {
            $periods[] = $value;
        }
    }
}

if (!function_exists('student_ca_latest_registration')) {
    function student_ca_latest_registration(mysqli $db, string $sid, ?string $academicYear = null, ?string $period = null): ?array
    {
        $cols = student_ca_table_columns($db, 'semester_registration');
        if (!$cols) {
            return null;
        }

        $sidCols = [];
        foreach (['student_id', 'sid'] as $candidate) {
            if (isset($cols[$candidate])) {
                $sidCols[] = $cols[$candidate];
            }
        }
        if (!$sidCols) {
            return null;
        }

        $yearCol = $cols['year_of_study'] ?? ($cols['year'] ?? null);
        $periodCol = $cols['semester'] ?? ($cols['semester_term'] ?? ($cols['term'] ?? null));
        $academicYearCol = $cols['academic_year'] ?? null;
        $periodTypeCol = $cols['period_type'] ?? null;
        $programCol = $cols['program_code'] ?? null;

        $select = [
            isset($cols['id']) ? "`{$cols['id']}` AS id" : 'NULL AS id',
            $periodCol ? "`{$periodCol}` AS period" : "'' AS period",
            $yearCol ? "`{$yearCol}` AS year_of_study" : "'1' AS year_of_study",
            $academicYearCol ? "`{$academicYearCol}` AS academic_year" : "'' AS academic_year",
            $periodTypeCol ? "`{$periodTypeCol}` AS period_type" : "'semester' AS period_type",
            $programCol ? "`{$programCol}` AS program_code" : "'' AS program_code",
        ];

        $where = ['(' . implode(' OR ', array_map(static fn(string $col): string => "`{$col}` = ?", $sidCols)) . ')'];
        $params = array_fill(0, count($sidCols), $sid);
        $types = str_repeat('s', count($sidCols));
        if ($academicYear !== null && trim($academicYear) !== '' && $academicYearCol) {
            $where[] = "`{$academicYearCol}` = ?";
            $params[] = trim($academicYear);
            $types .= 's';
        }
        if ($period !== null && trim($period) !== '' && $periodCol) {
            $where[] = "`{$periodCol}` = ?";
            $params[] = trim($period);
            $types .= 's';
        }

        $order = isset($cols['id']) ? " ORDER BY `{$cols['id']}` DESC" : '';
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM semester_registration WHERE ' . implode(' AND ', $where) . $order . ' LIMIT 1';
        if (!$stmt = $db->prepare($sql)) {
            error_log('ContinuousAssessment latest registration prepare failed: ' . $db->error);
            return null;
        }
        student_ca_bind($stmt, $types, $params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('student_ca_academic_year_options')) {
    function student_ca_academic_year_options(mysqli $db, string $sid, string $fallbackYear): array
    {
        $years = [];
        student_ca_add_year_option($years, $fallbackYear);

        $semesterCols = student_ca_table_columns($db, 'semester_registration');
        if ($semesterCols) {
            $sidCol = student_ca_pick_column($semesterCols, ['student_id', 'sid', 'student'], 'student_id');
            $academicYearCol = student_ca_pick_column($semesterCols, ['academic_year', 'acad_year', 'session'], '');
            if ($academicYearCol !== '' && $stmt = @$db->prepare("SELECT DISTINCT `{$academicYearCol}` AS academic_year FROM semester_registration WHERE `{$sidCol}` = ? AND `{$academicYearCol}` IS NOT NULL AND `{$academicYearCol}` <> '' ORDER BY `{$academicYearCol}` DESC")) {
                $stmt->bind_param('s', $sid);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    student_ca_add_year_option($years, (string)$row['academic_year']);
                }
                $stmt->close();
            }
        }

        $registrationCols = student_ca_table_columns($db, 'course_registration');
        if ($registrationCols) {
            $sidCol = student_ca_pick_column($registrationCols, ['Sid', 'student_id', 'sid'], 'Sid');
            foreach (['academic_year'] as $candidate) {
                $yearCol = student_ca_pick_column($registrationCols, [$candidate], '');
                if ($yearCol !== '' && $stmt = @$db->prepare("SELECT DISTINCT `{$yearCol}` AS academic_year FROM course_registration WHERE `{$sidCol}` = ? AND `{$yearCol}` IS NOT NULL AND `{$yearCol}` <> '' ORDER BY `{$yearCol}` DESC")) {
                    $stmt->bind_param('s', $sid);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        student_ca_add_year_option($years, (string)$row['academic_year']);
                    }
                    $stmt->close();
                }
            }
        }

        if (student_ca_table_exists($db, 'short_course_enrollments') && student_ca_table_exists($db, 'short_courses')) {
            $shortSql = "SELECT DISTINCT YEAR(sc.start_date) AS academic_year
                         FROM short_course_enrollments sce
                         INNER JOIN short_courses sc ON sc.id = sce.short_course_id
                         WHERE sce.student_id COLLATE utf8mb4_general_ci = ?
                           AND sc.start_date IS NOT NULL
                         ORDER BY academic_year DESC";
            if ($stmt = @$db->prepare($shortSql)) {
                $stmt->bind_param('s', $sid);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    student_ca_add_year_option($years, (string)$row['academic_year']);
                }
                $stmt->close();
            }
        }

        rsort($years, SORT_NATURAL);
        return $years;
    }
}

if (!function_exists('student_ca_period_options')) {
    function student_ca_period_options(mysqli $db, string $sid, string $academicYear, string $fallbackPeriod): array
    {
        $periods = [];
        $registration = student_ca_latest_registration($db, $sid, $academicYear);
        if ($registration) {
            student_ca_add_period_option($periods, (string)($registration['period'] ?? ''));
        }

        $semesterCols = student_ca_table_columns($db, 'semester_registration');
        if ($semesterCols) {
            $sidCols = [];
            foreach (['student_id', 'sid'] as $candidate) {
                if (isset($semesterCols[$candidate])) {
                    $sidCols[] = $semesterCols[$candidate];
                }
            }
            $periodCol = student_ca_pick_column($semesterCols, ['semester', 'semester_term', 'term'], '');
            $academicYearCol = student_ca_pick_column($semesterCols, ['academic_year', 'acad_year', 'session'], '');
            if ($sidCols && $periodCol !== '') {
                $where = ['(' . implode(' OR ', array_map(static fn(string $col): string => "`{$col}` = ?", $sidCols)) . ')'];
                $params = array_fill(0, count($sidCols), $sid);
                $types = str_repeat('s', count($sidCols));
                if ($academicYear !== '' && $academicYearCol !== '') {
                    $where[] = "`{$academicYearCol}` = ?";
                    $params[] = $academicYear;
                    $types .= 's';
                }
                $sql = "SELECT DISTINCT `{$periodCol}` AS period_value FROM semester_registration WHERE " . implode(' AND ', $where) . " AND `{$periodCol}` IS NOT NULL AND `{$periodCol}` <> ''";
                if ($stmt = $db->prepare($sql)) {
                    student_ca_bind($stmt, $types, $params);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        student_ca_add_period_option($periods, (string)$row['period_value']);
                    }
                    $stmt->close();
                }
            }
        }

        natsort($periods);
        if (!$periods) {
            student_ca_add_period_option($periods, $fallbackPeriod);
        }
        return array_values($periods);
    }
}

if (!function_exists('student_ca_empty_row')) {
    function student_ca_empty_row(): object
    {
        return (object)[
            'A1' => null,
            'A2' => null,
            'T1' => null,
            'T2' => null,
            'Total_CA' => null,
        ];
    }
}

if (!function_exists('student_ca_assessment_row')) {
    /**
     * Fetch published CA marks. `Year` in semester_assessment may store either
     * academic year (2026) or year-of-study (1) depending on upload path — try both.
     */
    function student_ca_assessment_row(
        mysqli $db,
        string $sid,
        string $courseCode,
        string $academicYear,
        string $period,
        ?string $yearOfStudy = null
    ): object {
        $empty = student_ca_empty_row();
        if (!student_ca_table_exists($db, 'semester_assessment')) {
            return $empty;
        }

        $publishedOnly = student_ca_published_status_clause($db);
        $yearCandidates = student_ca_year_candidates($academicYear, (string)($yearOfStudy ?? ''));

        foreach ($yearCandidates as $yearVal) {
            $sql = "SELECT A1, A2, T1, T2, Total_CA
                    FROM semester_assessment
                    WHERE Sid COLLATE utf8mb4_general_ci = ?
                      AND Course_Code COLLATE utf8mb4_general_ci = ?
                      AND `Year` = ?
                      AND semester = ?
                      {$publishedOnly}
                    LIMIT 1";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('ssss', $sid, $courseCode, $yearVal, $period);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_object();
                $stmt->close();
                if ($row) {
                    return $row;
                }
            }
        }

        $sql = "SELECT A1, A2, T1, T2, Total_CA
                FROM semester_assessment
                WHERE Sid COLLATE utf8mb4_general_ci = ?
                  AND Course_Code COLLATE utf8mb4_general_ci = ?
                  AND semester = ?
                  {$publishedOnly}
                ORDER BY id DESC
                LIMIT 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('sss', $sid, $courseCode, $period);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_object();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }

        return $empty;
    }
}

if (!function_exists('student_ca_normalize_course_code')) {
    function student_ca_normalize_course_code(string $code): string
    {
        return strtoupper(trim($code));
    }
}

if (!function_exists('student_ca_published_status_clause')) {
  /**
   * SQL fragment limiting CA rows to those visible to students (Published only).
   */
    function student_ca_published_status_clause(mysqli $db, string $tableAlias = ''): string
    {
        $saCols = student_ca_table_columns($db, 'semester_assessment');
        if (!isset($saCols['status'])) {
            return '';
        }
        $col = ($tableAlias !== '' ? "`{$tableAlias}`." : '') . "`{$saCols['status']}`";
        return " AND {$col} = 'Published'";
    }
}

if (!function_exists('student_ca_year_candidates')) {
    /**
     * @return list<string>
     */
    function student_ca_year_candidates(string $academicYear, string $yearOfStudy): array
    {
        $yearCandidates = [];
        if ($academicYear !== '') {
            $yearCandidates[] = $academicYear;
            if (preg_match('/\d{4}/', $academicYear, $yearMatch) && !in_array($yearMatch[0], $yearCandidates, true)) {
                $yearCandidates[] = $yearMatch[0];
            }
        }
        if ($yearOfStudy !== '' && !in_array($yearOfStudy, $yearCandidates, true)) {
            $yearCandidates[] = $yearOfStudy;
        }
        return $yearCandidates;
    }
}

if (!function_exists('student_ca_fetch_student_profile')) {
    function student_ca_fetch_student_profile(mysqli $db, string $sid): ?array
    {
        require_once dirname(__DIR__, 2) . '/includes/assessment_weighting_helpers.php';

        $studentSidCol = assessment_weighting_column_exists($db, 'students', 'SID') ? 'SID' : 'Sid';
        $studentProgramSidCol = assessment_weighting_column_exists($db, 'student_program', 'Sid') ? 'Sid'
            : (assessment_weighting_column_exists($db, 'student_program', 'SID') ? 'SID'
            : (assessment_weighting_column_exists($db, 'student_program', 'student_id') ? 'student_id' : ''));
        $programJoin = '';
        $programSelect = "'' AS program_name, '' AS program_code";
        if ($studentProgramSidCol !== '' && assessment_weighting_column_exists($db, 'student_program', 'program_code')) {
            $spOrder = assessment_weighting_column_exists($db, 'student_program', 'id') ? 'sp.id DESC' : 'sp.registration_date DESC';
            $programJoin .= " LEFT JOIN student_program sp ON sp.`{$studentProgramSidCol}` COLLATE utf8mb4_general_ci = s.`{$studentSidCol}` COLLATE utf8mb4_general_ci"
                . " AND sp.id = (SELECT sp2.id FROM student_program sp2"
                . " WHERE sp2.`{$studentProgramSidCol}` COLLATE utf8mb4_general_ci = s.`{$studentSidCol}` COLLATE utf8mb4_general_ci"
                . " ORDER BY {$spOrder} LIMIT 1)";
            if (assessment_weighting_table_exists($db, 'programs')) {
                $programJoin .= " LEFT JOIN programs p ON p.program_code COLLATE utf8mb4_general_ci = sp.program_code COLLATE utf8mb4_general_ci";
                $programSelect = "COALESCE(p.program_name, '') AS program_name, COALESCE(p.program_code, sp.program_code) AS program_code";
            } else {
                $programSelect = "'' AS program_name, sp.program_code AS program_code";
            }
        } elseif (assessment_weighting_column_exists($db, 'students', 'program')) {
            if (assessment_weighting_table_exists($db, 'programs')) {
                $programJoin = " LEFT JOIN programs p ON p.program_code COLLATE utf8mb4_general_ci = s.program COLLATE utf8mb4_general_ci";
                $programSelect = "COALESCE(p.program_name, '') AS program_name, COALESCE(s.program, '') AS program_code";
            } else {
                $programSelect = "'' AS program_name, COALESCE(s.program, '') AS program_code";
            }
        }

        $studentSql = "SELECT s.`{$studentSidCol}` AS SID, s.Fname, s.Lname, {$programSelect}
                         FROM students s
                         {$programJoin}
                         WHERE s.`{$studentSidCol}` COLLATE utf8mb4_general_ci = ?
                         LIMIT 1";
        if (!$stmt = $db->prepare($studentSql)) {
            return null;
        }
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $student ?: null;
    }
}

if (!function_exists('student_ca_format_score')) {
    function student_ca_format_score($score): string
    {
        if ($score === null || $score === '') {
            return '—';
        }
        return number_format((float)$score, 1);
    }
}

if (!function_exists('student_ca_total_badge_class')) {
    function student_ca_total_badge_class($total): string
    {
        if ($total === null || $total === '') {
            return 'secondary';
        }
        $val = (float)$total;
        if ($val >= 70) {
            return 'success';
        }
        if ($val >= 50) {
            return 'warning text-dark';
        }
        return 'danger';
    }
}

if (!function_exists('student_ca_compute_summary')) {
    /**
     * @param list<object> $records
     * @return array{course_count:int,published_count:int,pending_count:int,average_total:?float}
     */
    function student_ca_compute_summary(array $records): array
    {
        $published = 0;
        $totals = [];
        foreach ($records as $row) {
            if ($row->Total_CA !== null && $row->Total_CA !== '') {
                $published++;
                $totals[] = (float)$row->Total_CA;
            }
        }
        $count = count($records);
        return [
            'course_count' => $count,
            'published_count' => $published,
            'pending_count' => max(0, $count - $published),
            'average_total' => $totals !== [] ? round(array_sum($totals) / count($totals), 1) : null,
        ];
    }
}

if (!function_exists('student_ca_load_short_course_records')) {
    function student_ca_load_short_course_records(mysqli $db, string $sid): array
    {
        $records = [];
        if (!student_ca_table_exists($db, 'short_courses') || !student_ca_table_exists($db, 'short_course_enrollments')) {
            return $records;
        }

        $hasShortCourseAssessment = student_ca_table_exists($db, 'short_course_assessment');
        $shortSql = "SELECT sc.id, sc.course_code, sc.course_name, sc.start_date, sc.end_date
                     FROM short_course_enrollments sce
                     INNER JOIN short_courses sc ON sc.id = sce.short_course_id
                     WHERE sce.student_id COLLATE utf8mb4_general_ci = ?
                       AND COALESCE(sce.status, 'enrolled') IN ('enrolled','active','completed')
                     ORDER BY sc.course_code";
        if (!$shortStmt = $db->prepare($shortSql)) {
            return $records;
        }
        $shortStmt->bind_param('s', $sid);
        $shortStmt->execute();
        $shortRes = $shortStmt->get_result();
        while ($short = $shortRes->fetch_object()) {
            $caData = student_ca_empty_row();
            if ($hasShortCourseAssessment && $caStmt = $db->prepare('SELECT A1, A2, T1, T2, Total_CA FROM short_course_assessment WHERE short_course_id = ? AND student_id = ? LIMIT 1')) {
                $shortCourseId = (int)$short->id;
                $caStmt->bind_param('is', $shortCourseId, $sid);
                $caStmt->execute();
                $caRow = $caStmt->get_result()->fetch_object();
                if ($caRow) {
                    $caData = $caRow;
                }
                $caStmt->close();
            }
            $records[] = (object)[
                'course_code' => $short->course_code,
                'course_name' => $short->course_name,
                'start_date' => $short->start_date,
                'end_date' => $short->end_date,
                'A1' => $caData->A1,
                'A2' => $caData->A2,
                'T1' => $caData->T1,
                'T2' => $caData->T2,
                'Total_CA' => $caData->Total_CA,
            ];
        }
        $shortStmt->close();
        return $records;
    }
}

if (!function_exists('student_ca_resolve_year_of_study')) {
    function student_ca_resolve_year_of_study(mysqli $db, string $sid, string $academicYear): string
    {
        $registration = student_ca_latest_registration($db, $sid, $academicYear);
        $yearOfStudy = trim((string)($registration['year_of_study'] ?? ''));
        return $yearOfStudy !== '' ? $yearOfStudy : '1';
    }
}

if (!function_exists('student_ca_period_columns')) {
    /**
     * @return array{
     *   period_mode:string,
     *   period_label:string,
     *   period_short_label:string,
     *   periods:int[],
     *   headers:array<int,string>
     * }
     */
    function student_ca_period_columns(mysqli $db, string $programCode, string $programStructure): array
    {
        require_once dirname(__DIR__, 2) . '/includes/helpers/academic_period_helpers.php';

        $structure = $programCode !== ''
            ? getProgramAcademicStructure($db, $programCode)
            : [
                'period_mode' => $programStructure,
                'period_label' => wuc_period_label_from_structure($programStructure, true),
                'period_short_label' => wuc_period_short_label_from_structure($programStructure),
                'valid_periods' => $programStructure === 'term' ? [1, 2, 3] : [1, 2],
            ];

        $periodMode = (string)($structure['period_mode'] ?? $programStructure);
        if (!in_array($periodMode, ['term', 'semester'], true)) {
            $periodMode = $programStructure === 'term' ? 'term' : 'semester';
        }

        $periods = array_values(array_map('intval', (array)($structure['valid_periods'] ?? [])));
        if ($periods === []) {
            $periods = $periodMode === 'term' ? [1, 2, 3] : [1, 2];
        }

        $shortLabel = (string)($structure['period_short_label'] ?? wuc_period_short_label_from_structure($periodMode));
        $headers = [];
        foreach ($periods as $period) {
            $headers[$period] = $shortLabel . ' ' . $period;
        }

        return [
            'period_mode' => $periodMode,
            'period_label' => (string)($structure['period_label'] ?? wuc_period_label_from_structure($periodMode, true)),
            'period_short_label' => $shortLabel,
            'periods' => $periods,
            'headers' => $headers,
        ];
    }
}

if (!function_exists('student_ca_fetch_period_totals_map')) {
    /**
     * Published Total_CA keyed by course code then period number.
     *
     * @return array<string, array<int, float>>
     */
    function student_ca_fetch_period_totals_map(
        mysqli $db,
        string $sid,
        string $academicYear,
        string $yearOfStudy,
        array $periods
    ): array {
        $map = [];
        if ($sid === '' || !student_ca_table_exists($db, 'semester_assessment') || $periods === []) {
            return $map;
        }

        $publishedOnly = student_ca_published_status_clause($db);
        $yearCandidates = student_ca_year_candidates($academicYear, $yearOfStudy);

        $periodPlaceholders = implode(',', array_fill(0, count($periods), '?'));
        $yearPlaceholders = implode(',', array_fill(0, count($yearCandidates), '?'));

        $sql = "SELECT Course_Code, semester, Total_CA
                FROM semester_assessment
                WHERE Sid COLLATE utf8mb4_general_ci = ?
                  AND `Year` IN ({$yearPlaceholders})
                  AND semester IN ({$periodPlaceholders})
                  {$publishedOnly}";

        if (!$stmt = $db->prepare($sql)) {
            error_log('ContinuousAssessment period totals prepare failed: ' . $db->error);
            return $map;
        }

        $types = 's' . str_repeat('s', count($yearCandidates)) . str_repeat('s', count($periods));
        $params = array_merge([$sid], $yearCandidates, array_map('strval', $periods));
        student_ca_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $code = student_ca_normalize_course_code((string)($row['Course_Code'] ?? ''));
            $period = (int)($row['semester'] ?? 0);
            $total = $row['Total_CA'] ?? null;
            if ($code === '' || $period <= 0 || $total === null || $total === '') {
                continue;
            }
            if (!isset($map[$code])) {
                $map[$code] = [];
            }
            $map[$code][$period] = (float)$total;
        }
        $stmt->close();

        return $map;
    }
}

if (!function_exists('student_ca_compute_final_ca')) {
    /**
     * Year-end CA from published period totals (average of available periods).
     *
     * @param array<int, float|null> $periodTotals
     */
    function student_ca_compute_final_ca(array $periodTotals): ?float
    {
        $values = [];
        foreach ($periodTotals as $total) {
            if ($total !== null && $total !== '') {
                $values[] = (float)$total;
            }
        }
        if ($values === []) {
            return null;
        }
        return round(array_sum($values) / count($values), 1);
    }
}

if (!function_exists('student_ca_score_or_null')) {
    function student_ca_score_or_null($score): ?float
    {
        if ($score === null || $score === '') {
            return null;
        }
        return (float)$score;
    }
}

if (!function_exists('student_ca_empty_period_components')) {
    /**
     * @return array{ass1:?float,ass2:?float,test:?float,total:?float}
     */
    function student_ca_empty_period_components(): array
    {
        return [
            'ass1' => null,
            'ass2' => null,
            'test' => null,
            'total' => null,
        ];
    }
}

if (!function_exists('student_ca_fetch_period_components_map')) {
    /**
     * Published component marks keyed by course code then term number.
     *
     * @return array<string, array<int, array{ass1:?float,ass2:?float,test:?float,total:?float}>>
     */
    function student_ca_fetch_period_components_map(
        mysqli $db,
        string $sid,
        string $academicYear,
        string $yearOfStudy,
        array $periods
    ): array {
        $map = [];
        if ($sid === '' || !student_ca_table_exists($db, 'semester_assessment') || $periods === []) {
            return $map;
        }

        $publishedOnly = student_ca_published_status_clause($db);
        $yearCandidates = student_ca_year_candidates($academicYear, $yearOfStudy);
        if ($yearCandidates === []) {
            return $map;
        }

        $periodPlaceholders = implode(',', array_fill(0, count($periods), '?'));
        $yearPlaceholders = implode(',', array_fill(0, count($yearCandidates), '?'));

        $sql = "SELECT Course_Code, semester, A1, A2, A3, T1, T2, Total_CA
                FROM semester_assessment
                WHERE Sid COLLATE utf8mb4_general_ci = ?
                  AND `Year` IN ({$yearPlaceholders})
                  AND semester IN ({$periodPlaceholders})
                  {$publishedOnly}
                ORDER BY id ASC";

        if (!$stmt = $db->prepare($sql)) {
            error_log('ContinuousAssessment period components prepare failed: ' . $db->error);
            return $map;
        }

        $types = 's' . str_repeat('s', count($yearCandidates)) . str_repeat('s', count($periods));
        $params = array_merge([$sid], $yearCandidates, array_map('strval', $periods));
        student_ca_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $code = student_ca_normalize_course_code((string)($row['Course_Code'] ?? ''));
            $period = (int)($row['semester'] ?? 0);
            if ($code === '' || $period <= 0) {
                continue;
            }
            if (!isset($map[$code])) {
                $map[$code] = [];
            }
            $testScore = student_ca_score_or_null($row['T1'] ?? null);
            if ($testScore === null) {
                $testScore = student_ca_score_or_null($row['T2'] ?? null);
            }
            $map[$code][$period] = [
                'ass1' => student_ca_score_or_null($row['A1'] ?? null),
                'ass2' => student_ca_score_or_null($row['A2'] ?? null),
                'test' => $testScore,
                'total' => student_ca_score_or_null($row['Total_CA'] ?? null),
            ];
        }
        $stmt->close();

        if ($map === [] && student_ca_table_exists($db, 'assessments')) {
            $map = student_ca_fetch_assessments_fallback_map($db, $sid, $academicYear, $yearOfStudy, $periods);
        }

        return $map;
    }
}

if (!function_exists('student_ca_map_assessment_component')) {
    /**
     * Map legacy assessments.assess_type + assess_num to annual CA component keys.
     */
    function student_ca_map_assessment_component(string $assessType, string $assessNum): ?string
    {
        $type = strtolower(trim($assessType));
        $num = trim($assessNum);
        if (in_array($type, ['a1', 'assignment', 'ass1', 'ass'], true)) {
            return $num === '2' ? 'ass2' : 'ass1';
        }
        if (in_array($type, ['a2', 'ass2'], true)) {
            return 'ass2';
        }
        if (in_array($type, ['t1', 't2', 'test', 'make-up', 'makeup'], true)) {
            return 'test';
        }
        if (preg_match('/^a(\d+)$/i', $type, $m)) {
            return ((int)$m[1]) >= 2 ? 'ass2' : 'ass1';
        }
        if (preg_match('/^t(\d+)$/i', $type, $m)) {
            return 'test';
        }
        return null;
    }
}

if (!function_exists('student_ca_fetch_assessments_fallback_map')) {
    /**
     * Build component marks from the legacy assessments table when semester_assessment
     * has no published rows (common on installs that still use registrar upload).
     *
     * @return array<string, array<int, array{ass1:?float,ass2:?float,test:?float,total:?float}>>
     */
    function student_ca_fetch_assessments_fallback_map(
        mysqli $db,
        string $sid,
        string $academicYear,
        string $yearOfStudy,
        array $periods
    ): array {
        $map = [];
        if ($sid === '' || $periods === []) {
            return $map;
        }

        $assessCols = student_ca_table_columns($db, 'assessments');
        if (!$assessCols) {
            return $map;
        }

        $sidCol = student_ca_pick_column($assessCols, ['SID', 'Sid', 'student_id'], 'SID');
        $courseCol = student_ca_pick_column($assessCols, ['course_code', 'Course_Code'], 'course_code');
        $periodCol = student_ca_pick_column($assessCols, ['semester', 'term'], 'semester');
        $yearCol = student_ca_pick_column($assessCols, ['year', 'Year', 'academic_year'], 'year');
        $typeCol = student_ca_pick_column($assessCols, ['assess_type', 'component'], 'assess_type');
        $numCol = student_ca_pick_column($assessCols, ['assess_num', 'component_num'], 'assess_num');
        $marksCol = student_ca_pick_column($assessCols, ['marks', 'mark', 'score'], 'marks');

        $yearCandidates = student_ca_year_candidates($academicYear, $yearOfStudy);
        $periodPlaceholders = implode(',', array_fill(0, count($periods), '?'));
        $yearClause = '';
        $params = [$sid];
        $types = 's';
        if ($yearCandidates !== []) {
            $yearPlaceholders = implode(',', array_fill(0, count($yearCandidates), '?'));
            $yearClause = " AND `{$yearCol}` IN ({$yearPlaceholders})";
            $params = array_merge($params, $yearCandidates);
            $types .= str_repeat('s', count($yearCandidates));
        }
        $params = array_merge($params, array_map('strval', $periods));
        $types .= str_repeat('s', count($periods));

        $sql = "SELECT `{$courseCol}` AS course_code, `{$periodCol}` AS period_value,
                       `{$typeCol}` AS assess_type, `{$numCol}` AS assess_num, `{$marksCol}` AS marks
                FROM assessments
                WHERE `{$sidCol}` = ?
                  {$yearClause}
                  AND `{$periodCol}` IN ({$periodPlaceholders})";

        if (!$stmt = $db->prepare($sql)) {
            error_log('ContinuousAssessment assessments fallback prepare failed: ' . $db->error);
            return $map;
        }
        student_ca_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $code = student_ca_normalize_course_code((string)($row['course_code'] ?? ''));
            $period = (int)($row['period_value'] ?? 0);
            $componentKey = student_ca_map_assessment_component(
                (string)($row['assess_type'] ?? ''),
                (string)($row['assess_num'] ?? '')
            );
            $mark = student_ca_score_or_null($row['marks'] ?? null);
            if ($code === '' || $period <= 0 || $componentKey === null || $mark === null) {
                continue;
            }
            if (!isset($map[$code])) {
                $map[$code] = [];
            }
            if (!isset($map[$code][$period])) {
                $map[$code][$period] = student_ca_empty_period_components();
            }
            $map[$code][$period][$componentKey] = $mark;
            $parts = array_filter(
                [$map[$code][$period]['ass1'], $map[$code][$period]['ass2'], $map[$code][$period]['test']],
                static fn($v): bool => $v !== null
            );
            if ($parts !== []) {
                $map[$code][$period]['total'] = round(array_sum($parts), 1);
            }
        }
        $stmt->close();

        return $map;
    }
}

if (!function_exists('student_ca_count_pending_publication')) {
    /**
     * Rows with marks entered but not yet Published (student cannot see these).
     */
    function student_ca_count_pending_publication(
        mysqli $db,
        string $sid,
        string $academicYear,
        string $yearOfStudy,
        array $periods
    ): int {
        if ($sid === '' || !student_ca_table_exists($db, 'semester_assessment') || $periods === []) {
            return 0;
        }
        $saCols = student_ca_table_columns($db, 'semester_assessment');
        if (!isset($saCols['status'])) {
            return 0;
        }

        $yearCandidates = student_ca_year_candidates($academicYear, $yearOfStudy);
        if ($yearCandidates === []) {
            return 0;
        }

        $periodPlaceholders = implode(',', array_fill(0, count($periods), '?'));
        $yearPlaceholders = implode(',', array_fill(0, count($yearCandidates), '?'));
        $statusCol = $saCols['status'];

        $sql = "SELECT COUNT(*) AS pending_count
                FROM semester_assessment
                WHERE Sid COLLATE utf8mb4_general_ci = ?
                  AND `Year` IN ({$yearPlaceholders})
                  AND semester IN ({$periodPlaceholders})
                  AND `{$statusCol}` <> 'Published'
                  AND Total_CA IS NOT NULL";

        if (!$stmt = $db->prepare($sql)) {
            return 0;
        }
        $types = 's' . str_repeat('s', count($yearCandidates)) . str_repeat('s', count($periods));
        $params = array_merge([$sid], $yearCandidates, array_map('strval', $periods));
        student_ca_bind($stmt, $types, $params);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['pending_count'] ?? 0);
        $stmt->close();
        return $count;
    }
}

if (!function_exists('student_ca_build_annual_records')) {
    /**
     * @param list<array<string,mixed>> $courses
     * @param array<string, array<int, array{ass1:?float,ass2:?float,test:?float,total:?float}>> $componentsMap
     * @param int[] $periods
     * @return list<object{
     *   course_code:string,
     *   course_name:string,
     *   year_of_study:string,
     *   periods:array<int, array{ass1:?float,ass2:?float,test:?float,total:?float}>,
     *   final_ca:?float
     * }>
     */
    function student_ca_build_annual_records(array $courses, array $componentsMap, array $periods, string $yearOfStudy = ''): array
    {
        $records = [];
        foreach ($courses as $courseRow) {
            $code = trim((string)($courseRow['course_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $mapKey = student_ca_normalize_course_code($code);
            $periodValues = [];
            $periodTotals = [];
            foreach ($periods as $period) {
                $periodValues[$period] = $componentsMap[$mapKey][$period] ?? student_ca_empty_period_components();
                $periodTotals[$period] = $periodValues[$period]['total'] ?? null;
            }
            $records[] = (object)[
                'course_code' => $code,
                'course_name' => (string)($courseRow['course_name'] ?? $code),
                'year_of_study' => $yearOfStudy,
                'periods' => $periodValues,
                'final_ca' => student_ca_compute_final_ca($periodTotals),
            ];
        }
        return $records;
    }
}

if (!function_exists('student_ca_period_has_mark')) {
    function student_ca_period_has_mark($periodData): bool
    {
        if (!is_array($periodData)) {
            return $periodData !== null && $periodData !== '';
        }
        foreach (['ass1', 'ass2', 'test', 'total'] as $key) {
            if (($periodData[$key] ?? null) !== null && ($periodData[$key] ?? '') !== '') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('student_ca_compute_annual_summary')) {
    /**
     * @param list<object> $records
     * @param int[] $periods
     * @return array{course_count:int,published_count:int,pending_count:int,average_total:?float}
     */
    function student_ca_compute_annual_summary(array $records, array $periods): array
    {
        $published = 0;
        $finalTotals = [];
        foreach ($records as $row) {
            $hasPeriodMark = false;
            foreach ($periods as $period) {
                if (student_ca_period_has_mark($row->periods[$period] ?? null)) {
                    $hasPeriodMark = true;
                    break;
                }
            }
            if ($hasPeriodMark) {
                $published++;
            }
            if ($row->final_ca !== null) {
                $finalTotals[] = (float)$row->final_ca;
            }
        }
        $count = count($records);
        return [
            'course_count' => $count,
            'published_count' => $published,
            'pending_count' => max(0, $count - $published),
            'average_total' => $finalTotals !== [] ? round(array_sum($finalTotals) / count($finalTotals), 1) : null,
        ];
    }
}

if (!function_exists('student_ca_period_component_labels')) {
    /**
     * Column labels per period for the annual CA table.
     *
     * @param int[] $periodNumbers
     * @return array<int, array<string, string>>
     */
    function student_ca_period_component_labels(array $periodNumbers, string $periodMode): array
    {
        $labels = [];
        foreach ($periodNumbers as $period) {
            $period = (int)$period;
            if ($period <= 0) {
                continue;
            }
            if ($periodMode === 'term' && $period === 3) {
                $labels[$period] = ['ass1' => 'Ass1', 'ass2' => 'Ass2'];
            } else {
                $labels[$period] = ['ass1' => 'Ass1', 'ass2' => 'Ass2', 'test' => 'Test'];
            }
        }
        return $labels;
    }
}

if (!function_exists('student_ca_render_period_score_html')) {
    function student_ca_render_period_score_html(?float $score): string
    {
        if ($score === null) {
            return '<span class="ca-score missing">—</span>';
        }
        return '<span class="ca-period-score">' . student_ca_h(student_ca_format_score($score)) . '</span>';
    }
}

if (!function_exists('student_ca_render_component_score_html')) {
    function student_ca_render_component_score_html(?float $score): string
    {
        if ($score === null) {
            return '<span class="ca-score missing">-</span>';
        }
        return student_ca_h(student_ca_format_score($score));
    }
}

if (!function_exists('student_ca_render_final_score_html')) {
    function student_ca_render_final_score_html(?float $score): string
    {
        if ($score === null) {
            return '<span class="ca-total is-missing">-</span>';
        }
        $tone = student_ca_total_badge_class($score);
        $toneClass = 'neutral';
        if (str_contains($tone, 'success')) {
            $toneClass = 'pass';
        } elseif (str_contains($tone, 'warning')) {
            $toneClass = 'mid';
        } elseif (str_contains($tone, 'danger')) {
            $toneClass = 'low';
        }
        return '<span class="ca-total ca-total--' . student_ca_h($toneClass) . '">'
            . student_ca_h(student_ca_format_score($score))
            . '</span>';
    }
}
