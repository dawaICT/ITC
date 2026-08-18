<?php
/**
 * Seamless one-click admission of a processed applicant.
 *
 * A "fully admitted" student needs four things, not one:
 *   1. a row in `students`                (personal record)
 *   2. a row in `student_program`         (academic programme enrolment)
 *   3. a row in `student_login`           (so they can actually sign in)
 *   4. course assignments + an invoice    (so fees are tracked)
 *
 * The old admin flow only ever created (1) — and a separate manual step
 * created (2) — leaving every admin-admitted student unable to log in and with
 * no invoice. This helper does the whole thing in a single transaction,
 * reusing the exact same schema-safe helpers the modern admissions wizard uses
 * (admissionsInsert, admissionsEnsureStudentLogin, admissionsAssignCourses,
 * admissionsCreateInvoice …) so the two flows can never drift apart again.
 *
 * Everything needed is already on the processed_applicants row (the applicant
 * picked their programme, intake and mode when they applied online), so the
 * admission needs no extra input — one click does it all.
 */

require_once __DIR__ . '/student_id_generator.php';
require_once __DIR__ . '/applicant_program_helpers.php';
require_once __DIR__ . '/cse_progression.php';
require_once __DIR__ . '/../admissions/includes/registration_handlers.php';

if (!function_exists('admissionsPeriodFromIntake')) {
    /**
     * Map a free-text intake label ("January", "July 2026", "September intake")
     * onto the period number the rest of the pipeline expects:
     *   semester programmes → '1' (Jan) or '2' (Jun/Jul)
     *   term programmes     → '1' (Jan), '2' (May) or '3' (Sep)
     */
    function admissionsPeriodFromIntake(string $periodMode, string $intake): string
    {
        // Strip any 4-digit year first so the bare digit fallbacks below can't
        // mistake a year digit for a period number. We strip ANY 4-digit run
        // (not just \b-delimited ones): real intake labels are frequently glued,
        // e.g. "JAN2024"/"SEP2024", where \b\d{4}\b would miss the year and the
        // "2" in "2024" would wrongly read as period 2. A period is always a
        // single digit (1-3), so a 4-digit run is always a year, never a period.
        $s = strtolower(trim($intake));
        $s = preg_replace('/\d{4}/', '', $s);

        if ($periodMode === 'term') {
            if (strpos($s, 'sep') !== false || strpos($s, 'third') !== false
                || strpos($s, 'term 3') !== false || strpos($s, '3') !== false) {
                return '3';
            }
            if (strpos($s, 'may') !== false || strpos($s, 'second') !== false
                || strpos($s, 'term 2') !== false || strpos($s, '2') !== false) {
                return '2';
            }
            return '1';
        }
        // semester: the second-semester intake (Jun/Jul or the Aug/Sep "fall"
        // intake) is period 2; everything else (Jan first semester) is period 1.
        if (strpos($s, 'jul') !== false || strpos($s, 'jun') !== false
            || strpos($s, 'aug') !== false || strpos($s, 'sep') !== false
            || strpos($s, 'fall') !== false
            || strpos($s, 'second') !== false || strpos($s, 'semester 2') !== false
            || strpos($s, '2') !== false) {
            return '2';
        }
        return '1';
    }
}

if (!function_exists('admissionsFindExistingStudent')) {
    /**
     * Return the SID of an existing student matching this NRC / email / mobile,
     * or null if none. Used to keep admission idempotent — a second click can
     * never create a duplicate student.
     */
    function admissionsFindExistingStudent(mysqli $db, string $nrc, string $email, string $mobile): ?string
    {
        $checks = [];
        $params = [];
        $types = '';
        if ($nrc !== '') {
            $checks[] = 'LOWER(TRIM(nrc_pass)) = LOWER(TRIM(?))';
            $params[] = $nrc;
            $types .= 's';
        }
        if ($email !== '') {
            $checks[] = 'LOWER(TRIM(email)) = LOWER(TRIM(?))';
            $params[] = $email;
            $types .= 's';
        }
        if ($mobile !== '') {
            $checks[] = 'TRIM(mobile) = TRIM(?)';
            $params[] = $mobile;
            $types .= 's';
        }
        if (!$checks) {
            return null;
        }

        $sql = 'SELECT SID FROM students WHERE ' . implode(' OR ', $checks) . ' LIMIT 1';
        $stmt = $db->prepare($sql);
        $bind = [$types];
        foreach ($params as $i => $v) {
            $bind[] = &$params[$i];
        }
        $stmt->bind_param(...$bind);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row['SID'] ?? null;
    }
}

if (!function_exists('admitProcessedApplicant')) {
    /**
     * Fully admit the processed applicant identified by $applicantId.
     *
     * @return array{
     *   success: bool, message: string,
     *   student_id?: string, default_password?: string, warnings?: string[]
     * }
     */
    function admitProcessedApplicant(mysqli $db, int $applicantId, string $staffId = ''): array
    {
        if ($applicantId <= 0) {
            return ['success' => false, 'message' => 'Invalid applicant reference.'];
        }

        // 1. Load the processed application.
        $app = null;
        if ($q = $db->prepare('SELECT * FROM processed_applicants WHERE id = ? LIMIT 1')) {
            $q->bind_param('i', $applicantId);
            $q->execute();
            $app = $q->get_result()->fetch_assoc();
            $q->close();
        }
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found.'];
        }

        $nrc   = trim((string)($app['nrc_pass'] ?? ''));
        $email = strtolower(trim((string)($app['email'] ?? '')));
        $phone = trim((string)($app['mobile'] ?? ''));

        // 2. Idempotency guard — never create a duplicate student.
        $existingSid = admissionsFindExistingStudent($db, $nrc, $email, $phone);
        if ($existingSid !== null) {
            return ['success' => false, 'message' => "This applicant is already a student ({$existingSid})."];
        }

        $warnings = [];
        $transactionStarted = false;
        $manageTransaction = !invoice_transaction_active($db);
        $savepoint = 'applicant_admission';

        try {
            // Normalise the applicant data.
            $gender = strtoupper(substr((string)($app['sex'] ?? ''), 0, 1));
            if (!in_array($gender, ['M', 'F'], true)) {
                $gender = 'M';
            }
            $rawProgram = trim((string)($app['program_code'] ?? ''));
            if ($rawProgram === '') {
                $rawProgram = trim((string)($app['program'] ?? ''));
            }
            $resolvedProgram = wuc_resolve_applicant_program($db, $rawProgram);
            $programCode = $resolvedProgram['valid'] ? $resolvedProgram['code'] : $rawProgram;
            if ($stageError = wuc_cse_direct_assignment_error($programCode)) {
                return ['success' => false, 'message' => $stageError];
            }
            $entryYear    = (int)($app['year'] ?? 0) ?: (int)date('Y');
            $academicYear = (string)$entryYear;
            $sponsor      = trim((string)($app['sponsor'] ?? 'Self')) ?: 'Self';
            $mode         = trim((string)($app['mode'] ?? 'Full-time')) ?: 'Full-time';

            // 3. Resolve the programme. Degrade gracefully if the applicant's
            //    programme code isn't in the catalogue: still admit the student
            //    and create their login, but skip enrolment/invoice and warn so
            //    an officer can finish it manually rather than the click failing.
            $program   = null;
            $period    = '1';
            $intake    = (string)($app['intake'] ?? '');
            $termStart = null;
            $termEnd   = null;
            $endYear   = $entryYear;

            if ($programCode !== '') {
                try {
                    $program  = admissionsResolveProgram($db, $programCode);
                    $period   = admissionsPeriodFromIntake($program['period_mode'], (string)($app['intake'] ?? ''));
                    // Centralised schedule so term / semester / rolling (short
                    // course) intakes are all derived the same way the existing-
                    // student admit path uses — a rolling course starts on the
                    // enrolment date and runs its real length, not a term window.
                    $schedule  = admissionsEnrolmentSchedule($program, $period, $entryYear);
                    $intake    = $schedule['intake'];
                    $termStart = $schedule['start'];
                    $termEnd   = $schedule['end'];
                    $endYear   = $schedule['endYear'];
                } catch (Throwable $e) {
                    $warnings[] = 'Programme "' . $programCode . '" is not in the catalogue; '
                        . 'the student was admitted without programme enrolment or an invoice. '
                        . 'Assign a programme manually.';
                    $program = null;
                }
            } else {
                $warnings[] = 'No programme on the application; admitted without enrolment.';
            }

            // 4. Student number (NRC-based, programme-aware for the type letter).
            $studentId = generateStudentId($db, $programCode !== '' ? $programCode : 'GENERAL', $period, $academicYear, $nrc);

            if ($manageTransaction) {
                $db->begin_transaction();
            } else {
                $db->query('SAVEPOINT ' . $savepoint);
            }
            $transactionStarted = true;

            require_once __DIR__ . '/sponsorship_helpers.php';
            $sponsorType = sps_resolve_sponsor_type_from_string($db, $sponsor);
            $requiresApproval = $sponsorType && (int)$sponsorType['requires_approval'] === 1;
            $studentStatus = $requiresApproval ? 'pending' : 'active';
            $programStatus = $requiresApproval ? 'pending' : 'active';

            // 5. Student record.
            admissionsInsert($db, 'students', [
                'SID'             => $studentId,
                'title'           => (string)($app['title'] ?? ''),
                'Fname'           => (string)($app['Fname'] ?? ''),
                'Lname'           => (string)($app['Lname'] ?? ''),
                'sex'             => $gender,
                'dob'             => !empty($app['dob']) ? $app['dob'] : null,
                'nrc_pass'        => $nrc,
                'nrc_file'        => (string)($app['nrc_file'] ?? '') ?: null,
                'country'         => (string)($app['country'] ?? ''),
                'email'           => $email,
                'mobile'          => $phone,
                'h_addre'         => (string)($app['h_addre'] ?? ''),
                'p_addre'         => (string)($app['p_addre'] ?? ''),
                'sponsor'         => $sponsor,
                'next_kin'        => (string)($app['next_kin'] ?? ''),
                'next_kin_mobile' => (string)($app['next_kin_mobile'] ?? ''),
                'relat'           => (string)($app['relat'] ?? ''),
                'program'         => $programCode,
                'intake'          => $intake,
                'mode'            => $mode,
                'academic_year'   => $academicYear,
                'year'            => 1,
                'status'          => $studentStatus,
                'dte_adm'         => date('Y-m-d H:i:s'),
                'enrollment_date' => date('Y-m-d H:i:s'),
            ]);

            // 6. Programme enrolment + course assignment (only if resolved).
            $totalFees = 0.0;
            if ($program) {
                admissionsInsert($db, 'student_program', [
                    'Sid'             => $studentId,
                    'program_code'    => $programCode,
                    'intake'          => $intake,
                    'term'            => $period,
                    'mode'            => $mode,
                    'startYear'       => $entryYear,
                    'endYear'         => $endYear,
                    'status'          => $programStatus,
                    'academic_year'   => $academicYear,
                    'term_start_date' => $termStart,
                    'term_end_date'   => $termEnd,
                ]);
                $totalFees = admissionsAssignCourses($db, $studentId, $programCode, $period, $academicYear);
            }

            // Create sponsorship record
            if ($sponsorType) {
                $coverage = 100.0;
                create_student_sponsorship($db, [
                    'student_id' => $studentId,
                    'sponsor_type_id' => $sponsorType['id'],
                    'sponsor_id' => 0,
                    'program_code' => $programCode,
                    'academic_year' => $academicYear,
                    'reference_number' => '',
                    'coverage_percent' => $coverage,
                    'amount_approved' => null,
                    'start_date' => $termStart,
                    'end_date' => $termEnd,
                    'conditions' => $requiresApproval ? 'Pending sponsorship approval' : 'Auto-approved',
                ], 'admissions');
            }

            // 7. Login account — the critical step the old flow omitted.
            admissionsEnsureStudentLogin($db, $studentId, $nrc, $email);

            // 8. Invoice with bursary. Only if not pending approval.
            if ($program && !$requiresApproval) {
                $bursary = ($sponsorType && ($sponsorType['code'] === 'cdf' || $sponsorType['code'] === 'teveta')) ? 100.0 : 0.0;
                $invoiceAmount = $totalFees * (1 - $bursary / 100);
                $desc = $program['name'] . ' registration - ' . $intake
                    . ($bursary > 0 ? sprintf(' (%s bursary %.0f%%)', $sponsor, $bursary) : '');
                admissionsCreateInvoice(
                    $db,
                    $studentId,
                    $invoiceAmount,
                    $desc,
                    $academicYear,
                    $period,
                    1,
                    $staffId !== '' ? $staffId : 'admissions'
                );
            }

            // Generate student fee account automatically in the new fees system
            require_once __DIR__ . '/fees_helpers.php';
            $programName = $program ? $program['name'] : '';
            if ($courseStmt = $db->prepare("SELECT id FROM courses WHERE course_code = ? OR (course_name IS NOT NULL AND LOWER(TRIM(course_name)) = LOWER(TRIM(?))) LIMIT 1")) {
                $courseStmt->bind_param('ss', $programCode, $programName);
                $courseStmt->execute();
                $course_res = $courseStmt->get_result();
                if ($course_res && $course_row = $course_res->fetch_assoc()) {
                    $courseId = (int)$course_row['id'];
                    
                    $trainingModeId = 0;
                    if ($modeStmt = $db->prepare("SELECT id FROM training_modes WHERE LOWER(mode_name) = LOWER(?) LIMIT 1")) {
                        $modeStmt->bind_param('s', $mode);
                        $modeStmt->execute();
                        $mode_res = $modeStmt->get_result();
                        if ($mode_res && $mode_row = $mode_res->fetch_assoc()) {
                            $trainingModeId = (int)$mode_row['id'];
                        }
                        $modeStmt->close();
                    }
                    
                    if ($trainingModeId === 0) {
                        $mode_fallback = $db->query("SELECT id FROM training_modes LIMIT 1");
                        if ($mode_fallback && $mode_fallback->num_rows > 0) {
                            $trainingModeId = (int)$mode_fallback->fetch_assoc()['id'];
                        }
                    }
                    
                    if ($trainingModeId > 0) {
                        fees_generate_student_account($db, $studentId, $courseId, $trainingModeId, $academicYear, $intake);
                    }
                }
                $courseStmt->close();
            }

            require_once __DIR__ . '/audit.php';
            audit_log($db, $staffId !== '' ? $staffId : 'admissions', 'admissions.student_created_from_applicant', [
                'record_id' => (string)$applicantId,
                'applicant_id' => $applicantId,
                'student_id' => $studentId,
                'program_code' => $programCode,
                'academic_year' => $academicYear,
                'period' => $period,
            ]);

            if ($manageTransaction) {
                $db->commit();
            } else {
                $db->query('RELEASE SAVEPOINT ' . $savepoint);
            }

            // The initial password mirrors admissionsEnsureStudentLogin: the NRC
            // (or the SID when NRC is blank). The student must change it on first login.
            $defaultPassword = $nrc !== '' ? $nrc : $studentId;

            $fullName = trim((string)($app['Fname'] ?? '') . ' ' . (string)($app['Lname'] ?? ''));
            $message  = "Admitted {$fullName} as {$studentId}.";
            if ($program) {
                $message .= ' Enrolled in ' . $program['name'] . ' (' . $intake . ').';
            }

            return [
                'success'          => true,
                'message'          => $message,
                'student_id'       => $studentId,
                'default_password' => $defaultPassword,
                'warnings'         => $warnings,
            ];
        } catch (Throwable $e) {
            if ($transactionStarted) {
                if ($manageTransaction) {
                    $db->rollback();
                } else {
                    $db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $db->query('RELEASE SAVEPOINT ' . $savepoint);
                }
            }
            error_log('admitProcessedApplicant error (id ' . $applicantId . '): ' . $e->getMessage());
            return ['success' => false, 'message' => 'Admission failed: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('admissionsEnrollExistingStudent')) {
    /**
     * Canonical "admit an EXISTING student into a programme" path.
     *
     * The various admit-existing entry points (admin/admitStudent.php,
     * admin/processAdmit_student.php, admissions/admitStudent.php …) used to each
     * hand-write a `student_program` INSERT, and they drifted apart: some omitted
     * `status` (leaving NULL rows), some never created a `student_login` (locking
     * the student out), some fed `type="month"` values into the `smallint`
     * startYear/endYear columns, and none set academic_year / term / term dates.
     *
     * This helper makes the result identical to the modern online-applicant
     * admission (admitProcessedApplicant): a fully-populated programme enrolment,
     * the student activated, a login guaranteed, and course assignment + invoice
     * when there are fees. It is idempotent per programme — a second admission to
     * the same programme is rejected rather than duplicated.
     *
     * @param int|null $entryYear 4-digit start year; defaults to the current year.
     * @return array{success:bool,message:string,student_id?:string,warnings?:string[]}
     */
    function admissionsEnrollExistingStudent(
        mysqli $db,
        string $sid,
        string $programCode,
        string $intake,
        string $mode,
        ?int $entryYear = null
    ): array {
        $sid         = trim($sid);
        $programCode = trim($programCode);
        $intake      = trim($intake);
        $mode        = trim($mode) ?: 'Full-Time';
        $entryYear   = $entryYear ?: (int)date('Y');

        if ($sid === '' || $programCode === '') {
            return ['success' => false, 'message' => 'Student ID and programme are required.'];
        }
        if ($stageError = wuc_cse_direct_assignment_error($programCode)) {
            return ['success' => false, 'message' => $stageError];
        }

        // Student must exist; pull the fields we need for login + invoice.
        $student = null;
        if ($q = $db->prepare('SELECT nrc_pass, email, sponsor FROM students WHERE SID = ? LIMIT 1')) {
            $q->bind_param('s', $sid);
            $q->execute();
            $student = $q->get_result()->fetch_assoc();
            $q->close();
        }
        if (!$student) {
            return ['success' => false, 'message' => "Student ID {$sid} is not registered in the system."];
        }

        // Idempotency — never duplicate an enrolment in the SAME programme.
        if ($chk = $db->prepare('SELECT 1 FROM student_program WHERE Sid = ? AND program_code = ? LIMIT 1')) {
            $chk->bind_param('ss', $sid, $programCode);
            $chk->execute();
            $already = (bool)$chk->get_result()->num_rows;
            $chk->close();
            if ($already) {
                return ['success' => false, 'message' => "Student {$sid} is already admitted to {$programCode}."];
            }
        }

        $warnings = [];
        $transactionStarted = false;
        try {
            $program = admissionsResolveProgram($db, $programCode); // throws on invalid programme
            $period  = admissionsPeriodFromIntake($program['period_mode'], $intake);
            $schedule = admissionsEnrolmentSchedule($program, $period, $entryYear);

            // Keep the officer's chosen intake label, but make sure it carries a
            // year so reports read cleanly; fall back to the canonical label.
            $intakeLabel = $intake !== '' ? $intake : $schedule['intake'];
            if (!preg_match('/\d{4}/', $intakeLabel)) {
                $intakeLabel .= ' ' . $entryYear;
            }
            $academicYear = (string)$entryYear;

            $db->begin_transaction();
            $transactionStarted = true;

            admissionsInsert($db, 'student_program', [
                'Sid'             => $sid,
                'program_code'    => $programCode,
                'intake'          => $intakeLabel,
                'term'            => $period,
                'mode'            => $mode,
                'startYear'       => $entryYear,
                'endYear'         => $schedule['endYear'],
                'status'          => 'active',
                'academic_year'   => $academicYear,
                'term_start_date' => $schedule['start'],
                'term_end_date'   => $schedule['end'],
            ]);

            // Activate the student record (studentLogin.php requires 'active').
            if ($act = $db->prepare("UPDATE students SET status = 'active', enrollment_date = NOW() WHERE SID = ?")) {
                $act->bind_param('s', $sid);
                $act->execute();
                $act->close();
            }

            // Guarantee a login (initial password = NRC, forced change on first sign-in).
            admissionsEnsureStudentLogin($db, $sid, (string)($student['nrc_pass'] ?? ''), $student['email'] ?? null);

            // Course assignment + invoice (only when the programme actually has fees).
            $totalFees = admissionsAssignCourses($db, $sid, $programCode, $period, $academicYear);
            if ($totalFees > 0) {
                require_once __DIR__ . '/sponsorship_helpers.php';
                $sponsor = trim((string)($student['sponsor'] ?? 'Self')) ?: 'Self';
                $sponsorType = sps_resolve_sponsor_type_from_string($db, $sponsor);
                $requiresApproval = $sponsorType && (int)$sponsorType['requires_approval'] === 1;

                // Look up student's approved sponsorship summary
                $bursary = 0.0;
                $approvedSps = get_student_sponsorship($db, $sid, true);
                if ($approvedSps) {
                    $bursary = (float)($approvedSps['coverage_percent'] ?? 0.0);
                } elseif ($sponsorType && ($sponsorType['code'] === 'cdf' || $sponsorType['code'] === 'teveta')) {
                    $bursary = 100.0;
                }

                if (!$requiresApproval || $approvedSps) {
                    $amount  = $totalFees * (1 - $bursary / 100);
                    $desc    = $program['name'] . ' registration - ' . $intakeLabel
                        . ($bursary > 0 ? sprintf(' (%s bursary %.0f%%)', $sponsor, $bursary) : '');
                    admissionsCreateInvoice($db, $sid, $amount, $desc, $academicYear, $period, 1, 'admissions');
                }
            }

            // Generate student fee account automatically in the new fees system
            require_once __DIR__ . '/fees_helpers.php';
            $programName = $program ? $program['name'] : '';
            if ($courseStmt = $db->prepare("SELECT id FROM courses WHERE course_code = ? OR (course_name IS NOT NULL AND LOWER(TRIM(course_name)) = LOWER(TRIM(?))) LIMIT 1")) {
                $courseStmt->bind_param('ss', $programCode, $programName);
                $courseStmt->execute();
                $course_res = $courseStmt->get_result();
                if ($course_res && $course_row = $course_res->fetch_assoc()) {
                    $courseId = (int)$course_row['id'];
                    
                    $trainingModeId = 0;
                    if ($modeStmt = $db->prepare("SELECT id FROM training_modes WHERE LOWER(mode_name) = LOWER(?) LIMIT 1")) {
                        $modeStmt->bind_param('s', $mode);
                        $modeStmt->execute();
                        $mode_res = $modeStmt->get_result();
                        if ($mode_res && $mode_row = $mode_res->fetch_assoc()) {
                            $trainingModeId = (int)$mode_row['id'];
                        }
                        $modeStmt->close();
                    }
                    
                    if ($trainingModeId === 0) {
                        $mode_fallback = $db->query("SELECT id FROM training_modes LIMIT 1");
                        if ($mode_fallback && $mode_fallback->num_rows > 0) {
                            $trainingModeId = (int)$mode_fallback->fetch_assoc()['id'];
                        }
                    }
                    
                    if ($trainingModeId > 0) {
                        fees_generate_student_account($db, $sid, $courseId, $trainingModeId, $academicYear, $intakeLabel);
                    }
                }
                $courseStmt->close();
            }

            $db->commit();

            return [
                'success'    => true,
                'message'    => "Admitted {$sid} into {$program['name']} ({$intakeLabel}).",
                'student_id' => $sid,
                'warnings'   => $warnings,
            ];
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $db->rollback();
            }
            error_log('admissionsEnrollExistingStudent error (sid ' . $sid . '): ' . $e->getMessage());
            return ['success' => false, 'message' => 'Admission failed: ' . $e->getMessage()];
        }
    }
}
