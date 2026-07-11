<?php
require_once __DIR__ . '/Database.php';

class StudentRegistrationSystem {
    // Constants for fee calculation
    const TUITION_PER_CREDIT = 350.00;  // Base tuition rate per credit hour
    const REGISTRATION_FEE = 150.00;     // One-time registration fee
    const LABORATORY_FEE = 100.00;       // Additional fee for laboratory courses
    const ADVANCED_COURSE_FEE = 75.00;   // Additional fee for advanced courses
    const TRANSFER_PROCESSING_FEE = 200.00; // Additional fee for transfer students

    // Maximum credits allowed per year
    const MAX_CREDITS = [
        1 => 24, // Year 1 max credits
        2 => 24, // Year 2 max credits
        3 => 24, // Year 3 max credits
        4 => 24  // Year 4 max credits
    ];

    private $db;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    private function tableColumns(PDO $conn, string $table): array {
        $columns = [];
        $stmt = $conn->query("SHOW COLUMNS FROM `{$table}`");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = (string)$row['Field'];
            }
        }
        return $columns;
    }

    private function courseSelectExpressions(PDO $conn): array {
        $columns = $this->tableColumns($conn, 'courses');
        $creditExpr = in_array('credits', $columns, true) ? 'c.credits' : (in_array('credit_hours', $columns, true) ? 'c.credit_hours' : '3');
        $labExpr = in_array('is_laboratory', $columns, true) ? 'c.is_laboratory' : '0';
        $advancedExpr = in_array('is_advanced', $columns, true) ? 'c.is_advanced' : '0';
        $descriptionExpr = in_array('syllabus', $columns, true) ? 'c.syllabus' : "''";
        return [
            'credits' => $creditExpr,
            'type' => "CASE WHEN {$labExpr} = 1 THEN 'Lab' WHEN {$advancedExpr} = 1 THEN 'Advanced' ELSE 'Core' END",
            'description' => $descriptionExpr,
            'lab' => $labExpr,
            'advanced' => $advancedExpr,
        ];
    }

    private function periodAvailabilityFilter(array $columns, string $alias, ?string $periodColumn, array &$params, $period): string {
        if ($periodColumn === null || $periodColumn === '') {
            return '1=1';
        }

        $hasFullYear = in_array('is_full_year', $columns, true);
        $specificFlags = array_values(array_filter([
            in_array('is_period_specific', $columns, true) ? 'is_period_specific' : null,
            in_array('is_term_specific', $columns, true) ? 'is_term_specific' : null,
            in_array('is_semester_specific', $columns, true) ? 'is_semester_specific' : null,
        ]));
        $deliveryCol = in_array('delivery_period', $columns, true) ? 'delivery_period' : null;

        if (!$hasFullYear && !$specificFlags && !$deliveryCol) {
            return '1=1';
        }

        $fullYearClauses = [];
        if ($hasFullYear) {
            $fullYearClauses[] = "COALESCE({$alias}.is_full_year, 0) = 1";
        }
        foreach ($specificFlags as $flagCol) {
            $fullYearClauses[] = "COALESCE({$alias}.{$flagCol}, 0) = 0";
        }
        if ($deliveryCol) {
            $fullYearClauses[] = "LOWER(COALESCE({$alias}.{$deliveryCol}, '')) IN ('', 'full_year', 'year', 'annual', 'all')";
        }

        $params[] = (string)$period;
        return '(' . implode(' AND ', $fullYearClauses) . " OR {$alias}.`{$periodColumn}` = ?)";
    }

    public function getRequiredCourses($yearOfStudy, $semester, $isTransfer = false, $programCode = null) {
        try {
            $conn = $this->db->getConnection();
            $courseExpr = $this->courseSelectExpressions($conn);
            
            // PRIORITY 1: Use course_levels (Most specific: Program + Year + Semester)
            $hasCourseLevels = false;
            $chk = $conn->query("SHOW TABLES LIKE 'course_levels'");
            if ($chk) { $hasCourseLevels = $chk->rowCount() > 0; }
            
            if ($hasCourseLevels && !empty($programCode)) {
                $clColumns = $this->tableColumns($conn, 'course_levels');
                $clPeriodCol = in_array('semester', $clColumns, true) ? 'semester' : null;
                $where = ['cl.program_code = ?', 'cl.year = ?'];
                $params = [$programCode, (int)$yearOfStudy];
                $periodFilter = $this->periodAvailabilityFilter($clColumns, 'cl', $clPeriodCol, $params, $semester);
                if ($periodFilter !== '1=1') {
                    $where[] = $periodFilter;
                }
                $query = "SELECT 
                        c.course_code,
                        c.course_name,
                        COALESCE({$courseExpr['credits']}, 3) as credits,
                        {$courseExpr['type']} as course_type,
                        {$courseExpr['description']} as description
                    FROM course_levels cl
                    JOIN programs p ON p.program_code = cl.program_code
                    JOIN courses c ON c.course_code = cl.course_code
                    WHERE " . implode(' AND ', $where) . "
                    AND COALESCE(p.is_active, 1) = 1
                    AND (c.status = 'active' OR c.status IS NULL)";
                
                $stmt = $conn->prepare($query);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    return $rows;
                }
            }

            // PRIORITY 2: Use program_courses (Fallback: Program + Semester only)
            // Useful if year logic is not strict or enforced by absolute semesters
            $hasProgramCourses = false;
            $chk = $conn->query("SHOW TABLES LIKE 'program_courses'");
            if ($chk) { $hasProgramCourses = $chk->rowCount() > 0; }

            if ($hasProgramCourses && !empty($programCode)) {
                $pcColumns = $this->tableColumns($conn, 'program_courses');
                $pcYearCol = in_array('year', $pcColumns, true) ? 'year' : (in_array('year_of_study', $pcColumns, true) ? 'year_of_study' : null);
                $pcPeriodCol = in_array('semester', $pcColumns, true) ? 'semester' : null;
                $where = ['(c.status = \'active\' OR c.status IS NULL)', 'COALESCE(p.is_active, 1) = 1', 'pc.program_code = ?'];
                $params = [$programCode];
                if ($pcYearCol) {
                    $where[] = "pc.`{$pcYearCol}` = ?";
                    $params[] = (int)$yearOfStudy;
                }
                $periodFilter = $this->periodAvailabilityFilter($pcColumns, 'pc', $pcPeriodCol, $params, $semester);
                if ($periodFilter !== '1=1') {
                    $where[] = $periodFilter;
                }
                $query = "SELECT 
                        c.course_code,
                        c.course_name,
                        COALESCE({$courseExpr['credits']}, 3) as credits,
                        {$courseExpr['type']} as course_type,
                        {$courseExpr['description']} as description
                    FROM program_courses pc
                    JOIN programs p ON p.program_code = pc.program_code
                    JOIN courses c ON c.course_code = pc.course_code
                    WHERE " . implode(' AND ', $where);
                
                $stmt = $conn->prepare($query);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    return $rows;
                }
            }

            return [];

        } catch (PDOException $e) {
            throw new Exception('Error fetching required courses: ' . $e->getMessage());
        }
    }

    public function getRequiredCoursesForNewStudent($academicYear, $semester, $isTransfer = false, $programCode = null) {
        return $this->getRequiredCourses($academicYear, $semester, $isTransfer, $programCode);
    }

    public function validateCourseRegistration($studentSid, $courses, $isNewStudent = false) {
        try {
            $conn = $this->db->getConnection();
            $errors = [];
            $totalCredits = 0;

            if (!is_array($courses) || empty($courses)) {
                // Allow empty courses for semester-only registration
                return [
                    'isValid' => true,
                    'errors' => [],
                    'totalCredits' => 0
                ];
            }

            // Validate course codes exist and compute credits safely
            $placeholders = implode(',', array_fill(0, count($courses), '?'));
            $courseColumns = $this->tableColumns($conn, 'courses');
            $creditExpr = in_array('credits', $courseColumns, true) ? 'credits' : (in_array('credit_hours', $courseColumns, true) ? 'credit_hours' : '0');
            $stmt = $conn->prepare("SELECT course_code, COALESCE({$creditExpr}, 0) AS credit_hours FROM courses WHERE course_code IN ($placeholders) AND status = 'active'");
            $stmt->execute($courses);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $found = array_map(function($r){ return $r['course_code']; }, $rows);
            $missing = array_diff($courses, $found);
            if (!empty($missing)) {
                $errors[] = 'Unknown course(s): ' . implode(',', $missing);
            }

            foreach ($rows as $r) {
                $totalCredits += (int)$r['credit_hours'];
            }

            return [
                'isValid' => empty($errors),
                'errors' => $errors,
                'totalCredits' => $totalCredits
            ];
        } catch (Exception $e) {
            throw new Exception("Validation error: " . $e->getMessage());
        }
    }

    public function calculateRegistrationFees($courseCodes, $isTransfer = false, ?string $programCode = null, ?int $yearOfStudy = null, ?int $semester = null) {
        $fees = [
            'base_tuition' => 0,
            'registration_fee' => self::REGISTRATION_FEE,
            'additional_fees' => 0,
            'total' => 0
        ];

        try {
            $conn = $this->db->getConnection();

            if ($programCode !== null && $yearOfStudy !== null && $semester !== null) {
                $feeTable = $conn->query("SHOW TABLES LIKE 'fee_structure'");
                if ($feeTable && $feeTable->rowCount() > 0) {
                    $feeColumns = $this->tableColumns($conn, 'fee_structure');
                    $feeWhere = "program_code = ? AND year_of_study = ? AND semester = ?";
                    if (in_array('entity_type', $feeColumns, true)) {
                        $feeWhere .= " AND entity_type = 'program'";
                    }
                    if (in_array('status', $feeColumns, true)) {
                        $feeWhere .= " AND status = 'active'";
                    }
                    $stmt = $conn->prepare("SELECT fee_description, amount FROM fee_structure WHERE {$feeWhere} ORDER BY id");
                    $stmt->execute([$programCode, $yearOfStudy, $semester]);
                    $feeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($feeRows)) {
                        $tuition = 0.0;
                        $registration = 0.0;
                        $additional = 0.0;
                        $lineItems = [];
                        foreach ($feeRows as $row) {
                            $description = (string)($row['fee_description'] ?? 'Fee');
                            $amount = (float)($row['amount'] ?? 0);
                            $lineItems[] = ['description' => $description, 'amount' => $amount];
                            if (stripos($description, 'tuition') !== false) {
                                $tuition += $amount;
                            } elseif (stripos($description, 'registration') !== false) {
                                $registration += $amount;
                            } else {
                                $additional += $amount;
                            }
                        }
                        if ($isTransfer) {
                            $additional += self::TRANSFER_PROCESSING_FEE;
                            $lineItems[] = ['description' => 'Transfer processing fee', 'amount' => self::TRANSFER_PROCESSING_FEE];
                        }
                        $fees['base_tuition'] = $tuition;
                        $fees['registration_fee'] = $registration;
                        $fees['additional_fees'] = $additional;
                        $fees['total'] = $tuition + $registration + $additional;
                        $fees['breakdown'] = [
                            'base_tuition' => $tuition,
                            'registration_fee' => $registration,
                            'additional' => $additional,
                            'line_items' => $lineItems,
                            'source' => 'fee_structure',
                        ];
                        return $fees;
                    }
                }
            }
            
            // Validate course codes
            if (empty($courseCodes) || !is_array($courseCodes)) {
                $fees['total'] = $fees['registration_fee'];
                $fees['breakdown'] = [
                    'base_tuition' => 0,
                    'registration_fee' => self::REGISTRATION_FEE,
                    'additional' => 0,
                ];
                return $fees;
            }
            
            $placeholders = str_repeat('?,', count($courseCodes) - 1) . '?';
            $query = "SELECT * FROM courses WHERE course_code IN ($placeholders) AND status = 'active'";

            $stmt = $conn->prepare($query);
            $stmt->execute($courseCodes);
            $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($courses as $course) {
                // Calculate base tuition based on credit hours (with default of 3 if not present)
                $creditHours = isset($course['credits']) ? (float)$course['credits'] : (isset($course['credit_hours']) ? (float)$course['credit_hours'] : 3.0);
                $fees['base_tuition'] += $creditHours * self::TUITION_PER_CREDIT;

                // Add laboratory fees if applicable (safe null check)
                if (isset($course['is_laboratory']) && $course['is_laboratory'] == 1) {
                    $fees['additional_fees'] += self::LABORATORY_FEE;
                }

                // Add advanced course fees if applicable (safe null check)
                if (isset($course['is_advanced']) && $course['is_advanced'] == 1) {
                    $fees['additional_fees'] += self::ADVANCED_COURSE_FEE;
                }
            }

            // Add transfer student processing fee if applicable
            if ($isTransfer) {
                $fees['additional_fees'] += self::TRANSFER_PROCESSING_FEE;
            }

            // Calculate total fees
            $fees['total'] = $fees['base_tuition'] + $fees['registration_fee'] + $fees['additional_fees'];
            // Attach a breakdown for preview
            $fees['breakdown'] = [
                'base_tuition' => $fees['base_tuition'],
                'registration_fee' => self::REGISTRATION_FEE,
                'additional' => $fees['additional_fees'],
            ];

            return $fees;
        } catch (PDOException $e) {
            throw new Exception('Error calculating registration fees: ' . $e->getMessage());
        }
    }

    public function processRegistration($studentSid, $courses, $semester, $yearOfStudy = '1', $academicYearCalendar = '', $periodType = null) {
        try {
            $conn = $this->db->getConnection();
            $conn->beginTransaction();

            // Validate registration
            $validation = $this->validateCourseRegistration($studentSid, $courses, true);
            if (!$validation['isValid']) {
                throw new Exception("Registration validation failed: " . implode(", ", $validation['errors']));
            }

            // Calculate fees
            $fees = $this->calculateRegistrationFees($courses);

            // Ensure student exists (students table)
            $this->ensureStudentRowExists($conn, (string)$studentSid);

            // Resolve the intake period model (semester vs term). When the caller
            // does not pass one explicitly, derive it from the student's program so
            // term-based ITC/TVET programmes register against the correct period.
            $periodType = $this->normalisePeriodType($periodType)
                ?? $this->resolveProgramPeriodMode($conn, (string)$studentSid);

            if (!empty($courses)) {
                $programStmt = $conn->prepare(
                    "SELECT sp.program_code
                     FROM student_program sp
                     JOIN programs p ON p.program_code = sp.program_code
                     WHERE sp.Sid = ? AND COALESCE(p.is_active, 1) = 1
                     LIMIT 1"
                );
                $programStmt->execute([(string)$studentSid]);
                $programCode = (string)($programStmt->fetchColumn() ?: '');
                if ($programCode === '') {
                    throw new Exception('Active program not found for student.');
                }

                $availableRows = $this->getRequiredCourses($yearOfStudy, $semester, false, $programCode);
                $allowedCourses = array_map(function ($row) {
                    return strtoupper(trim((string)($row['course_code'] ?? '')));
                }, $availableRows);
                $requestedCourses = array_map(function ($code) {
                    return strtoupper(trim((string)$code));
                }, $courses);
                $notAllowed = array_diff($requestedCourses, $allowedCourses);
                if (!empty($notAllowed)) {
                    throw new Exception('Course(s) are not assigned to the student program: ' . implode(', ', $notAllowed));
                }
            }

            // 1. Sync with semester_registration (modern flow)
            $semRegId = $this->ensureSemesterRegistration($conn, (string)$studentSid, (string)$yearOfStudy, (int)$semester, (string)$academicYearCalendar, $periodType);

            // 2. Sync with course_registration (modern flow)
            // Determine column names for course_registration (dynamic discovery)
            $colsRes = $conn->query("DESCRIBE course_registration");
            $crCols = [];
            while ($c = $colsRes->fetch(PDO::FETCH_ASSOC)) {
                $crCols[strtolower($c['Field'])] = $c['Field'];
            }
            
            $crSidCol  = $crCols['sid'] ?? 'Sid';
            $crSemCol  = $crCols['semester'] ?? 'semester';
            $crYearCol = $crCols['year'] ?? 'Year';

            foreach ($courses as $courseCode) {
                // Check if already registered in course_registration
                // Use numeric Year for consistency with RegistrationDataService and other pages
                $chkCr = $conn->prepare("SELECT 1 FROM course_registration WHERE `{$crSidCol}` = ? AND course_code = ? AND `{$crSemCol}` = ? AND `{$crYearCol}` = ? LIMIT 1");
                $chkCr->execute([$studentSid, $courseCode, $semester, $yearOfStudy]);
                if ($chkCr->fetchColumn()) { continue; }

                $fields = ["`{$crSidCol}`", "course_code", "`{$crSemCol}`", "`{$crYearCol}`"];
                $params = [$studentSid, $courseCode, $semester, $yearOfStudy];

                // Year stores the year-of-study; also record the CALENDAR academic
                // year in academic_year so the results side (CA/exams) can key on
                // the real academic year shown in the UI.
                if (isset($crCols['academic_year'])) {
                    $fields[] = "academic_year";
                    $params[] = $academicYearCalendar;
                }

                if (isset($crCols['semester_registration_id'])) {
                    $fields[] = "semester_registration_id";
                    $params[] = $semRegId;
                }

                $placeholders = array_fill(0, count($fields), "?");
                $insCr = $conn->prepare("INSERT INTO course_registration (" . implode(", ", $fields) . ") VALUES (" . implode(", ", $placeholders) . ")");
                $insCr->execute($params);
            }

            // 3. Persist selections into student_courses (legacy compatibility).
            // Optional table: skip silently when it is absent so registration does
            // not fatally fail on installs that never created the legacy table.
            $hasLegacy = $conn->query("SHOW TABLES LIKE 'student_courses'");
            if ($hasLegacy && $hasLegacy->rowCount() > 0) {
                $insLegacy = $conn->prepare("INSERT INTO student_courses (student_id, course_code, academic_year, semester) VALUES (?, ?, ?, ?)");
                foreach ($courses as $courseCode) {
                    // Check if exists to avoid duplicates
                    $chkLegacy = $conn->prepare("SELECT 1 FROM student_courses WHERE student_id = ? AND course_code = ? AND academic_year = ? AND semester = ? LIMIT 1");
                    $chkLegacy->execute([$studentSid, $courseCode, $academicYearCalendar, $semester]);
                    if ($chkLegacy->fetchColumn()) continue;

                    $insLegacy->execute([$studentSid, $courseCode, $academicYearCalendar, $semester]);
                }
            }

            $conn->commit();
            return [
                'registration_id' => $semRegId,
                'fees' => $fees,
                'status' => 'pending'
            ];
        } catch (Exception $e) {
            if (isset($conn)) { $conn->rollBack(); }
            throw new Exception("Registration processing error: " . $e->getMessage());
        }
    }

    /**
     * Finds or creates a semester_registration record.
     */
    private function ensureSemesterRegistration(PDO $conn, string $sid, string $year, int $semester, string $academic_year = '', string $periodType = 'semester'): int {
        // Discovery column names for semester_registration
        $colsRes = $conn->query("DESCRIBE semester_registration");
        $srCols = [];
        while ($c = $colsRes->fetch(PDO::FETCH_ASSOC)) {
            $srCols[strtolower($c['Field'])] = $c['Field'];
        }

        $srSidCol  = $srCols['sid'] ?? ($srCols['student_id'] ?? 'student_id');
        $srSemCol  = $srCols['semester'] ?? 'semester';
        $srYearCol = $srCols['year'] ?? ($srCols['year_of_study'] ?? 'year_of_study');
        $srAYCol   = $srCols['academic_year'] ?? 'academic_year';
        $srPeriodCol = $srCols['period_type'] ?? null;
        $periodType  = ($this->normalisePeriodType($periodType) ?? 'semester');

        // Get program code EARLY so we can include it in the existence check
        $programCode = $this->getProgramCode($conn, $sid);

        // Check if exists using ALL components of the unique index
        $checkParams = [$sid, $programCode, $semester, $year];
        $checkSql = "SELECT id FROM semester_registration WHERE `{$srSidCol}` = ? AND program_code = ? AND `{$srSemCol}` = ? AND `{$srYearCol}` = ?";

        // A term and a semester registration for the same number are distinct
        // records, so the period type is part of the natural key.
        if ($srPeriodCol) {
            $checkSql .= " AND `{$srPeriodCol}` = ?";
            $checkParams[] = $periodType;
        }

        if (isset($srCols['academic_year']) && !empty($academic_year)) {
            $checkSql .= " AND `{$srAYCol}` = ?";
            $checkParams[] = $academic_year;
        }
        $checkSql .= " LIMIT 1";

        $stmt = $conn->prepare($checkSql);
        $stmt->execute($checkParams);
        $existing = $stmt->fetchColumn();
        if ($existing) { return (int)$existing; }

        // Create new
        $fields = ["`{$srSidCol}`", "`{$srSemCol}`", "`{$srYearCol}`", "student_type", "program_code"];
        $params = [$sid, $semester, $year, 'Regular', $programCode];

        if ($srPeriodCol) {
            $fields[] = "`{$srPeriodCol}`";
            $params[] = $periodType;
        }

        if (isset($srCols['academic_year'])) {
            $fields[] = "`{$srAYCol}`";
            $params[] = $academic_year;
        }

        $placeholders = array_fill(0, count($fields), "?");

        // Use atomic upsert to avoid duplicate-key race conditions.
        // If a duplicate exists, LAST_INSERT_ID(id) will be set to the existing id,
        // so we can reliably return the proper registration id.
        $insertSql = "INSERT INTO semester_registration (" . implode(", ", $fields) . ") VALUES (" . implode(", ", $placeholders) . ") ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)";
        $ins = $conn->prepare($insertSql);
        $ins->execute($params);

        return (int)$conn->lastInsertId();
    }

    /**
     * Normalise an arbitrary period-type input to 'semester' | 'term', or null
     * when nothing usable was supplied (so callers can fall back to derivation).
     */
    private function normalisePeriodType($value): ?string {
        if ($value === null) { return null; }
        $mode = strtolower(trim((string)$value));
        if ($mode === '') { return null; }
        if (strpos($mode, 'term') !== false) { return 'term'; }
        if (strpos($mode, 'semester') !== false) { return 'semester'; }
        return null;
    }

    /**
     * Derive the registration period model for a student's program by reading the
     * authoritative programs.period_mode column (added by
     * migrations/add_program_period_mode.php). Defaults to 'semester' when the
     * column or program is missing, matching period_mode_helper.php.
     */
    private function resolveProgramPeriodMode(PDO $conn, string $sid): string {
        try {
            $has = $conn->query("SHOW COLUMNS FROM programs LIKE 'period_mode'");
            if (!$has || $has->rowCount() === 0) { return 'semester'; }

            $programCode = $this->getProgramCode($conn, $sid);
            if ($programCode === '') { return 'semester'; }

            $stmt = $conn->prepare("SELECT period_mode FROM programs WHERE program_code = ? LIMIT 1");
            $stmt->execute([$programCode]);
            $mode = $this->normalisePeriodType($stmt->fetchColumn());
            return $mode ?? 'semester';
        } catch (Throwable $e) {
            return 'semester';
        }
    }

    /**
     * Retrieves student's program code.
     */
    private function getProgramCode(PDO $conn, string $sid): string {
        $stmt = $conn->prepare("SELECT program_code FROM student_program WHERE Sid = ? LIMIT 1");
        $stmt->execute([$sid]);
        $code = $stmt->fetchColumn();
        if ($code) return $code;
        
        // Fallback to first available program if no program assigned
        $stmt = $conn->query("SELECT program_code FROM programs LIMIT 1");
        $code = $stmt->fetchColumn();
        return $code ?: 'BSCS';
    }

    /**
     * Create a minimal students row for the given SID if it does not exist.
     * Builds the INSERT dynamically based on required columns.
     */
    private function ensureStudentRowExists(PDO $conn, string $sid): void {
        // Already exists?
        $chk = $conn->prepare('SELECT 1 FROM students WHERE SID = ? LIMIT 1');
        $chk->execute([$sid]);
        if ($chk->fetchColumn()) { return; }

        // Inspect schema to determine required columns
        $colsStmt = $conn->query('DESCRIBE students');
        $cols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (empty($cols)) {
            // Fallback: try inserting SID only
            $try = $conn->prepare('INSERT INTO students (SID) VALUES (?)');
            $try->execute([$sid]);
            return;
        }

        $columns = [];
        $placeHolders = [];
        $values = [];

        // Always set SID
        $columns[] = 'SID';
        $placeHolders[] = '?';
        $values[] = $sid;

        foreach ($cols as $c) {
            $name = (string)$c['Field'];
            if ($name === 'SID') { continue; }
            $isNullable = (string)$c['Null'] === 'YES';
            $default = $c['Default'];
            $key = (string)$c['Key'];
            // Skip auto PK and timestamps
            if ($key === 'PRI' || stripos($name, 'created_at') !== false || stripos($name, 'updated_at') !== false) {
                continue;
            }
            // Only provide values for NOT NULL columns without default
            if (!$isNullable && $default === null) {
                switch ($name) {
                    case 'Fname':
                        $columns[] = 'Fname'; $placeHolders[] = '?'; $values[] = 'New';
                        break;
                    case 'Lname':
                        $columns[] = 'Lname'; $placeHolders[] = '?'; $values[] = 'Student';
                        break;
                    case 'sex':
                        $columns[] = 'sex'; $placeHolders[] = '?'; $values[] = 'M';
                        break;
                    case 'profile_image':
                        $columns[] = 'profile_image'; $placeHolders[] = '?'; $values[] = 'default.jpg';
                        break;
                    default:
                        // Generic safe defaults
                        $columns[] = $name; $placeHolders[] = '?'; $values[] = '';
                }
            }
        }

        $sql = 'INSERT INTO students (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeHolders) . ')';
        $ins = $conn->prepare($sql);
        $ins->execute($values);
    }

    public function updateRegistrationStatus($registrationId, $status) {
        try {
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare("
                UPDATE registrations 
                SET status = ?, updated_at = NOW() 
                WHERE registration_id = ?
            ");
            $stmt->execute([$status, $registrationId]);
            return true;
        } catch (Exception $e) {
            throw new Exception("Status update error: " . $e->getMessage());
        }
    }

    public function getConnection() {
        return $this->db->getConnection();
    }
} 
