<?php
/**
 * Exam Fee Check Helper
 * Verifies if a student has paid at least 75% of their tuition fees
 * before allowing exam registration.
 * 
 * @param mysqli $db Database connection
 * @param string $studentId Student ID
 * @param int $semester Semester (1 or 2)
 * @param int $yearOfStudy Year of study
 * @return array ['eligible' => bool, 'message' => string, 'percentage' => float]
 */
function student_exam_table_exists(mysqli $db, string $table): bool {
    $safeTable = $db->real_escape_string($table);
    if ($result = $db->query("SHOW TABLES LIKE '{$safeTable}'")) {
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
    return false;
}

function student_exam_column_exists(mysqli $db, string $table, string $column): bool {
    if (!student_exam_table_exists($db, $table)) {
        return false;
    }
    $safeColumn = $db->real_escape_string($column);
    if ($result = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'")) {
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
    return false;
}

function student_exam_sum_paid(mysqli $db, string $studentId, ?int $semester = null, ?int $yearOfStudy = null): float {
    $paid = 0.0;

    if (student_exam_table_exists($db, 'payments') && student_exam_column_exists($db, 'payments', 'amount')) {
        $sql = "SELECT COALESCE(SUM(amount), 0) AS total_paid
                FROM payments
                WHERE student_id COLLATE utf8mb4_general_ci = ?";
        $types = 's';
        $params = [$studentId];
        if ($semester !== null && student_exam_column_exists($db, 'payments', 'semester')) {
            $sql .= " AND semester = ?";
            $types .= 'i';
            $params[] = $semester;
        }
        if (student_exam_column_exists($db, 'payments', 'status')) {
            $sql .= " AND status IN ('posted','completed','confirmed')";
        }
        if ($stmt = $db->prepare($sql)) {
            $bind = [$types];
            foreach ($params as $i => $value) {
                $bind[] = &$params[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $paid += (float)($row['total_paid'] ?? 0);
        }
    }

    if (student_exam_table_exists($db, 'student_payments')) {
        $sidCol = student_exam_column_exists($db, 'student_payments', 'Sid') ? 'Sid' : (student_exam_column_exists($db, 'student_payments', 'SID') ? 'SID' : null);
        $amountCol = student_exam_column_exists($db, 'student_payments', 'amount_paid') ? 'amount_paid' : (student_exam_column_exists($db, 'student_payments', 'amount') ? 'amount' : null);
        if ($sidCol !== null && $amountCol !== null) {
            $sql = "SELECT COALESCE(SUM(`{$amountCol}`), 0) AS total_paid
                    FROM student_payments
                    WHERE `{$sidCol}` = ?";
            $types = 's';
            $params = [$studentId];
            $semCol = student_exam_column_exists($db, 'student_payments', 'semester_term') ? 'semester_term' : (student_exam_column_exists($db, 'student_payments', 'semester') ? 'semester' : null);
            $yearCol = student_exam_column_exists($db, 'student_payments', 'year_of_study') ? 'year_of_study' : (student_exam_column_exists($db, 'student_payments', 'Year') ? 'Year' : null);
            $statusCol = student_exam_column_exists($db, 'student_payments', 'payment_status') ? 'payment_status' : (student_exam_column_exists($db, 'student_payments', 'status') ? 'status' : null);
            if ($semester !== null && $semCol !== null) {
                $sql .= " AND `{$semCol}` = ?";
                $types .= 'i';
                $params[] = $semester;
            }
            if ($yearOfStudy !== null && $yearCol !== null) {
                $sql .= " AND `{$yearCol}` = ?";
                $types .= 'i';
                $params[] = $yearOfStudy;
            }
            if ($statusCol !== null) {
                $sql .= " AND `{$statusCol}` IN ('posted','completed','confirmed')";
            }
            if ($stmt = $db->prepare($sql)) {
                $bind = [$types];
                foreach ($params as $i => $value) {
                    $bind[] = &$params[$i];
                }
                call_user_func_array([$stmt, 'bind_param'], $bind);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $paid += (float)($row['total_paid'] ?? 0);
            }
        }
    }

    return $paid;
}

function student_exam_ensure_schema(mysqli $db): bool {
    // Fast path: schema already in place. The app DB user is DML-only, so we
    // must not attempt CREATE/ALTER when everything already exists — the
    // denied DDL used to make this function report "not configured" even
    // though exam_registration was fully usable.
    if (student_exam_table_exists($db, 'exam_registration')) {
        $required = ['academic_year', 'programme_type', 'assessment_period_type', 'assessment_label'];
        $allPresent = true;
        foreach ($required as $col) {
            if (!student_exam_column_exists($db, 'exam_registration', $col)) {
                $allPresent = false;
                break;
            }
        }
        if ($allPresent) {
            return true;
        }
    }

    $createSql = "CREATE TABLE IF NOT EXISTS exam_registration (
        ExID INT UNSIGNED NOT NULL AUTO_INCREMENT,
        Sid VARCHAR(20) NOT NULL,
        course_code VARCHAR(20) NOT NULL,
        semester TINYINT UNSIGNED NOT NULL,
        `Year` TINYINT UNSIGNED NOT NULL,
        academic_year INT NULL,
        examType VARCHAR(50) DEFAULT NULL,
        programme_type VARCHAR(32) NOT NULL DEFAULT 'yearly',
        assessment_period_type VARCHAR(32) NOT NULL DEFAULT 'end_of_semester',
        assessment_label VARCHAR(80) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ExID),
        KEY idx_exam_reg_sid (Sid),
        KEY idx_exam_reg_course (course_code),
        KEY idx_exam_reg_assessment (assessment_period_type),
        UNIQUE KEY uniq_exam_reg_student_course_sem_year (Sid, course_code, semester, `Year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    try {
        $created = $db->query($createSql);
        if (!$created) {
            error_log('[exam_helpers] Could not create exam_registration: ' . $db->error);
        }
    } catch (Throwable $e) {
        // DDL denied for the DML-only app user: fall through — the table may
        // still exist (created by the migrator), which is what matters.
        error_log('[exam_helpers] Could not create exam_registration: ' . $e->getMessage());
    }
    if (!student_exam_table_exists($db, 'exam_registration')) {
        return false;
    }

    $columns = [
        'academic_year' => "ALTER TABLE exam_registration ADD COLUMN academic_year INT NULL AFTER `Year`",
        'programme_type' => "ALTER TABLE exam_registration ADD COLUMN programme_type VARCHAR(32) NOT NULL DEFAULT 'yearly' AFTER examType",
        'assessment_period_type' => "ALTER TABLE exam_registration ADD COLUMN assessment_period_type VARCHAR(32) NOT NULL DEFAULT 'end_of_semester' AFTER programme_type",
        'assessment_label' => "ALTER TABLE exam_registration ADD COLUMN assessment_label VARCHAR(80) DEFAULT NULL AFTER assessment_period_type",
    ];

    foreach ($columns as $column => $sql) {
        try {
            if (!student_exam_column_exists($db, 'exam_registration', $column) && !$db->query($sql)) {
                error_log("[exam_helpers] Could not add exam_registration.{$column}: " . $db->error);
            }
        } catch (Throwable $e) {
            error_log("[exam_helpers] Could not add exam_registration.{$column}: " . $e->getMessage());
        }
    }

    $indexes = [
        'idx_exam_reg_assessment' => "ALTER TABLE exam_registration ADD INDEX idx_exam_reg_assessment (assessment_period_type)",
        'idx_exam_reg_sid_period' => "ALTER TABLE exam_registration ADD INDEX idx_exam_reg_sid_period (Sid, semester, `Year`, assessment_period_type)",
    ];
    foreach ($indexes as $index => $sql) {
        $safeIndex = $db->real_escape_string($index);
        $exists = false;
        try {
            if ($res = $db->query("SHOW INDEX FROM exam_registration WHERE Key_name = '{$safeIndex}'")) {
                $exists = $res->num_rows > 0;
                $res->free();
            }
            if (!$exists && !$db->query($sql)) {
                error_log("[exam_helpers] Could not add exam_registration index {$index}: " . $db->error);
            }
        } catch (Throwable $e) {
            error_log("[exam_helpers] Could not add exam_registration index {$index}: " . $e->getMessage());
        }
    }

    return student_exam_table_exists($db, 'exam_registration');
}

function student_exam_get_program_period_mode(mysqli $db, string $studentId): string {
    if (function_exists('getStudentProgramPeriodMode')) {
        return getStudentProgramPeriodMode($db, $studentId);
    }

    if (!student_exam_table_exists($db, 'student_program') || !student_exam_table_exists($db, 'programs')) {
        return 'semester';
    }

    $columns = [];
    if ($res = $db->query("SHOW COLUMNS FROM programs")) {
        while ($row = $res->fetch_assoc()) {
            $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
        }
        $res->free();
    }

    $expr = "'semester'";
    if (isset($columns['period_mode'])) {
        $col = $columns['period_mode'];
        $expr = "COALESCE(NULLIF(p.`{$col}`, ''), 'semester')";
    } elseif (isset($columns['study_mode'])) {
        $col = $columns['study_mode'];
        $expr = "CASE WHEN LOWER(COALESCE(p.`{$col}`, '')) LIKE '%term%' THEN 'term' ELSE 'semester' END";
    } elseif (isset($columns['program_type'])) {
        $col = $columns['program_type'];
        $expr = "CASE WHEN LOWER(COALESCE(p.`{$col}`, '')) LIKE '%term%' THEN 'term' ELSE 'semester' END";
    }

    $sidCol = student_exam_column_exists($db, 'student_program', 'Sid') ? 'Sid' : 'SID';
    $sql = "SELECT {$expr} AS period_mode
            FROM student_program sp
            INNER JOIN programs p ON p.program_code = sp.program_code
            WHERE sp.`{$sidCol}` COLLATE utf8mb4_general_ci = ?
            LIMIT 1";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return strtolower((string)($row['period_mode'] ?? 'semester')) === 'term' ? 'term' : 'semester';
    }

    return 'semester';
}

function student_exam_is_short_course(mysqli $db, string $studentId, string $courseCode): bool {
    if (!student_exam_table_exists($db, 'short_courses') || !student_exam_table_exists($db, 'short_course_enrollments')) {
        return false;
    }

    $sql = "SELECT sce.id
            FROM short_course_enrollments sce
            INNER JOIN short_courses sc ON sc.id = sce.short_course_id
            WHERE sce.student_id COLLATE utf8mb4_general_ci = ?
              AND sc.course_code COLLATE utf8mb4_general_ci = ?
              AND COALESCE(sce.status, 'enrolled') IN ('enrolled','active','completed')
            LIMIT 1";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('ss', $studentId, $courseCode);
        $stmt->execute();
        $stmt->store_result();
        $found = $stmt->num_rows > 0;
        $stmt->close();
        return $found;
    }

    return false;
}

function student_exam_assessment_context(mysqli $db, string $studentId, int $semester, int $yearOfStudy, ?string $courseCode = null): array {
    if ($courseCode !== null && student_exam_is_short_course($db, $studentId, $courseCode)) {
        return [
            'programme_type' => 'short_course',
            'assessment_period_type' => 'short_course_test',
            'assessment_label' => 'Short Course Test',
            'period_label' => 'Short Course',
        ];
    }

    $mode = student_exam_get_program_period_mode($db, $studentId);
    if ($mode === 'term') {
        return [
            'programme_type' => 'yearly',
            'assessment_period_type' => 'end_of_term',
            'assessment_label' => 'End of Term Exam',
            'period_label' => 'Term',
        ];
    }

    return [
        'programme_type' => 'yearly',
        'assessment_period_type' => 'end_of_semester',
        'assessment_label' => 'End of Semester Exam',
        'period_label' => 'Semester',
    ];
}

function student_exam_academic_year(mysqli $db, string $studentId, int $semester, int $yearOfStudy): int {
    $currentYear = (int)date('Y');

    if (student_exam_table_exists($db, 'semester_registration')) {
        $sidCol = student_exam_column_exists($db, 'semester_registration', 'student_id') ? 'student_id' : (student_exam_column_exists($db, 'semester_registration', 'Sid') ? 'Sid' : 'SID');
        $yearCol = student_exam_column_exists($db, 'semester_registration', 'year_of_study') ? 'year_of_study' : 'Year';
        $dateCol = student_exam_column_exists($db, 'semester_registration', 'registration_date') ? 'registration_date' : (student_exam_column_exists($db, 'semester_registration', 'created_at') ? 'created_at' : null);
        $dateExpr = $dateCol ? "`{$dateCol}`" : 'NOW()';
        $academicExpr = student_exam_column_exists($db, 'semester_registration', 'academic_year')
            ? "COALESCE(academic_year, YEAR({$dateExpr}))"
            : "YEAR({$dateExpr})";
        $sql = "SELECT {$academicExpr} AS academic_year
                FROM semester_registration
                WHERE `{$sidCol}` COLLATE utf8mb4_general_ci = ?
                  AND semester = ?
                  AND `{$yearCol}` = ?
                ORDER BY {$dateExpr} DESC
                LIMIT 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('sii', $studentId, $semester, $yearOfStudy);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['academic_year'])) {
                return (int)$row['academic_year'];
            }
        }
    }

    if (student_exam_table_exists($db, 'course_registration') && student_exam_column_exists($db, 'course_registration', 'academic_year')) {
        $dateCol = student_exam_column_exists($db, 'course_registration', 'registration_date') ? 'registration_date' : (student_exam_column_exists($db, 'course_registration', 'created_at') ? 'created_at' : null);
        $dateExpr = $dateCol ? "`{$dateCol}`" : 'NOW()';
        $sql = "SELECT academic_year
                FROM course_registration
                WHERE Sid COLLATE utf8mb4_general_ci = ? AND semester = ? AND Year = ?
                ORDER BY {$dateExpr} DESC
                LIMIT 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('sii', $studentId, $semester, $yearOfStudy);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['academic_year'])) {
                return (int)$row['academic_year'];
            }
        }
    }

    return $currentYear;
}

function student_exam_is_registered(mysqli $db, string $studentId, string $courseCode, int $semester, int $yearOfStudy, int $academicYear, string $assessmentPeriodType): bool {
    if (!student_exam_table_exists($db, 'exam_registration')) {
        return false;
    }

    $sql = "SELECT ExID FROM exam_registration
            WHERE Sid COLLATE utf8mb4_general_ci = ?
              AND course_code COLLATE utf8mb4_general_ci = ?
              AND semester = ?
              AND `Year` = ?";
    $types = 'ssii';
    $values = [$studentId, $courseCode, $semester, $yearOfStudy];

    if (student_exam_column_exists($db, 'exam_registration', 'academic_year')) {
        $sql .= " AND (academic_year = ? OR academic_year IS NULL)";
        $types .= 'i';
        $values[] = $academicYear;
    }
    if (student_exam_column_exists($db, 'exam_registration', 'assessment_period_type')) {
        $sql .= " AND assessment_period_type = ?";
        $types .= 's';
        $values[] = $assessmentPeriodType;
    }
    $sql .= " LIMIT 1";

    if ($stmt = $db->prepare($sql)) {
        $bind = [$types];
        foreach ($values as $i => $value) {
            $bind[] = &$values[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
        $stmt->execute();
        $stmt->store_result();
        $found = $stmt->num_rows > 0;
        $stmt->close();
        return $found;
    }

    return false;
}

function student_exam_register_courses(mysqli $db, string $studentId, array $courseCodes, int $semester, int $yearOfStudy): array {
    if (!student_exam_ensure_schema($db)) {
        return [
            'ok' => false,
            'message' => 'External assessment registration is not configured yet. Please contact the examinations office.',
            'inserted' => 0,
        ];
    }

    $courseCodes = array_values(array_unique(array_filter(array_map(
        fn($code) => preg_replace('/[^A-Za-z0-9\-]/', '', trim((string)$code)),
        $courseCodes
    ))));

    if (empty($courseCodes) || $semester <= 0 || $yearOfStudy <= 0) {
        return ['ok' => false, 'message' => 'Please select at least one course and fill in all required fields.', 'inserted' => 0];
    }

    $feeCheck = checkExamFeeEligibility($db, $studentId, $semester, $yearOfStudy);
    if (empty($feeCheck['eligible'])) {
        return ['ok' => false, 'message' => $feeCheck['message'], 'inserted' => 0];
    }

    $available = [];
    foreach (getCoursesForExamRegistration($db, $studentId, $semester, $yearOfStudy) as $row) {
        $available[(string)$row['course_code']] = $row;
    }

    $invalid = [];
    $duplicates = [];
    foreach ($courseCodes as $code) {
        if (!isset($available[$code])) {
            $invalid[] = $code;
            continue;
        }
        if (!empty($available[$code]['already_registered'])) {
            $duplicates[] = $code;
        }
    }

    if (!empty($invalid)) {
        return ['ok' => false, 'message' => 'These courses are not available for this external assessment registration: ' . implode(', ', $invalid), 'inserted' => 0];
    }
    if (!empty($duplicates)) {
        return ['ok' => false, 'message' => 'The following course(s) are already registered for this assessment: ' . implode(', ', $duplicates), 'inserted' => 0];
    }

    $academicYear = student_exam_academic_year($db, $studentId, $semester, $yearOfStudy);
    $sql = "INSERT INTO exam_registration
            (Sid, course_code, semester, `Year`, academic_year, examType, programme_type, assessment_period_type, assessment_label, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Exam registration table is not ready. Please contact the examinations office.', 'inserted' => 0];
    }

    $db->begin_transaction();
    try {
        $inserted = 0;
        foreach ($courseCodes as $code) {
            $context = student_exam_assessment_context($db, $studentId, $semester, $yearOfStudy, $code);
            if (student_exam_is_registered($db, $studentId, $code, $semester, $yearOfStudy, $academicYear, $context['assessment_period_type'])) {
                throw new RuntimeException("Course {$code} is already registered for this assessment.");
            }
            $examType = $context['assessment_label'];
            $programmeType = $context['programme_type'];
            $assessmentPeriodType = $context['assessment_period_type'];
            $assessmentLabel = $context['assessment_label'];
            $stmt->bind_param(
                'ssiiissss',
                $studentId,
                $code,
                $semester,
                $yearOfStudy,
                $academicYear,
                $examType,
                $programmeType,
                $assessmentPeriodType,
                $assessmentLabel
            );
            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error);
            }
            $inserted++;
        }
        $db->commit();
        $stmt->close();
        return ['ok' => true, 'message' => "{$inserted} course(s) successfully registered for external assessment.", 'inserted' => $inserted];
    } catch (Throwable $e) {
        $db->rollback();
        $stmt->close();
        error_log('[exam_helpers] External assessment registration failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => $e->getMessage(), 'inserted' => 0];
    }
}

function checkExamFeeEligibility($db, $studentId, $semester, $yearOfStudy) {
    $result = [
        'eligible' => false,
        'message' => '',
        'percentage' => 0,
        'total_fee' => 0,
        'total_paid' => 0,
        'balance' => 0
    ];
    
    // Escape inputs
    $sidEsc = $db->real_escape_string($studentId);
    $semEsc = (int)$semester;
    $yearEsc = (int)$yearOfStudy;
    
    // Get student's program
    $programSql = "SELECT sp.program_code FROM student_program sp WHERE sp.Sid = '$sidEsc' LIMIT 1";
    $programResult = $db->query($programSql);
    
    if (!$programResult || $programResult->num_rows === 0) {
        if (student_exam_table_exists($db, 'short_courses') && student_exam_table_exists($db, 'short_course_enrollments')) {
            $feeJoin = '';
            $feeAmountExpr = '0';
            if (
                student_exam_table_exists($db, 'fee_structure')
                && student_exam_column_exists($db, 'fee_structure', 'short_course_id')
            ) {
                $entityPredicate = student_exam_column_exists($db, 'fee_structure', 'entity_type')
                    ? "AND COALESCE(fs.entity_type, 'short_course') = 'short_course'"
                    : '';
                $statusPredicate = student_exam_column_exists($db, 'fee_structure', 'status')
                    ? "AND COALESCE(fs.status, 'active') = 'active'"
                    : '';
                $feeJoin = "LEFT JOIN fee_structure fs ON fs.short_course_id = sc.id
                                {$entityPredicate}
                                {$statusPredicate}";
                $feeAmountExpr = 'fs.amount';
            }
            $shortCourseFeeExpr = student_exam_column_exists($db, 'short_courses', 'fee') ? 'sc.fee' : '0';
            $shortFeeSql = "SELECT COALESCE(SUM(COALESCE(fs.amount, sc.fee, 0)), 0) AS total_fee
                            FROM short_course_enrollments sce
                            INNER JOIN short_courses sc ON sc.id = sce.short_course_id
                            {$feeJoin}
                            WHERE sce.student_id COLLATE utf8mb4_general_ci = ?
                              AND COALESCE(sce.status, 'enrolled') IN ('enrolled','active','completed')";
            $shortFeeSql = str_replace('COALESCE(fs.amount, sc.fee, 0)', "COALESCE({$feeAmountExpr}, {$shortCourseFeeExpr}, 0)", $shortFeeSql);
            if ($shortStmt = $db->prepare($shortFeeSql)) {
                $shortStmt->bind_param('s', $studentId);
                $shortStmt->execute();
                $shortRow = $shortStmt->get_result()->fetch_assoc();
                $shortStmt->close();
                $shortFee = (float)($shortRow['total_fee'] ?? 0);
                if ($shortFee <= 0) {
                    $result['eligible'] = true;
                    $result['message'] = 'Short-course fee structure is not configured. Registration allowed.';
                    $result['percentage'] = 100;
                    return $result;
                }

                $result['total_fee'] = $shortFee;
                if (!student_exam_table_exists($db, 'student_payments') && !student_exam_table_exists($db, 'payments')) {
                    $result['message'] = 'Payment records are not configured yet. Please contact accounts before exam registration.';
                    $result['balance'] = $shortFee;
                    return $result;
                }

                $totalPaid = student_exam_sum_paid($db, $studentId);
                $percentagePaid = $shortFee > 0 ? ($totalPaid / $shortFee) * 100 : 0;
                $result['total_paid'] = $totalPaid;
                $result['balance'] = $shortFee - $totalPaid;
                $result['percentage'] = round($percentagePaid, 2);
                if ($percentagePaid >= 75) {
                    $result['eligible'] = true;
                    $result['message'] = sprintf('You have paid %.1f%% of your short-course fees. You are eligible for test registration.', $percentagePaid);
                } else {
                    $result['message'] = sprintf('You have only paid %.1f%% of your short-course fees. Please pay at least 75%% to register for tests. Balance: %s', $percentagePaid, number_format($result['balance'], 2));
                }
                return $result;
            }
        }

        $result['message'] = 'Student program not found. Please contact the registrar.';
        return $result;
    }

    require_once __DIR__ . '/StudentAcademicWorkflowService.php';
    $workflow = new StudentAcademicWorkflowService($db);
    $period = $workflow->getActiveAcademicPeriod($studentId);
    if ($period['ok']) {
        $fee = $workflow->checkFeeEligibility($studentId, $period);
        $result['eligible'] = (bool)$fee['is_eligible'];
        $result['message'] = (string)$fee['reason'];
        $result['percentage'] = (float)$fee['payment_percentage'];
        $result['total_fee'] = (float)$fee['total_fee'];
        $result['total_paid'] = (float)$fee['amount_paid'];
        $result['balance'] = (float)$fee['balance'];
        return $result;
    }

    $result['message'] = 'Could not resolve the active academic period.';
    return $result;
}

/**
 * Get registered courses available for exam registration
 * 
 * @param mysqli $db Database connection
 * @param string $studentId Student ID
 * @param int $semester Semester
 * @param int $yearOfStudy Year of study
 * @return array List of courses
 */
function getCoursesForExamRegistration($db, $studentId, $semester, $yearOfStudy) {
    $courses = [];

    $semester = (int)$semester;
    $yearOfStudy = (int)$yearOfStudy;
    $academicYear = student_exam_academic_year($db, $studentId, $semester, $yearOfStudy);

    if (student_exam_table_exists($db, 'course_registration')) {
        $activeSql = student_exam_column_exists($db, 'course_registration', 'is_active') ? " AND COALESCE(cr.is_active, 1) = 1" : '';
        $academicYearSql = '';
        $types = 'si';
        $params = [$studentId, $yearOfStudy];
        if (student_exam_column_exists($db, 'course_registration', 'academic_year')) {
            $academicYearSql = " AND (cr.academic_year = ? OR cr.academic_year IS NULL OR cr.academic_year = 0)";
            $types .= 'i';
            $params[] = $academicYear;
        }
        $sql = "SELECT DISTINCT cr.course_code,
                       COALESCE(c.course_name, cr.course_code) AS course_name,
                       c.credits,
                       cr.semester,
                       cr.Year
                FROM course_registration cr
                LEFT JOIN courses c ON c.course_code COLLATE utf8mb4_general_ci = cr.course_code COLLATE utf8mb4_general_ci
                WHERE cr.Sid COLLATE utf8mb4_general_ci = ?
                  AND cr.Year = ?
                  {$academicYearSql}
                  {$activeSql}
                ORDER BY cr.course_code";
        if ($stmt = $db->prepare($sql)) {
            $bind = [$types];
            foreach ($params as $i => $value) {
                $bind[] = &$params[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $context = student_exam_assessment_context($db, $studentId, $semester, $yearOfStudy, (string)$row['course_code']);
                $row['programme_type'] = $context['programme_type'];
                $row['assessment_period_type'] = $context['assessment_period_type'];
                $row['assessment_label'] = $context['assessment_label'];
                $row['period_label'] = $context['period_label'];
                $row['already_registered'] = student_exam_is_registered(
                    $db,
                    $studentId,
                    (string)$row['course_code'],
                    $semester,
                    $yearOfStudy,
                    $academicYear,
                    $context['assessment_period_type']
                ) ? 1 : 0;
                $courses[(string)$row['course_code']] = $row;
            }
            $stmt->close();
        }
    }

    if (student_exam_table_exists($db, 'short_courses') && student_exam_table_exists($db, 'short_course_enrollments')) {
        $sql = "SELECT sc.course_code,
                       sc.course_name,
                       0 AS credits,
                       sc.start_date,
                       sc.end_date,
                       sc.duration_value,
                       sc.duration_unit
                FROM short_course_enrollments sce
                INNER JOIN short_courses sc ON sc.id = sce.short_course_id
                WHERE sce.student_id COLLATE utf8mb4_general_ci = ?
                  AND COALESCE(sce.status, 'enrolled') IN ('enrolled','active','completed')
                  AND COALESCE(sc.status, 'active') IN ('active','upcoming')
                ORDER BY sc.course_code";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $code = (string)$row['course_code'];
                $start = !empty($row['start_date']) ? strtotime((string)$row['start_date']) : false;
                $end = !empty($row['end_date']) ? strtotime((string)$row['end_date']) : false;
                if (!$start || !$end || $end < $start) {
                    continue;
                }
                $context = student_exam_assessment_context($db, $studentId, $semester, $yearOfStudy, $code);
                $row['semester'] = $semester;
                $row['Year'] = $yearOfStudy;
                $row['programme_type'] = $context['programme_type'];
                $row['assessment_period_type'] = $context['assessment_period_type'];
                $row['assessment_label'] = $context['assessment_label'];
                $row['period_label'] = $context['period_label'];
                $row['already_registered'] = student_exam_is_registered(
                    $db,
                    $studentId,
                    $code,
                    $semester,
                    $yearOfStudy,
                    $academicYear,
                    $context['assessment_period_type']
                ) ? 1 : 0;
                $courses[$code] = $row;
            }
            $stmt->close();
        }
    }

    return array_values($courses);
}

/**
 * Get upcoming exams for a student
 * 
 * @param mysqli $db Database connection
 * @param string $studentId Student ID
 * @return array List of upcoming exams
 */
function getUpcomingExams($db, $studentId) {
    if (!student_exam_table_exists($db, 'exam_schedule') || !student_exam_table_exists($db, 'exam_registration')) {
        return [];
    }

    $sidEsc = $db->real_escape_string($studentId);
    
    $sql = "SELECT es.*, c.course_name, cl.room_code, cl.room_name, cl.building
            FROM exam_schedule es
            INNER JOIN exam_registration er ON er.course_code = es.course_code 
                AND er.semester = es.semester 
                AND er.Year = es.academic_year
            LEFT JOIN courses c ON c.course_code = es.course_code
            LEFT JOIN classrooms cl ON cl.id = es.classroom_id
            WHERE er.Sid = '$sidEsc'
            AND es.exam_date >= CURDATE()
            AND es.status = 'scheduled'
            ORDER BY es.exam_date, es.start_time
            LIMIT 10";
    
    $result = $db->query($sql);
    $exams = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $exams[] = $row;
        }
        $result->free();
    }
    
    return $exams;
}

/**
 * Get student's exam registration history
 * 
 * @param mysqli $db Database connection
 * @param string $studentId Student ID
 * @return array List of registered exams
 */
function getExamRegistrationHistory($db, $studentId) {
    if (!student_exam_table_exists($db, 'exam_registration')) {
        return [];
    }

    $sidEsc = $db->real_escape_string($studentId);
    $scheduleJoin = '';
    $scheduleSelect = 'NULL AS exam_date, NULL AS start_time, NULL AS end_time, NULL AS room_code';
    if (student_exam_table_exists($db, 'exam_schedule')) {
        $classroomJoin = student_exam_table_exists($db, 'classrooms')
            ? 'LEFT JOIN classrooms cl ON cl.id = es.classroom_id'
            : '';
        $roomExpr = $classroomJoin !== '' ? 'cl.room_code' : 'NULL';
        $scheduleJoin = "LEFT JOIN exam_schedule es ON es.course_code = er.course_code 
                AND es.semester = er.semester 
                AND es.academic_year = er.Year
            {$classroomJoin}";
        $scheduleSelect = "es.exam_date, es.start_time, es.end_time, {$roomExpr} AS room_code";
    }
    
    $sql = "SELECT er.*, c.course_name, 
                   {$scheduleSelect}
            FROM exam_registration er
            LEFT JOIN courses c ON c.course_code = er.course_code
            {$scheduleJoin}
            WHERE er.Sid = '$sidEsc'
            ORDER BY er.created_at DESC
            LIMIT 20";
    
    $result = $db->query($sql);
    $registrations = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $registrations[] = $row;
        }
        $result->free();
    }
    
    return $registrations;
}
