<?php

if (!function_exists('cg_h')) {
    function cg_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('cg_current_staff_id')) {
    function cg_current_staff_id(): string
    {
        return (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'system');
    }
}

if (!function_exists('cg_bind_params')) {
    function cg_bind_params(mysqli_stmt $stmt, string $types, array &$params): void
    {
        if ($types === '') {
            return;
        }

        $refs = [$types];
        foreach ($params as $key => &$value) {
            $refs[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('cg_execute')) {
    function cg_execute(mysqli $db, string $sql, string $types = '', array $params = []): mysqli_stmt
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Database prepare failed: ' . $db->error);
        }

        cg_bind_params($stmt, $types, $params);
        $stmt->execute();
        return $stmt;
    }
}

if (!function_exists('cg_fetch_all')) {
    function cg_fetch_all(mysqli $db, string $sql, string $types = '', array $params = []): array
    {
        $stmt = cg_execute($db, $sql, $types, $params);
        $result = $stmt->get_result();
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('cg_fetch_one')) {
    function cg_fetch_one(mysqli $db, string $sql, string $types = '', array $params = []): ?array
    {
        $rows = cg_fetch_all($db, $sql, $types, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('cg_scalar')) {
    function cg_scalar(mysqli $db, string $sql, string $types = '', array $params = []): int
    {
        $row = cg_fetch_one($db, $sql, $types, $params);
        if (!$row) {
            return 0;
        }
        return (int)array_values($row)[0];
    }
}

if (!function_exists('cg_table_exists')) {
    function cg_table_exists(mysqli $db, string $table): bool
    {
        $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        if ($safeTable === '') {
            return false;
        }

        $safeLike = $db->real_escape_string($safeTable);
        $result = $db->query("SHOW TABLES LIKE '{$safeLike}'");
        if (!$result) {
            return false;
        }
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
}

if (!function_exists('cg_normalize_datetime')) {
    function cg_normalize_datetime(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $value = str_replace('T', ' ', $value);
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}

if (!function_exists('cg_normalize_date')) {
    function cg_normalize_date(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }
}

if (!function_exists('cg_ensure_schema')) {
    function cg_ensure_schema(mysqli $db): void
    {
        $queries = [
            "CREATE TABLE IF NOT EXISTS city_guilds_learners (
                id INT AUTO_INCREMENT PRIMARY KEY,
                SID VARCHAR(50) NOT NULL,
                qualification_code VARCHAR(80) NOT NULL,
                cohort VARCHAR(80) NOT NULL,
                candidate_number VARCHAR(80) NOT NULL,
                progress_status VARCHAR(100) NOT NULL DEFAULT 'Enrolled',
                status ENUM('active','completed','withdrawn') NOT NULL DEFAULT 'active',
                notes TEXT NULL,
                created_by VARCHAR(64) NULL,
                updated_by VARCHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cg_candidate_number (candidate_number),
                UNIQUE KEY uq_cg_student_qualification_cohort (SID, qualification_code, cohort),
                KEY idx_cg_learners_sid (SID),
                KEY idx_cg_learners_cohort (cohort),
                KEY idx_cg_learners_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS city_guilds_units (
                id INT AUTO_INCREMENT PRIMARY KEY,
                qualification_code VARCHAR(80) NOT NULL,
                unit_code VARCHAR(80) NOT NULL,
                unit_title VARCHAR(190) NOT NULL,
                syllabus_reference VARCHAR(190) NULL,
                created_by VARCHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cg_unit (qualification_code, unit_code),
                KEY idx_cg_units_qualification (qualification_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS city_guilds_assignments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                learner_id INT NOT NULL,
                unit_id INT NULL,
                instructor_id VARCHAR(64) NOT NULL,
                schedule_start DATETIME NULL,
                schedule_end DATETIME NULL,
                location VARCHAR(150) NULL,
                assignment_status ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
                notes TEXT NULL,
                created_by VARCHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cg_assignments_learner (learner_id),
                KEY idx_cg_assignments_unit (unit_id),
                KEY idx_cg_assignments_instructor (instructor_id),
                KEY idx_cg_assignments_schedule (schedule_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS city_guilds_assessments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                learner_id INT NOT NULL,
                assignment_id INT NULL,
                title VARCHAR(190) NOT NULL,
                assessment_type ENUM('FORMATIVE','SUMMATIVE') NOT NULL,
                assessment_mode ENUM('practical','written','portfolio','other') NOT NULL DEFAULT 'other',
                date_assigned DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                date_due DATETIME NULL,
                grade VARCHAR(50) NULL,
                verifier_id VARCHAR(64) NULL,
                is_verified TINYINT(1) NOT NULL DEFAULT 0,
                verified_at DATETIME NULL,
                notes TEXT NULL,
                created_by VARCHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cg_assessments_learner (learner_id),
                KEY idx_cg_assessments_assignment (assignment_id),
                KEY idx_cg_assessments_due (date_due),
                KEY idx_cg_assessments_verified (is_verified)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS city_guilds_support_interventions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                learner_id INT NOT NULL,
                intervention_type VARCHAR(120) NOT NULL,
                support_date DATE NOT NULL,
                notes TEXT NOT NULL,
                follow_up_date DATE NULL,
                recorded_by VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_cg_support_learner (learner_id),
                KEY idx_cg_support_follow_up (follow_up_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS city_guilds_qa_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                record_type ENUM('tutor_qualification','assessment_plan','iqa_report','external_quality_assurance','other') NOT NULL,
                title VARCHAR(190) NOT NULL,
                staff_id VARCHAR(64) NULL,
                evidence_date DATE NULL,
                file_reference VARCHAR(255) NULL,
                notes TEXT NULL,
                created_by VARCHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_cg_qa_type (record_type),
                KEY idx_cg_qa_staff (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS city_guilds_audit (
                id INT AUTO_INCREMENT PRIMARY KEY,
                staff_id VARCHAR(64) NOT NULL,
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(80) NOT NULL,
                entity_id VARCHAR(80) NULL,
                details TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_cg_audit_staff (staff_id),
                KEY idx_cg_audit_entity (entity_type, entity_id),
                KEY idx_cg_audit_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        // Run as a best-effort guard: the DML-only app user cannot issue DDL, so
        // skip tables that already exist and never let a CREATE-denied error take
        // the page down. Schema is owned by the migrator (see /migrations).
        wuc_ensure_tables($db, $queries);
    }
}

if (!function_exists('cg_log_action')) {
    function cg_log_action(mysqli $db, string $action, string $entityType, ?string $entityId = null, array $details = []): void
    {
        $staffId = cg_current_staff_id();
        $payload = json_encode($details, JSON_UNESCAPED_SLASHES);

        try {
            cg_execute(
                $db,
                "INSERT INTO city_guilds_audit (staff_id, action, entity_type, entity_id, details, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                'sssss',
                [$staffId, $action, $entityType, $entityId, $payload]
            )->close();
        } catch (Throwable $e) {
            error_log('City & Guilds audit write failed: ' . $e->getMessage());
        }

        if (function_exists('audit_log_current_user')) {
            audit_log_current_user($db, 'city_guilds.' . $action, [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details,
            ]);
        }
    }
}

if (!function_exists('cg_update_learner_progress')) {
    function cg_update_learner_progress(mysqli $db, int $learnerId): void
    {
        $total = cg_scalar($db, 'SELECT COUNT(*) FROM city_guilds_assessments WHERE learner_id = ?', 'i', [$learnerId]);
        $verified = cg_scalar($db, 'SELECT COUNT(*) FROM city_guilds_assessments WHERE learner_id = ? AND is_verified = 1', 'i', [$learnerId]);

        if ($total === 0) {
            $status = 'Enrolled';
        } elseif ($verified >= $total) {
            $status = 'Completed';
        } else {
            $status = $verified . '/' . $total . ' completed';
        }

        $learnerStatus = $status === 'Completed' ? 'completed' : 'active';
        cg_execute(
            $db,
            'UPDATE city_guilds_learners SET progress_status = ?, status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            'sssi',
            [$status, $learnerStatus, cg_current_staff_id(), $learnerId]
        )->close();
    }
}

if (!function_exists('cg_allowed_types')) {
    function cg_allowed_types(string $group): array
    {
        $sets = [
            'assessment_type' => ['FORMATIVE', 'SUMMATIVE'],
            'assessment_mode' => ['practical', 'written', 'portfolio', 'other'],
            'qa_record_type' => ['tutor_qualification', 'assessment_plan', 'iqa_report', 'external_quality_assurance', 'other'],
        ];
        return $sets[$group] ?? [];
    }
}

if (!function_exists('cg_require_choice')) {
    function cg_require_choice(string $value, string $group): string
    {
        $value = trim($value);
        $allowed = cg_allowed_types($group);
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Invalid selection submitted.');
        }
        return $value;
    }
}

if (!function_exists('cg_enroll_learner')) {
    function cg_enroll_learner(mysqli $db, array $data): int
    {
        $sid = trim((string)($data['SID'] ?? ''));
        $qualificationCode = strtoupper(trim((string)($data['qualification_code'] ?? '')));
        $cohort = trim((string)($data['cohort'] ?? ''));
        $candidateNumber = trim((string)($data['candidate_number'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));

        if ($sid === '' || $qualificationCode === '' || $cohort === '' || $candidateNumber === '') {
            throw new InvalidArgumentException('Student ID, qualification code, cohort, and candidate number are required.');
        }

        $student = cg_fetch_one($db, 'SELECT SID FROM students WHERE SID = ? LIMIT 1', 's', [$sid]);
        if (!$student) {
            throw new InvalidArgumentException('The selected student does not exist in the student registry.');
        }

        $staffId = cg_current_staff_id();
        $stmt = cg_execute(
            $db,
            "INSERT INTO city_guilds_learners
                (SID, qualification_code, cohort, candidate_number, progress_status, status, notes, created_by, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'Enrolled', 'active', ?, ?, ?, NOW(), NOW())",
            'sssssss',
            [$sid, $qualificationCode, $cohort, $candidateNumber, $notes, $staffId, $staffId]
        );
        $learnerId = (int)$stmt->insert_id;
        $stmt->close();

        cg_log_action($db, 'enroll', 'learner', (string)$learnerId, [
            'SID' => $sid,
            'qualification_code' => $qualificationCode,
            'cohort' => $cohort,
            'candidate_number' => $candidateNumber,
        ]);

        return $learnerId;
    }
}

if (!function_exists('cg_save_unit')) {
    function cg_save_unit(mysqli $db, array $data): int
    {
        $qualificationCode = strtoupper(trim((string)($data['unit_qualification_code'] ?? '')));
        $unitCode = strtoupper(trim((string)($data['unit_code'] ?? '')));
        $unitTitle = trim((string)($data['unit_title'] ?? ''));
        $syllabusReference = trim((string)($data['syllabus_reference'] ?? ''));
        $staffId = cg_current_staff_id();

        if ($qualificationCode === '' || $unitCode === '' || $unitTitle === '') {
            throw new InvalidArgumentException('Qualification code, unit code, and unit title are required.');
        }

        cg_execute(
            $db,
            "INSERT INTO city_guilds_units
                (qualification_code, unit_code, unit_title, syllabus_reference, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                unit_title = VALUES(unit_title),
                syllabus_reference = VALUES(syllabus_reference),
                updated_at = NOW()",
            'sssss',
            [$qualificationCode, $unitCode, $unitTitle, $syllabusReference, $staffId]
        )->close();

        $row = cg_fetch_one(
            $db,
            'SELECT id FROM city_guilds_units WHERE qualification_code = ? AND unit_code = ? LIMIT 1',
            'ss',
            [$qualificationCode, $unitCode]
        );
        $unitId = (int)($row['id'] ?? 0);

        cg_log_action($db, 'save_unit', 'unit', (string)$unitId, [
            'qualification_code' => $qualificationCode,
            'unit_code' => $unitCode,
        ]);

        return $unitId;
    }
}

if (!function_exists('cg_assign_unit')) {
    function cg_assign_unit(mysqli $db, array $data): int
    {
        $learnerId = (int)($data['assignment_learner_id'] ?? 0);
        $unitId = (int)($data['assignment_unit_id'] ?? 0);
        $instructorId = trim((string)($data['instructor_id'] ?? ''));
        $scheduleStart = cg_normalize_datetime($data['schedule_start'] ?? '');
        $scheduleEnd = cg_normalize_datetime($data['schedule_end'] ?? '');
        $location = trim((string)($data['location'] ?? ''));
        $notes = trim((string)($data['assignment_notes'] ?? ''));

        if ($learnerId <= 0 || $unitId <= 0 || $instructorId === '') {
            throw new InvalidArgumentException('Learner, unit, and instructor are required for assignment.');
        }

        if (!cg_fetch_one($db, 'SELECT id FROM city_guilds_learners WHERE id = ? LIMIT 1', 'i', [$learnerId])) {
            throw new InvalidArgumentException('City & Guilds learner was not found.');
        }
        if (!cg_fetch_one($db, 'SELECT id FROM city_guilds_units WHERE id = ? LIMIT 1', 'i', [$unitId])) {
            throw new InvalidArgumentException('City & Guilds unit was not found.');
        }

        $stmt = cg_execute(
            $db,
            "INSERT INTO city_guilds_assignments
                (learner_id, unit_id, instructor_id, schedule_start, schedule_end, location, assignment_status, notes, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, NOW(), NOW())",
            'iissssss',
            [$learnerId, $unitId, $instructorId, $scheduleStart, $scheduleEnd, $location, $notes, cg_current_staff_id()]
        );
        $assignmentId = (int)$stmt->insert_id;
        $stmt->close();

        cg_log_action($db, 'assign_unit', 'assignment', (string)$assignmentId, [
            'learner_id' => $learnerId,
            'unit_id' => $unitId,
            'instructor_id' => $instructorId,
        ]);

        return $assignmentId;
    }
}

if (!function_exists('cg_record_assessment')) {
    function cg_record_assessment(mysqli $db, array $data): int
    {
        $learnerId = (int)($data['assessment_learner_id'] ?? 0);
        $assignmentId = (int)($data['assessment_assignment_id'] ?? 0);
        $assignmentIdValue = $assignmentId > 0 ? $assignmentId : null;
        $title = trim((string)($data['assessment_title'] ?? ''));
        $type = cg_require_choice(strtoupper((string)($data['assessment_type'] ?? '')), 'assessment_type');
        $mode = cg_require_choice(strtolower((string)($data['assessment_mode'] ?? '')), 'assessment_mode');
        $dateDue = cg_normalize_datetime($data['date_due'] ?? '');
        $notes = trim((string)($data['assessment_notes'] ?? ''));

        if ($learnerId <= 0 || $title === '') {
            throw new InvalidArgumentException('Learner and assessment title are required.');
        }

        if (!cg_fetch_one($db, 'SELECT id FROM city_guilds_learners WHERE id = ? LIMIT 1', 'i', [$learnerId])) {
            throw new InvalidArgumentException('City & Guilds learner was not found.');
        }

        $stmt = cg_execute(
            $db,
            "INSERT INTO city_guilds_assessments
                (learner_id, assignment_id, title, assessment_type, assessment_mode, date_assigned, date_due, notes, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW(), NOW())",
            'iissssss',
            [$learnerId, $assignmentIdValue, $title, $type, $mode, $dateDue, $notes, cg_current_staff_id()]
        );
        $assessmentId = (int)$stmt->insert_id;
        $stmt->close();

        cg_update_learner_progress($db, $learnerId);
        cg_log_action($db, 'record_assessment', 'assessment', (string)$assessmentId, [
            'learner_id' => $learnerId,
            'type' => $type,
            'mode' => $mode,
        ]);

        return $assessmentId;
    }
}

if (!function_exists('cg_verify_assessment')) {
    function cg_verify_assessment(mysqli $db, array $data): void
    {
        $assessmentId = (int)($data['assessment_id'] ?? 0);
        $grade = trim((string)($data['grade'] ?? ''));
        $verifierId = trim((string)($data['verifier_id'] ?? ''));
        $notes = trim((string)($data['verification_notes'] ?? ''));

        if ($assessmentId <= 0 || $grade === '') {
            throw new InvalidArgumentException('Assessment and grade are required for verification.');
        }
        if ($verifierId === '') {
            $verifierId = cg_current_staff_id();
        }

        $assessment = cg_fetch_one(
            $db,
            'SELECT id, learner_id, is_verified FROM city_guilds_assessments WHERE id = ? LIMIT 1',
            'i',
            [$assessmentId]
        );
        if (!$assessment) {
            throw new InvalidArgumentException('Assessment was not found.');
        }
        if ((int)$assessment['is_verified'] === 1) {
            throw new InvalidArgumentException('Assessment is already verified.');
        }

        cg_execute(
            $db,
            "UPDATE city_guilds_assessments
             SET grade = ?, verifier_id = ?, is_verified = 1, verified_at = NOW(),
                 notes = CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' OR ? = '' THEN '' ELSE '\n' END, ?),
                 updated_at = NOW()
             WHERE id = ?",
            'ssssi',
            [$grade, $verifierId, $notes, $notes, $assessmentId]
        )->close();

        cg_update_learner_progress($db, (int)$assessment['learner_id']);
        cg_log_action($db, 'verify_assessment', 'assessment', (string)$assessmentId, [
            'learner_id' => (int)$assessment['learner_id'],
            'grade' => $grade,
            'verifier_id' => $verifierId,
        ]);
    }
}

if (!function_exists('cg_record_support')) {
    function cg_record_support(mysqli $db, array $data): int
    {
        $learnerId = (int)($data['support_learner_id'] ?? 0);
        $type = trim((string)($data['intervention_type'] ?? ''));
        $supportDate = cg_normalize_date($data['support_date'] ?? '') ?? date('Y-m-d');
        $followUpDate = cg_normalize_date($data['follow_up_date'] ?? '');
        $notes = trim((string)($data['support_notes'] ?? ''));

        if ($learnerId <= 0 || $type === '' || $notes === '') {
            throw new InvalidArgumentException('Learner, support type, and notes are required.');
        }

        $stmt = cg_execute(
            $db,
            "INSERT INTO city_guilds_support_interventions
                (learner_id, intervention_type, support_date, notes, follow_up_date, recorded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            'isssss',
            [$learnerId, $type, $supportDate, $notes, $followUpDate, cg_current_staff_id()]
        );
        $supportId = (int)$stmt->insert_id;
        $stmt->close();

        cg_log_action($db, 'record_support', 'support_intervention', (string)$supportId, [
            'learner_id' => $learnerId,
            'intervention_type' => $type,
            'follow_up_date' => $followUpDate,
        ]);

        return $supportId;
    }
}

if (!function_exists('cg_save_qa_record')) {
    function cg_save_qa_record(mysqli $db, array $data): int
    {
        $recordType = cg_require_choice((string)($data['qa_record_type'] ?? ''), 'qa_record_type');
        $title = trim((string)($data['qa_title'] ?? ''));
        $staffId = trim((string)($data['qa_staff_id'] ?? ''));
        $evidenceDate = cg_normalize_date($data['evidence_date'] ?? '');
        $fileReference = trim((string)($data['file_reference'] ?? ''));
        $notes = trim((string)($data['qa_notes'] ?? ''));

        if ($title === '') {
            throw new InvalidArgumentException('QA record title is required.');
        }

        $stmt = cg_execute(
            $db,
            "INSERT INTO city_guilds_qa_records
                (record_type, title, staff_id, evidence_date, file_reference, notes, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            'sssssss',
            [$recordType, $title, $staffId, $evidenceDate, $fileReference, $notes, cg_current_staff_id()]
        );
        $qaId = (int)$stmt->insert_id;
        $stmt->close();

        cg_log_action($db, 'save_qa_record', 'qa_record', (string)$qaId, [
            'record_type' => $recordType,
            'title' => $title,
            'staff_id' => $staffId,
        ]);

        return $qaId;
    }
}

if (!function_exists('cg_dashboard_counts')) {
    function cg_dashboard_counts(mysqli $db): array
    {
        return [
            'learners' => cg_scalar($db, 'SELECT COUNT(*) FROM city_guilds_learners'),
            'active' => cg_scalar($db, "SELECT COUNT(*) FROM city_guilds_learners WHERE status = 'active'"),
            'completed' => cg_scalar($db, "SELECT COUNT(*) FROM city_guilds_learners WHERE status = 'completed'"),
            'pending_verification' => cg_scalar($db, 'SELECT COUNT(*) FROM city_guilds_assessments WHERE is_verified = 0'),
            'support_followups' => cg_scalar($db, 'SELECT COUNT(*) FROM city_guilds_support_interventions WHERE follow_up_date IS NOT NULL AND follow_up_date >= CURDATE()'),
            'qa_records' => cg_scalar($db, 'SELECT COUNT(*) FROM city_guilds_qa_records'),
        ];
    }
}

if (!function_exists('cg_learners')) {
    function cg_learners(mysqli $db): array
    {
        return cg_fetch_all(
            $db,
            "SELECT l.*,
                    s.Fname,
                    s.Lname,
                    s.email,
                    s.mobile,
                    COALESCE(ac.assessments_total, 0) AS assessments_total,
                    COALESCE(ac.assessments_verified, 0) AS assessments_verified
             FROM city_guilds_learners l
             INNER JOIN students s ON s.SID = l.SID COLLATE utf8mb4_unicode_ci
             LEFT JOIN (
                SELECT learner_id,
                       COUNT(*) AS assessments_total,
                       SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) AS assessments_verified
                FROM city_guilds_assessments
                GROUP BY learner_id
             ) ac ON ac.learner_id = l.id
             ORDER BY l.updated_at DESC, l.id DESC"
        );
    }
}

if (!function_exists('cg_students_for_select')) {
    function cg_students_for_select(mysqli $db): array
    {
        return cg_fetch_all(
            $db,
            "SELECT SID, Fname, Lname
             FROM students
             ORDER BY Fname ASC, Lname ASC, SID ASC
             LIMIT 1000"
        );
    }
}

if (!function_exists('cg_staff_for_select')) {
    function cg_staff_for_select(mysqli $db): array
    {
        return cg_fetch_all(
            $db,
            "SELECT staff_id, title, Fname, Lname, qualification
             FROM staff
             ORDER BY Fname ASC, Lname ASC, staff_id ASC
             LIMIT 1000"
        );
    }
}

if (!function_exists('cg_units')) {
    function cg_units(mysqli $db): array
    {
        return cg_fetch_all(
            $db,
            'SELECT * FROM city_guilds_units ORDER BY qualification_code ASC, unit_code ASC'
        );
    }
}

if (!function_exists('cg_assignments')) {
    function cg_assignments(mysqli $db): array
    {
        return cg_fetch_all(
            $db,
            "SELECT a.id,
                    a.learner_id,
                    a.unit_id,
                    a.instructor_id,
                    a.schedule_start,
                    a.schedule_end,
                    u.unit_code,
                    u.unit_title,
                    l.candidate_number,
                    s.SID,
                    s.Fname,
                    s.Lname
             FROM city_guilds_assignments a
             INNER JOIN city_guilds_learners l ON l.id = a.learner_id
             INNER JOIN students s ON s.SID = l.SID COLLATE utf8mb4_unicode_ci
             LEFT JOIN city_guilds_units u ON u.id = a.unit_id
             ORDER BY COALESCE(a.schedule_start, a.created_at) DESC, a.id DESC
             LIMIT 500"
        );
    }
}

if (!function_exists('cg_pending_assessments')) {
    function cg_pending_assessments(mysqli $db): array
    {
        return cg_fetch_all(
            $db,
            "SELECT a.*,
                    l.candidate_number,
                    l.qualification_code,
                    s.SID,
                    s.Fname,
                    s.Lname,
                    u.unit_code,
                    u.unit_title
             FROM city_guilds_assessments a
             INNER JOIN city_guilds_learners l ON l.id = a.learner_id
             INNER JOIN students s ON s.SID = l.SID COLLATE utf8mb4_unicode_ci
             LEFT JOIN city_guilds_assignments ca ON ca.id = a.assignment_id
             LEFT JOIN city_guilds_units u ON u.id = ca.unit_id
             WHERE a.is_verified = 0
             ORDER BY a.date_due ASC, a.id DESC
             LIMIT 100"
        );
    }
}

if (!function_exists('cg_recent_qa_records')) {
    function cg_recent_qa_records(mysqli $db): array
    {
        return cg_fetch_all(
            $db,
            "SELECT q.*,
                    s.Fname,
                    s.Lname
             FROM city_guilds_qa_records q
             LEFT JOIN staff s ON s.staff_id = q.staff_id COLLATE utf8mb4_unicode_ci
             ORDER BY q.created_at DESC
             LIMIT 50"
        );
    }
}

if (!function_exists('cg_cohorts')) {
    function cg_cohorts(mysqli $db): array
    {
        return cg_fetch_all(
            $db,
            'SELECT DISTINCT cohort FROM city_guilds_learners WHERE cohort <> "" ORDER BY cohort DESC'
        );
    }
}

if (!function_exists('cg_student_has_access')) {
    function cg_student_has_access(mysqli $db, string $sid): bool
    {
        $sid = trim($sid);
        if ($sid === '' || !cg_table_exists($db, 'city_guilds_learners')) {
            return false;
        }

        return cg_scalar(
            $db,
            'SELECT COUNT(*) FROM city_guilds_learners WHERE SID COLLATE utf8mb4_unicode_ci = ?',
            's',
            [$sid]
        ) > 0;
    }
}

if (!function_exists('cg_student_learners')) {
    function cg_student_learners(mysqli $db, string $sid): array
    {
        $sid = trim($sid);
        if ($sid === '' || !cg_table_exists($db, 'city_guilds_learners')) {
            return [];
        }

        return cg_fetch_all(
            $db,
            "SELECT l.*,
                    COALESCE(ac.assessments_total, 0) AS assessments_total,
                    COALESCE(ac.assessments_verified, 0) AS assessments_verified,
                    COALESCE(sc.units_total, 0) AS units_total,
                    sc.next_session
             FROM city_guilds_learners l
             LEFT JOIN (
                SELECT learner_id,
                       COUNT(*) AS assessments_total,
                       SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) AS assessments_verified
                FROM city_guilds_assessments
                GROUP BY learner_id
             ) ac ON ac.learner_id = l.id
             LEFT JOIN (
                SELECT learner_id,
                       COUNT(DISTINCT unit_id) AS units_total,
                       MIN(CASE WHEN schedule_start >= NOW() THEN schedule_start ELSE NULL END) AS next_session
                FROM city_guilds_assignments
                GROUP BY learner_id
             ) sc ON sc.learner_id = l.id
             WHERE l.SID COLLATE utf8mb4_unicode_ci = ?
             ORDER BY l.updated_at DESC, l.id DESC",
            's',
            [$sid]
        );
    }
}

if (!function_exists('cg_student_assignments')) {
    function cg_student_assignments(mysqli $db, string $sid): array
    {
        $sid = trim($sid);
        if ($sid === '' || !cg_table_exists($db, 'city_guilds_assignments')) {
            return [];
        }

        return cg_fetch_all(
            $db,
            "SELECT a.*,
                    l.candidate_number,
                    l.qualification_code,
                    u.unit_code,
                    u.unit_title,
                    u.syllabus_reference,
                    s.title AS staff_title,
                    s.Fname AS staff_fname,
                    s.Lname AS staff_lname
             FROM city_guilds_assignments a
             INNER JOIN city_guilds_learners l ON l.id = a.learner_id
             LEFT JOIN city_guilds_units u ON u.id = a.unit_id
             LEFT JOIN staff s ON s.staff_id = a.instructor_id COLLATE utf8mb4_unicode_ci
             WHERE l.SID COLLATE utf8mb4_unicode_ci = ?
             ORDER BY COALESCE(a.schedule_start, a.created_at) DESC, a.id DESC",
            's',
            [$sid]
        );
    }
}

if (!function_exists('cg_student_assessments')) {
    function cg_student_assessments(mysqli $db, string $sid): array
    {
        $sid = trim($sid);
        if ($sid === '' || !cg_table_exists($db, 'city_guilds_assessments')) {
            return [];
        }

        return cg_fetch_all(
            $db,
            "SELECT a.*,
                    l.candidate_number,
                    l.qualification_code,
                    u.unit_code,
                    u.unit_title
             FROM city_guilds_assessments a
             INNER JOIN city_guilds_learners l ON l.id = a.learner_id
             LEFT JOIN city_guilds_assignments ca ON ca.id = a.assignment_id
             LEFT JOIN city_guilds_units u ON u.id = ca.unit_id
             WHERE l.SID COLLATE utf8mb4_unicode_ci = ?
             ORDER BY COALESCE(a.date_due, a.date_assigned) DESC, a.id DESC",
            's',
            [$sid]
        );
    }
}

if (!function_exists('cg_student_support_interventions')) {
    function cg_student_support_interventions(mysqli $db, string $sid): array
    {
        $sid = trim($sid);
        if ($sid === '' || !cg_table_exists($db, 'city_guilds_support_interventions')) {
            return [];
        }

        return cg_fetch_all(
            $db,
            "SELECT si.*,
                    l.candidate_number,
                    l.qualification_code
             FROM city_guilds_support_interventions si
             INNER JOIN city_guilds_learners l ON l.id = si.learner_id
             WHERE l.SID COLLATE utf8mb4_unicode_ci = ?
             ORDER BY si.support_date DESC, si.id DESC
             LIMIT 100",
            's',
            [$sid]
        );
    }
}

if (!function_exists('cg_export_rows')) {
    function cg_export_rows(mysqli $db, string $exportType, string $cohort = ''): array
    {
        $where = '';
        $types = '';
        $params = [];
        if ($cohort !== '') {
            $where = ' WHERE l.cohort = ?';
            $types = 's';
            $params = [$cohort];
        }

        if ($exportType === 'exam_entry') {
            return cg_fetch_all(
                $db,
                "SELECT l.candidate_number AS CandidateNumber,
                        l.qualification_code AS QualificationCode,
                        l.cohort AS Cohort,
                        s.SID AS StudentID,
                        s.Fname AS FirstName,
                        s.Lname AS LastName,
                        COALESCE(u.unit_code, '') AS UnitCode,
                        COALESCE(u.unit_title, '') AS UnitTitle,
                        a.title AS AssessmentTitle,
                        a.assessment_type AS AssessmentType,
                        a.assessment_mode AS AssessmentMode,
                        DATE(a.date_due) AS DueDate
                 FROM city_guilds_assessments a
                 INNER JOIN city_guilds_learners l ON l.id = a.learner_id
                 INNER JOIN students s ON s.SID = l.SID COLLATE utf8mb4_unicode_ci
                 LEFT JOIN city_guilds_assignments ca ON ca.id = a.assignment_id
                 LEFT JOIN city_guilds_units u ON u.id = ca.unit_id" . $where . "
                 ORDER BY l.cohort, l.candidate_number, a.date_due",
                $types,
                $params
            );
        }

        if ($exportType === 'certificate_claim') {
            $claimWhere = $where === '' ? ' WHERE a.is_verified = 1' : $where . ' AND a.is_verified = 1';
            return cg_fetch_all(
                $db,
                "SELECT l.candidate_number AS CandidateNumber,
                        l.qualification_code AS QualificationCode,
                        l.cohort AS Cohort,
                        s.SID AS StudentID,
                        s.Fname AS FirstName,
                        s.Lname AS LastName,
                        COALESCE(u.unit_code, '') AS UnitCode,
                        COALESCE(u.unit_title, '') AS UnitTitle,
                        a.title AS AssessmentTitle,
                        a.grade AS Grade,
                        DATE(a.verified_at) AS VerifiedDate,
                        a.verifier_id AS VerifierID,
                        CASE WHEN l.status = 'completed' THEN 'Ready' ELSE 'In Progress' END AS ClaimStatus
                 FROM city_guilds_assessments a
                 INNER JOIN city_guilds_learners l ON l.id = a.learner_id
                 INNER JOIN students s ON s.SID = l.SID COLLATE utf8mb4_unicode_ci
                 LEFT JOIN city_guilds_assignments ca ON ca.id = a.assignment_id
                 LEFT JOIN city_guilds_units u ON u.id = ca.unit_id" . $claimWhere . "
                 ORDER BY l.cohort, l.candidate_number, u.unit_code",
                $types,
                $params
            );
        }

        return cg_fetch_all(
            $db,
            "SELECT l.candidate_number AS CandidateNumber,
                    l.qualification_code AS QualificationCode,
                    l.cohort AS Cohort,
                    s.SID AS StudentID,
                    s.Fname AS FirstName,
                    s.Lname AS LastName,
                    s.dob AS DateOfBirth,
                    s.sex AS Gender,
                    s.email AS Email,
                    s.mobile AS Mobile,
                    l.progress_status AS Progress,
                    l.status AS Status
             FROM city_guilds_learners l
             INNER JOIN students s ON s.SID = l.SID COLLATE utf8mb4_unicode_ci" . $where . "
             ORDER BY l.cohort, l.candidate_number",
            $types,
            $params
        );
    }
}

if (!function_exists('cg_stream_csv_export')) {
    function cg_stream_csv_export(mysqli $db, string $exportType, string $cohort = ''): void
    {
        $allowed = ['registration', 'exam_entry', 'certificate_claim'];
        if (!in_array($exportType, $allowed, true)) {
            $exportType = 'registration';
        }

        $rows = cg_export_rows($db, $exportType, $cohort);
        $filename = 'city_guilds_' . $exportType . '_' . ($cohort !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '_', $cohort) . '_' : '') . date('Ymd_His') . '.csv';

        cg_log_action($db, 'export_' . $exportType, 'export', null, [
            'cohort' => $cohort,
            'rows' => count($rows),
        ]);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
        } else {
            fputcsv($out, ['No records found']);
        }
        fclose($out);
        exit;
    }
}
