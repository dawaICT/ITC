<?php
/**
 * StudentDataService - Centralized data access for student information
 * 
 * Provides consistent data structures and access patterns for:
 * - Student profile data
 * - Program information
 * - Academic session/term info
 * - Payment status
 * 
 * Returns normalized array structures regardless of underlying column variations.
 */

declare(strict_types=1);

require_once __DIR__ . '/DatabaseConnection.php';

class StudentDataService
{
    // Nullable: a transient connection failure must degrade (every usage
    // is inside a try/catch), not fatal the whole page from the constructor.
    private ?mysqli $db;
    private ?PDO $pdo;
    
    // Column name caches for schema flexibility
    private array $studentsCols = [];
    private array $studentProgramCols = [];
    private array $programsCols = [];
    private array $paymentsCols = [];
    private array $studentPaymentsCols = [];
    
    public function __construct(?mysqli $mysqli = null, ?PDO $pdo = null)
    {
        $conn = DatabaseConnection::getInstance();
        $this->db = $mysqli ?? $conn->getMysqli();
        $this->pdo = $pdo ?? $conn->getPdo();
        
        $this->discoverColumns();
    }
    
    /**
     * Discover column names for flexible schema handling
     */
    private function discoverColumns(): void
    {
        $tables = [
            'students' => &$this->studentsCols,
            'student_program' => &$this->studentProgramCols,
            'programs' => &$this->programsCols,
            'payments' => &$this->paymentsCols,
            'student_payments' => &$this->studentPaymentsCols
        ];
        
        foreach ($tables as $table => &$cache) {
            try {
                if ($result = $this->db->query("SHOW COLUMNS FROM `{$table}`")) {
                    while ($row = $result->fetch_assoc()) {
                        $cache[strtolower($row['Field'])] = $row['Field'];
                    }
                    $result->free();
                }
            } catch (Throwable $e) {
                error_log("StudentDataService: Failed to discover columns for {$table}: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Get column name from cache with fallback candidates
     */
    private function getCol(array &$cache, string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($cache[strtolower($candidate)])) {
                return $cache[strtolower($candidate)];
            }
        }
        return null;
    }
    
    // ========================================
    // STUDENT PROFILE METHODS
    // ========================================
    
    /**
     * Get student profile by ID
     * @return array|null Normalized student data structure
     */
    public function getStudentById(string $studentId): ?array
    {
        $sidCol = $this->getCol($this->studentsCols, 'SID', 'Sid', 'student_id') ?? 'SID';
        $fnameCol = $this->getCol($this->studentsCols, 'Fname', 'fname', 'first_name') ?? 'Fname';
        $lnameCol = $this->getCol($this->studentsCols, 'Lname', 'lname', 'last_name') ?? 'Lname';
        $sexCol = $this->getCol($this->studentsCols, 'sex', 'Sex', 'gender') ?? 'sex';
        $emailCol = $this->getCol($this->studentsCols, 'email', 'Email') ?? 'email';
        $phoneCol = $this->getCol($this->studentsCols, 'phone', 'Phone', 'mobile') ?? 'phone';
        
        $sql = "SELECT 
                    `{$sidCol}` AS student_id,
                    `{$fnameCol}` AS first_name,
                    `{$lnameCol}` AS last_name,
                    `{$sexCol}` AS gender,
                    `{$emailCol}` AS email,
                    `{$phoneCol}` AS phone,
                    profile_image
                FROM students 
                WHERE `{$sidCol}` = ?
                LIMIT 1";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$studentId]);
            $row = $stmt->fetch();
            
            if ($row) {
                return [
                    'student_id' => $row['student_id'],
                    'first_name' => $row['first_name'] ?? '',
                    'last_name' => $row['last_name'] ?? '',
                    'full_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                    'gender' => $row['gender'] ?? '',
                    'email' => $row['email'] ?? '',
                    'phone' => $row['phone'] ?? '',
                    'profile_image' => $row['profile_image'] ?? null
                ];
            }
        } catch (Throwable $e) {
            error_log("StudentDataService::getStudentById error: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Get student with program information
     * @return array|null Normalized structure with student + program data
     */
    public function getStudentWithProgram(string $studentId): ?array
    {
        $student = $this->getStudentById($studentId);
        if (!$student) {
            return null;
        }
        
        // Get program info
        $program = $this->getStudentProgram($studentId);
        
        return array_merge($student, [
            'program_code' => $program['program_code'] ?? null,
            'program_name' => $program['program_name'] ?? 'Not Assigned',
            'department_name' => $program['department_name'] ?? '',
            'faculty_name' => $program['faculty_name'] ?? '',
            'program_level' => $program['program_level'] ?? '',
            'program_duration' => $program['program_duration'] ?? 4
        ]);
    }
    
    /**
     * Get student's program information
     */
    public function getStudentProgram(string $studentId): ?array
    {
        // Default column names if discovery failed
        $spSidCol = $this->getCol($this->studentProgramCols, 'Sid', 'SID', 'student_id') ?? 'Sid';
        $spProgCol = $this->getCol($this->studentProgramCols, 'program_code', 'ProgramCode') ?? 'program_code';
        
        // 1. Try Simple Query First (Most Robust) - Matches index.php logic
        // This avoids potential issues with departments/faculties tables schema
        try {
            // Check if programs table exists logic could go here, but let's assume it does as index.php uses it.
            $durationExpr = $this->getCol($this->programsCols, 'program_duration')
                ? 'COALESCE(p.program_duration, 4) * 12'
                : ($this->getCol($this->programsCols, 'duration_months')
                    ? 'COALESCE(p.duration_months, 48)'
                    : '48');

            $simpleSql = "SELECT 
                        sp.`{$spProgCol}` AS program_code,
                        COALESCE(p.program_name, 'Unknown Program') AS program_name,
                        COALESCE(p.program_type, 'undergraduate') AS program_level,
                        {$durationExpr} AS program_duration
                    FROM student_program sp
                    LEFT JOIN programs p ON sp.`{$spProgCol}` = p.program_code
                    WHERE sp.`{$spSidCol}` = ?
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($simpleSql);
            $stmt->execute([$studentId]);
            $basicData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$basicData) {
                if (defined('DEBUG_MODE') && DEBUG_MODE) error_log("StudentDataService: No program found for student $studentId using simple query.");
                return null;
            }
            
            // 2. Try to enrich with Department/Faculty (Optional)
            // We do this separately so if it fails, we still returned the basic program info
            try {
                $deptSql = "SELECT 
                            d.department_name, 
                            '' AS faculty_name 
                        FROM programs p
                        LEFT JOIN departments d ON p.department_id = d.department_id
                        WHERE p.program_code = ?";
                        
                $stmtDep = $this->pdo->prepare($deptSql);
                $stmtDep->execute([$basicData['program_code']]);
                $deptData = $stmtDep->fetch(PDO::FETCH_ASSOC);
                
                if ($deptData) {
                    $basicData['department_name'] = $deptData['department_name'] ?? '';
                    $basicData['faculty_name'] = $deptData['faculty_name'] ?? '';
                }
            } catch (Throwable $e) {
                // Ignore department fetch errors, just use defaults
                if (defined('DEBUG_MODE') && DEBUG_MODE) error_log("StudentDataService: Department enrichment failed: " . $e->getMessage());
                $basicData['department_name'] = '';
                $basicData['faculty_name'] = '';
            }
            
            return $basicData;

        } catch (Throwable $e) {
            error_log("StudentDataService::getStudentProgram CRITICAL error: " . $e->getMessage());
            return null;
        }
    }
    
    // ========================================
    // ACADEMIC TERM METHODS
    // ========================================
    
    /**
     * Get current academic session
     * @return array ['academic_year', 'semester', 'start_date', 'end_date', 'is_active']
     */
    public function getCurrentAcademicSession(): array
    {
        $default = [
            'academic_year' => '1',  // Year of study (1, 2, 3, 4)
            'semester' => '1',
            'start_date' => null,
            'end_date' => null,
            'is_active' => true
        ];
        
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    academic_year,
                    COALESCE(semester_term, semester, '1') AS semester,
                    start_date,
                    end_date,
                    1 AS is_active
                FROM academic_sessions 
                WHERE is_current = 1 
                   OR (NOW() BETWEEN start_date AND end_date)
                ORDER BY is_current DESC, start_date DESC
                LIMIT 1
            ");
            
            $row = $stmt->fetch();
            if ($row) {
                return [
                    'academic_year' => $row['academic_year'],
                    'semester' => $row['semester'],
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'is_active' => true
                ];
            }
        } catch (Throwable $e) {
            // Table may not exist, use defaults
        }
        
        return $default;
    }
    
    /**
     * Determine student's current year of study based on registration history
     */
    public function getStudentYearOfStudy(string $studentId, ?string $academicYear = null): int
    {
        try {
            // Resolve within the target academic year when supplied: a
            // progression record for a future year must not leak backwards
            // into the current year's enrolment.
            if ($academicYear !== null && preg_match('/(\d{4})/', $academicYear, $ayMatch)) {
                $stmt = $this->pdo->prepare("
                    SELECT MAX(CAST(year_of_study AS UNSIGNED)) AS max_year
                    FROM semester_registration
                    WHERE student_id = ?
                      AND LEFT(CAST(academic_year AS CHAR), 4) = ?
                ");
                $stmt->execute([$studentId, $ayMatch[1]]);
                $row = $stmt->fetch();
                if ($row && $row['max_year']) {
                    return min((int)$row['max_year'], 4);
                }

                // No registration for that year yet: use the programme
                // position maintained by admissions/progression.
                $stmt = $this->pdo->prepare("
                    SELECT COALESCE(NULLIF(current_year_number, 0), NULLIF(year_of_study, 0), 1) AS yos
                    FROM student_program
                    WHERE Sid = ?
                      AND (status IS NULL OR status = '' OR LOWER(status) = 'active')
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmt->execute([$studentId]);
                $row = $stmt->fetch();
                if ($row && (int)($row['yos'] ?? 0) > 0) {
                    return min((int)$row['yos'], 4);
                }

                return 1;
            }

            $stmt = $this->pdo->prepare("
                SELECT MAX(CAST(year_of_study AS UNSIGNED)) AS max_year
                FROM semester_registration
                WHERE student_id = ?
            ");
            $stmt->execute([$studentId]);
            $row = $stmt->fetch();
            
            if ($row && $row['max_year']) {
                return min((int)$row['max_year'], 4);
            }
        } catch (Throwable $e) {
            // Ignore, return default
        }
        
        return 1;
    }
    
    // ========================================
    // PAYMENT STATUS METHODS
    // ========================================
    
    /**
     * Get payment summary for a student in a term
     * @return array Normalized payment status
     */
    public function getPaymentStatus(string $studentId, string $academicYear, string $semester): array
    {
        $result = [
            'total_due' => 0.0,
            'total_paid' => 0.0,
            'balance' => 0.0,
            'percent_paid' => 0.0,
            'can_register' => true, // Default to allow
            'has_invoice' => false
        ];
        
        try {
            // Get invoiced amount
            $invoiceStmt = $this->pdo->prepare("
                SELECT SUM(amount) AS total_due
                FROM invoices
                WHERE (Sid = ? OR SID = ?)
                  AND academic_year = ?
                  AND semester = ?
            ");
            $invoiceStmt->execute([$studentId, $studentId, $academicYear, $semester]);
            $invoiceRow = $invoiceStmt->fetch();
            
            if ($invoiceRow && $invoiceRow['total_due'] > 0) {
                $result['total_due'] = (float)$invoiceRow['total_due'];
                $result['has_invoice'] = true;
            }
            
            // Get payments from the normalized ledger first, with a dynamic
            // legacy fallback for older student_payments schemas.
            $paymentRow = null;
            if ($this->getCol($this->paymentsCols, 'student_id') && $this->getCol($this->paymentsCols, 'amount')) {
                $statusFilter = $this->getCol($this->paymentsCols, 'status')
                    ? "AND (LOWER(status) IN ('completed', 'paid', 'success') OR status IS NULL)"
                    : "";
                $paymentStmt = $this->pdo->prepare("
                    SELECT SUM(amount) AS total_paid
                    FROM payments
                    WHERE student_id = ?
                      AND academic_year = ?
                      AND semester = ?
                      {$statusFilter}
                ");
                $paymentStmt->execute([$studentId, $academicYear, $semester]);
                $paymentRow = $paymentStmt->fetch();
            } else {
                $sidCol = $this->getCol($this->studentPaymentsCols, 'Sid', 'SID', 'student_id');
                $amountCol = $this->getCol($this->studentPaymentsCols, 'amount_paid', 'amount');
                $yearCol = $this->getCol($this->studentPaymentsCols, 'academic_year');
                $semesterCol = $this->getCol($this->studentPaymentsCols, 'semester_term', 'semester');
                $statusCol = $this->getCol($this->studentPaymentsCols, 'payment_status', 'status');

                if ($sidCol && $amountCol) {
                    $where = ["`{$sidCol}` = ?"];
                    $params = [$studentId];
                    if ($yearCol) {
                        $where[] = "`{$yearCol}` = ?";
                        $params[] = $academicYear;
                    }
                    if ($semesterCol) {
                        $where[] = "`{$semesterCol}` = ?";
                        $params[] = $semester;
                    }
                    if ($statusCol) {
                        $where[] = "(LOWER(`{$statusCol}`) IN ('completed', 'paid', 'success') OR `{$statusCol}` IS NULL)";
                    }
                    $paymentStmt = $this->pdo->prepare("
                        SELECT SUM(`{$amountCol}`) AS total_paid
                        FROM student_payments
                        WHERE " . implode(' AND ', $where)
                    );
                    $paymentStmt->execute($params);
                    $paymentRow = $paymentStmt->fetch();
                }
            }
            
            if ($paymentRow) {
                $result['total_paid'] = (float)($paymentRow['total_paid'] ?? 0);
            }
            
            $result['balance'] = max(0, $result['total_due'] - $result['total_paid']);
            
            if ($result['total_due'] > 0) {
                $result['percent_paid'] = round(($result['total_paid'] / $result['total_due']) * 100, 2);
                $result['can_register'] = ($result['percent_paid'] >= 50);
            } else {
                $result['percent_paid'] = 100;
                $result['can_register'] = true;
            }
            
        } catch (Throwable $e) {
            error_log("StudentDataService::getPaymentStatus error: " . $e->getMessage());
            // On error, allow registration
            $result['can_register'] = true;
        }
        
        return $result;
    }
    
    // ========================================
    // REGISTRATION STATUS METHODS
    // ========================================
    
    /**
     * Check if student has completed semester registration for a term
     */
    public function hasSemesterRegistration(string $studentId, string $academicYear, string $semester): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id FROM semester_registration
                WHERE student_id = ?
                  AND academic_year = ?
                  AND semester = ?
                LIMIT 1
            ");
            $stmt->execute([$studentId, $academicYear, $semester]);
            return $stmt->fetch() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }
    
    /**
     * Check if student has any course registrations (returning student check)
     */
    public function isReturningStudent(string $studentId): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) AS cnt
                FROM course_registration
                WHERE Sid = ?
            ");
            $stmt->execute([$studentId]);
            $row = $stmt->fetch();
            return ($row && (int)$row['cnt'] > 0);
        } catch (Throwable $e) {
            return false;
        }
    }
    
    /**
     * Get complete registration status for a student
     * @return array Normalized registration status
     */
    public function getRegistrationStatus(string $studentId): array
    {
        $session = $this->getCurrentAcademicSession();
        $academicYear = $session['academic_year'];
        $semester = $session['semester'];
        
        $hasSemReg = $this->hasSemesterRegistration($studentId, $academicYear, $semester);
        $isReturning = $this->isReturningStudent($studentId);
        $payment = $this->getPaymentStatus($studentId, $academicYear, $semester);
        $yearOfStudy = $this->getStudentYearOfStudy($studentId);
        
        return [
            'student_id' => $studentId,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'year_of_study' => $yearOfStudy,
            'is_returning' => $isReturning,
            'has_semester_registration' => $hasSemReg,
            'payment' => $payment,
            'can_register_courses' => $hasSemReg,
            'next_step' => $this->determineNextStep($hasSemReg, $isReturning)
        ];
    }
    
    /**
     * Determine the next step in registration flow
     */
    private function determineNextStep(bool $hasSemReg, bool $isReturning): string
    {
        if (!$hasSemReg) {
            return 'semester_registration';
        }
        return 'course_registration';
    }
}
