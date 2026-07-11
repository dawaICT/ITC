<?php
/**
 * Registration Service Layer
 * Handles all business logic for student semester and course registration
 * Provides clean API for registration operations with validation and error handling
 */

require_once __DIR__ . '/DatabaseConnection.php';

class RegistrationService {
    private $db;
    
    public function __construct() {
        $this->db = DatabaseConnection::getInstance();
    }
    
    /**
     * Get current active academic session
     * @return array|null Session data
     */
    public function getCurrentSession(): ?array {
        $query = "SELECT * FROM academic_sessions 
                  WHERE is_active = 1 AND is_registration_open = 1 
                  ORDER BY id DESC LIMIT 1";
        return $this->db->fetchOne($query);
    }
    
    /**
     * Check if registration is currently open
     * @return bool
     */
    public function isRegistrationOpen(): bool {
        $session = $this->getCurrentSession();
        if (!$session) {
            return false;
        }
        
        $today = date('Y-m-d');
        return $session['is_registration_open'] && 
               $today >= $session['registration_start_date'] && 
               $today <= $session['registration_end_date'];
    }
    
    /**
     * Create a new semester registration
     * @param array $data Registration data
     * @return array Success status and registration ID or error message
     */
    public function createSemesterRegistration(array $data): array {
        // Validate required fields
        $required = ['student_id', 'program_code', 'semester', 'year_of_study', 'student_type'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'message' => "Missing required field: $field"];
            }
        }
        
        // Check if registration is open
        if (!$this->isRegistrationOpen()) {
            return ['success' => false, 'message' => 'Registration is currently closed'];
        }
        
        // Get current session
        $session = $this->getCurrentSession();
        if (!$session) {
            return ['success' => false, 'message' => 'No active academic session found'];
        }
        
        try {
            return $this->db->transaction(function($db) use ($data, $session) {
                // Check if student already registered for this semester
                $existing = $db->fetchOne(
                    "SELECT id FROM semester_registration 
                     WHERE student_id = ? AND academic_session_id = ? 
                     AND semester = ? AND year_of_study = ?",
                    [$data['student_id'], $session['id'], $data['semester'], $data['year_of_study']]
                );
                
                if ($existing) {
                    return [
                        'success' => false, 
                        'message' => 'Already registered for this semester',
                        'registration_id' => $existing['id']
                    ];
                }
                
                // Generate registration number
                $regNumber = $this->generateRegistrationNumber($session['id'], $data['semester']);
                
                // Check financial clearance for returning students
                $financialStatus = 'Pending';
                if ($data['student_type'] === 'Returning') {
                    $financialStatus = $this->checkFinancialStatus($data['student_id']);
                }
                
                // Insert semester registration
                $insertData = [
                    'registration_number' => $regNumber,
                    'student_id' => $data['student_id'],
                    'program_code' => $data['program_code'],
                    'academic_session_id' => $session['id'],
                    'semester' => $data['semester'],
                    'year_of_study' => $data['year_of_study'],
                    'student_type' => $data['student_type'],
                    'registration_status' => 'Draft',
                    'financial_status' => $financialStatus,
                    'has_failed_courses' => $data['has_failed_courses'] ?? false,
                    'failed_courses_data' => !empty($data['failed_courses']) ? json_encode($data['failed_courses']) : null,
                    'remarks' => $data['remarks'] ?? null
                ];
                
                $registrationId = $db->insert('semester_registration', $insertData);
                
                return [
                    'success' => true,
                    'message' => 'Semester registration created successfully',
                    'registration_id' => $registrationId,
                    'registration_number' => $regNumber
                ];
            });
        } catch (Exception $e) {
            error_log('Semester registration failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Registration could not be completed. Please try again.'];
        }
    }
    
    /**
     * Register courses for a semester registration
     * @param int $semesterRegistrationId
     * @param array $courses Array of course codes
     * @return array Success status and details
     */
    public function registerCourses(int $semesterRegistrationId, array $courses, string $studentId): array {
        if (empty($courses)) {
            return ['success' => false, 'message' => 'No courses provided'];
        }
        
        try {
            return $this->db->transaction(function($db) use ($semesterRegistrationId, $courses, $studentId) {
                // Get semester registration details
                $semReg = $db->fetchOne(
                    "SELECT * FROM semester_registration WHERE id = ? AND student_id = ?",
                    [$semesterRegistrationId, $studentId]
                );
                
                if (!$semReg) {
                    return ['success' => false, 'message' => 'Semester registration not found'];
                }
                
                if ($semReg['registration_status'] !== 'Draft') {
                    return ['success' => false, 'message' => 'Cannot modify submitted registration'];
                }
                
                $registeredCourses = [];
                $totalCredits = 0;
                $totalFees = 0;
                $errors = [];
                
                foreach ($courses as $courseCode) {
                    // Get course details
                    $course = $db->fetchOne(
                        "SELECT * FROM courses WHERE course_code = ? AND status = 'active'",
                        [$courseCode]
                    );
                    
                    if (!$course) {
                        $errors[] = "Course $courseCode not found or inactive";
                        continue;
                    }
                    
                    // Check if course is in program curriculum
                    $inCurriculum = $db->fetchOne(
                        "SELECT * FROM program_courses 
                         WHERE program_code = ? AND course_code = ?",
                        [$semReg['program_code'], $courseCode]
                    );
                    
                    if (!$inCurriculum) {
                        $errors[] = "Course $courseCode not in program curriculum";
                        continue;
                    }
                    
                    // Check if already registered for this course
                    $existing = $db->fetchOne(
                        "SELECT id FROM course_registration 
                         WHERE semester_registration_id = ? AND course_code = ?",
                        [$semesterRegistrationId, $courseCode]
                    );
                    
                    if ($existing) {
                        continue; // Skip already registered courses
                    }
                    
                    // Determine if this is a repeat course
                    $isRepeat = false;
                    $previousAttempt = null;
                    
                    if ($semReg['has_failed_courses']) {
                        $prevAttempt = $db->fetchOne(
                            "SELECT id FROM course_registration 
                             WHERE student_id = ? AND course_code = ? 
                             AND grade IN ('F', 'I') 
                             ORDER BY registered_at DESC LIMIT 1",
                            [$semReg['student_id'], $courseCode]
                        );
                        
                        if ($prevAttempt) {
                            $isRepeat = true;
                            $previousAttempt = $prevAttempt['id'];
                        }
                    }
                    
                    // Insert course registration
                    $courseData = [
                        'semester_registration_id' => $semesterRegistrationId,
                        'student_id' => $semReg['student_id'],
                        'course_code' => $courseCode,
                        'course_type' => $isRepeat ? 'Repeat' : 'Core',
                        'credits' => $course['credits'] ?? 3,
                        'course_fee' => $course['course_fee'] ?? 0,
                        'is_repeat' => $isRepeat ? 1 : 0,
                        'previous_attempt_id' => $previousAttempt,
                        'registration_status' => 'Active'
                    ];
                    
                    $courseRegId = $db->insert('course_registration', $courseData);
                    $registeredCourses[] = $courseCode;
                    $totalCredits += $courseData['credits'];
                    $totalFees += $courseData['course_fee'];
                }
                
                // Validate credit requirements
                if ($totalCredits < 12) {
                    return [
                        'success' => false,
                        'message' => 'Minimum 12 credits required for registration',
                        'total_credits' => $totalCredits
                    ];
                }
                
                if ($totalCredits > 21) {
                    return [
                        'success' => false,
                        'message' => 'Maximum 21 credits allowed per semester',
                        'total_credits' => $totalCredits
                    ];
                }
                
                return [
                    'success' => true,
                    'message' => 'Courses registered successfully',
                    'registered_courses' => $registeredCourses,
                    'total_courses' => count($registeredCourses),
                    'total_credits' => $totalCredits,
                    'total_fees' => $totalFees,
                    'errors' => $errors
                ];
            });
        } catch (Exception $e) {
            error_log('Course registration failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Course registration could not be completed. Please try again.'];
        }
    }
    
    /**
     * Submit a semester registration for approval
     * @param int $semesterRegistrationId
     * @return array Success status and message
     */
    public function submitRegistration(int $semesterRegistrationId, string $studentId): array {
        try {
            return $this->db->transaction(function($db) use ($semesterRegistrationId, $studentId) {
                $semReg = $db->fetchOne(
                    "SELECT * FROM semester_registration WHERE id = ? AND student_id = ?",
                    [$semesterRegistrationId, $studentId]
                );
                
                if (!$semReg) {
                    return ['success' => false, 'message' => 'Registration not found'];
                }
                
                if ($semReg['registration_status'] !== 'Draft') {
                    return ['success' => false, 'message' => 'Registration already submitted'];
                }
                
                // Validate minimum course requirements
                if ($semReg['total_courses'] == 0) {
                    return ['success' => false, 'message' => 'No courses registered'];
                }
                
                if ($semReg['total_credits'] < 12) {
                    return [
                        'success' => false,
                        'message' => 'Minimum 12 credits required',
                        'current_credits' => $semReg['total_credits']
                    ];
                }
                
                // Update registration status
                $db->update(
                    'semester_registration',
                    [
                        'registration_status' => 'Pending',
                        'registration_date' => date('Y-m-d H:i:s')
                    ],
                    'id = :id',
                    [':id' => $semesterRegistrationId]
                );
                
                // Generate fees breakdown
                $this->generateRegistrationFees($semesterRegistrationId);
                
                return [
                    'success' => true,
                    'message' => 'Registration submitted successfully',
                    'registration_number' => $semReg['registration_number']
                ];
            });
        } catch (Exception $e) {
            error_log('Submit registration failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Registration submission could not be completed. Please try again.'];
        }
    }
    
    /**
     * Get registration details with courses
     * @param int $semesterRegistrationId
     * @return array|null
     */
    public function getRegistrationDetails(int $semesterRegistrationId, string $studentId): ?array {
        $registration = $this->db->fetchOne(
            "SELECT sr.*, s.Fname, s.Lname, s.email, p.program_name, acs.session_name
             FROM semester_registration sr
             JOIN students s ON sr.student_id = s.SID
             JOIN programs p ON sr.program_code = p.program_code
             JOIN academic_sessions acs ON sr.academic_session_id = acs.id
              WHERE sr.id = ? AND sr.student_id = ?",
            [$semesterRegistrationId, $studentId]
        );
        
        if (!$registration) {
            return null;
        }
        
        // Get registered courses
        $courses = $this->db->fetchAll(
            "SELECT cr.*, c.course_name, c.credits, c.course_fee
             FROM course_registration cr
             JOIN courses c ON cr.course_code = c.course_code
             WHERE cr.semester_registration_id = ?
             AND cr.registration_status = 'Active'",
            [$semesterRegistrationId]
        );
        
        $registration['courses'] = $courses;
        
        // Get fees breakdown
        $fees = $this->db->fetchAll(
            "SELECT * FROM registration_fees WHERE semester_registration_id = ?",
            [$semesterRegistrationId]
        );
        
        $registration['fees'] = $fees;
        
        return $registration;
    }
    
    /**
     * Get student's registration history
     * @param string $studentId
     * @return array
     */
    public function getStudentRegistrationHistory(string $studentId): array {
        return $this->db->fetchAll(
            "SELECT sr.*, acs.session_name, p.program_name
             FROM semester_registration sr
             JOIN academic_sessions acs ON sr.academic_session_id = acs.id
             JOIN programs p ON sr.program_code = p.program_code
             WHERE sr.student_id = ?
             ORDER BY sr.created_at DESC",
            [$studentId]
        );
    }
    
    /**
     * Get available courses for a program. Semester is kept for legacy callers;
     * course ownership is academic-year/program based unless rows are explicitly
     * made period-specific in newer schema.
     * @param string $programCode
     * @param int $semester
     * @return array
     */
    public function getAvailableCourses(string $programCode, int $semester): array {
        return $this->db->fetchAll(
            "SELECT c.*, pc.semester as program_semester
             FROM courses c
             JOIN program_courses pc ON c.course_code = pc.course_code
             WHERE pc.program_code = ? AND c.status = 'active'
             ORDER BY c.course_code",
            [$programCode]
        );
    }
    
    /**
     * Drop a course from registration
     * @param int $courseRegistrationId
     * @return array
     */
    public function dropCourse(int $courseRegistrationId, string $studentId): array {
        try {
            return $this->db->transaction(function($db) use ($courseRegistrationId, $studentId) {
                $courseReg = $db->fetchOne(
                    "SELECT cr.*, sr.registration_status 
                     FROM course_registration cr
                     JOIN semester_registration sr ON cr.semester_registration_id = sr.id
                     WHERE cr.id = ? AND sr.student_id = ?",
                    [$courseRegistrationId, $studentId]
                );
                
                if (!$courseReg) {
                    return ['success' => false, 'message' => 'Course registration not found'];
                }
                
                if ($courseReg['registration_status'] !== 'Draft') {
                    return ['success' => false, 'message' => 'Cannot drop course from submitted registration'];
                }
                
                // Delete course registration (triggers will update semester_registration totals)
                $db->delete('course_registration', 'id = ?', [$courseRegistrationId]);
                
                return ['success' => true, 'message' => 'Course dropped successfully'];
            });
        } catch (Exception $e) {
            error_log('Drop course failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'The course could not be dropped. Please try again.'];
        }
    }
    
    /**
     * Generate unique registration number
     * @param int $sessionId
     * @param int $semester
     * @return string
     */
    private function generateRegistrationNumber(int $sessionId, int $semester): string {
        $session = $this->db->fetchOne("SELECT session_name FROM academic_sessions WHERE id = ?", [$sessionId]);
        $year = substr($session['session_name'], 0, 4);
        
        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) + 1 FROM semester_registration WHERE academic_session_id = ?",
            [$sessionId]
        );
        
        return sprintf('REG-%s-S%d-%04d', $year, $semester, $count);
    }
    
    /**
     * Check student's financial status
     * @param string $studentId
     * @return string
     */
    private function checkFinancialStatus(string $studentId): string {
        $unpaidInvoices = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM invoices WHERE student_id = ? AND status = 'Pending'",
            [$studentId]
        );
        
        return $unpaidInvoices > 0 ? 'Blocked' : 'Clear';
    }
    
    /**
     * Generate registration fees breakdown
     * @param int $semesterRegistrationId
     * @return void
     */
    private function generateRegistrationFees(int $semesterRegistrationId): void {
        $semReg = $this->db->fetchOne(
            "SELECT * FROM semester_registration WHERE id = ?",
            [$semesterRegistrationId]
        );
        
        if (!$semReg) return;
        
        // Standard fees (customize as needed)
        $fees = [
            ['fee_type' => 'Tuition', 'fee_name' => 'Semester Tuition', 'amount' => $semReg['total_fees']],
            ['fee_type' => 'Registration', 'fee_name' => 'Registration Fee', 'amount' => 50.00],
            ['fee_type' => 'Library', 'fee_name' => 'Library Fee', 'amount' => 25.00],
            ['fee_type' => 'Sports', 'fee_name' => 'Sports Fee', 'amount' => 15.00]
        ];
        
        foreach ($fees as $fee) {
            $fee['semester_registration_id'] = $semesterRegistrationId;
            $this->db->insert('registration_fees', $fee);
        }
    }
}
