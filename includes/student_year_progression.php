<?php
declare(strict_types=1);

/**
 * Audited academic-year progression for programmes with external examinations.
 * Internal CA is supporting evidence; the authorized HOS/admin decision and its
 * external-results/board reference are the authority for progression.
 */

require_once __DIR__ . '/cse_progression.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/helpers/progression_helpers.php';

if (!function_exists('wuc_progression_ca_evidence')) {
    /**
     * @return array{required:int,recorded:int,approved:int,average_ca:?float,missing:list<string>,courses:list<array<string,mixed>>}
     */
    function wuc_progression_ca_evidence(mysqli $db, string $sid, string $programCode, int $yearOfStudy): array
    {
        $required = [];
        $stmt = $db->prepare(
            'SELECT pc.course_code, c.course_name
               FROM program_courses pc
               INNER JOIN courses c ON c.course_code = pc.course_code
              WHERE pc.program_code = ? AND pc.year = ? AND COALESCE(pc.is_required, 1) = 1
              ORDER BY pc.course_code'
        );
        $stmt->bind_param('si', $programCode, $yearOfStudy);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $required[(string)$row['course_code']] = (string)$row['course_name'];
        }
        $stmt->close();

        $results = [];
        $stmt = $db->prepare(
            "SELECT sa.Course_Code, sa.Total_CA, sa.status,
                    sa.internal_moderation_status, sa.approved_at, sa.published_at
               FROM semester_assessment sa
               INNER JOIN (
                    SELECT Course_Code, MAX(id) AS latest_id
                      FROM semester_assessment
                     WHERE Sid = ?
                     GROUP BY Course_Code
               ) latest ON latest.latest_id = sa.id"
        );
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $code = trim((string)$row['Course_Code']);
            if (isset($required[$code])) {
                $results[$code] = $row;
            }
        }
        $stmt->close();

        $courses = [];
        $missing = [];
        $approved = 0;
        $caMarks = [];
        foreach ($required as $courseCode => $courseName) {
            $row = $results[$courseCode] ?? null;
            if (!$row) {
                $missing[] = $courseCode;
                $courses[] = [
                    'course_code' => $courseCode,
                    'course_name' => $courseName,
                    'ca_mark' => null,
                    'status' => 'Missing',
                    'moderation_status' => 'pending',
                ];
                continue;
            }

            $mark = $row['Total_CA'] !== null && $row['Total_CA'] !== '' ? (float)$row['Total_CA'] : null;
            if ($mark !== null) {
                $caMarks[] = $mark;
            }
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $moderation = strtolower(trim((string)($row['internal_moderation_status'] ?? 'pending')));
            if (in_array($status, ['approved', 'published'], true) || $moderation === 'approved') {
                $approved++;
            }
            $courses[] = [
                'course_code' => $courseCode,
                'course_name' => $courseName,
                'ca_mark' => $mark,
                'status' => $row['status'] ?: 'Pending',
                'moderation_status' => $moderation ?: 'pending',
            ];
        }

        return [
            'required' => count($required),
            'recorded' => count($results),
            'approved' => $approved,
            'average_ca' => $caMarks !== [] ? round(array_sum($caMarks) / count($caMarks), 2) : null,
            'missing' => $missing,
            'courses' => $courses,
        ];
    }
}

if (!function_exists('wuc_progression_program_max_year')) {
    /**
     * Prefer curriculum span when program_duration understates staged years
     * (common when certificates carry Year-1/Year-2 module maps but duration=1).
     */
    function wuc_progression_program_max_year(mysqli $db, string $programCode, float $duration): int
    {
        static $cache = [];
        $programCode = strtoupper(trim($programCode));
        $cacheKey = $programCode . '|' . number_format($duration, 2, '.', '');
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $maxYear = max(1, (int)ceil($duration));
        $stmt = $db->prepare('SELECT MAX(year) AS max_year FROM program_courses WHERE program_code = ?');
        if ($stmt) {
            $stmt->bind_param('s', $programCode);
            $stmt->execute();
            $curriculumMax = (int)($stmt->get_result()->fetch_assoc()['max_year'] ?? 0);
            $stmt->close();
            if ($curriculumMax > $maxYear) {
                $maxYear = $curriculumMax;
            }
        }

        return $cache[$cacheKey] = $maxYear;
    }
}

if (!function_exists('wuc_progression_target')) {
    /** @return array{ok:bool,program_code:string,year:int,message:string} */
    function wuc_progression_target(string $programCode, int $currentYear, float $duration, ?int $maxYear = null): array
    {
        $programCode = strtoupper(trim($programCode));
        if ($programCode === WUC_CSE_CERTIFICATE_PROGRAM && $currentYear === 1) {
            return ['ok' => true, 'program_code' => WUC_CSE_DIPLOMA_PROGRAM, 'year' => 2, 'message' => 'Diploma Year 2'];
        }
        $resolvedMax = $maxYear !== null ? max(1, $maxYear) : max(1, (int)ceil($duration));
        if ($currentYear >= $resolvedMax) {
            return ['ok' => false, 'program_code' => $programCode, 'year' => $currentYear, 'message' => 'Student is already in the final academic year.'];
        }
        return [
            'ok' => true,
            'program_code' => $programCode,
            'year' => $currentYear + 1,
            'message' => 'Year ' . ($currentYear + 1),
        ];
    }
}

if (!function_exists('wuc_progression_evaluate_all')) {
    /**
     * Schema-safe clearance evaluation for every active student.
     * Uses courses.credits (not the phantom credit_hours) and published exams.
     *
     * @return list<array{SID:string,name:string,program_code:string,gpa:float,earned_credits:int,fails:int,eligible:bool,modules:int,awaiting_exam:int,reason:string}>
     */
    function wuc_progression_evaluate_all(
        mysqli $db,
        float $minGpa = 2.0,
        int $minCredits = 12,
        int $maxFails = 2
    ): array {
        require_once __DIR__ . '/grading_helpers.php';

        $studentsSql = "SELECT s.SID, s.Fname, s.Lname,
                               COALESCE(sp.program_code, NULLIF(s.program, ''), 'N/A') AS program_code,
                               CASE
                                 WHEN sp.program_code IS NULL AND NULLIF(s.program, '') IS NOT NULL THEN 1
                                 WHEN sp.program_code IS NOT NULL AND p.program_code IS NULL THEN 1
                                 ELSE 0
                               END AS enrolment_issue
                          FROM students s
                          LEFT JOIN (
                                SELECT sp1.Sid, sp1.program_code
                                  FROM student_program sp1
                                  INNER JOIN (
                                        SELECT Sid, MAX(id) AS max_id
                                          FROM student_program
                                         WHERE LOWER(COALESCE(status, 'active')) = 'active'
                                         GROUP BY Sid
                                  ) latest ON latest.max_id = sp1.id
                          ) sp ON sp.Sid = s.SID
                          LEFT JOIN programs p ON p.program_code = sp.program_code
                         WHERE COALESCE(s.status, 'active') NOT IN ('inactive', 'deleted')
                         ORDER BY s.Lname, s.Fname, s.SID";
        $studentsRes = $db->query($studentsSql);
        if (!$studentsRes) {
            throw new RuntimeException('Unable to load students for progression evaluation.');
        }

        $examSql = "SELECT e.Course_Code, e.Exam_marks,
                           COALESCE(NULLIF(c.credits, 0), 3) AS credit_hours,
                           COALESCE((
                                SELECT sa.Total_CA
                                  FROM semester_assessment sa
                                 WHERE sa.Sid = e.Sid
                                   AND sa.Course_Code = e.Course_Code
                                   AND sa.Year = e.Year
                                   AND sa.semester = e.semester
                                 ORDER BY sa.id DESC
                                 LIMIT 1
                           ), 0) AS Total_CA
                      FROM exams e
                      INNER JOIN (
                            SELECT Course_Code, MAX(id) AS latest_id
                              FROM exams
                             WHERE Sid = ?
                               AND LOWER(COALESCE(status, '')) = 'published'
                               AND Exam_marks IS NOT NULL
                             GROUP BY Course_Code
                      ) latest ON latest.latest_id = e.id
                      LEFT JOIN courses c ON c.course_code = e.Course_Code";
        $examStmt = $db->prepare($examSql);
        if (!$examStmt) {
            throw new RuntimeException('Unable to prepare progression exam query.');
        }

        $awaitingSql = "SELECT COUNT(*) AS awaiting_exam
                          FROM exams e
                          INNER JOIN (
                                SELECT Course_Code, MAX(id) AS latest_id
                                  FROM exams
                                 WHERE Sid = ?
                                   AND LOWER(COALESCE(status, '')) = 'published'
                                 GROUP BY Course_Code
                          ) latest ON latest.latest_id = e.id
                         WHERE e.Exam_marks IS NULL";
        $awaitingStmt = $db->prepare($awaitingSql);
        if (!$awaitingStmt) {
            throw new RuntimeException('Unable to prepare awaiting-exam query.');
        }

        $results = [];
        while ($student = $studentsRes->fetch_assoc()) {
            $sid = (string)$student['SID'];
            $examStmt->bind_param('s', $sid);
            $examStmt->execute();
            $exams = $examStmt->get_result();

            $totalPoints = 0.0;
            $totalCredits = 0;
            $failsCount = 0;
            $earnedCredits = 0;
            $modules = 0;

            while ($exam = $exams->fetch_assoc()) {
                $modules++;
                $ca = (float)$exam['Total_CA'];
                $examMark = (float)$exam['Exam_marks'];
                $credits = max(1, (int)$exam['credit_hours']);
                $computed = wuc_result_compute($db, $sid, $ca, $examMark);
                $totalPoints += ((float)$computed['points']) * $credits;
                $totalCredits += $credits;
                if ($computed['grade'] === 'F') {
                    $failsCount++;
                } else {
                    $earnedCredits += $credits;
                }
            }

            $awaitingStmt->bind_param('s', $sid);
            $awaitingStmt->execute();
            $awaitingExam = (int)($awaitingStmt->get_result()->fetch_assoc()['awaiting_exam'] ?? 0);

            $gpa = $totalCredits > 0 ? round($totalPoints / $totalCredits, 2) : 0.0;
            $eligible = ($modules > 0 && $gpa >= $minGpa && $earnedCredits >= $minCredits && $failsCount <= $maxFails);
            $reasons = [];
            if ((int)($student['enrolment_issue'] ?? 0) === 1) {
                $reasons[] = 'Enrolment/programme mapping needs registrar review';
            }
            if ($modules === 0) {
                $reasons[] = $awaitingExam > 0
                    ? 'Published CA only — exam marks not yet recorded'
                    : 'No published exam results';
            } else {
                if ($gpa < $minGpa) {
                    $reasons[] = 'GPA below minimum';
                }
                if ($earnedCredits < $minCredits) {
                    $reasons[] = 'Earned credits below minimum';
                }
                if ($failsCount > $maxFails) {
                    $reasons[] = 'Too many failed modules';
                }
            }

            $results[] = [
                'SID' => $sid,
                'name' => trim((string)$student['Fname'] . ' ' . (string)$student['Lname']),
                'program_code' => (string)($student['program_code'] ?? 'N/A'),
                'gpa' => $gpa,
                'earned_credits' => $earnedCredits,
                'fails' => $failsCount,
                'modules' => $modules,
                'awaiting_exam' => $awaitingExam,
                'eligible' => $eligible,
                'reason' => $eligible ? 'Meets clearance thresholds' : implode('; ', $reasons),
            ];
        }
        $examStmt->close();
        $awaitingStmt->close();
        $studentsRes->free();

        return $results;
    }
}

if (!function_exists('wuc_progression_candidates')) {
    /** @return list<array<string,mixed>> */
    function wuc_progression_candidates(mysqli $db, ?array $allowedProgramCodes = null): array
    {
        if ($allowedProgramCodes !== null && $allowedProgramCodes === []) {
            return [];
        }

        $where = [
            "LOWER(COALESCE(sp.status, 'active')) = 'active'",
            "COALESCE(s.status, 'active') NOT IN ('inactive', 'deleted')",
            "COALESCE(p.is_active, 1) = 1",
            "COALESCE(p.is_short_course, 0) = 0",
            "COALESCE(p.structure_type, '') NOT IN ('SHORT_COURSE', 'TRADE_TEST_LEVEL')",
        ];
        $types = '';
        $params = [];
        if ($allowedProgramCodes !== null) {
            $allowedProgramCodes = array_values(array_unique(array_filter(array_map('strval', $allowedProgramCodes))));
            if ($allowedProgramCodes === []) {
                return [];
            }
            $where[] = 'sp.program_code IN (' . implode(',', array_fill(0, count($allowedProgramCodes), '?')) . ')';
            $types .= str_repeat('s', count($allowedProgramCodes));
            $params = $allowedProgramCodes;
        }

        $sql = "SELECT s.SID, s.Fname, s.Lname, sp.program_code, p.program_name,
                       p.program_duration, p.examination_type,
                       COALESCE(sp.year_of_study, sp.current_year_number, s.year, 1) AS year_of_study,
                       COALESCE(NULLIF(sp.academic_year, ''), s.academic_year, '') AS academic_year
                  FROM student_program sp
                  INNER JOIN students s ON s.SID = sp.Sid
                  INNER JOIN programs p ON p.program_code = sp.program_code
                 WHERE " . implode(' AND ', $where) . '
                 ORDER BY p.program_name, s.Lname, s.Fname, s.SID';
        $stmt = $db->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $currentYear = max(1, (int)$row['year_of_study']);
            $maxYear = wuc_progression_program_max_year($db, (string)$row['program_code'], (float)$row['program_duration']);
            $target = wuc_progression_target((string)$row['program_code'], $currentYear, (float)$row['program_duration'], $maxYear);
            if (!$target['ok']) {
                continue;
            }
            $row['target_program_code'] = $target['program_code'];
            $row['target_year_of_study'] = $target['year'];
            $row['target_label'] = $target['message'];
            $row['next_academic_year'] = wuc_progression_next_academic_year((string)$row['academic_year']);
            $row['ca_evidence'] = wuc_progression_ca_evidence($db, (string)$row['SID'], (string)$row['program_code'], $currentYear);
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('wuc_progress_student_year')) {
    /**
     * @param ?array $allowedProgramCodes Null for systems admin; explicit list for HOS.
     * @return array{ok:bool,message:string}
     */
    function wuc_progress_student_year(
        mysqli $db,
        string $sid,
        string $actorId,
        string $actorRole,
        string $decisionBasis,
        string $decisionReference,
        string $notes = '',
        ?array $allowedProgramCodes = null,
        ?string $sectionId = null,
        bool $manageTransaction = true
    ): array {
        $sid = trim($sid);
        $actorId = trim($actorId);
        $actorRole = strtolower(trim($actorRole));
        $decisionBasis = strtolower(trim($decisionBasis));
        $decisionReference = trim($decisionReference);
        $notes = trim($notes);

        if (!in_array($actorRole, ['systems_admin', 'head_of_department'], true)) {
            return ['ok' => false, 'message' => 'Only a Head of Section or systems administrator may approve academic progression.'];
        }
        if ($actorRole === 'head_of_department' && $allowedProgramCodes === null) {
            return ['ok' => false, 'message' => 'Head of Section progression requires an assigned programme scope.'];
        }
        $allowedBases = ['external_results', 'examination_board', 'internal_ca_review', 'administrative'];
        if (!in_array($decisionBasis, $allowedBases, true)) {
            return ['ok' => false, 'message' => 'Select a valid progression decision basis.'];
        }
        if ($decisionReference === '' || mb_strlen($decisionReference) < 3 || mb_strlen($decisionReference) > 120) {
            return ['ok' => false, 'message' => 'Enter the external results, examination board, or administrative decision reference.'];
        }

        if ($manageTransaction) {
            $db->begin_transaction();
        }
        try {
            $stmt = $db->prepare(
                "SELECT sp.id, sp.program_code,
                        COALESCE(sp.year_of_study, sp.current_year_number, s.year, 1) AS year_of_study,
                        COALESCE(NULLIF(sp.academic_year, ''), s.academic_year, '') AS academic_year,
                        p.program_duration
                   FROM student_program sp
                   INNER JOIN students s ON s.SID = sp.Sid
                   INNER JOIN programs p ON p.program_code = sp.program_code
                  WHERE sp.Sid = ? AND LOWER(COALESCE(sp.status, 'active')) = 'active'
                  ORDER BY sp.id DESC LIMIT 1 FOR UPDATE"
            );
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$current) {
                throw new RuntimeException('Active student programme enrolment was not found.');
            }

            $fromProgram = strtoupper(trim((string)$current['program_code']));
            wuc_progression_period_type($db, $fromProgram);
            if ($allowedProgramCodes !== null && !in_array($fromProgram, $allowedProgramCodes, true)) {
                throw new RuntimeException('This student is outside the Head of Section programme scope.');
            }
            $fromYear = max(1, (int)$current['year_of_study']);
            $maxYear = wuc_progression_program_max_year($db, $fromProgram, (float)$current['program_duration']);
            $target = wuc_progression_target($fromProgram, $fromYear, (float)$current['program_duration'], $maxYear);
            if (!$target['ok']) {
                throw new RuntimeException($target['message']);
            }
            $toProgram = $target['program_code'];
            $toYear = $target['year'];
            $fromAcademicYear = (string)$current['academic_year'];
            $toAcademicYear = wuc_progression_next_academic_year($fromAcademicYear);
            if ($toAcademicYear === '') {
                throw new RuntimeException('The current academic year is missing or invalid. Contact the registrar before progressing this student.');
            }
            $evidence = wuc_progression_ca_evidence($db, $sid, $fromProgram, $fromYear);
            $evidenceJson = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt = $db->prepare(
                "INSERT INTO student_progression_decisions
                    (student_id, decision_type, decision_status,
                     from_program_code, to_program_code,
                     from_year_of_study, to_year_of_study,
                     from_academic_year, to_academic_year,
                     decision_basis, decision_reference, decision_notes,
                     ca_evidence_json, decided_by, decided_by_role, section_id)
                 VALUES (?, 'progress', 'approved', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'sssiisssssssss',
                $sid,
                $fromProgram,
                $toProgram,
                $fromYear,
                $toYear,
                $fromAcademicYear,
                $toAcademicYear,
                $decisionBasis,
                $decisionReference,
                $notes,
                $evidenceJson,
                $actorId,
                $actorRole,
                $sectionId
            );
            $stmt->execute();
            $decisionId = (int)$stmt->insert_id;
            $stmt->close();

            if ($fromProgram === WUC_CSE_CERTIFICATE_PROGRAM && $fromYear === 1) {
                $result = wuc_cse_progress_student($db, $sid, $actorId, false, true);
                if (!$result['ok']) {
                    throw new RuntimeException($result['message']);
                }
            } else {
                $periodType = wuc_progression_period_type($db, $toProgram);
                $termNumber = $periodType === 'term' ? 1 : null;
                $semesterNumber = $periodType === 'semester' ? 1 : null;
                $enrolmentId = (int)$current['id'];
                $stmt = $db->prepare(
                    "UPDATE student_program
                        SET year_of_study = ?, current_year_number = ?,
                            academic_year = ?, term = '1', current_term_number = ?,
                            semester = 1, current_semester_number = ?,
                            current_level_number = NULL, updated_at = CURRENT_TIMESTAMP
                      WHERE id = ? AND Sid = ?"
                );
                $stmt->bind_param('iisiiis', $toYear, $toYear, $toAcademicYear, $termNumber, $semesterNumber, $enrolmentId, $sid);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    throw new RuntimeException('The student academic year could not be updated.');
                }
                $stmt->close();

                $stmt = $db->prepare('UPDATE students SET year = ?, academic_year = ? WHERE SID = ?');
                $stmt->bind_param('iss', $toYear, $toAcademicYear, $sid);
                $stmt->execute();
                $stmt->close();

                $stmt = $db->prepare(
                    "UPDATE course_registration
                        SET is_active = 0,
                            status = CASE WHEN LOWER(COALESCE(status, '')) = 'dropped' THEN status ELSE 'completed' END
                      WHERE Sid = ? AND Year = ? AND COALESCE(is_active, 1) = 1"
                );
                $stmt->bind_param('si', $sid, $fromYear);
                $stmt->execute();
                $stmt->close();

                wuc_progression_prepare_registration($db, $sid, $toProgram, $periodType, $toYear, $toAcademicYear);
            }

            audit_log($db, $actorId, 'student.academic_year_progressed', [
                'record_id' => $decisionId,
                'student_id' => $sid,
                'from_program_code' => $fromProgram,
                'to_program_code' => $toProgram,
                'from_year_of_study' => $fromYear,
                'to_year_of_study' => $toYear,
                'decision_basis' => $decisionBasis,
                'decision_reference' => $decisionReference,
                'section_id' => $sectionId,
            ]);

            if ($manageTransaction) {
                $db->commit();
            }
            return [
                'ok' => true,
                'message' => "Student {$sid} progressed to {$toProgram}, Year {$toYear} ({$toAcademicYear}).",
            ];
        } catch (Throwable $e) {
            if ($manageTransaction) {
                $db->rollback();
            }
            error_log('[Student progression] ' . $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
