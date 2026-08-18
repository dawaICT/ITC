<?php
declare(strict_types=1);

/**
 * Computer Systems Engineering staged-award workflow.
 *
 * ICT-001 is the direct-entry Craft Certificate (Year 1).
 * ICT-002 is the progression-only Diploma stage (Year 2).
 * CSE is a retired legacy umbrella code and must not receive new enrolments.
 */

require_once __DIR__ . '/grading_helpers.php';
require_once __DIR__ . '/audit.php';

if (!defined('WUC_CSE_CERTIFICATE_PROGRAM')) {
    define('WUC_CSE_CERTIFICATE_PROGRAM', 'ICT-001');
    define('WUC_CSE_DIPLOMA_PROGRAM', 'ICT-002');
    define('WUC_CSE_LEGACY_PROGRAM', 'CSE');
}

if (!function_exists('wuc_cse_direct_assignment_error')) {
    function wuc_cse_direct_assignment_error(string $programCode): ?string
    {
        $programCode = strtoupper(trim($programCode));
        if ($programCode === WUC_CSE_LEGACY_PROGRAM) {
            return 'The legacy CSE programme is retired. Enrol first-year students in the Craft Certificate in Computer Systems Engineering.';
        }
        if ($programCode === WUC_CSE_DIPLOMA_PROGRAM) {
            return 'The Diploma in Computer Systems Engineering is a Year-2 progression stage. Use Progression & Graduation after the student passes all Year-1 craft certificate modules.';
        }
        return null;
    }
}

if (!function_exists('wuc_cse_progression_status')) {
    /**
     * @return array{eligible:bool,program_code:string,required:int,passed:int,failed:list<string>,missing:list<string>,message:string}
     */
    function wuc_cse_progression_status(mysqli $db, string $sid): array
    {
        $sid = trim($sid);
        $empty = [
            'eligible' => false,
            'program_code' => '',
            'required' => 0,
            'passed' => 0,
            'failed' => [],
            'missing' => [],
            'message' => 'Student was not found in the craft certificate stage.',
        ];
        if ($sid === '') {
            return $empty;
        }

        $stmt = $db->prepare(
            "SELECT program_code
               FROM student_program
              WHERE Sid = ?
                AND LOWER(COALESCE(status, 'active')) = 'active'
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $programCode = strtoupper(trim((string)($stmt->get_result()->fetch_assoc()['program_code'] ?? '')));
        $stmt->close();
        $empty['program_code'] = $programCode;

        if ($programCode !== WUC_CSE_CERTIFICATE_PROGRAM) {
            if ($programCode === WUC_CSE_DIPLOMA_PROGRAM) {
                $empty['message'] = 'Student has already progressed to the diploma stage.';
            }
            return $empty;
        }

        $required = [];
        $stmt = $db->prepare(
            'SELECT pc.course_code
               FROM program_courses pc
               INNER JOIN courses c ON c.course_code = pc.course_code
              WHERE pc.program_code = ? AND pc.year = 1
                AND COALESCE(pc.is_required, 1) = 1
                AND COALESCE(c.status, \'active\') = \'active\'
              ORDER BY pc.course_code'
        );
        $certificate = WUC_CSE_CERTIFICATE_PROGRAM;
        $stmt->bind_param('s', $certificate);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $required[] = (string)$row['course_code'];
        }
        $stmt->close();

        $empty['required'] = count($required);
        if ($required === []) {
            $empty['message'] = 'The craft certificate curriculum has no required Year-1 modules.';
            return $empty;
        }

        $results = [];
        $stmt = $db->prepare(
            "SELECT e.Course_Code, e.Exam_marks,
                    COALESCE(e.Total_CA,
                        (SELECT sa.Total_CA
                           FROM semester_assessment sa
                          WHERE sa.Sid = e.Sid
                            AND sa.Course_Code = e.Course_Code
                            AND sa.Year = e.Year
                            AND sa.semester = e.semester
                          ORDER BY sa.id DESC LIMIT 1), 0) AS ca_mark
               FROM exams e
               INNER JOIN (
                    SELECT Course_Code, MAX(id) AS latest_id
                      FROM exams
                     WHERE Sid = ?
                       AND CAST(Year AS UNSIGNED) = 1
                       AND LOWER(COALESCE(status, '')) = 'published'
                     GROUP BY Course_Code
               ) latest ON latest.latest_id = e.id"
        );
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $code = trim((string)$row['Course_Code']);
            if ($code === '' || !wuc_result_exam_written($row['Exam_marks'])) {
                continue;
            }
            $computed = wuc_result_compute($db, $sid, $row['ca_mark'], $row['Exam_marks']);
            $results[$code] = $computed['grade'];
        }
        $stmt->close();

        $failed = [];
        $missing = [];
        $passed = 0;
        foreach ($required as $courseCode) {
            if (!array_key_exists($courseCode, $results)) {
                $missing[] = $courseCode;
            } elseif ($results[$courseCode] === 'F') {
                $failed[] = $courseCode;
            } else {
                $passed++;
            }
        }

        $eligible = $passed === count($required) && $failed === [] && $missing === [];
        return [
            'eligible' => $eligible,
            'program_code' => $programCode,
            'required' => count($required),
            'passed' => $passed,
            'failed' => $failed,
            'missing' => $missing,
            'message' => $eligible
                ? 'All Year-1 craft certificate modules have published passing results.'
                : 'Progression requires published passing results for all Year-1 craft certificate modules.',
        ];
    }
}

if (!function_exists('wuc_cse_progress_student')) {
    /** @return array{ok:bool,message:string} */
    function wuc_cse_progress_student(
        mysqli $db,
        string $sid,
        string $actorId,
        bool $manageTransaction = true,
        bool $manualApproval = false
    ): array
    {
        $sid = trim($sid);
        $status = wuc_cse_progression_status($db, $sid);
        if (!$status['eligible'] && !$manualApproval) {
            return ['ok' => false, 'message' => $status['message']];
        }

        if ($manageTransaction) {
            $db->begin_transaction();
        }
        try {
            $stmt = $db->prepare(
                "SELECT id, academic_year
                   FROM student_program
                  WHERE Sid = ? AND program_code = ?
                    AND LOWER(COALESCE(status, 'active')) = 'active'
                  ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $certificate = WUC_CSE_CERTIFICATE_PROGRAM;
            $stmt->bind_param('ss', $sid, $certificate);
            $stmt->execute();
            $enrolment = $stmt->get_result()->fetch_assoc() ?: [];
            $enrolmentId = (int)($enrolment['id'] ?? 0);
            $stmt->close();
            if ($enrolmentId < 1) {
                throw new RuntimeException('The active craft certificate enrolment no longer exists.');
            }

            $versionStmt = $db->prepare(
                "SELECT id FROM curriculum_versions
                  WHERE program_code = ? AND LOWER(COALESCE(status, 'active')) = 'active'
                  ORDER BY effective_year DESC, id DESC LIMIT 1"
            );
            $diploma = WUC_CSE_DIPLOMA_PROGRAM;
            $versionStmt->bind_param('s', $diploma);
            $versionStmt->execute();
            $curriculumVersionId = (int)($versionStmt->get_result()->fetch_assoc()['id'] ?? 0);
            $versionStmt->close();

            $latestAcademicYear = trim((string)($enrolment['academic_year'] ?? ''));
            $registrationYearStmt = $db->prepare(
                'SELECT academic_year FROM semester_registration
                  WHERE student_id = ? OR SID = ? ORDER BY id DESC LIMIT 1'
            );
            $registrationYearStmt->bind_param('ss', $sid, $sid);
            $registrationYearStmt->execute();
            $latestRegistrationYear = trim((string)($registrationYearStmt->get_result()->fetch_assoc()['academic_year'] ?? ''));
            $registrationYearStmt->close();
            if (preg_match('/^(\d{4})$/', $latestRegistrationYear, $yearMatch)) {
                $nextAcademicYear = (string)((int)$yearMatch[1] + 1);
            } elseif (preg_match('/^(\d{4})$/', $latestAcademicYear, $yearMatch)) {
                $nextAcademicYear = (string)((int)$yearMatch[1] + 1);
            } else {
                $nextAcademicYear = (string)((int)date('Y') + 1);
            }

            $stmt = $db->prepare(
                "UPDATE student_program
                    SET program_code = ?,
                        curriculum_version_id = NULLIF(?, 0),
                        year_of_study = 2,
                        current_year_number = 2,
                        term = '1',
                        current_term_number = 1,
                        semester = 1,
                        current_semester_number = NULL,
                        current_level_number = NULL,
                        academic_year = ?,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = ? AND Sid = ?"
            );
            $stmt->bind_param('sisis', $diploma, $curriculumVersionId, $nextAcademicYear, $enrolmentId, $sid);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('The student programme stage could not be updated.');
            }
            $stmt->close();

            $stmt = $db->prepare('UPDATE students SET program = ?, year = 2, academic_year = ? WHERE SID = ?');
            $stmt->bind_param('sss', $diploma, $nextAcademicYear, $sid);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare(
                "UPDATE course_registration
                    SET is_active = 0,
                        status = CASE WHEN LOWER(COALESCE(status, '')) = 'dropped' THEN status ELSE 'completed' END
                  WHERE Sid = ? AND COALESCE(is_active, 1) = 1"
            );
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare(
                "INSERT INTO semester_registration
                    (student_id, SID, program_code, semester, period_type,
                     year_of_study, Year, academic_year, registration_status, fee_status)
                 VALUES (?, ?, ?, '1', 'term', 2, 2, ?, 'pending', 'unknown')
                 ON DUPLICATE KEY UPDATE
                    year_of_study = 2,
                    Year = 2,
                    registration_status = 'pending',
                    fee_status = 'unknown',
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->bind_param('ssss', $sid, $sid, $diploma, $nextAcademicYear);
            $stmt->execute();
            $stmt->close();

            audit_log($db, $actorId, 'student.cse_progressed_to_diploma', [
                'student_id' => $sid,
                'old_program_code' => WUC_CSE_CERTIFICATE_PROGRAM,
                'new_program_code' => WUC_CSE_DIPLOMA_PROGRAM,
                'new_year_of_study' => 2,
                'academic_year' => $nextAcademicYear,
            ]);

            if ($manageTransaction) {
                $db->commit();
            }
            return ['ok' => true, 'message' => 'Student progressed to the Diploma in Computer Systems Engineering (Year 2).'];
        } catch (Throwable $e) {
            if ($manageTransaction) {
                $db->rollback();
            }
            error_log('[CSE progression] ' . $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
