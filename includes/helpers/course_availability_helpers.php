<?php
declare(strict_types=1);

/**
 * Course availability helpers.
 *
 * Legacy curriculum tables store a period number in columns like `semester`,
 * but those columns are not proof that a course is owned by only that period.
 * Unless a row has an explicit specificity flag, programme courses are treated
 * as available for the whole academic year.
 */

if (!function_exists('wuc_course_availability_columns')) {
    function wuc_course_availability_columns(mysqli $db, string $table): array
    {
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $columns = [];
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        if ($safe !== '' && ($meta = @$db->query("SHOW COLUMNS FROM `{$safe}`"))) {
            while ($row = $meta->fetch_assoc()) {
                $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
            }
            $meta->free();
        }

        return $cache[$key] = $columns;
    }
}

if (!function_exists('wuc_course_availability_period_filter')) {
    /**
     * Optional delivery-period filter. When $period is null, returns no filter
     * (academic-year-wide catalogue).
     *
     * @return array{sql:string,types:string,params:list<string>}
     */
    function wuc_course_availability_period_filter(array $columns, string $alias, ?string $periodColumn, $period): array
    {
        if ($period === null || $period === '') {
            return ['sql' => '1=1', 'types' => '', 'params' => []];
        }

        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 't';
        $periodColumn = $periodColumn !== null ? trim($periodColumn) : '';

        $hasFullYear = isset($columns['is_full_year']);
        $specificFlags = array_values(array_filter([
            $columns['is_period_specific'] ?? null,
            $columns['is_term_specific'] ?? null,
            $columns['is_semester_specific'] ?? null,
        ]));
        $deliveryCol = $columns['delivery_period'] ?? null;

        if ($periodColumn === '' || (!$hasFullYear && !$specificFlags && !$deliveryCol)) {
            return ['sql' => '1=1', 'types' => '', 'params' => []];
        }

        $fullYearClauses = [];
        if ($hasFullYear) {
            $fullYearClauses[] = "COALESCE({$alias}.`{$columns['is_full_year']}`, 0) = 1";
        }
        foreach ($specificFlags as $flagCol) {
            $fullYearClauses[] = "COALESCE({$alias}.`{$flagCol}`, 0) = 0";
        }
        if ($deliveryCol) {
            $fullYearClauses[] = "LOWER(COALESCE({$alias}.`{$deliveryCol}`, '')) IN ('', 'full_year', 'year', 'annual', 'all')";
        }

        $fullYearSql = $fullYearClauses ? '(' . implode(' AND ', $fullYearClauses) . ')' : '0=1';
        return [
            'sql' => "({$fullYearSql} OR {$alias}.`{$periodColumn}` = ?)",
            'types' => 's',
            'params' => [(string)$period],
        ];
    }
}

if (!function_exists('wuc_program_course_year_exists')) {
    /** Whether programme already owns this course for the given year of study. */
    function wuc_program_course_year_exists(mysqli $db, string $programCode, string $courseCode, int $year): bool
    {
        $programCode = trim($programCode);
        $courseCode = trim($courseCode);
        if ($programCode === '' || $courseCode === '' || $year < 1) {
            return false;
        }
        $stmt = $db->prepare(
            'SELECT 1 FROM program_courses
              WHERE program_code = ? AND course_code = ? AND year = ?
              LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssi', $programCode, $courseCode, $year);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('wuc_insert_program_course_assignment')) {
    /**
     * Assign a course to a programme for a year of study.
     *
     * By default the course is available for the full academic year.
     * Set $periodSpecific true only for explicit term/semester exceptions.
     *
     * @return array{ok:bool,skipped:bool,message:string}
     */
    function wuc_insert_program_course_assignment(
        mysqli $db,
        string $programCode,
        string $courseCode,
        int $year,
        ?int $deliveryPeriod = null,
        bool $periodSpecific = false
    ): array {
        $programCode = trim($programCode);
        $courseCode = trim($courseCode);
        if ($programCode === '' || $courseCode === '' || $year < 1) {
            return ['ok' => false, 'skipped' => false, 'message' => 'Invalid programme, course, or year.'];
        }

        if (wuc_program_course_year_exists($db, $programCode, $courseCode, $year)) {
            return ['ok' => true, 'skipped' => true, 'message' => 'Course already assigned for this year.'];
        }

        $cols = wuc_course_availability_columns($db, 'program_courses');
        $hasFlags = isset($cols['is_full_year']);
        $semester = ($deliveryPeriod !== null && $deliveryPeriod > 0) ? $deliveryPeriod : 1;

        if ($hasFlags) {
            $isFullYear = $periodSpecific ? 0 : 1;
            $isPeriodSpecific = $periodSpecific ? 1 : 0;
            $delivery = $periodSpecific ? (string)$semester : 'full_year';
            $sql = 'INSERT INTO program_courses
                        (program_code, course_code, year, semester, is_required,
                         is_full_year, is_period_specific, delivery_period)
                    VALUES (?, ?, ?, ?, 1, ?, ?, ?)';
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return ['ok' => false, 'skipped' => false, 'message' => 'Could not prepare insert.'];
            }
            $stmt->bind_param('ssiiiis', $programCode, $courseCode, $year, $semester, $isFullYear, $isPeriodSpecific, $delivery);
        } else {
            $sql = 'INSERT INTO program_courses (program_code, course_code, year, semester, is_required)
                    VALUES (?, ?, ?, ?, 1)';
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return ['ok' => false, 'skipped' => false, 'message' => 'Could not prepare insert.'];
            }
            $stmt->bind_param('ssii', $programCode, $courseCode, $year, $semester);
        }

        $ok = $stmt->execute();
        $err = $ok ? '' : ($stmt->error ?: 'Insert failed.');
        $stmt->close();

        return [
            'ok' => $ok,
            'skipped' => false,
            'message' => $ok ? 'Course assigned.' : $err,
        ];
    }
}
