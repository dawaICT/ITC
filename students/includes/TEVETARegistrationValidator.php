<?php
/**
 * TEVETA Registration Validator
 * 
 * Enforces TEVETA-compliant validation and correction on student registrations.
 * Uses database-driven configuration with JSON fallback files for flexible rules.
 */

declare(strict_types=1);

class TEVETARegistrationValidator
{
    private mysqli $db;
    private array $options;
    private array $errors = [];
    private array $warnings = [];
    private array $correctionsApplied = [];
    private array $suggestedCorrections = [];
    private bool $requiresManualReview = false;
    private array $rules = [];

    public function __construct(mysqli $db, array $options = [])
    {
        $this->db = $db;
        $this->options = array_merge([
            'debug' => false,
            'auto_apply_safe_corrections' => true,
            'rules_json_path' => __DIR__ . '/teveta_rules.json'
        ], $options);

        $this->loadRulesConfiguration();
    }

    /**
     * Load TEVETA compliance rules from DB or fallback JSON
     */
    private function loadRulesConfiguration(): void
    {
        // 1. Try JSON configuration file as fallback or primary
        $jsonPath = $this->options['rules_json_path'];
        if (file_exists($jsonPath)) {
            $content = file_get_contents($jsonPath);
            if ($content) {
                $this->rules = json_decode($content, true) ?: [];
            }
        }
        
        // 2. Try loading any runtime overrides from database if tables exist
        $this->loadRulesFromDatabase();
    }

    /**
     * Read rules from DB if tables exist
     */
    private function loadRulesFromDatabase(): void
    {
        // Check if enrolment_rules exists
        if ($this->tableExists('enrolment_rules')) {
            $result = $this->db->query("SELECT rule_name, rule_value FROM enrolment_rules");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $this->rules['enrolment_rules'][$row['rule_name']] = $row['rule_value'];
                }
                $result->free();
            }
        }

        // Check if programme_duration_rules exists
        if ($this->tableExists('programme_duration_rules')) {
            $result = $this->db->query("SELECT * FROM programme_duration_rules");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $pId = $row['programme_id'];
                    $this->rules['programme_duration_rules'][$pId] = [
                        'min_weeks' => (int)$row['min_weeks'],
                        'max_weeks' => (int)$row['max_weeks'],
                        'expected_weeks' => (int)$row['expected_weeks'],
                        'allow_custom_duration' => (int)$row['allow_custom_duration']
                    ];
                }
                $result->free();
            }
        }

        // Check if registration_windows exists
        if ($this->tableExists('registration_windows')) {
            $result = $this->db->query("SELECT * FROM registration_windows");
            if ($result) {
                $this->rules['registration_windows'] = [];
                while ($row = $result->fetch_assoc()) {
                    $this->rules['registration_windows'][] = [
                        'programme_type' => $row['programme_type'],
                        'academic_period_id' => $row['academic_period_id'] ? (int)$row['academic_period_id'] : null,
                        'official_open_date' => $row['official_open_date'],
                        'official_close_date' => $row['official_close_date'],
                        'pre_registration_days' => (int)$row['pre_registration_days'],
                        'late_registration_days' => (int)$row['late_registration_days']
                    ];
                }
                $result->free();
            }
        }
    }

    /**
     * Centralized validator entrypoint
     */
    public function validateRegistration(array $payload): array
    {
        $this->errors = [];
        $this->warnings = [];
        $this->correctionsApplied = [];
        $this->suggestedCorrections = [];
        $this->requiresManualReview = false;

        $normalized = $payload;

        // 1. Required Field Validation
        $required = ['student_id', 'programme_id', 'registration_date', 'start_date', 'end_date'];
        foreach ($required as $field) {
            if (empty($payload[$field])) {
                $this->errors[] = "Missing required field: {$field}";
            }
        }

        // Must provide academic year/session and term/semester
        if (empty($payload['academic_year']) && empty($payload['academic_session_id'])) {
            $this->errors[] = "Missing required field: academic_year or academic_session_id";
        }

        $periodProvided = !empty($payload['academic_period_id']) || 
                          !empty($payload['term']) || 
                          !empty($payload['semester']) || 
                          !empty($payload['month']) || 
                          !empty($payload['quarter']) || 
                          !empty($payload['custom_period']);

        if (!$periodProvided) {
            $this->errors[] = "Missing required period indicator (academic_period_id, term, semester, month, quarter, or custom_period)";
        }

        if (!empty($this->errors)) {
            return $this->buildResponse($normalized);
        }

        // Normalize Dates
        $normalized['registration_date'] = $this->normalizeDate($payload['registration_date']);
        $normalized['start_date'] = $this->normalizeDate($payload['start_date']);
        $normalized['end_date'] = $this->normalizeDate($payload['end_date']);

        if ($payload['registration_date'] !== $normalized['registration_date']) {
            $this->correctionsApplied['registration_date'] = "Normalized format to YYYY-MM-DD";
        }
        if ($payload['start_date'] !== $normalized['start_date']) {
            $this->correctionsApplied['start_date'] = "Normalized format to YYYY-MM-DD";
        }
        if ($payload['end_date'] !== $normalized['end_date']) {
            $this->correctionsApplied['end_date'] = "Normalized format to YYYY-MM-DD";
        }

        // 2. Programme Lookup
        $programme = $this->loadProgramme((string)$payload['programme_id']);
        if (!$programme) {
            $this->errors[] = "Invalid or inactive program code: " . htmlspecialchars((string)$payload['programme_id']);
            $this->requiresManualReview = true;
            return $this->buildResponse($normalized);
        }

        // 3. Academic Session Validation
        $session = $this->resolveAcademicSession($payload);
        if ($session['error']) {
            $this->errors[] = $session['error'];
            if ($session['ambiguous']) {
                $this->requiresManualReview = true;
            }
        } else {
            $normalized['academic_session_id'] = $session['session_id'];
            $normalized['academic_year'] = $session['academic_year'];
            if ($session['corrected']) {
                $this->correctionsApplied['academic_year'] = "Standardized to format: " . $session['academic_year'];
            }
        }

        // 4. Academic Period Validation
        $period = $this->resolveAcademicPeriod($payload, $programme, $session);
        if ($period['error']) {
            $this->errors[] = $period['error'];
            if ($period['requires_review']) {
                $this->requiresManualReview = true;
            }
        } else {
            $normalized['academic_period_id'] = $period['period_id'];
            $normalized['semester'] = $period['normalized_value'];
            if ($period['corrected']) {
                $this->correctionsApplied['semester'] = "Normalized input '{$period['input']}' to '{$period['normalized_value']}'";
            }
            if ($period['warning']) {
                $this->warnings[] = $period['warning'];
                $this->suggestedCorrections['semester'] = $period['suggested_value'];
                $this->requiresManualReview = true;
            }
        }

        // 5. Registration Window Check
        $regDate = new DateTimeImmutable($normalized['registration_date']);
        $window = $this->assessRegistrationWindow($programme, $period, $regDate, $session);
        $normalized['registration_type'] = $window['registration_type'];
        
        if ($window['registration_type'] === 'Closed') {
            $this->errors[] = "Registration window is closed: " . $window['notice'];
            $this->requiresManualReview = true;
        } elseif ($window['registration_type'] === 'Late') {
            $this->warnings[] = "Registration is LATE: " . $window['notice'];
        } elseif ($window['registration_type'] === 'Early') {
            $this->warnings[] = "Registration is early (pre-registration): " . $window['notice'];
        }

        // 6. Duration Alignment
        $startDate = new DateTimeImmutable($normalized['start_date']);
        $endDate = new DateTimeImmutable($normalized['end_date']);
        $duration = $this->validateDuration($programme, $startDate, $endDate);
        
        if ($duration['error']) {
            $this->errors[] = $duration['error'];
            if ($duration['suggested_end_date']) {
                $this->suggestedCorrections['end_date'] = $duration['suggested_end_date'];
                if ($this->options['auto_apply_safe_corrections'] && $duration['allow_auto_correct']) {
                    $normalized['end_date'] = $duration['suggested_end_date'];
                    $this->correctionsApplied['end_date'] = "Auto-adjusted end date based on expected course duration";
                }
            }
        }

        // 7. Duplicate Check
        $duplicate = $this->checkDuplicateRegistration($normalized);
        if ($duplicate['is_duplicate']) {
            $this->errors[] = "Duplicate registration detected for student " . htmlspecialchars($normalized['student_id']);
            $this->requiresManualReview = true;
        }

        // 8. Overlapping Enrollments
        $overlap = $this->checkOverlappingEnrollment($normalized);
        if ($overlap['overlap']) {
            if ($overlap['rule'] === 'block') {
                $this->errors[] = "Blocked: Overlapping active registration found. Concurrent enrollment not permitted.";
                $this->requiresManualReview = true;
            } else {
                $this->warnings[] = "Warning: Overlapping active registration found.";
            }
        }

        return $this->buildResponse($normalized);
    }

    /**
     * Retrieve program record
     */
    public function loadProgramme(string $programmeId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM programs WHERE program_code = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $programmeId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && isset($row['is_active']) && $row['is_active']) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Match academic year or session
     */
    public function resolveAcademicSession(array $payload): array
    {
        $response = ['session_id' => null, 'academic_year' => null, 'corrected' => false, 'error' => null, 'ambiguous' => false];

        if (!empty($payload['academic_session_id'])) {
            $stmt = $this->db->prepare("SELECT * FROM academic_sessions WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $payload['academic_session_id']);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    if (!$row['is_active']) {
                        $response['error'] = "Academic session ID {$payload['academic_session_id']} is inactive.";
                    } else {
                        $response = array_merge($row, $response);
                        $response['session_id'] = (int)$row['id'];
                        $dbSessionName = $row['session_name'];
                        if (is_numeric($dbSessionName) && strlen($dbSessionName) < 4 && !empty($payload['academic_year'])) {
                            $response['academic_year'] = $payload['academic_year'];
                        } else {
                            $response['academic_year'] = $dbSessionName;
                        }
                    }
                    return $response;
                }
            }
            $response['error'] = "Academic session ID {$payload['academic_session_id']} not found.";
            return $response;
        }

        $rawYear = trim((string)$payload['academic_year']);
        $normalizedYear = $this->normalizeAcademicYear($rawYear);

        // Find match in DB by normalized year
        $rows = [];
        $stmt = $this->db->prepare("SELECT * FROM academic_sessions WHERE session_name = ? AND is_active = 1");
        if ($stmt) {
            $stmt->bind_param("s", $normalizedYear);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // Try raw year if not matched
        if (empty($rows) && $rawYear !== $normalizedYear) {
            $stmt = $this->db->prepare("SELECT * FROM academic_sessions WHERE session_name = ? AND is_active = 1");
            if ($stmt) {
                $stmt->bind_param("s", $rawYear);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
        }

        // Try date range check matching registration_date
        if (empty($rows)) {
            $regDate = $payload['registration_date'] ?? date('Y-m-d');
            $stmt = $this->db->prepare("SELECT * FROM academic_sessions WHERE is_active = 1 AND ? BETWEEN start_date AND end_date");
            if ($stmt) {
                $stmt->bind_param("s", $regDate);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
        }

        // Try fallback to any active session ordered by start_date DESC
        if (empty($rows)) {
            $stmt = $this->db->prepare("SELECT * FROM academic_sessions WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1");
            if ($stmt) {
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
        }

        if (count($rows) === 1) {
            $response = array_merge($rows[0], $response);
            $response['session_id'] = (int)$rows[0]['id'];
            $dbSessionName = $rows[0]['session_name'];
            if (is_numeric($dbSessionName) && strlen($dbSessionName) < 4) {
                $response['academic_year'] = $rawYear;
            } else {
                $response['academic_year'] = $dbSessionName;
            }
            if ($response['academic_year'] !== $rawYear) {
                $response['corrected'] = true;
            }
            return $response;
        }

        if (count($rows) > 1) {
            $response['error'] = "Ambiguous academic year: multiple active sessions match '{$normalizedYear}'";
            $response['ambiguous'] = true;
            return $response;
        }

        $response['error'] = "No active academic session found matching '{$rawYear}'";
        return $response;
    }

    /**
     * Resolve and validate academic period
     */
    public function resolveAcademicPeriod(array $payload, array $programme, array $session): array
    {
        $res = [
            'period_id' => null, 
            'normalized_value' => null, 
            'corrected' => false, 
            'error' => null, 
            'requires_review' => false,
            'warning' => null,
            'suggested_value' => null,
            'input' => ''
        ];

        $model = strtolower($programme['period_mode'] ?? 'semester');
        
        $inputVal = '';
        if ($model === 'term') {
            $inputVal = (string)($payload['term'] ?? $payload['academic_period_id'] ?? $payload['semester'] ?? '');
        } elseif ($model === 'semester') {
            $inputVal = (string)($payload['semester'] ?? $payload['academic_period_id'] ?? $payload['term'] ?? '');
        } else {
            $inputVal = (string)($payload['custom_period'] ?? $payload['month'] ?? $payload['quarter'] ?? $payload['semester'] ?? '1');
        }
        $res['input'] = $inputVal;

        $normalized = $this->normalizePeriodInput($inputVal);
        $res['normalized_value'] = $normalized;

        if ($normalized !== $inputVal) {
            $res['corrected'] = true;
        }

        // Validate structure alignment
        if ($model === 'term' && !in_array($normalized, ['1', '2', '3'], true)) {
            $res['error'] = "Term-based program requires a valid term period (1, 2, or 3).";
            return $res;
        }
        if ($model === 'semester' && !in_array($normalized, ['1', '2'], true)) {
            $res['error'] = "Semester-based program requires a valid semester period (1 or 2).";
            return $res;
        }

        // DB check for periods if table academic_periods contains rows
        if ($this->tableExists('academic_periods')) {
            $ay = $session['academic_year'];
            $stmt = $this->db->prepare("SELECT * FROM academic_periods WHERE academic_year = ? AND period_type = ? AND semester_term = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("sss", $ay, $model, $normalized);
                $stmt->execute();
                $periodRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($periodRow) {
                    $res['period_id'] = (int)$periodRow['id'];
                    
                    // Suggestion warning check: if student attempts to register for a different period than current
                    if (!$periodRow['is_current'] && $periodRow['status'] !== 'active') {
                        // Find current active period to suggest
                        $currStmt = $this->db->prepare("SELECT semester_term FROM academic_periods WHERE academic_year = ? AND period_type = ? AND is_current = 1 LIMIT 1");
                        if ($currStmt) {
                            $currStmt->bind_param("ss", $ay, $model);
                            $currStmt->execute();
                            $currRow = $currStmt->get_result()->fetch_assoc();
                            $currStmt->close();
                            if ($currRow) {
                                $res['warning'] = "Warning: Registration period selected is not the active period.";
                                $res['suggested_value'] = $currRow['semester_term'];
                                $res['requires_review'] = true;
                            }
                        }
                    }
                }
            }
        }

        return $res;
    }

    /**
     * Check if registration is inside the window
     */
    public function assessRegistrationWindow(array $programme, array $period, DateTimeImmutable $registrationDate, ?array $session = null): array
    {
        $response = ['registration_type' => 'Normal', 'notice' => ''];
        
        $pType = $programme['program_type'] ?? 'Certificate';
        $openDate = null;
        $closeDate = null;
        $preDays = 0;
        $lateDays = 0;

        // 1. Try session-specific registration dates if available
        if ($session && empty($session['error']) && !empty($session['registration_start_date']) && !empty($session['registration_end_date'])) {
            $openDate = new DateTimeImmutable($session['registration_start_date']);
            $closeDate = new DateTimeImmutable($session['registration_end_date']);
            
            $ruleKey = strtolower($pType);
            if (strpos($ruleKey, 'diploma') !== false) {
                $preDays = (int)($this->rules['enrolment_rules']['default_pre_reg_days_diploma'] ?? 28);
                $lateDays = (int)($this->rules['enrolment_rules']['default_late_reg_days_diploma'] ?? 28);
            } elseif (strpos($ruleKey, 'short') !== false) {
                $preDays = (int)($this->rules['enrolment_rules']['default_pre_reg_days_short_course'] ?? 7);
                $lateDays = (int)($this->rules['enrolment_rules']['default_late_reg_days_short_course'] ?? 14);
            } elseif (strpos($ruleKey, 'trade') !== false) {
                $preDays = (int)($this->rules['enrolment_rules']['default_pre_reg_days_trade_test'] ?? 14);
                $lateDays = (int)($this->rules['enrolment_rules']['default_late_reg_days_trade_test'] ?? 21);
            } else {
                $preDays = (int)($this->rules['enrolment_rules']['default_pre_reg_days_certificate'] ?? 21);
                $lateDays = (int)($this->rules['enrolment_rules']['default_late_reg_days_certificate'] ?? 28);
            }
        } else {
            // 2. Try DB lookup
            $dbFound = false;
            if ($this->tableExists('registration_windows')) {
                $pId = $period['period_id'];
                if ($pId !== null) {
                    $stmt = $this->db->prepare("SELECT * FROM registration_windows WHERE academic_period_id = ? LIMIT 1");
                    $stmt->bind_param("i", $pId);
                } else {
                    $stmt = $this->db->prepare("SELECT * FROM registration_windows WHERE programme_type = ? LIMIT 1");
                    $stmt->bind_param("s", $pType);
                }
                if ($stmt) {
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row) {
                        $openDate = new DateTimeImmutable($row['official_open_date']);
                        $closeDate = new DateTimeImmutable($row['official_close_date']);
                        $preDays = (int)$row['pre_registration_days'];
                        $lateDays = (int)$row['late_registration_days'];
                        $dbFound = true;
                    }
                }
            }

            // 3. JSON Fallback
            if (!$dbFound && isset($this->rules['registration_windows'])) {
                foreach ($this->rules['registration_windows'] as $win) {
                    if (strcasecmp($win['programme_type'], $pType) === 0) {
                        $openDate = new DateTimeImmutable($win['official_open_date']);
                        $closeDate = new DateTimeImmutable($win['official_close_date']);
                        $preDays = (int)$win['pre_registration_days'];
                        $lateDays = (int)$win['late_registration_days'];
                        break;
                    }
                }
            }

            // 4. Fallback to hardcoded defaults based on program type
            if (!$openDate || !$closeDate) {
                $openDate = new DateTimeImmutable(date('Y-m-d'));
                $closeDate = $openDate->modify('+45 days');
                
                $ruleKey = strtolower($pType);
                if (strpos($ruleKey, 'diploma') !== false) {
                    $preDays = (int)($this->rules['enrolment_rules']['default_pre_reg_days_diploma'] ?? 28);
                    $lateDays = (int)($this->rules['enrolment_rules']['default_late_reg_days_diploma'] ?? 28);
                } elseif (strpos($ruleKey, 'short') !== false) {
                    $preDays = (int)($this->rules['enrolment_rules']['default_pre_reg_days_short_course'] ?? 7);
                    $lateDays = (int)($this->rules['enrolment_rules']['default_late_reg_days_short_course'] ?? 14);
                } elseif (strpos($ruleKey, 'trade') !== false) {
                    $preDays = (int)($this->rules['enrolment_rules']['default_pre_reg_days_trade_test'] ?? 14);
                    $lateDays = (int)($this->rules['enrolment_rules']['default_late_reg_days_trade_test'] ?? 21);
                } else {
                    // Default Certificate
                    $preDays = (int)($this->rules['enrolment_rules']['default_pre_reg_days_certificate'] ?? 21);
                    $lateDays = (int)($this->rules['enrolment_rules']['default_late_reg_days_certificate'] ?? 28);
                }
            }

            // Dynamically adjust window bounds to the active session's year if needed
            if ($session && empty($session['error']) && !empty($session['start_date'])) {
                $sessionStartYear = (int)(new DateTimeImmutable($session['start_date']))->format('Y');
                if ($openDate) {
                    $openYear = (int)$openDate->format('Y');
                    if ($openYear !== $sessionStartYear) {
                        $diffYears = $sessionStartYear - $openYear;
                        $openDate = $openDate->modify("{$diffYears} years");
                    }
                }
                if ($closeDate) {
                    $closeYear = (int)$closeDate->format('Y');
                    if ($closeYear !== $sessionStartYear) {
                        $diffYears = $sessionStartYear - $closeYear;
                        $closeDate = $closeDate->modify("{$diffYears} years");
                    }
                }
            }
        }

        $preOpenLimit = $openDate->modify("-{$preDays} days");
        $lateCloseLimit = $closeDate->modify("+{$lateDays} days");

        if ($registrationDate < $preOpenLimit) {
            $response['registration_type'] = 'Closed';
            $response['notice'] = "Registration opens on " . $openDate->format('Y-m-d') . ". Earliest pre-registration starts " . $preOpenLimit->format('Y-m-d') . ".";
        } elseif ($registrationDate < $openDate) {
            $response['registration_type'] = 'Early';
            $response['notice'] = "Pre-registration phase (Official open date: " . $openDate->format('Y-m-d') . ").";
        } elseif ($registrationDate <= $closeDate) {
            $response['registration_type'] = 'Normal';
        } elseif ($registrationDate <= $lateCloseLimit) {
            $response['registration_type'] = 'Late';
            $response['notice'] = "Official close date " . $closeDate->format('Y-m-d') . " has passed. Grace period ends " . $lateCloseLimit->format('Y-m-d') . ".";
        } else {
            // It is past lateCloseLimit
            // Allow late registration as long as registration date falls before session overall end date
            if ($session && empty($session['error']) && !empty($session['end_date'])) {
                $sessionEndDate = new DateTimeImmutable($session['end_date']);
                if ($registrationDate <= $sessionEndDate) {
                    $response['registration_type'] = 'Late';
                    $response['notice'] = "Official close date " . $closeDate->format('Y-m-d') . " has passed. Grace period ended on " . $lateCloseLimit->format('Y-m-d') . ". Registration allowed before academic session ends on " . $sessionEndDate->format('Y-m-d') . ".";
                    return $response;
                }
            }
            $response['registration_type'] = 'Closed';
            $response['notice'] = "Late registration grace period ended on " . $lateCloseLimit->format('Y-m-d') . ".";
        }

        return $response;
    }

    /**
     * Validate course start and end duration alignment
     */
    public function validateDuration(array $programme, DateTimeImmutable $startDate, DateTimeImmutable $endDate): array
    {
        $res = ['error' => null, 'suggested_end_date' => null, 'allow_auto_correct' => false];

        if ($startDate >= $endDate) {
            $res['error'] = "Start date must be strictly before the end date.";
            return $res;
        }

        $pCode = $programme['program_code'];
        $rule = $this->rules['programme_duration_rules'][$pCode] ?? null;

        if (!$rule) {
            // Estimate based on program duration in years
            $years = (float)($programme['program_duration'] ?? 1);
            $expectedWeeks = (int)round($years * 52);
            $rule = [
                'min_weeks' => (int)max(1, $expectedWeeks - 12),
                'max_weeks' => (int)($expectedWeeks + 12),
                'expected_weeks' => $expectedWeeks,
                'allow_custom_duration' => 0
            ];
        }

        $diff = $startDate->diff($endDate);
        $weeks = (int)ceil($diff->days / 7);

        if (!$rule['allow_custom_duration']) {
            // Determine scale factor based on what the registration dates represent
            $programYears = (float)($programme['program_duration'] ?? 1.0);
            if ($programYears <= 0) {
                $programYears = 1.0;
            }
            
            $periodMode = strtolower($programme['period_mode'] ?? 'semester');
            $periodsPerYear = ($periodMode === 'term') ? 3.0 : (($periodMode === 'semester') ? 2.0 : 1.0);

            $fullExpected = (float)$rule['expected_weeks'];
            $yearExpected = $fullExpected / $programYears;
            $periodExpected = $yearExpected / $periodsPerYear;

            $diffFull = abs($weeks - $fullExpected);
            $diffYear = abs($weeks - $yearExpected);
            $diffPeriod = abs($weeks - $periodExpected);

            $closest = min($diffFull, $diffYear, $diffPeriod);

            $scale = 1.0;
            $periodLabel = 'program';
            
            if ($closest === $diffYear) {
                $scale = 1.0 / $programYears;
                $periodLabel = 'academic year';
            } elseif ($closest === $diffPeriod) {
                $scale = (1.0 / $periodsPerYear) / $programYears;
                $periodLabel = ($periodMode === 'term') ? 'term' : (($periodMode === 'semester') ? 'semester' : 'period');
            }

            $scaledMin = (int)max(1, round($rule['min_weeks'] * $scale));
            $scaledMax = (int)round($rule['max_weeks'] * $scale);
            $scaledExpected = (int)round($rule['expected_weeks'] * $scale);

            if ($weeks < $scaledMin) {
                $res['error'] = "Course duration ({$weeks} weeks) is shorter than minimum required ({$scaledMin} weeks) for {$programme['program_name']} ({$periodLabel}).";
                $res['suggested_end_date'] = $startDate->modify("+{$scaledExpected} weeks")->format('Y-m-d');
                $res['allow_auto_correct'] = true;
            } elseif ($weeks > $scaledMax) {
                $res['error'] = "Course duration ({$weeks} weeks) exceeds maximum allowed ({$scaledMax} weeks) for {$programme['program_name']} ({$periodLabel}).";
                $res['suggested_end_date'] = $startDate->modify("+{$scaledExpected} weeks")->format('Y-m-d');
                $res['allow_auto_correct'] = true;
            }
        }

        return $res;
    }

    /**
     * Check duplicates in database
     */
    public function checkDuplicateRegistration(array $payload): array
    {
        $res = ['is_duplicate' => false];
        
        // We probe semester_registration
        $sql = "SELECT id FROM semester_registration 
                WHERE (student_id = ? OR SID = ?) AND program_code = ? AND academic_year = ? AND semester = ? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sssss", $payload['student_id'], $payload['student_id'], $payload['programme_id'], $payload['academic_year'], $payload['semester']);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $res['is_duplicate'] = true;
            }
            $stmt->close();
        }

        return $res;
    }

    /**
     * Check overlapping active registrations
     */
    public function checkOverlappingEnrollment(array $payload): array
    {
        $res = ['overlap' => false, 'rule' => 'warn'];
        
        $rule = $this->rules['enrolment_rules']['allow_concurrent_enrollment'] ?? 'warn';
        $res['rule'] = $rule;

        // Check for overlaps in semester_registration
        $sql = "SELECT sr.id, p.program_name FROM semester_registration sr
                INNER JOIN programs p ON sr.program_code = p.program_code
                WHERE (sr.student_id = ? OR sr.SID = ?) AND sr.program_code != ? AND sr.academic_year = ? LIMIT 5";
        
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ssss", $payload['student_id'], $payload['student_id'], $payload['programme_id'], $payload['academic_year']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $res['overlap'] = true;
            }
            $stmt->close();
        }

        return $res;
    }

    public function normalizeAcademicYear(string $academicYear): string
    {
        $academicYear = trim($academicYear);
        $academicYear = preg_replace('/[\s\-]+/', '/', $academicYear);
        
        if (preg_match('/^\d{4}$/', $academicYear)) {
            $year = (int)$academicYear;
            $nextYear = ($year + 1) % 100;
            $nextYearStr = str_pad((string)$nextYear, 2, '0', STR_PAD_LEFT);
            return $year . '/' . $nextYearStr;
        }
        
        return $academicYear;
    }

    public function normalizePeriodInput(string $periodInput): string
    {
        $input = strtolower(trim($periodInput));
        if (in_array($input, ['1', 't1', 's1', 'term 1', 'term one', 'semester 1', 'semester one', 'first term', 'first semester'], true)) {
            return '1';
        }
        if (in_array($input, ['2', 't2', 's2', 'term 2', 'term two', 'semester 2', 'semester two', 'second term', 'second semester'], true)) {
            return '2';
        }
        if (in_array($input, ['3', 't3', 's3', 'term 3', 'term three', 'semester 3', 'semester three', 'third term', 'third semester'], true)) {
            return '3';
        }
        if (in_array($input, ['4', 's4', 'semester 4', 'semester four', 'fourth semester'], true)) {
            return '4';
        }
        return $periodInput;
    }

    private function normalizeDate(string $dateStr): string
    {
        $ts = strtotime($dateStr);
        return $ts ? date('Y-m-d', $ts) : $dateStr;
    }

    private function tableExists(string $table): bool
    {
        $safe = $this->db->real_escape_string($table);
        $result = @$this->db->query("SHOW TABLES LIKE '{$safe}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $exists;
    }

    private function buildResponse(array $payload): array
    {
        $isValid = empty($this->errors);
        
        $res = [
            'is_valid' => $isValid,
            'requires_manual_review' => $this->requiresManualReview,
            'registration_type' => $payload['registration_type'] ?? 'Normal',
            'normalized_payload' => $payload,
            'corrections_applied' => $this->correctionsApplied,
            'suggested_corrections' => $this->suggestedCorrections,
            'warnings' => $this->warnings,
            'errors' => $this->errors
        ];

        if ($this->options['debug']) {
            $res['debug'] = [
                'rules' => $this->rules,
                'options' => $this->options
            ];
        }

        return $res;
    }
}

// ===== USAGE EXAMPLE =====
if (defined('RUN_TEVETA_EXAMPLE') && RUN_TEVETA_EXAMPLE === true) {
    // Database connection simulation
    $db = new mysqli("localhost", "wucportal_app", "password", "wucportal");

    // Receive and Sanitize Payload
    $payload = [
        'student_id'        => filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'CSE26456789',
        'programme_id'       => filter_input(INPUT_POST, 'programme_id', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'CSE',
        'registration_date'  => date('Y-m-d'),
        'academic_year'      => filter_input(INPUT_POST, 'academic_year', FILTER_SANITIZE_SPECIAL_CHARS) ?: '2025/26',
        'semester'          => filter_input(INPUT_POST, 'semester', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'Semester 1',
        'start_date'        => filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_SPECIAL_CHARS) ?: '2025-09-01',
        'end_date'          => filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_SPECIAL_CHARS) ?: '2026-06-30'
    ];

    // Validator instantiation
    $validator = new TEVETARegistrationValidator($db, ['debug' => true]);
    $result = $validator->validateRegistration($payload);

    if ($result['is_valid'] && !$result['requires_manual_review']) {
        // Proceed with insertion inside a transaction
        $db->begin_transaction();
        try {
            $data = $result['normalized_payload'];
            
            // Query details
            $stmt = $db->prepare("INSERT INTO semester_registration (student_id, program_code, semester, academic_year, registration_date, period_type) VALUES (?, ?, ?, ?, ?, ?)");
            $pType = 'semester'; // Resolvable
            $stmt->bind_param("ssssss", $data['student_id'], $data['programme_id'], $data['semester'], $data['academic_year'], $data['registration_date'], $pType);
            $stmt->execute();
            $stmt->close();
            
            $db->commit();
            echo "SUCCESS: Student registration processed successfully.\n";
            print_r($result['normalized_payload']);
        } catch (Exception $e) {
            $db->rollback();
            echo "DATABASE ERROR: " . $e->getMessage() . "\n";
        }
    } else {
        // Show validation messages
        echo "VALIDATION FAILED:\n";
        if (!empty($result['errors'])) {
            echo "Errors:\n - " . implode("\n - ", $result['errors']) . "\n";
        }
        if (!empty($result['warnings'])) {
            echo "Warnings:\n - " . implode("\n - ", $result['warnings']) . "\n";
        }
        if (!empty($result['suggested_corrections'])) {
            echo "Suggested Corrections:\n";
            print_r($result['suggested_corrections']);
        }
    }
}
