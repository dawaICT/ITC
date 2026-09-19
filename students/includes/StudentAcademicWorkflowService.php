<?php
/**
 * Central academic workflow: period resolution, fee eligibility, registration,
 * test docket, and exam slip gates.
 */
declare(strict_types=1);

require_once __DIR__ . '/AcademicSessionService.php';
require_once __DIR__ . '/RegistrationDataService.php';
require_once __DIR__ . '/StudentDataService.php';
require_once __DIR__ . '/period_mode_helper.php';
require_once __DIR__ . '/FeeGuard.php';
require_once __DIR__ . '/student_fee_records.php';
require_once dirname(__DIR__, 2) . '/includes/helpers/academic_period_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/helpers/academic_structure_helpers.php';

class StudentAcademicWorkflowService
{
    private mysqli $db;
    private AcademicSessionService $sessionService;
    private RegistrationDataService $regData;
    private StudentDataService $studentData;
    private array $semRegCols = [];
    private array $courseRegCols = [];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->sessionService = new AcademicSessionService($db);
        $this->regData = new RegistrationDataService($db);
        $this->studentData = new StudentDataService($db);
        $this->discoverColumns();
    }

    /** Required payment % by calendar type and period number. */
    public static function requiredFeePercentage(string $calendarType, int $periodNumber, float $shortCourseDefault = 100.0): float
    {
        $calendarType = strtolower(trim($calendarType));
        if (in_array($calendarType, ['short_course', 'short_course_cycle', 'cycle'], true)) {
            return max(0.0, min(100.0, $shortCourseDefault));
        }
        if ($calendarType === 'term') {
            return $periodNumber <= 1 ? 50.0 : 100.0;
        }
        if ($calendarType === 'semester') {
            return $periodNumber <= 1 ? 50.0 : 100.0;
        }
        return $periodNumber <= 1 ? 50.0 : 100.0;
    }

    /**
     * Resolve active academic period for a student.
     *
     * @return array{ok:bool,error?:string,student_id?:string,program_code?:string,calendar_type?:string,academic_year_id?:int|null,academic_year?:string,academic_period_id?:int|null,period_name?:string,period_number?:int,year_of_study?:int,registration_open?:bool,docket_open?:bool,exam_slip_open?:bool,period_label?:string}
     */
    public function getActiveAcademicPeriod(string $studentId): array
    {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return ['ok' => false, 'error' => 'Student session is missing.'];
        }

        $student = $this->studentData->getStudentWithProgram($studentId);
        if (!$student) {
            return ['ok' => false, 'error' => 'Student account is inactive or invalid.'];
        }

        $programCode = $this->regData->getBestStudentProgramCode($studentId);
        if ($programCode === '') {
            $programCode = trim((string)($student['program_code'] ?? ''));
        }
        if ($programCode === '') {
            return ['ok' => false, 'error' => 'No programme is assigned to your student account.'];
        }

        $periodMode = getStudentProgramPeriodMode($this->db, $studentId);
        if ($periodMode === '') {
            $periodMode = normalizeProgramPeriodMode(getProgramPeriodMode($this->db, $programCode));
        }
        if ($periodMode === '') {
            return ['ok' => false, 'error' => 'Programme calendar type is not configured.'];
        }

        $calendarType = in_array($periodMode, ['term', 'semester'], true) ? $periodMode : $periodMode;
        $session = $this->sessionService->getCurrentSession(
            in_array($periodMode, ['term', 'semester'], true) ? $periodMode : null
        );
        if (empty($session['academic_year'])) {
            return ['ok' => false, 'error' => 'No active academic year is configured.'];
        }

        $periodNumber = (int)($session['period_number'] ?? $session['semester_term'] ?? $session['semester'] ?? 0);
        if ($periodNumber < 1) {
            return ['ok' => false, 'error' => 'No active term or semester is open for registration.'];
        }
        if (in_array($calendarType, ['term', 'semester'], true)
            && !in_array($periodNumber, getValidAcademicPeriods($this->db, $programCode), true)) {
            return ['ok' => false, 'error' => 'The active academic period is invalid for your programme. Contact the registrar.'];
        }

        $periodName = trim((string)($session['period_name'] ?? ''));
        if ($periodName === '') {
            $periodName = getPeriodLabel($this->db, $studentId) . ' ' . $periodNumber;
        }

        $academicYear = trim((string)$session['academic_year']);
        $yearOfStudy = max(1, $this->studentData->getStudentYearOfStudy($studentId, $academicYear));
        $periodLabel = self::formatPeriodLabel($calendarType, $periodNumber, $academicYear, $periodName);

        return [
            'ok' => true,
            'student_id' => $studentId,
            'program_code' => $programCode,
            'calendar_type' => $calendarType,
            'academic_year_id' => isset($session['academic_year_id']) ? (int)$session['academic_year_id'] : null,
            'academic_year' => $academicYear,
            'academic_period_id' => isset($session['id']) ? (int)$session['id'] : null,
            'period_name' => $periodName,
            'period_number' => $periodNumber,
            'year_of_study' => $yearOfStudy,
            'registration_open' => (bool)(int)($session['registration_open'] ?? 1),
            'docket_open' => (bool)(int)($session['docket_open'] ?? 0),
            'exam_slip_open' => (bool)(int)($session['exam_slip_open'] ?? 0),
            'period_label' => $periodLabel,
            'session' => $session,
        ];
    }

    public static function formatPeriodLabel(string $calendarType, int $periodNumber, string $academicYear, ?string $periodName = null): string
    {
        $calendarType = strtolower($calendarType);
        $unit = $calendarType === 'term' ? 'Term' : ($calendarType === 'semester' ? 'Semester' : 'Period');
        $name = $periodName !== null && $periodName !== '' ? $periodName : "{$unit} {$periodNumber}";
        return "{$name}, Academic Year {$academicYear}";
    }

    /**
     * @return array{total_fee:float,amount_paid:float,balance:float,payment_percentage:float,required_percentage:float,is_eligible:bool,reason:string,is_sponsored:bool,fee_status:string}
     */
    public function checkFeeEligibility(string $studentId, ?array $periodContext = null): array
    {
        $base = [
            'total_fee' => 0.0,
            'amount_paid' => 0.0,
            'balance' => 0.0,
            'payment_percentage' => 0.0,
            'required_percentage' => 50.0,
            'is_eligible' => false,
            'reason' => '',
            'is_sponsored' => false,
            'fee_status' => 'unknown',
        ];

        if ($periodContext === null) {
            $periodContext = $this->getActiveAcademicPeriod($studentId);
            if (!$periodContext['ok']) {
                $base['reason'] = (string)($periodContext['error'] ?? 'Could not resolve academic period.');
                return $base;
            }
        }

        $calendarType = (string)($periodContext['calendar_type'] ?? 'semester');
        $periodNumber = (int)($periodContext['period_number'] ?? 1);
        $yearOfStudy = (int)($periodContext['year_of_study'] ?? 1);
        $academicYear = (string)($periodContext['academic_year'] ?? '');
        $programCode = (string)($periodContext['program_code'] ?? '');

        $requiredPct = self::requiredFeePercentage($calendarType, $periodNumber);
        $base['required_percentage'] = $requiredPct;

        if (fg_is_fully_sponsored($this->db, $studentId)) {
            $base['is_sponsored'] = true;
            $base['is_eligible'] = true;
            $base['fee_status'] = 'sponsored';
            $base['payment_percentage'] = 100.0;
            $base['reason'] = 'Student has met the required fee threshold (sponsorship).';
            return $base;
        }

        // New fee accounts module takes precedence when active.
        if (student_fee_table_exists($this->db, 'student_fee_accounts')) {
            $stmt = $this->db->prepare(
                "SELECT total_payable, amount_paid, balance FROM student_fee_accounts
                 WHERE student_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $total = (float)($row['total_payable'] ?? 0);
                    $paid = (float)($row['amount_paid'] ?? 0);
                    if ($total > 0) {
                        $base['total_fee'] = $total;
                        $base['amount_paid'] = $paid;
                        $base['balance'] = max(0.0, $total - $paid);
                        $base['payment_percentage'] = round(($paid / $total) * 100, 2);
                        $base['is_eligible'] = $base['payment_percentage'] + 1e-6 >= $requiredPct;
                        $base['fee_status'] = $base['is_eligible'] ? 'eligible' : 'not_eligible';
                        $base['reason'] = $base['is_eligible']
                            ? 'Student has met the required fee threshold.'
                            : self::feeBlockReason($calendarType, $periodNumber, $requiredPct);
                        return $base;
                    }
                }
            }
        }

        $totalFee = $programCode !== '' ? fg_required_fee($this->db, $programCode, $yearOfStudy, $periodNumber) : 0.0;
        $base['total_fee'] = $totalFee;

        if ($totalFee <= 0) {
            error_log(sprintf(
                'StudentAcademicWorkflow: no fee structure for %s year %d period %d — fail-open.',
                $programCode,
                $yearOfStudy,
                $periodNumber
            ));
            $base['is_eligible'] = true;
            $base['payment_percentage'] = 100.0;
            $base['fee_status'] = 'eligible';
            $base['reason'] = 'No fee structure configured for this period.';
            return $base;
        }

        $amountPaid = $this->sumApprovedPayments($studentId, $academicYear, $periodNumber, $yearOfStudy, $totalFee);
        $base['amount_paid'] = $amountPaid;
        $base['balance'] = max(0.0, $totalFee - $amountPaid);
        $base['payment_percentage'] = round(($amountPaid / $totalFee) * 100, 2);
        $base['is_eligible'] = $base['payment_percentage'] + 1e-6 >= $requiredPct;
        $base['fee_status'] = $base['is_eligible'] ? 'eligible' : 'not_eligible';
        $base['reason'] = $base['is_eligible']
            ? 'Student has met the required fee threshold.'
            : self::feeBlockReason($calendarType, $periodNumber, $requiredPct);

        return $base;
    }

    private static function feeBlockReason(string $calendarType, int $periodNumber, float $requiredPct): string
    {
        $unit = strtolower($calendarType) === 'term' ? 'Term' : 'Semester';
        return sprintf(
            'Payment is below the required threshold. You must clear %.0f%% of fees for %s %d registration.',
            $requiredPct,
            $unit,
            $periodNumber
        );
    }

    private function sumApprovedPayments(string $studentId, string $academicYear, int $periodNumber, int $yearOfStudy, float $totalFee): float
    {
        $latestBalance = fg_latest_term_balance($this->db, $studentId, $yearOfStudy, $periodNumber);
        if ($latestBalance !== null && $totalFee > 0) {
            return max(0.0, $totalFee - (float)$latestBalance);
        }

        $filter = [
            'semester' => (string)$periodNumber,
            'year_of_study' => (string)$yearOfStudy,
            'academic_year' => $academicYear,
        ];
        $records = student_fee_completed_payments($this->db, $studentId, $filter, 500);
        $paid = 0.0;
        foreach ($records as $row) {
            $paid += (float)($row['amount_paid'] ?? 0);
        }
        if ($paid > 0) {
            return $paid;
        }

        if (fg_table_exists($this->db, 'payments')) {
            $where = ['student_id = ?'];
            $types = 's';
            $params = [$studentId];
            if ($academicYear !== '' && fg_column_exists($this->db, 'payments', 'academic_year')) {
                $where[] = 'academic_year = ?';
                $types .= 's';
                $params[] = $academicYear;
            }
            if (fg_column_exists($this->db, 'payments', 'semester')) {
                $where[] = 'semester = ?';
                $types .= 'i';
                $params[] = $periodNumber;
            }
            if (fg_column_exists($this->db, 'payments', 'status')) {
                $where[] = "status IN ('posted','completed','confirmed')";
            }
            $sql = 'SELECT COALESCE(SUM(amount),0) AS p FROM payments WHERE ' . implode(' AND ', $where);
            if ($stmt = $this->db->prepare($sql)) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $paid = (float)($stmt->get_result()->fetch_assoc()['p'] ?? 0.0);
                $stmt->close();
            }
        }

        return $paid;
    }

    /**
     * @return array{is_registered:bool,registration_id:?int,registration_status:string,registered_courses:array,period_label:string,row:?array}
     */
    public function checkStudentRegistration(string $studentId, ?array $periodContext = null): array
    {
        $empty = [
            'is_registered' => false,
            'registration_id' => null,
            'registration_status' => '',
            'registered_courses' => [],
            'period_label' => '',
            'row' => null,
        ];

        if ($periodContext === null) {
            $periodContext = $this->getActiveAcademicPeriod($studentId);
            if (!$periodContext['ok']) {
                return $empty;
            }
        }

        $row = $this->findSemesterRegistration($studentId, $periodContext);
        if (!$row) {
            return $empty;
        }

        $regId = isset($row['id']) ? (int)$row['id'] : null;
        $yearOfStudy = (int)($row['year_of_study'] ?? $periodContext['year_of_study'] ?? 1);
        $semester = (int)($row['semester'] ?? $periodContext['period_number'] ?? 1);
        $academicYear = trim((string)($row['academic_year'] ?? $periodContext['academic_year'] ?? ''));
        $courses = $this->regData->getRegisteredCourses(
            $studentId,
            $yearOfStudy,
            $semester,
            $regId,
            $academicYear !== '' ? $academicYear : null,
            'year'
        );

        $calendarType = (string)($periodContext['calendar_type'] ?? 'semester');
        $periodLabel = (string)($periodContext['period_label'] ?? self::formatPeriodLabel(
            $calendarType,
            $semester,
            (string)($periodContext['academic_year'] ?? '')
        ));

        return [
            'is_registered' => true,
            'registration_id' => $regId,
            'registration_status' => (string)($row['registration_status'] ?? 'registered'),
            'registered_courses' => $courses,
            'period_label' => $periodLabel,
            'row' => $row,
        ];
    }

    /** @return array{available:bool,status:string,reason:string,fee?:array,registration?:array,period?:array} */
    public function getDocketEligibility(string $studentId): array
    {
        return $this->getDocumentEligibility($studentId, 'docket');
    }

    /** @return array{available:bool,status:string,reason:string,fee?:array,registration?:array,period?:array} */
    public function getExamSlipEligibility(string $studentId): array
    {
        return $this->getDocumentEligibility($studentId, 'exam_slip');
    }

    /** @return array{available:bool,status:string,reason:string,fee?:array,registration?:array,period?:array} */
    private function getDocumentEligibility(string $studentId, string $kind): array
    {
        $blocked = static fn(string $reason): array => [
            'available' => false,
            'status' => 'Not Available',
            'reason' => $reason,
        ];

        if ($this->isStudentBlocked($studentId)) {
            return $blocked('Your account is blocked from academic services. Contact administration.');
        }

        $period = $this->getActiveAcademicPeriod($studentId);
        if (!$period['ok']) {
            return $blocked((string)($period['error'] ?? 'Academic period could not be resolved.'));
        }

        $openFlag = $kind === 'docket' ? ($period['docket_open'] ?? false) : ($period['exam_slip_open'] ?? false);
        if (!$openFlag) {
            $label = $kind === 'docket' ? 'Test docket' : 'Exam slip';
            return $blocked("{$label} is not yet available for this period.");
        }

        $registration = $this->checkStudentRegistration($studentId, $period);
        if (!$registration['is_registered']) {
            return $blocked('You are not registered for this academic period.');
        }

        if ($registration['registered_courses'] === []) {
            return $blocked('No registered courses found.');
        }

        $fee = $this->checkFeeEligibility($studentId, $period);
        if (!$fee['is_eligible']) {
            return $blocked($fee['reason']);
        }

        $label = $kind === 'docket' ? 'Test Docket' : 'Exam Slip';
        return [
            'available' => true,
            'status' => 'Available',
            'reason' => "{$label} is available for {$registration['period_label']}.",
            'fee' => $fee,
            'registration' => $registration,
            'period' => $period,
        ];
    }

    /**
     * Register student for active period and auto-enrol programme courses.
     *
     * @param array<string,mixed> $options registration_type, skip_teveta
     * @return array{ok:bool,message:string,registration_id?:int,courses_registered?:int,period_label?:string}
     */
    public function registerStudentForPeriod(string $studentId, array $options = []): array
    {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return ['ok' => false, 'message' => 'Student session is missing.'];
        }

        if ($this->isStudentBlocked($studentId)) {
            return ['ok' => false, 'message' => 'Your account is blocked from registration. Contact administration.'];
        }

        $period = $this->getActiveAcademicPeriod($studentId);
        if (!$period['ok']) {
            return ['ok' => false, 'message' => (string)($period['error'] ?? 'Could not resolve academic period.')];
        }

        if (empty($period['registration_open'])) {
            return ['ok' => false, 'message' => 'Registration is not open for the current academic period.'];
        }

        // A progression/pre-allocation placeholder (registration_status =
        // 'pending') must not lock the student out: completing registration
        // claims the placeholder row in the transaction below.
        $existingRegistration = $this->findSemesterRegistration($studentId, $period);
        $claimPendingRegistration = false;
        if ($existingRegistration) {
            $existingStatus = strtolower(trim((string)($existingRegistration['registration_status'] ?? '')));
            if ($existingStatus === 'pending') {
                $claimPendingRegistration = true;
            } else {
                return ['ok' => false, 'message' => 'You are already registered for this academic period.'];
            }
        }

        $fee = $this->checkFeeEligibility($studentId, $period);
        if (!$fee['is_eligible'] && $this->isRegistrationFeeGateEnabled()) {
            return ['ok' => false, 'message' => $fee['reason']];
        }

        $programCode = (string)$period['program_code'];
        $yearOfStudy = (int)$period['year_of_study'];
        $periodNumber = (int)$period['period_number'];
        $academicYear = (string)$period['academic_year'];
        $calendarType = (string)$period['calendar_type'];

        $courses = getCoursesForProgramYearOfStudy($this->db, $programCode, $yearOfStudy);
        if ($courses === []) {
            return ['ok' => false, 'message' => 'No courses are configured for your programme and academic year.'];
        }

        if (empty($options['skip_teveta'])) {
            require_once __DIR__ . '/TEVETARegistrationValidator.php';
            $validator = new TEVETARegistrationValidator($this->db);
            $session = $period['session'] ?? [];
            $valResult = $validator->validateRegistration([
                'student_id' => $studentId,
                'programme_id' => $programCode,
                'registration_date' => date('Y-m-d'),
                'academic_year' => $academicYear,
                'semester' => (string)$periodNumber,
                'start_date' => $session['start_date'] ?? date('Y-m-d'),
                'end_date' => $session['end_date'] ?? date('Y-m-d', strtotime('+120 days')),
            ]);
            if (!$valResult['is_valid']) {
                return ['ok' => false, 'message' => 'TEVETA Validation Error: ' . implode(' ', $valResult['errors'])];
            }
            if (!empty($valResult['requires_manual_review'])) {
                return ['ok' => false, 'message' => 'Registration requires manual administrative review.'];
            }
        }

        $regType = (string)($options['registration_type'] ?? 'Regular');
        $periodType = wuc_legacy_period_type($calendarType);
        $feeStatus = $fee['is_sponsored'] ? 'sponsored' : ($fee['is_eligible'] ? 'eligible' : 'not_eligible');

        $this->db->begin_transaction();
        try {
            // Serialize registration with annual progression and reject a stale
            // calendar/request before it can rewind the student's position.
            $position = $this->db->prepare(
                "SELECT academic_year, COALESCE(NULLIF(year_of_study, 0), current_year_number, 1) AS year_of_study
                   FROM student_program WHERE Sid = ? AND program_code = ?
                    AND (status IS NULL OR status = '' OR LOWER(status) = 'active')
                  ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $position->bind_param('ss', $studentId, $programCode);
            $position->execute();
            $currentPosition = $position->get_result()->fetch_assoc();
            $position->close();
            if (!$currentPosition
                || (int)$currentPosition['academic_year'] > (int)$academicYear
                || (int)$currentPosition['year_of_study'] !== $yearOfStudy) {
                throw new RuntimeException('The student academic position has changed. Refresh registration or contact the registrar.');
            }
            if ($claimPendingRegistration) {
                $semRegId = (int)$existingRegistration['id'];
            } else {
                $semRegId = $this->regData->createRegistration([
                    'student_id' => $studentId,
                    'academic_year' => $academicYear,
                    'semester' => (string)$periodNumber,
                    'year_of_study' => $yearOfStudy,
                    'program_code' => $programCode,
                    'registration_date' => date('Y-m-d H:i:s'),
                    'registration_type' => $regType,
                    'period_type' => $periodType,
                ]);
            }

            $this->updateRegistrationWorkflowColumns($semRegId, $period, $feeStatus);
            $this->syncStudentProgramPosition($studentId, $programCode, $yearOfStudy, $periodNumber, $calendarType, $academicYear);

            $courseCount = $this->autoEnrolCourses($studentId, $semRegId, $courses, $periodNumber, $yearOfStudy, $academicYear, $programCode);

            $this->db->commit();

            $periodLabel = (string)$period['period_label'];
            return [
                'ok' => true,
                'message' => "Registration successful for {$periodLabel}. {$courseCount} course(s) enrolled.",
                'registration_id' => $semRegId,
                'courses_registered' => $courseCount,
                'period_label' => $periodLabel,
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('[StudentAcademicWorkflow] registerStudentForPeriod: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Registration could not be completed. Please try again or contact ICT.'];
        }
    }

    /**
     * Enrol any missing academic-year catalogue courses for an already-registered student.
     */
    public function ensureYearCoursesEnrolled(string $studentId, ?array $periodContext = null): int
    {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return 0;
        }

        if ($periodContext === null) {
            $periodContext = $this->getActiveAcademicPeriod($studentId);
            if (empty($periodContext['ok'])) {
                return 0;
            }
        }

        $row = $this->findSemesterRegistration($studentId, $periodContext);
        if (!$row) {
            return 0;
        }

        // Progression placeholder rows are claimed (and courses enrolled) only
        // when the student completes the fee-gated registration step.
        if (strtolower(trim((string)($row['registration_status'] ?? ''))) === 'pending') {
            return 0;
        }

        $programCode = trim((string)($row['program_code'] ?? $periodContext['program_code'] ?? ''));
        if ($programCode === '') {
            return 0;
        }

        $regId = (int)($row['id'] ?? 0);
        if ($regId < 1) {
            return 0;
        }

        $yearOfStudy = (int)($row['year_of_study'] ?? $periodContext['year_of_study'] ?? 1);
        $periodNumber = (int)($row['semester'] ?? $periodContext['period_number'] ?? 1);
        $academicYear = trim((string)($row['academic_year'] ?? $periodContext['academic_year'] ?? ''));
        $courses = getCoursesForProgramYearOfStudy($this->db, $programCode, $yearOfStudy);
        if ($courses === []) {
            return 0;
        }

        try {
            $this->db->begin_transaction();
            $count = $this->autoEnrolCourses(
                $studentId,
                $regId,
                $courses,
                $periodNumber,
                $yearOfStudy,
                $academicYear,
                $programCode
            );
            $this->db->commit();
            return $count;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('[StudentAcademicWorkflow] ensureYearCoursesEnrolled: ' . $e->getMessage());
            return 0;
        }
    }

    /** @param array<int,array<string,mixed>> $courses */
    private function autoEnrolCourses(
        string $studentId,
        int $semRegId,
        array $courses,
        int $periodNumber,
        int $yearOfStudy,
        string $academicYear,
        string $programCode
    ): int {
        $sidCol = $this->courseRegCols['sid'] ?? ($this->courseRegCols['student_id'] ?? 'Sid');
        $semCol = $this->courseRegCols['semester'] ?? 'semester';
        $yearCol = $this->courseRegCols['year'] ?? 'Year';
        $acYearCol = $this->courseRegCols['academic_year'] ?? null;
        $semRegCol = $this->courseRegCols['semester_registration_id'] ?? null;
        $statusCol = $this->courseRegCols['status'] ?? null;
        $activeCol = $this->courseRegCols['is_active'] ?? null;

        $inserted = 0;
        foreach ($courses as $course) {
            $code = trim((string)($course['course_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            if ($this->courseRegistrationExistsForYear($studentId, $code, $yearOfStudy, $academicYear)) {
                $this->reactivateCourseRegistrationForYear($studentId, $code, $yearOfStudy, $academicYear, $semRegId, $periodNumber);
                continue;
            }

            $cols = ["`{$sidCol}`", 'course_code', "`{$semCol}`", "`{$yearCol}`"];
            $types = 'ssii';
            $params = [$studentId, $code, $periodNumber, $yearOfStudy];

            if ($acYearCol) {
                $cols[] = "`{$acYearCol}`";
                $norm = preg_match('/(\d{4})/', $academicYear, $m) ? $m[1] : $academicYear;
                $types .= 's';
                $params[] = $norm;
            }
            if ($semRegCol) {
                $cols[] = 'semester_registration_id';
                $types .= 'i';
                $params[] = $semRegId;
            }
            if ($statusCol) {
                $cols[] = "`{$statusCol}`";
                $types .= 's';
                $params[] = 'registered';
            }
            if ($activeCol) {
                $cols[] = "`{$activeCol}`";
                $types .= 'i';
                $params[] = 1;
            }

            $updates = ['id = LAST_INSERT_ID(id)'];
            if ($semRegCol) {
                $updates[] = 'semester_registration_id = VALUES(semester_registration_id)';
            }
            if ($statusCol) {
                $updates[] = "`{$statusCol}` = 'registered'";
            }
            if ($activeCol) {
                $updates[] = "`{$activeCol}` = 1";
            }
            if (isset($this->courseRegCols['updated_at'])) {
                $updates[] = '`' . $this->courseRegCols['updated_at'] . '` = CURRENT_TIMESTAMP';
            }
            $sql = 'INSERT INTO course_registration (' . implode(', ', $cols) . ') VALUES ('
                . implode(',', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE '
                . implode(', ', $updates);
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Failed to prepare course registration insert.');
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new RuntimeException('Course registration failed: ' . $err);
            }
            $wasInserted = $stmt->affected_rows === 1;
            $stmt->close();
            if ($wasInserted) {
                $inserted++;
            }

            if (function_exists('wuc_sync_legacy_course_registration_to_canonical')) {
                wuc_sync_legacy_course_registration_to_canonical(
                    $this->db,
                    $studentId,
                    $programCode,
                    $code,
                    $yearOfStudy,
                    $periodNumber,
                    'REGISTERED'
                );
            }
        }

        return $inserted;
    }

    private function courseRegistrationExistsForYear(
        string $studentId,
        string $courseCode,
        int $yearOfStudy,
        string $academicYear
    ): bool {
        $sidCol = $this->courseRegCols['sid'] ?? ($this->courseRegCols['student_id'] ?? 'Sid');
        $yearCol = $this->courseRegCols['year'] ?? 'Year';
        $acYearCol = $this->courseRegCols['academic_year'] ?? null;
        $activeCol = $this->courseRegCols['is_active'] ?? null;

        $match = ["`{$yearCol}` = ?"];
        $types = 'ssi';
        $params = [$studentId, $courseCode, $yearOfStudy];
        if (preg_match('/(\d{4})/', $academicYear, $m)) {
            if ($acYearCol) {
                $match[] = "CAST(`{$acYearCol}` AS CHAR) = ?";
                $types .= 's';
                $params[] = $m[1];
            }
        }

        $sql = "SELECT 1 FROM course_registration WHERE `{$sidCol}` = ? AND course_code = ? AND "
            . implode(' AND ', $match);
        if ($activeCol) {
            $sql .= " AND (`{$activeCol}` = 1 OR `{$activeCol}` IS NULL)";
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }

    private function reactivateCourseRegistrationForYear(
        string $studentId,
        string $courseCode,
        int $yearOfStudy,
        string $academicYear,
        int $semRegId,
        int $periodNumber
    ): void {
        $sidCol = $this->courseRegCols['sid'] ?? ($this->courseRegCols['student_id'] ?? 'Sid');
        $yearCol = $this->courseRegCols['year'] ?? 'Year';
        $semCol = $this->courseRegCols['semester'] ?? 'semester';
        $acYearCol = $this->courseRegCols['academic_year'] ?? null;
        $activeCol = $this->courseRegCols['is_active'] ?? null;
        $statusCol = $this->courseRegCols['status'] ?? null;
        $semRegCol = $this->courseRegCols['semester_registration_id'] ?? null;

        $match = ["`{$yearCol}` = ?"];
        $types = 'ssi';
        $params = [$studentId, $courseCode, $yearOfStudy];
        if (preg_match('/(\d{4})/', $academicYear, $m)) {
            if ($acYearCol) {
                $match[] = "CAST(`{$acYearCol}` AS CHAR) = ?";
                $types .= 's';
                $params[] = $m[1];
            }
        }

        $sets = [];
        // Re-enrolment happens in the *current* period: move the row so
        // period-scoped consumers (CA class lists, per-term fee sums) stay
        // aligned with the student's active term/semester.
        $sets[] = "`{$semCol}` = " . max(1, $periodNumber);
        if ($activeCol) {
            $sets[] = "`{$activeCol}` = 1";
        }
        if ($statusCol) {
            $sets[] = "`{$statusCol}` = 'registered'";
        }
        if ($semRegCol) {
            $sets[] = 'semester_registration_id = ' . (int)$semRegId;
        }
        if ($sets === []) {
            return;
        }
        $sql = 'UPDATE course_registration SET ' . implode(', ', $sets)
            . " WHERE `{$sidCol}` = ? AND course_code = ? AND " . implode(' AND ', $match);
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }
    }

    /** @deprecated period-scoped; kept for legacy callers */
    private function courseRegistrationExists(string $studentId, string $courseCode, int $period, int $yearOfStudy): bool
    {
        $sidCol = $this->courseRegCols['sid'] ?? ($this->courseRegCols['student_id'] ?? 'Sid');
        $semCol = $this->courseRegCols['semester'] ?? 'semester';
        $yearCol = $this->courseRegCols['year'] ?? 'Year';
        $activeCol = $this->courseRegCols['is_active'] ?? null;

        $sql = "SELECT 1 FROM course_registration WHERE `{$sidCol}` = ? AND course_code = ? AND `{$semCol}` = ? AND `{$yearCol}` = ?";
        if ($activeCol) {
            $sql .= " AND (`{$activeCol}` = 1 OR `{$activeCol}` IS NULL)";
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssii', $studentId, $courseCode, $period, $yearOfStudy);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }

    private function reactivateCourseRegistration(string $studentId, string $courseCode, int $period, int $yearOfStudy, int $semRegId): void
    {
        $sidCol = $this->courseRegCols['sid'] ?? ($this->courseRegCols['student_id'] ?? 'Sid');
        $semCol = $this->courseRegCols['semester'] ?? 'semester';
        $yearCol = $this->courseRegCols['year'] ?? 'Year';
        $activeCol = $this->courseRegCols['is_active'] ?? null;
        $statusCol = $this->courseRegCols['status'] ?? null;
        $semRegCol = $this->courseRegCols['semester_registration_id'] ?? null;

        $sets = [];
        if ($activeCol) {
            $sets[] = "`{$activeCol}` = 1";
        }
        if ($statusCol) {
            $sets[] = "`{$statusCol}` = 'registered'";
        }
        if ($semRegCol) {
            $sets[] = 'semester_registration_id = ' . (int)$semRegId;
        }
        if ($sets === []) {
            return;
        }
        $sql = "UPDATE course_registration SET " . implode(', ', $sets)
            . " WHERE `{$sidCol}` = ? AND course_code = ? AND `{$semCol}` = ? AND `{$yearCol}` = ?";
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ssii', $studentId, $courseCode, $period, $yearOfStudy);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Portal setting gate for registration fee enforcement (default: enforced).
     */
    private function isRegistrationFeeGateEnabled(): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM portal_settings WHERE setting_key = 'reg_payment_gate_enabled' LIMIT 1");
            if ($stmt) {
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row !== null) {
                    return trim((string)$row['setting_value']) === '1';
                }
            }
        } catch (Throwable $e) {
            error_log('[StudentAcademicWorkflow] isRegistrationFeeGateEnabled: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * Keep student_program's position columns in step with self-service
     * registration (previously only admin/progression paths updated them,
     * leaving current_term_number stale after Term 2/3 registration).
     */
    private function syncStudentProgramPosition(
        string $studentId,
        string $programCode,
        int $yearOfStudy,
        int $periodNumber,
        string $calendarType,
        string $academicYear
    ): void {
        try {
            $cols = [];
            if ($meta = $this->db->query("SHOW COLUMNS FROM student_program")) {
                while ($c = $meta->fetch_assoc()) {
                    $cols[strtolower((string)$c['Field'])] = (string)$c['Field'];
                }
                $meta->free();
            }
            if ($cols === [] || !isset($cols['sid'])) {
                throw new RuntimeException('Student programme position columns are unavailable.');
            }

            $sets = [];
            $types = '';
            $params = [];
            $addInt = static function (string $col, int $value) use (&$sets, &$types, &$params, $cols): void {
                $key = strtolower($col);
                if (isset($cols[$key])) {
                    $sets[] = '`' . $cols[$key] . '` = ?';
                    $types .= 'i';
                    $params[] = $value;
                }
            };

            $addInt('year_of_study', $yearOfStudy);
            $addInt('current_year_number', $yearOfStudy);
            if (strtolower($calendarType) === 'term') {
                $addInt('current_term_number', $periodNumber);
                if (isset($cols['current_semester_number'])) {
                    $sets[] = '`current_semester_number` = NULL';
                }
                // Legacy mirror observed on live rows: term and semester both
                // carry the current period number for term-based programmes.
                if (isset($cols['term'])) {
                    $sets[] = '`' . $cols['term'] . '` = ?';
                    $types .= 's';
                    $params[] = (string)$periodNumber;
                }
                $addInt('semester', $periodNumber);
            } elseif (strtolower($calendarType) === 'semester') {
                $addInt('current_semester_number', $periodNumber);
                $addInt('semester', $periodNumber);
                if (isset($cols['current_term_number'])) {
                    $sets[] = '`current_term_number` = NULL';
                }
            }
            if (isset($cols['academic_year'])) {
                $sets[] = '`academic_year` = ?';
                $types .= 's';
                $params[] = $academicYear;
            }
            if (isset($cols['updated_at'])) {
                $sets[] = '`updated_at` = CURRENT_TIMESTAMP';
            }
            if ($sets === []) {
                return;
            }

            $sql = 'UPDATE student_program SET ' . implode(', ', $sets)
                . ' WHERE `' . $cols['sid'] . '` = ?';
            $types .= 's';
            $params[] = $studentId;
            if (isset($cols['program_code']) && $programCode !== '') {
                $sql .= ' AND `' . $cols['program_code'] . '` = ?';
                $types .= 's';
                $params[] = $programCode;
            }
            if (isset($cols['status'])) {
                $sql .= ' AND (`' . $cols['status'] . "` IS NULL OR `" . $cols['status'] . "` = '' OR LOWER(`" . $cols['status'] . "`) = 'active')";
            }
            if (isset($cols['id'])) {
                $sql .= ' ORDER BY `' . $cols['id'] . '` DESC LIMIT 1';
            }

            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Could not update the student programme position.');
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[StudentAcademicWorkflow] syncStudentProgramPosition: ' . $e->getMessage());
            throw $e;
        }
    }

    private function updateRegistrationWorkflowColumns(int $semRegId, array $period, string $feeStatus): void
    {
        $sets = [];
        $types = '';
        $params = [];

        if (isset($this->semRegCols['academic_period_id']) && !empty($period['academic_period_id'])) {
            $sets[] = '`' . $this->semRegCols['academic_period_id'] . '` = ?';
            $types .= 'i';
            $params[] = (int)$period['academic_period_id'];
        }
        if (isset($this->semRegCols['registration_status'])) {
            $sets[] = '`' . $this->semRegCols['registration_status'] . "` = 'registered'";
        }
        if (isset($this->semRegCols['fee_status'])) {
            $sets[] = '`' . $this->semRegCols['fee_status'] . '` = ?';
            $types .= 's';
            $params[] = $feeStatus;
        }
        if ($sets === []) {
            return;
        }

        $idCol = $this->semRegCols['id'] ?? 'id';
        $sql = 'UPDATE semester_registration SET ' . implode(', ', $sets) . " WHERE `{$idCol}` = ?";
        $types .= 'i';
        $params[] = $semRegId;
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }
    }

    /** @param array<string,mixed> $period */
    private function findSemesterRegistration(string $studentId, array $period): ?array
    {
        $academicYear = (string)($period['academic_year'] ?? '');
        $periodNumber = (int)($period['period_number'] ?? 0);
        $calendarType = (string)($period['calendar_type'] ?? 'semester');
        $periodType = wuc_legacy_period_type($calendarType);
        $periodId = (int)($period['academic_period_id'] ?? 0);

        if ($periodId > 0 && isset($this->semRegCols['academic_period_id'])) {
            $sidCols = $this->studentIdColumns();
            foreach ($sidCols as $sidCol) {
                $idCol = $this->semRegCols['id'] ?? 'id';
                $apCol = $this->semRegCols['academic_period_id'];
                $sql = "SELECT * FROM semester_registration WHERE `{$sidCol}` = ? AND `{$apCol}` = ? LIMIT 1";
                $stmt = $this->db->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param('si', $studentId, $periodId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row) {
                        return $row;
                    }
                }
            }
        }

        $ctx = $this->regData->resolveRegistrationTermContext(
            $studentId,
            null,
            $academicYear,
            $periodNumber,
            $periodType,
            false
        );
        if (!$ctx) {
            return null;
        }

        $idCol = $this->semRegCols['id'] ?? 'id';
        $stmt = $this->db->prepare("SELECT * FROM semester_registration WHERE `{$idCol}` = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $regId = (int)$ctx['id'];
        $stmt->bind_param('i', $regId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function isStudentBlocked(string $studentId): bool
    {
        if (!fg_table_exists($this->db, 'students')) {
            return false;
        }
        $cols = [];
        if ($meta = $this->db->query('SHOW COLUMNS FROM students')) {
            while ($c = $meta->fetch_assoc()) {
                $cols[strtolower((string)$c['Field'])] = (string)$c['Field'];
            }
            $meta->free();
        }
        $statusCol = $cols['status'] ?? ($cols['account_status'] ?? null);
        if ($statusCol === null) {
            return false;
        }
        $sql = "SELECT `{$statusCol}` AS st FROM students WHERE SID = ? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $st = strtolower(trim((string)($row['st'] ?? '')));
        return in_array($st, ['blocked', 'inactive', 'suspended', 'disabled'], true);
    }

    /** @return list<string> */
    private function studentIdColumns(): array
    {
        $cols = [];
        foreach (['student_id', 'sid', 'Sid', 'SID'] as $c) {
            if (isset($this->semRegCols[strtolower($c)])) {
                $cols[] = $this->semRegCols[strtolower($c)];
            }
        }
        return $cols;
    }

    private function discoverColumns(): void
    {
        if ($meta = $this->db->query('SHOW COLUMNS FROM semester_registration')) {
            while ($c = $meta->fetch_assoc()) {
                $this->semRegCols[strtolower((string)$c['Field'])] = (string)$c['Field'];
            }
            $meta->free();
        }
        if ($meta = $this->db->query('SHOW COLUMNS FROM course_registration')) {
            while ($c = $meta->fetch_assoc()) {
                $this->courseRegCols[strtolower((string)$c['Field'])] = (string)$c['Field'];
            }
            $meta->free();
        }
    }
}

// Procedural wrappers for backward compatibility.
if (!function_exists('getActiveAcademicPeriod')) {
    function getActiveAcademicPeriod(mysqli $db, string $studentId): array
    {
        return (new StudentAcademicWorkflowService($db))->getActiveAcademicPeriod($studentId);
    }
}

if (!function_exists('checkFeeEligibility')) {
    function checkFeeEligibility(mysqli $db, string $studentId, ?int $academicYearId = null, ?int $academicPeriodId = null): array
    {
        $svc = new StudentAcademicWorkflowService($db);
        $period = $svc->getActiveAcademicPeriod($studentId);
        if (!$period['ok']) {
            return [
                'total_fee' => 0.0,
                'amount_paid' => 0.0,
                'balance' => 0.0,
                'payment_percentage' => 0.0,
                'required_percentage' => 50.0,
                'is_eligible' => false,
                'reason' => (string)($period['error'] ?? ''),
                'is_sponsored' => false,
                'fee_status' => 'unknown',
            ];
        }
        return $svc->checkFeeEligibility($studentId, $period);
    }
}

if (!function_exists('checkStudentRegistration')) {
    function checkStudentRegistration(mysqli $db, string $studentId, ?int $academicYearId = null, ?int $academicPeriodId = null): array
    {
        return (new StudentAcademicWorkflowService($db))->checkStudentRegistration($studentId);
    }
}
