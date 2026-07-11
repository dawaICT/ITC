<?php
/**
 * CourseService - compatibility service for course catalogue and registration.
 *
 * Canonical registration storage is course_registration. Older code used
 * student_courses, but that table is not part of the current schema.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers/academic_structure_helpers.php';
require_once __DIR__ . '/../../includes/helpers/course_availability_helpers.php';

class CourseService
{
    private mysqli $db;

    public function __construct(mysqli $dbConnection)
    {
        $this->db = $dbConnection;
    }

    /**
     * Get courses available for a specific program, year, and semester.
     */
    public function getCoursesForProgram($programCode, $yearOfStudy, $semester): array
    {
        if (trim((string)$programCode) === '') {
            return [];
        }

        $catalog = $this->courseCatalogMeta();
        $courses = [];

        if ($this->tableExists('course_levels')) {
            $cl = $this->columnsFor('course_levels');
            $progCol = $cl['program_code'] ?? 'program_code';
            $courseCol = $cl['course_code'] ?? 'course_code';
            $yearCol = $cl['year'] ?? ($cl['year_of_study'] ?? ($cl['year_level'] ?? null));
            $semCol = $cl['semester'] ?? null;
            $statusCol = $cl['status'] ?? null;

            $where = ["cl.`{$progCol}` = ?"];
            $types = 's';
            $params = [(string)$programCode];

            if ($yearCol) {
                $where[] = "cl.`{$yearCol}` = ?";
                $types .= 's';
                $params[] = (string)$yearOfStudy;
            }
            if ($semCol) {
                $periodFilter = wuc_course_availability_period_filter($cl, 'cl', $semCol, $semester);
                if ($periodFilter['sql'] !== '1=1') {
                    $where[] = $periodFilter['sql'];
                    $types .= $periodFilter['types'];
                    $params = array_merge($params, $periodFilter['params']);
                }
            }
            if ($statusCol) {
                $where[] = "(cl.`{$statusCol}` IS NULL OR cl.`{$statusCol}` = '' OR LOWER(cl.`{$statusCol}`) = 'active')";
            }
            $where[] = "COALESCE(p.is_active, 1) = 1";
            $where[] = $catalog['active_filter'];

            $sql = "SELECT DISTINCT
                        cl.`{$courseCol}` AS course_code,
                        {$catalog['name_select_with_cl']},
                        {$catalog['credits_select']},
                        {$catalog['fee_select']},
                        '' AS prerequisites,
                        1 AS is_compulsory
                    FROM course_levels cl
                    JOIN programs p ON p.program_code = cl.`{$progCol}`
                    {$catalog['join_sql_cl']}
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY cl.`{$courseCol}`";

            $courses = $this->fetchAll($sql, $types, $params);
        }

        if (!empty($courses) || !$this->tableExists('program_courses')) {
            return $courses;
        }

        $pc = $this->columnsFor('program_courses');
        $progCol = $pc['program_code'] ?? 'program_code';
        $courseCol = $pc['course_code'] ?? 'course_code';
        $yearCol = $pc['year_of_study'] ?? ($pc['year'] ?? ($pc['year_level'] ?? null));
        $semCol = $pc['semester'] ?? null;

        $where = ["pc.`{$progCol}` = ?"];
        $types = 's';
        $params = [(string)$programCode];

        if ($yearCol) {
            $where[] = "pc.`{$yearCol}` = ?";
            $types .= 's';
            $params[] = (string)$yearOfStudy;
        }
        if ($semCol) {
            $periodFilter = wuc_course_availability_period_filter($pc, 'pc', $semCol, $semester);
            if ($periodFilter['sql'] !== '1=1') {
                $where[] = $periodFilter['sql'];
                $types .= $periodFilter['types'];
                $params = array_merge($params, $periodFilter['params']);
            }
        }
        $where[] = "COALESCE(p.is_active, 1) = 1";
        $where[] = $catalog['active_filter'];

        $sql = "SELECT DISTINCT
                    pc.`{$courseCol}` AS course_code,
                    {$catalog['name_select_with_pc']},
                    {$catalog['credits_select']},
                    {$catalog['fee_select']},
                    '' AS prerequisites,
                    1 AS is_compulsory
                FROM program_courses pc
                JOIN programs p ON p.program_code = pc.`{$progCol}`
                {$catalog['join_sql_pc']}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY pc.`{$courseCol}`";

        return $this->fetchAll($sql, $types, $params);
    }

    /**
     * Get courses already registered by a student for an academic year and semester.
     */
    public function getRegisteredCourses($studentId, $academicYear, $semester): array
    {
        if (!$this->tableExists('course_registration')) {
            return [];
        }

        $cr = $this->columnsFor('course_registration');
        $sidCol = $cr['sid'] ?? ($cr['student_id'] ?? 'Sid');
        $courseCol = $cr['course_code'] ?? 'course_code';
        $semCol = $cr['semester'] ?? 'semester';
        $semRegCol = $cr['semester_registration_id'] ?? null;
        $activeCol = $cr['is_active'] ?? null;
        $createdCol = $cr['created_at'] ?? ($cr['registration_date'] ?? null);
        $catalog = $this->courseCatalogMeta();

        $where = ["cr.`{$sidCol}` = ?", "cr.`{$semCol}` = ?"];
        $types = 'ss';
        $params = [(string)$studentId, (string)$semester];
        $joinSemesterRegistration = '';

        if ($semRegCol && $this->tableExists('semester_registration')) {
            $sr = $this->columnsFor('semester_registration');
            $srAcademicYearCol = $sr['academic_year'] ?? null;
            if ($srAcademicYearCol) {
                $joinSemesterRegistration = "LEFT JOIN semester_registration sr ON sr.id = cr.`{$semRegCol}`";
                $where[] = "(sr.`{$srAcademicYearCol}` = ? OR sr.`{$srAcademicYearCol}` LIKE ? OR ? LIKE CONCAT(sr.`{$srAcademicYearCol}`, '%'))";
                $types .= 'sss';
                $params[] = (string)$academicYear;
                $params[] = substr((string)$academicYear, 0, 4) . '%';
                $params[] = (string)$academicYear;
            }
        }

        if ($activeCol) {
            $where[] = "COALESCE(cr.`{$activeCol}`, 1) = 1";
        }

        $registrationDateSelect = $createdCol ? "cr.`{$createdCol}` AS registration_date" : "NULL AS registration_date";
        $statusSelect = $activeCol ? "CASE WHEN COALESCE(cr.`{$activeCol}`, 1) = 1 THEN 'registered' ELSE 'dropped' END AS status" : "'registered' AS status";

        $sql = "SELECT DISTINCT
                    cr.id AS registration_course_id,
                    cr.`{$courseCol}` AS course_code,
                    {$catalog['name_select_with_cr']},
                    {$catalog['credits_select']},
                    {$catalog['fee_select']},
                    {$statusSelect},
                    {$registrationDateSelect}
                FROM course_registration cr
                {$catalog['join_sql_cr']}
                {$joinSemesterRegistration}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY cr.`{$courseCol}`";

        return $this->fetchAll($sql, $types, $params);
    }

    /**
     * Get course fee information by course code.
     */
    public function getCourseFee($courseCode): ?array
    {
        return $this->getCourseDetails($courseCode);
    }

    /**
     * Register a course for a student against a semester_registration id.
     */
    public function registerCourseForStudent($studentId, $registrationId, $courseData): bool
    {
        if (!$this->tableExists('course_registration') || !$this->tableExists('semester_registration')) {
            return false;
        }

        $courseCode = trim((string)($courseData['course_code'] ?? ''));
        if ($courseCode === '') {
            return false;
        }

        $sr = $this->fetchSemesterRegistration((int)$registrationId, (string)$studentId);
        if (!$sr) {
            return false;
        }
        $programCode = trim((string)($sr['program_code'] ?? ''));
        if ($programCode !== '') {
            $guard = wuc_legacy_course_registration_guard($this->db, (string)$studentId, $programCode, $sr['year_of_study'], $sr['semester'], [$courseCode]);
            if (!$guard['ok']) {
                error_log('CourseService registration rejected: ' . $guard['reason']);
                return false;
            }
        }

        $cr = $this->columnsFor('course_registration');
        $sidCol = $cr['sid'] ?? ($cr['student_id'] ?? 'Sid');
        $courseCol = $cr['course_code'] ?? 'course_code';
        $semCol = $cr['semester'] ?? 'semester';
        $yearCol = $cr['year'] ?? ($cr['year_of_study'] ?? 'Year');
        $semRegCol = $cr['semester_registration_id'] ?? null;
        $activeCol = $cr['is_active'] ?? null;

        $checkSql = "SELECT id FROM course_registration WHERE `{$sidCol}` = ? AND `{$courseCol}` = ? AND `{$semCol}` = ? AND `{$yearCol}` = ? LIMIT 1";
        if ($stmt = $this->db->prepare($checkSql)) {
            $semester = (string)$sr['semester'];
            $year = (string)$sr['year_of_study'];
            $stmt->bind_param('ssss', $studentId, $courseCode, $semester, $year);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && $activeCol) {
                $id = (int)$row['id'];
                $this->db->query("UPDATE course_registration SET `{$activeCol}` = 1 WHERE id = {$id}");
                if ($programCode !== '') {
                    $sync = wuc_sync_legacy_course_registration_to_canonical($this->db, (string)$studentId, $programCode, $courseCode, $sr['year_of_study'], $sr['semester']);
                    if (!$sync['ok']) {
                        error_log('CourseService canonical sync failed: ' . $sync['reason']);
                        return false;
                    }
                }
                return true;
            }
            if ($row) {
                if ($programCode !== '') {
                    $sync = wuc_sync_legacy_course_registration_to_canonical($this->db, (string)$studentId, $programCode, $courseCode, $sr['year_of_study'], $sr['semester']);
                    if (!$sync['ok']) {
                        error_log('CourseService canonical sync failed: ' . $sync['reason']);
                        return false;
                    }
                }
                return true;
            }
        }

        $columns = ["`{$sidCol}`", "`{$courseCol}`", "`{$semCol}`", "`{$yearCol}`"];
        $values = ['?', '?', '?', '?'];
        $types = 'ssss';
        $params = [(string)$studentId, $courseCode, (string)$sr['semester'], (string)$sr['year_of_study']];

        if ($semRegCol) {
            $columns[] = "`{$semRegCol}`";
            $values[] = '?';
            $types .= 'i';
            $params[] = (int)$registrationId;
        }
        if ($activeCol) {
            $columns[] = "`{$activeCol}`";
            $values[] = '1';
        }

        $sql = 'INSERT INTO course_registration (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
        $ok = $this->executeStatement($sql, $types, $params);
        if ($ok && $programCode !== '') {
            $sync = wuc_sync_legacy_course_registration_to_canonical($this->db, (string)$studentId, $programCode, $courseCode, $sr['year_of_study'], $sr['semester']);
            if (!$sync['ok']) {
                error_log('CourseService canonical sync failed: ' . $sync['reason']);
                return false;
            }
        }
        return $ok;
    }

    /**
     * Drop a registered course by marking it inactive where supported.
     */
    public function dropCourse($studentId, $courseId, $academicYear, $semester): bool
    {
        if (!$this->tableExists('course_registration')) {
            return false;
        }

        $cr = $this->columnsFor('course_registration');
        $sidCol = $cr['sid'] ?? ($cr['student_id'] ?? 'Sid');
        $semCol = $cr['semester'] ?? 'semester';
        $activeCol = $cr['is_active'] ?? null;

        if (!$activeCol) {
            return $this->executeStatement(
                "DELETE FROM course_registration WHERE id = ? AND `{$sidCol}` = ? AND `{$semCol}` = ?",
                'iss',
                [(int)$courseId, (string)$studentId, (string)$semester]
            );
        }

        return $this->executeStatement(
            "UPDATE course_registration SET `{$activeCol}` = 0 WHERE id = ? AND `{$sidCol}` = ? AND `{$semCol}` = ?",
            'iss',
            [(int)$courseId, (string)$studentId, (string)$semester]
        );
    }

    /**
     * Check if a student has active course registrations for a term.
     */
    public function checkIfRegistered($studentId, $academicYear, $semester): bool
    {
        return count($this->getRegisteredCourses($studentId, $academicYear, $semester)) > 0;
    }

    /**
     * Get course details by course code.
     */
    public function getCourseDetails($courseCode): ?array
    {
        if (!$this->tableExists('courses')) {
            return null;
        }

        $catalog = $this->courseCatalogMeta();
        $codeCol = $catalog['code_col'];
        $nameCol = $catalog['name_col'];
        $creditsCol = $catalog['credits_col'];
        $feeCol = $catalog['fee_col'];

        $nameSelect = $nameCol ? "`{$nameCol}` AS course_name" : "'' AS course_name";
        $creditsSelect = $creditsCol ? "`{$creditsCol}` AS credit_hours" : "3 AS credit_hours";
        $feeSelect = $feeCol ? "`{$feeCol}` AS fee" : "0 AS fee";

        $statusFilter = $catalog['active_filter_unaliased'];

        $sql = "SELECT `{$codeCol}` AS course_code, {$nameSelect}, {$creditsSelect}, {$feeSelect}
                FROM courses
                WHERE `{$codeCol}` = ? AND {$statusFilter}
                LIMIT 1";

        $rows = $this->fetchAll($sql, 's', [(string)$courseCode]);
        return $rows[0] ?? null;
    }

    private function fetchSemesterRegistration(int $registrationId, string $studentId): ?array
    {
        $sr = $this->columnsFor('semester_registration');
        $sidCol = $sr['student_id'] ?? ($sr['sid'] ?? 'student_id');
        $semCol = $sr['semester'] ?? ($sr['semester_term'] ?? 'semester');
        $yearCol = $sr['year_of_study'] ?? ($sr['year'] ?? 'year_of_study');
        $programCol = $sr['program_code'] ?? null;

        $sql = "SELECT id, `{$semCol}` AS semester, `{$yearCol}` AS year_of_study"
             . ($programCol ? ", `{$programCol}` AS program_code" : ", '' AS program_code") . "
                FROM semester_registration
                WHERE id = ? AND `{$sidCol}` = ?
                LIMIT 1";

        $rows = $this->fetchAll($sql, 'is', [$registrationId, $studentId]);
        return $rows[0] ?? null;
    }

    private function courseCatalogMeta(): array
    {
        $cols = $this->columnsFor('courses');
        $codeCol = $cols['course_code'] ?? 'course_code';
        $nameCol = $cols['course_name'] ?? ($cols['name'] ?? null);
        $creditsCol = $cols['credit_hours'] ?? ($cols['credits'] ?? ($cols['credit'] ?? ($cols['units'] ?? null)));
        $feeCol = $cols['fee'] ?? ($cols['course_fee'] ?? ($cols['cost'] ?? null));
        $statusCol = $cols['status'] ?? null;

        $nameSelect = $nameCol ? "COALESCE(c.`{$nameCol}`, '') AS course_name" : "'' AS course_name";
        $creditsSelect = $creditsCol ? "COALESCE(c.`{$creditsCol}`, 3) AS credit_hours" : "3 AS credit_hours";
        $feeSelect = $feeCol ? "COALESCE(c.`{$feeCol}`, 0) AS fee" : "0 AS fee";
        $activeFilter = $statusCol ? "LOWER(COALESCE(c.`{$statusCol}`, 'active')) = 'active'" : '1=1';
        $activeFilterUnaliased = $statusCol ? "LOWER(COALESCE(`{$statusCol}`, 'active')) = 'active'" : '1=1';

        return [
            'code_col' => $codeCol,
            'name_col' => $nameCol,
            'credits_col' => $creditsCol,
            'fee_col' => $feeCol,
            'credits_select' => $creditsSelect,
            'fee_select' => $feeSelect,
            'active_filter' => $activeFilter,
            'active_filter_unaliased' => $activeFilterUnaliased,
            'name_select_with_cl' => $nameCol ? "COALESCE(c.`{$nameCol}`, CONCAT('[Not in catalog] ', cl.course_code)) AS course_name" : "CONCAT('[Not in catalog] ', cl.course_code) AS course_name",
            'name_select_with_pc' => $nameCol ? "COALESCE(c.`{$nameCol}`, '') AS course_name" : "'' AS course_name",
            'name_select_with_cr' => $nameSelect,
            'join_sql_cl' => "JOIN courses c ON TRIM(UPPER(c.`{$codeCol}`)) = TRIM(UPPER(cl.course_code))",
            'join_sql_pc' => "JOIN courses c ON TRIM(UPPER(c.`{$codeCol}`)) = TRIM(UPPER(pc.course_code))",
            'join_sql_cr' => "LEFT JOIN courses c ON TRIM(UPPER(c.`{$codeCol}`)) = TRIM(UPPER(cr.course_code))",
        ];
    }

    private function fetchAll(string $sql, string $types = '', array $params = []): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                error_log('CourseService prepare failed: ' . $this->db->error);
                return [];
            }
            if ($types !== '') {
                $refs = [];
                foreach ($params as $idx => $value) {
                    $refs[$idx] = &$params[$idx];
                }
                array_unshift($refs, $types);
                call_user_func_array([$stmt, 'bind_param'], $refs);
            }
            $rows = [];
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
            $stmt->close();
            return $rows;
        } catch (Throwable $e) {
            error_log('CourseService query failed: ' . $e->getMessage());
            return [];
        }
    }

    private function executeStatement(string $sql, string $types = '', array $params = []): bool
    {
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                error_log('CourseService prepare failed: ' . $this->db->error);
                return false;
            }
            if ($types !== '') {
                $refs = [];
                foreach ($params as $idx => $value) {
                    $refs[$idx] = &$params[$idx];
                }
                array_unshift($refs, $types);
                call_user_func_array([$stmt, 'bind_param'], $refs);
            }
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } catch (Throwable $e) {
            error_log('CourseService statement failed: ' . $e->getMessage());
            return false;
        }
    }

    private function columnsFor(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $cache[$table] = [];
        if (!$this->tableExists($table)) {
            return $cache[$table];
        }

        if ($result = $this->db->query("SHOW COLUMNS FROM `{$table}`")) {
            while ($row = $result->fetch_assoc()) {
                $cache[$table][strtolower((string)$row['Field'])] = (string)$row['Field'];
            }
            $result->free();
        }

        return $cache[$table];
    }

    private function tableExists(string $table): bool
    {
        $safe = $this->db->real_escape_string($table);
        if ($result = $this->db->query("SHOW TABLES LIKE '{$safe}'")) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }
        return false;
    }
}
