<?php
/**
 * RegistrationDataService - Centralized data access layer for registration flow
 * 
 * This service manages the connection between:
 * - registration.php (semester registration & invoicing)
 * - courseReg.php (course selection)
 * - processCourseReg.php (course registration processing)
 * 
 * Data Flow:
 * 1. Student completes semester registration (registration.php)
 *    -> Creates: semester_registration record
 *    -> Creates: invoice record
 * 
 * 2. Student selects courses (courseReg.php)
 *    -> Reads: semester_registration (to get current term)
 *    -> Reads: course_levels (available courses for program/year/semester)
 *    -> Checks: 50% fee payment via FeeGuard
 * 
 * 3. Student submits course selection (processCourseReg.php)
 *    -> Creates: course_registration records (linked to semester_registration)
 *    -> Validates: prerequisites, credit limits, fee threshold
 * 
 * Key Relationships:
 * - semester_registration.id -> course_registration.semester_registration_id
 * - semester_registration.student_id -> students.SID
 * - student_payments.Sid -> students.SID
 */

declare(strict_types=1);

require_once __DIR__ . '/period_mode_helper.php';
require_once __DIR__ . '/../../includes/helpers/academic_structure_helpers.php';
require_once __DIR__ . '/../../includes/helpers/course_availability_helpers.php';

class RegistrationDataService
{
    private mysqli $db;
    
    // Column name cache for schema flexibility
    private array $semRegCols = [];
    private array $courseRegCols = [];
    private ?array $coursesTableCols = null;
    
    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->discoverColumnNames();
    }
    
    /**
     * Discover column names from tables for schema flexibility
     */
    private function discoverColumnNames(): void
    {
        // semester_registration columns
        if ($meta = $this->db->query("SHOW COLUMNS FROM semester_registration")) {
            while ($c = $meta->fetch_assoc()) {
                $this->semRegCols[strtolower((string)$c['Field'])] = (string)$c['Field'];
            }
            $meta->free();
        }
        
        // course_registration columns
        if ($meta = $this->db->query("SHOW COLUMNS FROM course_registration")) {
            while ($c = $meta->fetch_assoc()) {
                $this->courseRegCols[strtolower((string)$c['Field'])] = (string)$c['Field'];
            }
            $meta->free();
        }
    }
    
    /**
     * Get correct column name from discovered columns
     */
    private function getSemRegCol(string ...$candidates): string
    {
        foreach ($candidates as $c) {
            if (isset($this->semRegCols[strtolower($c)])) {
                return $this->semRegCols[strtolower($c)];
            }
        }
        return $candidates[0]; // fallback
    }
    
    private function getCourseRegCol(string ...$candidates): string
    {
        foreach ($candidates as $c) {
            if (isset($this->courseRegCols[strtolower($c)])) {
                return $this->courseRegCols[strtolower($c)];
            }
        }
        return $candidates[0];
    }

    /**
     * Normalize calendar academic year for DB comparisons (handles 2026, 2025/2026).
     *
     * @return array{string:string,int:?int}
     */
    private function normalizeAcademicYearForDb(?string $academicYear): array
    {
        $raw = trim((string)$academicYear);
        if ($raw === '') {
            return ['string' => '', 'int' => null];
        }
        if (preg_match('/(\d{4})/', $raw, $m)) {
            return ['string' => $m[1], 'int' => (int)$m[1]];
        }

        return ['string' => $raw, 'int' => null];
    }

    /**
     * @param list<string> $yearMatchSql
     * @param list<string|int> $params
     */
    private function appendAcademicYearOwnershipMatch(
        array &$yearMatchSql,
        array &$params,
        string &$types,
        string $alias,
        string $yearCol,
        ?string $acYearCol,
        int $yearOfStudy,
        ?string $academicYear
    ): void {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'cr';
        $yearMatchSql[] = "{$alias}.`{$yearCol}` = ?";
        $params[] = (string)$yearOfStudy;
        $types .= 's';

        $norm = $this->normalizeAcademicYearForDb($academicYear);
        if ($norm['string'] !== '') {
            $yearMatchSql[] = "{$alias}.`{$yearCol}` = ?";
            $params[] = $norm['string'];
            $types .= 's';
            if ($acYearCol) {
                $yearMatchSql[] = "CAST({$alias}.`{$acYearCol}` AS CHAR) = ?";
                $params[] = $norm['string'];
                $types .= 's';
                if ($norm['int'] !== null) {
                    $yearMatchSql[] = "{$alias}.`{$acYearCol}` = ?";
                    $params[] = $norm['int'];
                    $types .= 'i';
                }
            }
        }
    }

    /**
     * Discover courses-table columns once (schema varies across installs).
     */
    private function getCoursesTableCols(): array
    {
        if ($this->coursesTableCols !== null) {
            return $this->coursesTableCols;
        }

        $this->coursesTableCols = [];
        if ($meta = $this->db->query('SHOW COLUMNS FROM courses')) {
            while ($c = $meta->fetch_assoc()) {
                $this->coursesTableCols[strtolower((string)$c['Field'])] = (string)$c['Field'];
            }
            $meta->free();
        }

        return $this->coursesTableCols;
    }

    /**
     * Canonical courses-catalog column picks shared by registration UIs.
     *
     * @return array{course_code:string,course_name:?string,credit_hours:?string,status:?string}
     */
    public function getCourseCatalogColumnMap(): array
    {
        $cols = $this->getCoursesTableCols();

        return [
            'course_code' => $cols['course_code'] ?? 'course_code',
            'course_name' => $cols['course_name'] ?? ($cols['name'] ?? ($cols['title'] ?? null)),
            'credit_hours' => $cols['credit_hours'] ?? ($cols['credits'] ?? ($cols['credit'] ?? ($cols['units'] ?? null))),
            'status' => $cols['status'] ?? null,
        ];
    }

    /**
     * Resolve display names from the courses catalogue (case/whitespace tolerant).
     *
     * @param list<string> $courseCodes
     * @return array<string, string> course_code => course_name
     */
    public function lookupCourseCatalogNames(array $courseCodes): array
    {
        $names = [];
        $codes = [];
        foreach ($courseCodes as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $codes[$code] = true;
            }
        }
        if (!$codes) {
            return $names;
        }

        $catalog = $this->getCourseCatalogColumnMap();
        $codeCol = $catalog['course_code'];
        $nameCol = $catalog['course_name'];
        if (!$nameCol) {
            return $names;
        }

        $codeList = array_keys($codes);
        $placeholders = implode(',', array_fill(0, count($codeList), '?'));
        $types = str_repeat('s', count($codeList));
        $sql = "SELECT TRIM(`{$codeCol}`) AS course_code, COALESCE(`{$nameCol}`, '') AS course_name
                FROM courses
                WHERE TRIM(UPPER(`{$codeCol}`)) IN ($placeholders)";
        $upperCodes = array_map(static fn(string $c): string => strtoupper(trim($c)), $codeList);

        if (!$stmt = $this->db->prepare($sql)) {
            return $names;
        }
        $stmt->bind_param($types, ...$upperCodes);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $code = trim((string)($row['course_code'] ?? ''));
                if ($code !== '') {
                    $names[$code] = (string)($row['course_name'] ?? '');
                }
            }
        }
        $stmt->close();

        return $names;
    }

    /**
     * Keep recommendation cards aligned with getSelectableCoursesForTerm() names.
     */
    public function applySelectableCourseNamesToRecommendations(array $recs, array $selectableCourses): array
    {
        $nameByCode = [];
        foreach ($selectableCourses['courses'] ?? [] as $course) {
            $code = trim((string)($course['course_code'] ?? ''));
            if ($code !== '') {
                $nameByCode[$code] = (string)($course['course_name'] ?? '');
            }
        }

        if (!$nameByCode) {
            return $recs;
        }

        foreach (['missing', 'retake'] as $type) {
            if (!isset($recs[$type]) || !is_array($recs[$type])) {
                continue;
            }
            foreach ($recs[$type] as $idx => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $code = trim((string)($item['course_code'] ?? ''));
                if ($code !== '' && array_key_exists($code, $nameByCode)) {
                    $recs[$type][$idx]['course_name'] = $nameByCode[$code];
                }
            }
        }

        return $recs;
    }

    private function getExistingSemRegCols(string ...$candidates): array
    {
        $cols = [];
        foreach ($candidates as $c) {
            $key = strtolower($c);
            if (isset($this->semRegCols[$key])) {
                $cols[] = $this->semRegCols[$key];
            }
        }
        return array_values(array_unique($cols));
    }

    private function getExistingCourseRegCols(string ...$candidates): array
    {
        $cols = [];
        foreach ($candidates as $c) {
            $key = strtolower($c);
            if (isset($this->courseRegCols[$key])) {
                $cols[] = $this->courseRegCols[$key];
            }
        }
        return array_values(array_unique($cols));
    }

    private function appendStudentColumnMatch(string &$sql, array &$params, string &$types, string $alias, array $columns, string $studentId): void
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = "{$alias}.`{$column}` = ?";
            $params[] = $studentId;
            $types .= 's';
        }
        $sql .= '(' . implode(' OR ', $parts) . ')';
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        if ($types === '') {
            return;
        }

        $refs = [];
        foreach ($params as $idx => &$value) {
            $refs[$idx] = &$value;
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    private function tableExists(string $table): bool
    {
        $safeTable = $this->db->real_escape_string($table);
        if ($result = $this->db->query("SHOW TABLES LIKE '{$safeTable}'")) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }

        return false;
    }

    public function getBestStudentProgramCode(string $studentId): string
    {
        return $this->getStudentProgramCode($studentId);
    }

    private function getStudentProgramCode(string $studentId): string
    {
        if (!$this->tableExists('student_program')) {
            return '';
        }

        $spCols = [];
        if ($meta = $this->db->query("SHOW COLUMNS FROM student_program")) {
            while ($col = $meta->fetch_assoc()) {
                $spCols[strtolower((string)$col['Field'])] = (string)$col['Field'];
            }
            $meta->free();
        }

        $sidCol = $spCols['sid'] ?? ($spCols['student_id'] ?? null);
        $programCol = $spCols['program_code'] ?? ($spCols['programcode'] ?? null);
        if (!$sidCol || !$programCol) {
            return '';
        }

        $statusCol = $spCols['status'] ?? null;
        $idCol = $spCols['id'] ?? null;
        $hasProgramCourses = $this->tableExists('program_courses');

        // Prefer the active assigned program that actually has curriculum
        // mappings. A later administrative/test assignment with no courses must
        // not hide the student's real intake/term registration.
        foreach ([true, false] as $activeOnly) {
            $mappedSelect = $hasProgramCourses ? 'COUNT(pc.course_code)' : '0';
            $joinSql = $hasProgramCourses
                ? " LEFT JOIN program_courses pc ON pc.program_code = sp.`{$programCol}`"
                : '';
            $sql = "SELECT sp.`{$programCol}` AS program_code, {$mappedSelect} AS mapped_courses
                    FROM student_program sp{$joinSql}
                    WHERE sp.`{$sidCol}` = ?";
            if ($activeOnly && $statusCol) {
                $sql .= " AND (sp.`{$statusCol}` IS NULL OR sp.`{$statusCol}` = '' OR LOWER(sp.`{$statusCol}`) = 'active')";
            }
            $sql .= " GROUP BY sp.`{$programCol}`";
            if ($idCol) {
                $sql .= ", sp.`{$idCol}`";
            }
            $sql .= " ORDER BY mapped_courses DESC";
            if ($idCol) {
                $sql .= ", sp.`{$idCol}` DESC";
            }
            $sql .= " LIMIT 1";

            if ($stmt = $this->db->prepare($sql)) {
                $stmt->bind_param('s', $studentId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $programCode = (string)($row['program_code'] ?? '');
                        $stmt->close();
                        if ($programCode !== '') {
                            return $programCode;
                        }
                    }
                }
                $stmt->close();
            }
        }

        return '';
    }
    
    // ========================================
    // SEMESTER REGISTRATION METHODS
    // ========================================
    
    /**
     * Get the latest semester registration for a student.
     *
     * When $academicYear and $semester are supplied the query is scoped to that
     * exact academic period (matching registration.php). No row is returned when
     * the student has not registered for that period — callers must not fall
     * back to an older term in that case.
     *
     * @return array|null ['id', 'semester', 'year_of_study', 'program_code', 'financial_status', ...]
     */
    public function getLatestSemesterRegistration(
        string $studentId,
        ?string $academicYear = null,
        ?int $semester = null,
        ?string $periodType = null
    ): ?array {
        $periodScoped = ($academicYear !== null && $semester !== null);
        $sidCols = $this->getExistingSemRegCols('student_id', 'Sid', 'SID', 'student');
        if (empty($sidCols)) {
            return $this->getLatestCourseRegistrationTerm($studentId);
        }
        $semCol = $this->getSemRegCol('semester', 'semester_term');
        $yearCol = $this->getSemRegCol('year_of_study', 'Year');
        $academicYearCol = $this->semRegCols['academic_year'] ?? null;
        $periodTypeCol = $this->semRegCols['period_type'] ?? null;

        $programCol = $this->semRegCols['program_code'] ?? null;
        $financialStatusSelect = isset($this->semRegCols['financial_status']) ? "COALESCE(financial_status, 'Pending') AS financial_status" : "'Pending' AS financial_status";
        $registrationDateCol = $this->semRegCols['registration_date'] ?? ($this->semRegCols['date_registered'] ?? null);
        $createdAtSelect = isset($this->semRegCols['created_at']) ? 'created_at' : 'NULL AS created_at';

        $selectParts = [
            'id',
            "`{$semCol}` AS semester",
            ($yearCol ? "`{$yearCol}` AS year_of_study" : 'NULL AS year_of_study'),
            $programCol ? "`{$programCol}` AS program_code" : "'' AS program_code",
            $financialStatusSelect,
            '0 AS has_failed_courses',
            $registrationDateCol ? "`{$registrationDateCol}` AS registration_date" : 'NULL AS registration_date',
            $createdAtSelect
        ];

        if ($academicYearCol) {
            $selectParts[] = "`{$academicYearCol}` AS academic_year";
        } else {
            $selectParts[] = "NULL AS academic_year";
        }
        $selectParts[] = $periodTypeCol ? "`{$periodTypeCol}` AS period_type" : "'semester' AS period_type";

        $selectParts[] = "0 AS mapped_course_count";
        if ($programCol && $this->tableExists('program_courses')) {
            $pcCols = [];
            if ($pcMeta = $this->db->query("SHOW COLUMNS FROM program_courses")) {
                while ($pc = $pcMeta->fetch_assoc()) {
                    $pcCols[strtolower((string)$pc['Field'])] = (string)$pc['Field'];
                }
                $pcMeta->free();
            }
            $pcProgramCol = $pcCols['program_code'] ?? null;
            $pcCourseCol = $pcCols['course_code'] ?? null;
            $pcYearCol = $pcCols['year'] ?? ($pcCols['year_of_study'] ?? ($pcCols['year_level'] ?? null));
            $pcSemCol = $pcCols['semester'] ?? ($pcCols['term'] ?? null);
            if ($pcProgramCol && $pcCourseCol) {
                $mappedWhere = ["pc.`{$pcProgramCol}` = sr.`{$programCol}`"];
                if ($pcYearCol && $yearCol) {
                    $mappedWhere[] = "pc.`{$pcYearCol}` = sr.`{$yearCol}`";
                }
                if ($pcSemCol && $semCol) {
                    $mappedWhere[] = "pc.`{$pcSemCol}` = sr.`{$semCol}`";
                }
                // This count lets a valid curriculum-backed program outrank a
                // newer empty program assignment without hard-coding semester
                // or term behavior.
                $selectParts[count($selectParts) - 1] = "(SELECT COUNT(DISTINCT pc.`{$pcCourseCol}`) FROM program_courses pc WHERE " . implode(' AND ', $mappedWhere) . ") AS mapped_course_count";
            }
        }

        $selectParts[] = "0 AS active_course_count";
        if (isset($this->semRegCols['id']) && $this->tableExists('course_registration') && isset($this->courseRegCols['semester_registration_id'])) {
            $activeCourseSql = '';
            if (isset($this->courseRegCols['is_active'])) {
                $activeCourseSql = " AND COALESCE(cr.`is_active`, 1) = 1";
            }
            // If courses are already registered, that academic period is the
            // safest context to display first.
            $selectParts[count($selectParts) - 1] = "(SELECT COUNT(*) FROM course_registration cr WHERE cr.`semester_registration_id` = sr.`id`{$activeCourseSql}) AS active_course_count";
        }

        $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM semester_registration sr WHERE ';
        $params = [];
        $types = '';
        $this->appendStudentColumnMatch($sql, $params, $types, 'sr', $sidCols, $studentId);

        if ($periodScoped) {
            $sql .= " AND sr.`{$semCol}` = ?";
            $params[] = (string)$semester;
            $types .= 's';
            if ($periodTypeCol && $periodType !== null && $periodType !== '') {
                $sql .= " AND sr.`{$periodTypeCol}` = ?";
                $params[] = wuc_legacy_period_type($periodType);
                $types .= 's';
            }
            if ($academicYearCol && $academicYear !== '') {
                $sql .= " AND (sr.`{$academicYearCol}` = ? OR sr.`{$academicYearCol}` LIKE ? OR ? LIKE CONCAT(sr.`{$academicYearCol}`, '%'))";
                $params[] = $academicYear;
                $params[] = substr($academicYear, 0, 4) . '%';
                $params[] = $academicYear;
                $types .= 'sss';
            }
            $sql .= isset($this->semRegCols['id'])
                ? ' ORDER BY sr.`id` DESC LIMIT 1'
                : " ORDER BY sr.`{$yearCol}` DESC, sr.`{$semCol}` DESC LIMIT 1";
        } else {
            $sql .= isset($this->semRegCols['id'])
                ? ' ORDER BY active_course_count DESC, mapped_course_count DESC, sr.`id` DESC LIMIT 1'
                : " ORDER BY active_course_count DESC, mapped_course_count DESC, sr.`{$yearCol}` DESC, sr.`{$semCol}` DESC LIMIT 1";
        }

        if ($stmt = $this->db->prepare($sql)) {
            $this->bindParams($stmt, $types, $params);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $stmt->close();
                    return $row;
                }
            }
            $stmt->close();
        }

        if ($periodScoped) {
            return null;
        }

        return $this->getLatestCourseRegistrationTerm($studentId);
    }

    private function getLatestCourseRegistrationTerm(string $studentId): ?array
    {
        return $this->getLatestRegisteredCourseTerm($studentId);
    }

    public function getLatestRegisteredCourseTerm(string $studentId): ?array
    {
        $sidCols = $this->getExistingCourseRegCols('Sid', 'student_id', 'SID', 'student');
        $semCol = $this->getCourseRegCol('semester', 'semester_term');
        $yearCol = $this->getCourseRegCol('Year', 'year_of_study');
        if (empty($sidCols) || !isset($this->courseRegCols[strtolower($semCol)]) || !isset($this->courseRegCols[strtolower($yearCol)])) {
            return null;
        }

        $activeSql = isset($this->courseRegCols['is_active']) ? ' AND COALESCE(cr.`is_active`, 1) = 1' : '';
        $semRegIdCol = $this->courseRegCols['semester_registration_id'] ?? null;
        $idSelect = $semRegIdCol ? "MAX(cr.`{$semRegIdCol}`) AS id" : "NULL AS id";
        $updatedOrder = isset($this->courseRegCols['updated_at']) ? 'MAX(cr.`updated_at`) DESC,' : '';
        $idOrder = isset($this->courseRegCols['id']) ? 'MAX(cr.`id`) DESC,' : '';
        $sql = "SELECT {$idSelect},
                       cr.`{$semCol}` AS semester,
                       cr.`{$yearCol}` AS year_of_study,
                       ? AS program_code,
                       'Pending' AS financial_status,
                       0 AS has_failed_courses,
                       NULL AS registration_date,
                       NULL AS created_at,
                       NULL AS academic_year
                FROM course_registration cr
                WHERE ";
        $params = [$this->getStudentProgramCode($studentId)];
        $types = 's';
                $this->appendStudentColumnMatch($sql, $params, $types, 'cr', $sidCols, $studentId);
        $groupBy = $semRegIdCol ? ", cr.`{$semRegIdCol}`" : '';
        $sql .= $activeSql . "
                GROUP BY cr.`{$yearCol}`, cr.`{$semCol}`{$groupBy}
                ORDER BY {$updatedOrder} {$idOrder} cr.`{$yearCol}` DESC, cr.`{$semCol}` DESC
                LIMIT 1";

        if ($stmt = $this->db->prepare($sql)) {
            $this->bindParams($stmt, $types, $params);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $stmt->close();
                    return $row;
                }
            }
            $stmt->close();
        }

        return null;
    }

    /**
     * Get a semester registration record for a specific academic year and semester.
     */
    public function getSemesterRegistrationForTerm(string $studentId, string $academicYear, string $semester): ?array
    {
        $sidCols = $this->getExistingSemRegCols('student_id', 'Sid', 'SID', 'student');
        if (empty($sidCols)) {
            return null;
        }
        $semCol = $this->getSemRegCol('semester', 'semester_term');
        $yearCol = $this->getSemRegCol('year_of_study', 'Year');
        $academicYearCol = $this->semRegCols['academic_year'] ?? null;
        $periodTypeCol = $this->semRegCols['period_type'] ?? null;

        $programCol = $this->semRegCols['program_code'] ?? null;

        $selectParts = [
            'id',
            "`{$semCol}` AS semester",
            ($yearCol ? "`{$yearCol}` AS year_of_study" : 'NULL AS year_of_study'),
            $programCol ? "`{$programCol}` AS program_code" : "'' AS program_code"
        ];

        if ($academicYearCol) {
            $selectParts[] = "`{$academicYearCol}` AS academic_year";
        } else {
            $selectParts[] = "NULL AS academic_year";
        }
        $selectParts[] = $periodTypeCol ? "`{$periodTypeCol}` AS period_type" : "'semester' AS period_type";

        $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM semester_registration ' .
               'sr WHERE ';

        $params = [];
        $types = '';
        $this->appendStudentColumnMatch($sql, $params, $types, 'sr', $sidCols, $studentId);

        if ($academicYearCol && $academicYear !== '') {
            // Flexible academic year matching: handles '2025-2026' vs '2025' format mismatch
            // Match if the stored value equals the passed value OR if the year portion matches
            $sql .= " AND (sr.`{$academicYearCol}` = ? OR sr.`{$academicYearCol}` LIKE ? OR ? LIKE CONCAT(sr.`{$academicYearCol}`, '%'))";
            $params[] = $academicYear;
            $params[] = substr($academicYear, 0, 4) . '%';
            $params[] = $academicYear;
            $types .= 'sss';
        }

        $sql .= " AND sr.`{$semCol}` = ? ORDER BY sr.`id` DESC LIMIT 1";
        $params[] = $semester;
        $types .= 's';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $this->bindParams($stmt, $types, $params);

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return $row;
            }
        }

        $stmt->close();
        return null;
    }
    
    /**
     * Check if student has semester registration for a specific term
     */
    public function hasSemesterRegistration(string $studentId, int $yearOfStudy, int $semester): bool
    {
        $sidCol = $this->getSemRegCol('student_id', 'Sid', 'SID');
        $semCol = $this->getSemRegCol('semester', 'semester_term');
        $yearCol = $this->getSemRegCol('year_of_study', 'Year');
        
        $sql = "SELECT id FROM semester_registration 
                WHERE `{$sidCol}` = ? AND `{$yearCol}` = ? AND `{$semCol}` = ?
                LIMIT 1";
        
        if ($stmt = $this->db->prepare($sql)) {
            $semStr = (string)$semester;
            $yearStr = (string)$yearOfStudy;
            $stmt->bind_param('sss', $studentId, $yearStr, $semStr);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $exists = ($result->num_rows > 0);
                $stmt->close();
                return $exists;
            }
            $stmt->close();
        }
        return false;
    }
    
    /**
     * Get semester registration ID for linking course registrations
     */
    public function getSemesterRegistrationId(string $studentId, int $yearOfStudy, int $semester): ?int
    {
        $sidCol = $this->getSemRegCol('student_id', 'Sid', 'SID');
        $semCol = $this->getSemRegCol('semester', 'semester_term');
        $yearCol = $this->getSemRegCol('year_of_study', 'Year');
        
        $sql = "SELECT id FROM semester_registration 
                WHERE `{$sidCol}` = ? AND `{$yearCol}` = ? AND `{$semCol}` = ?
                ORDER BY id DESC LIMIT 1";
        
        if ($stmt = $this->db->prepare($sql)) {
            $semStr = (string)$semester;
            $yearStr = (string)$yearOfStudy;
            $stmt->bind_param('sss', $studentId, $yearStr, $semStr);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $stmt->close();
                    return (int)$row['id'];
                }
            }
            $stmt->close();
        }
        return null;
    }

    /**
     * Get student registration (Alias for getSemesterRegistrationForTerm)
     * required by registration.php
     */
    public function getStudentRegistration(string $studentId, string $academicYear, string $semester): ?array
    {
        return $this->getSemesterRegistrationForTerm($studentId, $academicYear, $semester);
    }

    /**
     * Create a new semester registration record
     */
    public function createRegistration(array $data): int
    {
        $sidCol = $this->getSemRegCol('student_id', 'Sid', 'SID');
        $semCol = $this->getSemRegCol('semester', 'semester_term');
        $yearCol = $this->getSemRegCol('year_of_study', 'Year');
        $acYearCol = $this->semRegCols['academic_year'] ?? 'academic_year';
        $periodTypeCol = $this->semRegCols['period_type'] ?? null;
        $typeCol = $this->getSemRegCol('student_type', 'entry_status', 'registration_type', 'type');
        $programCol = $this->semRegCols['program_code'] ?? null;
        $registrationDateCol = $this->semRegCols['registration_date'] ?? ($this->semRegCols['date_registered'] ?? null);
        
        $studentId = $data['student_id'];
        $semester = $data['semester'];
        $year = $data['year_of_study'] ?? 1;
        $academicYear = $data['academic_year'];
        $date = $data['registration_date'] ?? date('Y-m-d H:i:s');
        $programCode = trim((string)($data['program_code'] ?? ''));
        if ($programCode !== '') {
            $guard = wuc_legacy_course_registration_guard($this->db, (string)$studentId, $programCode, $year, $semester);
            if (!$guard['ok']) {
                throw new Exception($guard['reason']);
            }
            $data['period_type'] = $guard['period_type'];
        }
        
        // Prepare columns and values
        $columns = ["`{$sidCol}`", "`{$semCol}`", "`{$yearCol}`"];
        $placeholders = ["?", "?", "?"];
        $types = "sss";
        $params = [$studentId, (string)$semester, (string)$year];

        if ($registrationDateCol) {
            $columns[] = "`{$registrationDateCol}`";
            $placeholders[] = "?";
            $types .= "s";
            $params[] = $date;
        }

        if ($programCol && !empty($data['program_code'])) {
            $columns[] = "`{$programCol}`";
            $placeholders[] = "?";
            $types .= "s";
            $params[] = (string)$data['program_code'];
        }
        
        // Add academic year if column exists
        if (isset($this->semRegCols['academic_year'])) {
            $columns[] = "`{$acYearCol}`";
            $placeholders[] = "?";
            $types .= "s";
            $params[] = $academicYear;
        }

        if ($periodTypeCol) {
            $periodType = strtolower(trim((string)($data['period_type'] ?? 'semester')));
            $periodType = wuc_legacy_period_type($periodType);
            $columns[] = "`{$periodTypeCol}`";
            $placeholders[] = "?";
            $types .= "s";
            $params[] = $periodType;
        }

        // Add registration type/status if column exists
        if (isset($data['registration_type']) && isset($this->semRegCols[strtolower($typeCol)])) {
            $registrationType = ucfirst(strtolower(trim((string)$data['registration_type'])));
            if (!in_array($registrationType, ['Regular', 'Repeat', 'Transfer'], true)) {
                $registrationType = 'Regular';
            }
            $columns[] = "`{$typeCol}`";
            $placeholders[] = "?";
            $types .= "s";
            $params[] = $registrationType;
        }
        
        $sql = "INSERT INTO semester_registration (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
             throw new Exception("Failed to prepare registration insert: " . $this->db->error);
        }
        
        $stmt->bind_param($types, ...$params);
        
        if (!$stmt->execute()) {
             $error = $stmt->error;
             $stmt->close();
             throw new Exception("Failed to create registration: " . $error);
        }
        
        $id = $stmt->insert_id;
        $stmt->close();
        
        return $id;
    }
    
    // ========================================
    // COURSE REGISTRATION METHODS
    // ========================================
    
    /**
     * Get registered courses for a student.
     *
     * scope=year (default): all active courses for year_of_study in the academic
     * year — term/semester do not hide year-wide enrolments.
     * scope=period: legacy term-scoped lookup.
     */
    public function getRegisteredCourses(
        string $studentId,
        int $yearOfStudy,
        int $semester,
        ?int $semesterRegistrationId = null,
        ?string $academicYear = null,
        string $scope = 'year'
    ): array {
        if ($scope === 'year') {
            return $this->getRegisteredCoursesForAcademicYear(
                $studentId,
                $yearOfStudy,
                $academicYear,
                $semesterRegistrationId
            );
        }

        return $this->getRegisteredCoursesForPeriod(
            $studentId,
            $yearOfStudy,
            $semester,
            $semesterRegistrationId
        );
    }

    /**
     * Academic-year course enrolments (year of study + calendar year).
     */
    public function getRegisteredCoursesForAcademicYear(
        string $studentId,
        int $yearOfStudy,
        ?string $academicYear = null,
        ?int $semesterRegistrationId = null
    ): array {
        $sidCols = $this->getExistingCourseRegCols('Sid', 'student_id', 'SID', 'student');
        if (empty($sidCols)) {
            return [];
        }

        $yearCol = $this->getCourseRegCol('Year', 'year_of_study');
        $courseCol = $this->getCourseRegCol('course_code', 'Course_Code');
        $semRegIdCol = $this->courseRegCols['semester_registration_id'] ?? null;
        $acYearCol = $this->courseRegCols['academic_year'] ?? null;
        $catalog = $this->getCourseCatalogColumnMap();
        $courseCodeCol = $catalog['course_code'];
        $courseNameCol = $catalog['course_name'];
        $creditCol = $catalog['credit_hours'];
        $registrationDateCol = $this->courseRegCols['registration_date'] ?? ($this->courseRegCols['created_at'] ?? null);
        $activeSql = isset($this->courseRegCols['is_active']) ? ' AND COALESCE(cr.`is_active`, 1) = 1' : '';

        $courseNameSelect = $courseNameCol ? "COALESCE(c.`{$courseNameCol}`, '') AS course_name" : "'' AS course_name";
        $creditSelect = $creditCol ? "COALESCE(c.`{$creditCol}`, 3) AS credit_hours" : "3 AS credit_hours";
        $registrationDateSelect = $registrationDateCol ? "cr.`{$registrationDateCol}` AS registration_date" : "NULL AS registration_date";
        $joinSql = $courseCodeCol
            ? "LEFT JOIN courses c ON TRIM(UPPER(c.`{$courseCodeCol}`)) = TRIM(UPPER(cr.`{$courseCol}`))"
            : '';

        $where = '';
        $params = [];
        $types = '';
        $this->appendStudentColumnMatch($where, $params, $types, 'cr', $sidCols, $studentId);
        $yearMatchSql = [];
        $this->appendAcademicYearOwnershipMatch(
            $yearMatchSql,
            $params,
            $types,
            'cr',
            $yearCol,
            $acYearCol,
            $yearOfStudy,
            $academicYear
        );
        $where .= ' AND (' . implode(' OR ', $yearMatchSql) . ')' . $activeSql;

        $sql = "SELECT DISTINCT cr.`{$courseCol}` AS course_code, {$courseNameSelect}, {$creditSelect}, {$registrationDateSelect}
                FROM course_registration cr {$joinSql}
                WHERE {$where}
                ORDER BY cr.`{$courseCol}`";

        if (!$stmt = $this->db->prepare($sql)) {
            return [];
        }
        $this->bindParams($stmt, $types, $params);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $courses = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
        $stmt->close();
        return $courses;
    }

    /**
     * Legacy term-scoped registered courses.
     */
    private function getRegisteredCoursesForPeriod(
        string $studentId,
        int $yearOfStudy,
        int $semester,
        ?int $semesterRegistrationId = null
    ): array {
        $sidCols = $this->getExistingCourseRegCols('Sid', 'student_id', 'SID', 'student');
        if (empty($sidCols)) {
            return [];
        }
        $semCol = $this->getCourseRegCol('semester', 'semester_term');
        // course_registration.Year stores year-of-study (1..N), matching what every
        // current writer puts there (courseReg.php, StudentRegistrationSystem,
        // direct_course_submit). It is NOT the academic year.
        $yearCol = $this->getCourseRegCol('Year', 'year_of_study');
        $courseCol = $this->getCourseRegCol('course_code', 'Course_Code');
        $semRegIdCol = $this->courseRegCols['semester_registration_id'] ?? null;
        $catalog = $this->getCourseCatalogColumnMap();
        $courseCodeCol = $catalog['course_code'];
        $courseNameCol = $catalog['course_name'];
        $creditCol = $catalog['credit_hours'];
        $registrationDateCol = $this->courseRegCols['registration_date'] ?? ($this->courseRegCols['created_at'] ?? ($this->courseRegCols['updated_at'] ?? null));

        $courseNameSelect = $courseNameCol ? "COALESCE(c.`{$courseNameCol}`, '') AS course_name" : "'' AS course_name";
        $creditSelect = $creditCol ? "COALESCE(c.`{$creditCol}`, 3) AS credit_hours" : "3 AS credit_hours";
        $registrationDateSelect = $registrationDateCol ? "cr.`{$registrationDateCol}` AS registration_date" : "NULL AS registration_date";
        $joinSql = $courseCodeCol
            ? "LEFT JOIN courses c ON TRIM(UPPER(c.`{$courseCodeCol}`)) = TRIM(UPPER(cr.`{$courseCol}`))"
            : "";
        
        $baseSql = "SELECT DISTINCT 
                    cr.`{$courseCol}` AS course_code,
                    {$courseNameSelect},
                    {$creditSelect},
                    {$registrationDateSelect}
                FROM course_registration cr
                {$joinSql}
                WHERE ";
        
        $activeSql = isset($this->courseRegCols['is_active']) ? ' AND COALESCE(cr.`is_active`, 1) = 1' : '';
        $queries = [];

        if ($semesterRegistrationId !== null && $semRegIdCol) {
            $queries[] = [
                'where' => "cr.`{$semRegIdCol}` = ?" . $activeSql,
                'params' => [$semesterRegistrationId],
                'types' => 'i',
                'strict_current_registration' => true
            ];
            // Fallback: match by term columns when FK linkage is missing
            // (covers legacy rows where semester_registration_id was not set)
            $termSql = '';
            $termParams = [];
            $termTypes = '';
            $this->appendStudentColumnMatch($termSql, $termParams, $termTypes, 'cr', $sidCols, $studentId);
            $termSql .= " AND cr.`{$semCol}` = ? AND cr.`{$yearCol}` = ?" . $activeSql;
            $termParams[] = (string)$semester;
            $termParams[] = (string)$yearOfStudy;
            $termTypes .= 'ss';
            $queries[] = ['where' => $termSql, 'params' => $termParams, 'types' => $termTypes];
        } else {
            $termSql = '';
            $termParams = [];
            $termTypes = '';
            $this->appendStudentColumnMatch($termSql, $termParams, $termTypes, 'cr', $sidCols, $studentId);
            $termSql .= " AND cr.`{$semCol}` = ? AND cr.`{$yearCol}` = ?" . $activeSql;
            $termParams[] = (string)$semester;
            $termParams[] = (string)$yearOfStudy;
            $termTypes .= 'ss';
            $queries[] = ['where' => $termSql, 'params' => $termParams, 'types' => $termTypes];
        }

        foreach ($queries as $query) {
            $sql = $baseSql . $query['where'] . " ORDER BY cr.`{$courseCol}`";
            $params = $query['params'];
            $types = $query['types'];
            $courses = [];
            if (!$stmt = $this->db->prepare($sql)) {
                continue;
            }
            $this->bindParams($stmt, $types, $params);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $courses[] = $row;
                }
            }
            $stmt->close();
            if (!empty($query['strict_current_registration'])) {
                // If semester_registration_id matched courses, return them.
                // Otherwise, fall through to the term-column fallback query.
                if (!empty($courses)) {
                    return $courses;
                }
                continue;
            }
            if (!empty($courses) || $semesterRegistrationId === null) {
                return $courses;
            }
        }

        return [];
    }
    
    /**
     * Check if student has course registrations for a term
     */
    public function hasCourseRegistrations(string $studentId, int $yearOfStudy, int $semester, ?string $academicYear = null): bool
    {
        return count($this->getRegisteredCourses($studentId, $yearOfStudy, $semester, null, $academicYear)) > 0;
    }
    
    /**
     * Get total credits registered for a term
     */
    public function getTotalCredits(string $studentId, int $yearOfStudy, int $semester, ?int $semesterRegistrationId = null): int
    {
        $courses = $this->getRegisteredCourses($studentId, $yearOfStudy, $semester, $semesterRegistrationId);
        $total = 0;
        foreach ($courses as $c) {
            $total += (int)($c['credit_hours'] ?? 0);
        }
        return $total;
    }
    
    /**
     * Get payment status for a student in a term
     * @return array ['total_due', 'total_paid', 'balance', 'percent_paid', 'meets_threshold']
     */
    public function getPaymentStatus(string $studentId, int $yearOfStudy, int $semester, float $threshold = 50.0, ?string $academicYear = null): array
    {
        require_once __DIR__ . '/StudentAcademicWorkflowService.php';
        $workflow = new StudentAcademicWorkflowService($this->db);
        $period = $workflow->getActiveAcademicPeriod($studentId);
        if ($period['ok']) {
            $fee = $workflow->checkFeeEligibility($studentId, $period);
            return [
                'total_due' => (float)$fee['total_fee'],
                'total_paid' => (float)$fee['amount_paid'],
                'balance' => (float)$fee['balance'],
                'percent_paid' => (float)$fee['payment_percentage'],
                'meets_threshold' => (bool)$fee['is_eligible'],
                'can_receive_ca' => (bool)$fee['is_eligible'],
                'required_percentage' => (float)$fee['required_percentage'],
                'reason' => (string)$fee['reason'],
            ];
        }

        require_once __DIR__ . '/FeeGuard.php';
        
        $result = [
            'total_due' => 0.0,
            'total_paid' => 0.0,
            'balance' => 0.0,
            'percent_paid' => 0.0,
            'meets_threshold' => false,
            'can_receive_ca' => false
        ];

        // Check sponsorship first
        $isSponsored = false;
        if (function_exists('fg_is_fully_sponsored') && fg_is_fully_sponsored($this->db, $studentId)) {
            $isSponsored = true;
        }

        // Try course_registration table first (most specific: per-course fee tracking)
        $hasCourseReg = false;
        if (fg_table_exists($this->db, 'course_registration') && 
            fg_column_exists($this->db, 'course_registration', 'tuition_total') && 
            fg_column_exists($this->db, 'course_registration', 'amount_paid')) {
            
            $sql = "SELECT SUM(tuition_total) AS total_due, SUM(amount_paid) AS total_paid
                    FROM course_registration
                    WHERE Sid = ? AND Year = ? AND semester = ? AND is_active = 1";
            if ($stmt = $this->db->prepare($sql)) {
                $stmt->bind_param('sii', $studentId, $yearOfStudy, $semester);
                if ($stmt->execute()) {
                    $r = $stmt->get_result();
                    if ($row = $r->fetch_assoc()) {
                        $due = (float)($row['total_due'] ?? 0.0);
                        $paid = (float)($row['total_paid'] ?? 0.0);
                        if ($due > 0.0) {
                            $result['total_due'] = $due;
                            $result['total_paid'] = $paid;
                            $hasCourseReg = true;
                        }
                    }
                }
                $stmt->close();
            }
        }
        
        if (!$hasCourseReg) {
            // Fallback to FeeGuard fee structures
            if (function_exists('fg_student_program') && function_exists('fg_required_fee')) {
                $program = fg_student_program($this->db, $studentId);
                if ($program) {
                    $result['total_due'] = fg_required_fee($this->db, $program, $yearOfStudy, $semester);
                }
            }

            // Sum payments from payments table (filtered by term)
            $paid = 0.0;
            if (fg_table_exists($this->db, 'payments')) {
                $sqlPayments = "SELECT IFNULL(SUM(amount), 0) AS total_paid
                                FROM payments
                                WHERE student_id = ?
                                  AND (status IN ('completed', 'posted', 'confirmed') OR status IS NULL)";
                $types = 's';
                $params = [$studentId];
                // academic_year stores the calendar/intake year in payments; do
                // not compare it to year_of_study unless no academic year was
                // supplied by the caller.
                if (fg_column_exists($this->db, 'payments', 'academic_year')) {
                    $sqlPayments .= " AND academic_year = ?";
                    $types .= 's';
                    $params[] = (string)($academicYear ?: $yearOfStudy);
                }
                if (fg_column_exists($this->db, 'payments', 'semester')) {
                    $sqlPayments .= " AND semester = ?";
                    $types .= 's';
                    $params[] = (string)$semester;
                }
            if ($stmt = $this->db->prepare($sqlPayments)) {
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    $r = $stmt->get_result();
                    if ($row = $r->fetch_assoc()) {
                        $paid += (float)($row['total_paid'] ?? 0.0);
                    }
                }
                $stmt->close();
            }
            }

            // Sum payments from student_payments table (filtered by term)
            if (fg_table_exists($this->db, 'student_payments')) {
                $spCols = [];
                if ($meta = $this->db->query("SHOW COLUMNS FROM student_payments")) {
                    while ($col = $meta->fetch_assoc()) {
                        $spCols[strtolower((string)$col['Field'])] = (string)$col['Field'];
                    }
                    $meta->free();
                }
                $sidCol = $spCols['sid'] ?? ($spCols['student_id'] ?? null);
                $amountCol = $spCols['amount_paid'] ?? ($spCols['amount'] ?? null);
                $statusCol = $spCols['payment_status'] ?? ($spCols['status'] ?? null);
                $yearCol = $spCols['academic_year'] ?? null;
                $semCol = $spCols['semester_term'] ?? ($spCols['semester'] ?? null);
                if ($sidCol && $amountCol) {
                    $where = ["`{$sidCol}` = ?"];
                    $types = 's';
                    $params = [$studentId];
                    if ($statusCol) {
                        $where[] = "(`{$statusCol}` IN ('completed', 'posted', 'confirmed') OR `{$statusCol}` IS NULL)";
                    }
                    if ($yearCol) {
                        $where[] = "`{$yearCol}` = ?";
                        $types .= 's';
                        $params[] = (string)($academicYear ?: $yearOfStudy);
                    }
                    if ($semCol) {
                        $where[] = "`{$semCol}` = ?";
                        $types .= 's';
                        $params[] = (string)$semester;
                    }
                    $sqlStdPayments = "SELECT IFNULL(SUM(`{$amountCol}`), 0) AS total_paid FROM student_payments WHERE " . implode(' AND ', $where);
                    if ($stmt = $this->db->prepare($sqlStdPayments)) {
                        $stmt->bind_param($types, ...$params);
                        if ($stmt->execute()) {
                            $r = $stmt->get_result();
                            if ($row = $r->fetch_assoc()) {
                                $paid += (float)($row['total_paid'] ?? 0.0);
                            }
                        }
                        $stmt->close();
                    }
                }
            }

            $result['total_paid'] = $paid;
        }

        // Calculate derived values
        $result['balance'] = max(0.0, $result['total_due'] - $result['total_paid']);
        
        if ($isSponsored) {
            $result['percent_paid'] = 100.0;
            $result['meets_threshold'] = true;
            $result['can_receive_ca'] = true;
        } else {
            if ($result['total_due'] > 0) {
                $result['percent_paid'] = round(($result['total_paid'] / $result['total_due']) * 100, 2);
            } else {
                $result['percent_paid'] = 100.0; // No fee = 100% paid
            }
            $result['meets_threshold'] = ($result['percent_paid'] >= $threshold);
            $result['can_receive_ca'] = $result['meets_threshold'];
        }
        
        return $result;
    }

    /**
     * Get complete registration status for a student
     * Used to determine what the student can/cannot do
     */
    public function getRegistrationStatus(string $studentId): array
    {
        $status = [
            'student_id' => $studentId,
            'has_semester_registration' => false,
            'has_course_registration' => false,
            'current_term' => null,
            'period_label' => '',
            'active_period' => null,
            'payment_status' => null,
            'can_register_courses' => false,
            'can_receive_ca' => false,
            'registered_courses' => [],
            'total_credits' => 0,
            'semester_registration_id' => null
        ];

        require_once __DIR__ . '/StudentAcademicWorkflowService.php';
        $workflow = new StudentAcademicWorkflowService($this->db);
        $activePeriod = $workflow->getActiveAcademicPeriod($studentId);
        if (!empty($activePeriod['ok'])) {
            $status['active_period'] = [
                'period_number' => (int)($activePeriod['period_number'] ?? 0),
                'year_of_study' => (int)($activePeriod['year_of_study'] ?? 1),
                'academic_year' => (string)($activePeriod['academic_year'] ?? ''),
                'period_label' => (string)($activePeriod['period_label'] ?? ''),
                'program_code' => (string)($activePeriod['program_code'] ?? ''),
                'calendar_type' => (string)($activePeriod['calendar_type'] ?? ''),
            ];
        }

        $semReg = null;
        $regCheck = null;
        if (!empty($activePeriod['ok'])) {
            $regCheck = $workflow->checkStudentRegistration($studentId, $activePeriod);
            $status['period_label'] = (string)($regCheck['period_label'] ?? $activePeriod['period_label'] ?? '');
            if (!empty($regCheck['is_registered']) && !empty($regCheck['row'])) {
                $semReg = $regCheck['row'];
            } else {
                $status['current_term'] = [
                    'year_of_study' => (int)($activePeriod['year_of_study'] ?? 1),
                    'semester' => (int)($activePeriod['period_number'] ?? 0),
                    'program_code' => (string)($activePeriod['program_code'] ?? ''),
                    'academic_year' => (string)($activePeriod['academic_year'] ?? ''),
                    'period_type' => wuc_legacy_period_type((string)($activePeriod['calendar_type'] ?? 'semester')),
                ];
            }
        }
        if (!$semReg) {
            $semReg = $this->getLatestSemesterRegistration($studentId);
        }
        
        if ($semReg) {
            $status['has_semester_registration'] = $regCheck
                ? !empty($regCheck['is_registered'])
                : true;
            $status['current_term'] = [
                'year_of_study' => (int)$semReg['year_of_study'],
                'semester' => (int)$semReg['semester'],
                'program_code' => $semReg['program_code'],
                'academic_year' => $semReg['academic_year'] ?? null,
                'period_type' => $semReg['period_type'] ?? 'semester'
            ];
            $status['semester_registration_id'] = (int)$semReg['id'];

            if ($status['period_label'] === '' && !empty($activePeriod['period_label'])) {
                $status['period_label'] = (string)$activePeriod['period_label'];
            }
            
            $year = (int)$semReg['year_of_study'];
            $sem = (int)$semReg['semester'];
            $semRegId = (int)$semReg['id'];
            
            // Academic-year course enrolments (term/semester are not ownership boundaries)
            $academicYear = isset($semReg['academic_year']) ? trim((string)$semReg['academic_year']) : null;
            $courses = $this->getRegisteredCourses(
                $studentId,
                $year,
                $sem,
                $semRegId,
                $academicYear !== '' ? $academicYear : null,
                'year'
            );
            $status['has_course_registration'] = !empty($courses);
            $status['registered_courses'] = $courses;
            $status['total_credits'] = $this->getTotalCredits($studentId, $year, $sem, $semRegId);
            
            // Check payment status
            $payment = $this->getPaymentStatus($studentId, $year, $sem, 50.0, isset($semReg['academic_year']) ? (string)$semReg['academic_year'] : null);
            $status['payment_status'] = $payment;
            $status['can_receive_ca'] = $payment['can_receive_ca'];
            
            // Can register courses if semester registration exists
            // (50% check was removed per user requirement - courses should appear)
            $status['can_register_courses'] = true;
        }
        
        return $status;
    }
    
    // ========================================
    // TERM CONTEXT + SELECTABLE COURSES (shared by registration.php & courseReg.php)
    // ========================================

    /**
     * Resolve the semester_registration row both registration pages should use.
     *
     * Priority:
     * 1. Explicit semester_registration.id (must belong to the student)
     * 2. Current academic period (academic year + semester/term + period type)
     * 3. Latest registration row for the student (legacy fallback only)
     */
    public function resolveRegistrationTermContext(
        string $studentId,
        ?int $semesterRegistrationId = null,
        ?string $academicYear = null,
        ?int $semester = null,
        ?string $periodType = null,
        bool $allowLatestFallback = true
    ): ?array {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return null;
        }

        $foundReg = null;

        if ($semesterRegistrationId !== null && $semesterRegistrationId > 0) {
            $sidCols = $this->getExistingSemRegCols('student_id', 'sid', 'Sid', 'SID', 'student');
            if (!empty($sidCols)) {
                $semCol = $this->getSemRegCol('semester', 'semester_term');
                $yearCol = $this->getSemRegCol('year_of_study', 'Year');
                $programCol = $this->semRegCols['program_code'] ?? null;
                $academicYearCol = $this->semRegCols['academic_year'] ?? null;
                $periodTypeCol = $this->semRegCols['period_type'] ?? null;
                $idCol = $this->semRegCols['id'] ?? 'id';
                $sidCol = $sidCols[0];

                $selectParts = [
                    "`{$idCol}` AS id",
                    "`{$semCol}` AS semester",
                    ($yearCol ? "`{$yearCol}` AS year_of_study" : 'NULL AS year_of_study'),
                    $programCol ? "`{$programCol}` AS program_code" : "'' AS program_code",
                    $academicYearCol ? "`{$academicYearCol}` AS academic_year" : "'' AS academic_year",
                    $periodTypeCol ? "`{$periodTypeCol}` AS period_type" : "'semester' AS period_type",
                ];
                $sql = 'SELECT ' . implode(', ', $selectParts)
                    . " FROM semester_registration WHERE `{$idCol}` = ? AND `{$sidCol}` = ? LIMIT 1";

                if ($stmt = $this->db->prepare($sql)) {
                    $stmt->bind_param('is', $semesterRegistrationId, $studentId);
                    if ($stmt->execute()) {
                        $foundReg = $stmt->get_result()->fetch_assoc() ?: null;
                    }
                    $stmt->close();
                }
            }
        }

        if (!$foundReg && $academicYear !== null && $academicYear !== '' && $semester !== null && $semester > 0) {
            $foundReg = $this->getLatestSemesterRegistration(
                $studentId,
                $academicYear,
                $semester,
                $periodType
            );
        }

        if (!$foundReg && $allowLatestFallback) {
            $foundReg = $this->getLatestSemesterRegistration($studentId);
        }

        if (!$foundReg) {
            return null;
        }

        return $this->normalizeRegistrationTermContext($studentId, $foundReg);
    }

    /**
     * Normalize a semester_registration row for UI and course queries.
     */
    private function normalizeRegistrationTermContext(string $studentId, array $row): array
    {
        $assignedProgram = $this->getBestStudentProgramCode($studentId);
        $programCode = $assignedProgram !== ''
            ? $assignedProgram
            : trim((string)($row['program_code'] ?? ''));

        return [
            'id' => isset($row['id']) ? (int)$row['id'] : null,
            'semester' => (int)($row['semester'] ?? 0),
            'year_of_study' => max(1, (int)($row['year_of_study'] ?? 1)),
            'program_code' => $programCode,
            'academic_year' => trim((string)($row['academic_year'] ?? '')),
            'period_type' => trim((string)($row['period_type'] ?? 'semester')),
        ];
    }

    /**
     * Whether an active program catalogue row exists for the supplied code.
     */
    public function programCodeExists(string $programCode): bool
    {
        $programCode = trim($programCode);
        if ($programCode === '') {
            return false;
        }

        if (!$this->tableExists('programs')) {
            return false;
        }

        $programCols = [];
        if ($programMeta = $this->db->query('SHOW COLUMNS FROM programs')) {
            while ($programCol = $programMeta->fetch_assoc()) {
                $programCols[strtolower((string)$programCol['Field'])] = (string)$programCol['Field'];
            }
            $programMeta->free();
        }

        $programCodeCol = $programCols['program_code'] ?? null;
        $programActiveCol = $programCols['is_active'] ?? null;
        if (!$programCodeCol) {
            return false;
        }

        $programSql = "SELECT `{$programCodeCol}` FROM programs WHERE `{$programCodeCol}` = ?";
        if ($programActiveCol) {
            $programSql .= " AND COALESCE(`{$programActiveCol}`, 1) = 1";
        }
        $programSql .= ' LIMIT 1';

        if (!$programStmt = $this->db->prepare($programSql)) {
            return false;
        }
        $programStmt->bind_param('s', $programCode);
        $programStmt->execute();
        $exists = $programStmt->get_result()->num_rows > 0;
        $programStmt->close();

        return $exists;
    }

    /**
     * Build the course list shown on courseReg.php and counted on registration.php.
     *
     * When the student already has active course rows for the term, returns those.
     * Otherwise returns curriculum courses for the term plus failed carry-overs
     * offered this term (same rules as courseReg.php).
     */
    public function getSelectableCoursesForTerm(string $studentId, ?array $termContext = null): array
    {
        $empty = [
            'context' => null,
            'already_registered' => false,
            'program_missing' => false,
            'registered_courses' => [],
            'courses' => [],
            'count' => 0,
        ];

        $studentId = trim($studentId);
        if ($studentId === '') {
            return $empty;
        }

        if ($termContext === null) {
            $termContext = $this->resolveRegistrationTermContext($studentId);
        } elseif (!isset($termContext['program_code'])) {
            $termContext = $this->normalizeRegistrationTermContext($studentId, $termContext);
        }

        if (!$termContext || (int)($termContext['semester'] ?? 0) < 1 || trim((string)($termContext['program_code'] ?? '')) === '') {
            return $empty;
        }

        $yearInt = (int)$termContext['year_of_study'];
        $semInt = (int)$termContext['semester'];
        $program = (string)$termContext['program_code'];
        $semesterRegId = isset($termContext['id']) ? (int)$termContext['id'] : null;

        $registeredCourses = $this->getRegisteredCourses(
            $studentId,
            $yearInt,
            $semInt,
            $semesterRegId,
            trim((string)($termContext['academic_year'] ?? '')),
            'year'
        );
        $alreadyRegistered = !empty($registeredCourses);

        if (!$this->programCodeExists($program)) {
            return [
                'context' => $termContext,
                'already_registered' => false,
                'program_missing' => true,
                'registered_courses' => [],
                'courses' => [],
                'count' => 0,
            ];
        }

        $coursesByCode = [];
        foreach ($this->getAvailableCourses($program, $yearInt, $semInt) as $course) {
            $code = trim((string)($course['course_code'] ?? ''));
            if ($code !== '') {
                $coursesByCode[$code] = $course;
            }
        }

        require_once __DIR__ . '/EligibilityService.php';
        $failed = EligibilityService::getFailedCourses($this->db, $studentId);
        $failedThisTerm = EligibilityService::filterFailedCoursesOfferedThisTerm(
            $this->db,
            $program,
            $yearInt,
            $semInt,
            $failed,
            false
        );

        $registeredByCode = [];
        foreach ($registeredCourses as $registeredCourse) {
            $code = trim((string)($registeredCourse['course_code'] ?? ''));
            if ($code !== '') {
                $registeredByCode[$code] = $registeredCourse;
            }
        }
        foreach ($registeredByCode as $code => $registeredCourse) {
            if (!isset($coursesByCode[$code])) {
                $coursesByCode[$code] = $registeredCourse;
            } else {
                $coursesByCode[$code] = array_merge($coursesByCode[$code], $registeredCourse, ['is_enrolled' => true]);
            }
        }
        foreach ($coursesByCode as $code => $course) {
            $coursesByCode[$code]['is_enrolled'] = isset($registeredByCode[$code]);
        }

        foreach ($failedThisTerm as $failedCourse) {
            $code = trim((string)($failedCourse['course_code'] ?? ''));
            if ($code === '' || isset($coursesByCode[$code])) {
                continue;
            }

            $catalogNames = $this->lookupCourseCatalogNames([$code]);
            $coursesByCode[$code] = [
                'course_code' => $code,
                'course_name' => $catalogNames[$code] ?? '',
                'is_enrolled' => isset($registeredByCode[$code]),
                'credit_hours' => 3,
            ];
        }

        ksort($coursesByCode, SORT_NATURAL | SORT_FLAG_CASE);
        $courses = array_values($coursesByCode);

        return [
            'context' => $termContext,
            'already_registered' => $alreadyRegistered,
            'program_missing' => false,
            'registered_courses' => $registeredCourses,
            'courses' => $courses,
            'count' => count($courses),
        ];
    }

    // ========================================
    // AVAILABLE COURSES
    // ========================================
    
    /**
     * Get available courses for a program/year/semester from curriculum
     */
    public function getAvailableCourses(string $programCode, int $yearOfStudy, int $semester): array
    {
        $programCode = trim($programCode);
        if ($programCode === '' || !$this->programCodeExists($programCode)) {
            return [];
        }

        require_once dirname(__DIR__, 2) . '/includes/helpers/academic_period_helpers.php';
        $yearRows = getCoursesForProgramYearOfStudy($this->db, $programCode, $yearOfStudy);
        if ($yearRows !== []) {
            $mapped = [];
            foreach ($yearRows as $row) {
                $code = trim((string)($row['course_code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $mapped[] = [
                    'course_code' => $code,
                    'course_name' => (string)($row['course_name'] ?? ''),
                    'credit_hours' => (int)($row['credit_value'] ?? $row['credit_hours'] ?? 3),
                    'course_type' => !empty($row['is_core']) ? 'Core' : 'Elective',
                    'description' => '',
                ];
            }
            if ($mapped !== []) {
                return $mapped;
            }
        }

        $courses = [];
        $hasCourseLevels = false;
        if ($chk = $this->db->query("SHOW TABLES LIKE 'course_levels'")) {
            $hasCourseLevels = ($chk->num_rows > 0);
            $chk->free();
        }

        $courseCols = $this->getCoursesTableCols();
        $catalog = $this->getCourseCatalogColumnMap();
        $catalogCodeCol = $catalog['course_code'];
        $catalogNameCol = $catalog['course_name'];
        $catalogCreditCol = $catalog['credit_hours'];
        $catalogStatusCol = $catalog['status'];
        $catalogLabCol = $courseCols['is_laboratory'] ?? null;
        $catalogAdvancedCol = $courseCols['is_advanced'] ?? null;
        $catalogDescriptionCol = $courseCols['syllabus'] ?? ($courseCols['description'] ?? null);
        $courseStatusFilter = $catalogStatusCol
            ? "(c.`{$catalogStatusCol}` IS NULL OR c.`{$catalogStatusCol}` = '' OR LOWER(c.`{$catalogStatusCol}`) = 'active')"
            : '1=1';

        if ($hasCourseLevels) {
        // 1. Try course_levels first (Most specific: Program + Year + Semester)
        // Discover course_levels columns
        $clCols = [];
        if ($meta = $this->db->query("SHOW COLUMNS FROM course_levels")) {
            while ($c = $meta->fetch_assoc()) {
                $clCols[strtolower((string)$c['Field'])] = (string)$c['Field'];
            }
            $meta->free();
        }
        
        $progCol = $clCols['program_code'] ?? 'program_code';
        $semCol = $clCols['semester'] ?? 'semester';
        $yearCol = $clCols['year'] ?? ($clCols['year_level'] ?? 'Year');
        $courseCol = $clCols['course_code'] ?? 'course_code';
        $statusCol = $clCols['status'] ?? null;

        $courseNameSelect = $catalogNameCol
            ? "COALESCE(c.`{$catalogNameCol}`, CONCAT('[Not in catalog] ', cl.`{$courseCol}`)) AS course_name"
            : "CONCAT('[Not in catalog] ', cl.`{$courseCol}`) AS course_name";
        $courseCreditSelect = $catalogCreditCol
            ? "COALESCE(NULLIF(c.`{$catalogCreditCol}`, 0), 3) AS credit_hours"
            : "3 AS credit_hours";
        $courseTypeSelect = "'Core' AS course_type";
        if ($catalogLabCol || $catalogAdvancedCol) {
            $courseTypeSelect = 'CASE ';
            if ($catalogLabCol) {
                $courseTypeSelect .= "WHEN c.`{$catalogLabCol}` = 1 THEN 'Lab' ";
            }
            if ($catalogAdvancedCol) {
                $courseTypeSelect .= "WHEN c.`{$catalogAdvancedCol}` = 1 THEN 'Advanced' ";
            }
            $courseTypeSelect .= "ELSE 'Core' END AS course_type";
        }
        $courseDescriptionSelect = $catalogDescriptionCol ? "c.`{$catalogDescriptionCol}` AS description" : "'' AS description";
        $courseLevelStatusFilter = $statusCol
            ? "(cl.`{$statusCol}` IS NULL OR cl.`{$statusCol}` = '' OR LOWER(cl.`{$statusCol}`) = 'active')"
            : '1=1';
        
        $where = ["`{$progCol}` = ?", "`{$yearCol}` = ?"];
        $types = 'ss';
        $params = [$programCode, (string)$yearOfStudy];
        require_once dirname(__DIR__, 2) . '/includes/helpers/course_availability_helpers.php';
        $periodFilter = wuc_course_availability_period_filter($clCols, 'cl', $semCol, null);
        if ($periodFilter['sql'] !== '1=1') {
            $where[] = $periodFilter['sql'];
            $types .= $periodFilter['types'];
            $params = array_merge($params, $periodFilter['params']);
        }
        // Filter by course_levels status if column exists
        if ($courseLevelStatusFilter !== '1=1') {
            $where[] = $courseLevelStatusFilter;
        }
        
        // Use LEFT JOIN so missing catalog rows don't hide curriculum entries.
        // Keep the status filter, but allow NULL (missing course row).
        $sql = "SELECT DISTINCT 
                    cl.`{$courseCol}` AS course_code,
                    {$courseNameSelect},
                    {$courseCreditSelect},
                    {$courseTypeSelect},
                    {$courseDescriptionSelect}
                FROM course_levels cl
                JOIN programs p ON p.program_code = cl.`{$progCol}`
                JOIN courses c ON TRIM(UPPER(c.`{$catalogCodeCol}`)) = TRIM(UPPER(cl.`{$courseCol}`))
                WHERE " . implode(' AND ', $where) . " AND COALESCE(p.is_active, 1) = 1 AND {$courseStatusFilter}
                ORDER BY cl.`{$courseCol}`";
        
        if ($stmt = $this->db->prepare($sql)) {
            $this->bindParams($stmt, $types, $params);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $courses[] = $row;
                }
            }
            $stmt->close();
        }
        
        if (!empty($courses)) {
            return $courses;
        }
        }

        // 2. Fallback to program_courses (Program + Semester only)
        $hasProgramCourses = false;
        if ($chk = $this->db->query("SHOW TABLES LIKE 'program_courses'")) {
            $hasProgramCourses = ($chk->num_rows > 0);
            $chk->free();
        }

        if ($hasProgramCourses && !empty($programCode)) {
            // Check columns to possibly filter by year
            $pcCols = [];
            if ($meta = $this->db->query("SHOW COLUMNS FROM program_courses")) {
                while ($c = $meta->fetch_assoc()) {
                    $pcCols[strtolower((string)$c['Field'])] = (string)$c['Field'];
                }
                $meta->free();
            }

            $pcProgCol = $pcCols['program_code'] ?? 'program_code';
            $pcSemCol  = $pcCols['semester'] ?? 'semester';
            $pcCourseCol = $pcCols['course_code'] ?? 'course_code';
            $pcYearCol = $pcCols['year'] ?? ($pcCols['year_of_study'] ?? ($pcCols['year_level'] ?? null));
            $programCourseNameSelect = $catalogNameCol ? "COALESCE(c.`{$catalogNameCol}`, '') AS course_name" : "'' AS course_name";
            $programCourseCreditSelect = $catalogCreditCol
                ? "COALESCE(NULLIF(c.`{$catalogCreditCol}`, 0), 3) AS credit_hours"
                : "3 AS credit_hours";

            // Base query
            $where = ["pc.`{$pcProgCol}` = ?", "COALESCE(p.is_active, 1) = 1"];
            $types = 's';
            $params = [$programCode];
            if ($pcYearCol) {
                $where[] = "pc.`{$pcYearCol}` = ?";
                $types .= 's';
                $params[] = (string)$yearOfStudy;
            }
            $periodFilter = wuc_course_availability_period_filter($pcCols, 'pc', $pcSemCol, null);
            if ($periodFilter['sql'] !== '1=1') {
                $where[] = $periodFilter['sql'];
                $types .= $periodFilter['types'];
                $params = array_merge($params, $periodFilter['params']);
            }
            $where[] = $courseStatusFilter;

            $sql = "SELECT DISTINCT 
                        pc.`{$pcCourseCol}` AS course_code,
                        {$programCourseNameSelect},
                        {$programCourseCreditSelect}
                    FROM program_courses pc
                    JOIN programs p ON p.program_code = pc.`{$pcProgCol}`
                    JOIN courses c ON TRIM(UPPER(c.`{$catalogCodeCol}`)) = TRIM(UPPER(pc.`{$pcCourseCol}`))
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY pc.`{$pcCourseCol}`";

            if ($stmt = $this->db->prepare($sql)) {
                $this->bindParams($stmt, $types, $params);

                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $courses[] = $row;
                    }
                    $stmt->close();
                } else {
                    $stmt->close();
                }
            }
        }
        
        // 3. Absolute fallback REMOVED: Do not return random courses if curriculum is missing.
        // Returning random courses causes confusion (mixing semesters/years).
        // It is better to return empty and let the UI show "No courses found".
        /*
        if (empty($courses)) {
            // ... logic removed ...
        }
        */
        
        return $courses;
    }
}
