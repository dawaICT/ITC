<?php
/**
 * Canonical grading scale, result status workflow, and audit trail.
 *
 * Single source of truth for the improved result logic:
 *   Marks (CA + Exam)  ->  Final mark (assessment_weighting_total)
 *   Final mark         ->  Grade (wuc_result_grade)  ->  Remark (wuc_result_remark)
 *   Status workflow:   Draft -> Submitted -> Approved -> Published (+ Rejected)
 *
 * Every result action is recorded through wuc_result_log() so the institution
 * can answer "who entered / changed / approved / published this result, and why".
 *
 * The grading scale here is the institutional undergraduate scale:
 *   80-100 = A = Distinction
 *   70-79  = B = Merit
 *   60-69  = C = Credit
 *   50-59  = D = Pass
 *    0-49  = F = Fail
 */

require_once __DIR__ . '/schema_guard.php';            // wuc_table_exists
require_once __DIR__ . '/assessment_weighting_helpers.php'; // assessment_weighting_total
require_once __DIR__ . '/academic_risk_engine.php';

if (!function_exists('wuc_column_exists')) {
    function wuc_column_exists(mysqli $db, string $table, string $column): bool
    {
        try {
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            $exists = (bool)$stmt->get_result()->num_rows;
            $stmt->close();
            return $exists;
        } catch (Throwable $e) {
            return false;
        }
    }
}

/* ---------------------------------------------------------------------------
 * Grade / remark / points
 * ------------------------------------------------------------------------- */

if (!function_exists('wuc_result_grade')) {
    /** Letter grade for a final mark out of 100. */
    function wuc_result_grade(float $total): string
    {
        if ($total >= 80) return 'A';
        if ($total >= 70) return 'B';
        if ($total >= 60) return 'C';
        if ($total >= 50) return 'D';
        return 'F';
    }
}

if (!function_exists('wuc_result_remark')) {
    /** Classification remark for a letter grade (A=Distinction ... F=Fail). */
    function wuc_result_remark(string $grade): string
    {
        $map = ['A' => 'Distinction', 'B' => 'Merit', 'C' => 'Credit', 'D' => 'Pass', 'F' => 'Fail'];
        return $map[strtoupper(trim($grade))] ?? '';
    }
}

if (!function_exists('wuc_result_points')) {
    /** GPA points for a final mark out of 100. */
    function wuc_result_points(float $total): float
    {
        if ($total >= 80) return 4.0;
        if ($total >= 70) return 3.0;
        if ($total >= 60) return 2.0;
        if ($total >= 50) return 1.0;
        return 0.0;
    }
}

if (!function_exists('wuc_result_compute')) {
    /**
     * Full grading breakdown for a student's CA + Exam in one place.
     * Final mark uses the programme weighting policy (assessment_weighting_total).
     *
     * @return array{final:float,grade:string,remark:string,points:float}
     */
    function wuc_result_compute(mysqli $db, string $sid, $caMark, $examMark): array
    {
        $final = assessment_weighting_total($db, $sid, $caMark, $examMark);
        $grade = wuc_result_grade($final);
        return [
            'final'  => $final,
            'grade'  => $grade,
            'remark' => wuc_result_remark($grade),
            'points' => wuc_result_points($final),
        ];
    }
}

/* ---------------------------------------------------------------------------
 * "Did the student write the final examination?"
 *
 * A final grade is only meaningful once the student has actually sat the exam.
 * The Exam column (semester_assessment.Exam) is decimal NULL by default, so a
 * NULL / empty value means no exam was sat — distinct from a genuine 0.00 score
 * (sat and scored zero). Readers that COALESCE the exam mark to 0 turn a missing
 * exam into a real 0 and emit a bogus F; use this helper instead so staff see
 * "NE" (Not Examined) and students simply see no final grade.
 * ------------------------------------------------------------------------- */

if (!defined('WUC_RESULT_NOT_EXAMINED')) {
    define('WUC_RESULT_NOT_EXAMINED', 'NE');
}

if (!function_exists('wuc_result_exam_written')) {
    /** True only when an exam mark is on record (NULL / '' = not examined; 0 counts). */
    function wuc_result_exam_written($examMark): bool
    {
        return $examMark !== null && $examMark !== '' && is_numeric($examMark);
    }
}

if (!function_exists('wuc_result_grade_or_ne')) {
    /**
     * Letter grade for a staff-facing result cell, or "NE" when the student has
     * not sat the final exam. Centralises the rule so every lecturer/staff view
     * behaves identically.
     */
    function wuc_result_grade_or_ne(mysqli $db, string $sid, $caMark, $examMark): string
    {
        if (!wuc_result_exam_written($examMark)) {
            return WUC_RESULT_NOT_EXAMINED;
        }
        return wuc_result_grade(assessment_weighting_total($db, $sid, $caMark, $examMark));
    }
}

/* ---------------------------------------------------------------------------
 * Status workflow: Draft -> Submitted -> Approved -> Published (+ Rejected)
 * Legacy rows created by the CA flow carry the default 'Pending' and are
 * treated as 'Submitted' (awaiting review).
 * ------------------------------------------------------------------------- */

if (!function_exists('wuc_result_statuses')) {
    function wuc_result_statuses(): array
    {
        return ['Draft', 'Submitted', 'Approved', 'Rejected', 'Published'];
    }
}

if (!function_exists('wuc_result_normalize_status')) {
    function wuc_result_normalize_status(?string $status): string
    {
        $s = ucfirst(strtolower(trim((string)$status)));
        if ($s === '' || $s === 'Pending') {
            return 'Submitted';
        }
        return in_array($s, wuc_result_statuses(), true) ? $s : 'Submitted';
    }
}

if (!function_exists('wuc_result_status_label')) {
    function wuc_result_status_label(?string $status): string
    {
        $labels = [
            'Draft'     => 'Draft',
            'Submitted' => 'Submitted (awaiting review)',
            'Approved'  => 'Approved',
            'Rejected'  => 'Returned for correction',
            'Published' => 'Published',
        ];
        return $labels[wuc_result_normalize_status($status)] ?? 'Submitted';
    }
}

if (!function_exists('wuc_result_status_badge')) {
    /** Bootstrap badge class for a status. */
    function wuc_result_status_badge(?string $status): string
    {
        $classes = [
            'Draft'     => 'bg-secondary',
            'Submitted' => 'bg-warning text-dark',
            'Approved'  => 'bg-info',
            'Rejected'  => 'bg-danger',
            'Published' => 'bg-success',
        ];
        return $classes[wuc_result_normalize_status($status)] ?? 'bg-secondary';
    }
}

if (!function_exists('wuc_result_allowed_transitions')) {
    /** Statuses a result may legally move to from its current status. */
    function wuc_result_allowed_transitions(?string $from): array
    {
        switch (wuc_result_normalize_status($from)) {
            case 'Draft':     return ['Submitted'];
            case 'Submitted': return ['Approved', 'Rejected'];
            case 'Approved':  return ['Published', 'Rejected'];
            case 'Rejected':  return ['Submitted', 'Draft'];
            case 'Published': return ['Rejected']; // formal correction unlock
            default:          return [];
        }
    }
}

if (!function_exists('wuc_result_can_transition')) {
    function wuc_result_can_transition(?string $from, string $to): bool
    {
        return in_array(
            wuc_result_normalize_status($to),
            wuc_result_allowed_transitions($from),
            true
        );
    }
}

if (!function_exists('wuc_result_can_edit_marks')) {
    /** A lecturer may edit marks only while a result is Draft or has been sent back. */
    function wuc_result_can_edit_marks(?string $status): bool
    {
        $s = wuc_result_normalize_status($status);
        return $s === 'Draft' || $s === 'Rejected';
    }
}

if (!function_exists('wuc_result_visible_to_student')) {
    /** Students only see Published results. */
    function wuc_result_visible_to_student(?string $status): bool
    {
        return wuc_result_normalize_status($status) === 'Published';
    }
}

/* ---------------------------------------------------------------------------
 * Offering-based result sync
 * ------------------------------------------------------------------------- */

if (!function_exists('wuc_result_table_ready')) {
    function wuc_result_table_ready(mysqli $db, string $table): bool
    {
        return function_exists('wuc_table_exists') && wuc_table_exists($db, $table);
    }
}

if (!function_exists('wuc_result_normalized_tables_ready')) {
    function wuc_result_normalized_tables_ready(mysqli $db): bool
    {
        foreach ([
            'student_program',
            'student_course_registrations',
            'course_offerings',
            'academic_periods',
            'curriculum_courses',
            'assessment_schemes',
            'assessment_components',
            'student_assessment_marks',
            'student_course_results',
        ] as $table) {
            if (!wuc_result_table_ready($db, $table)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('wuc_result_resolve_registration')) {
    function wuc_result_resolve_registration(mysqli $db, string $sid, string $courseCode, string $period, string $year): ?array
    {
        if (!wuc_result_normalized_tables_ready($db)) {
            return null;
        }

        $sql = "SELECT scr.id AS student_course_registration_id,
                       p.program_code,
                       p.structure_type
                  FROM student_program sp
                  JOIN programs p ON p.program_code = sp.program_code
                  JOIN student_course_registrations scr ON scr.student_programme_id = sp.id
                  JOIN course_offerings co ON co.id = scr.course_offering_id
                  JOIN academic_periods ap ON ap.id = co.academic_period_id
                  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                 WHERE sp.Sid = ?
                   AND cc.course_code = ?
                   AND ap.academic_year = ?
                   AND scr.registration_status IN ('REGISTERED','COMPLETED','REPEATING')
                   AND (
                        (p.structure_type = 'TERM_BASED' AND ap.period_type = 'term' AND ap.period_number = CAST(? AS UNSIGNED))
                     OR (p.structure_type = 'SEMESTER_BASED' AND ap.period_type = 'semester' AND ap.period_number = CAST(? AS UNSIGNED))
                     OR (p.structure_type = 'TRADE_TEST_LEVEL' AND ap.period_type = 'trade_test_level')
                   )
                 ORDER BY scr.id
                 LIMIT 1";

        if (!$stmt = $db->prepare($sql)) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('sssss', $sid, $courseCode, $year, $period, $period);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('wuc_result_sync_exam_component')) {
    function wuc_result_sync_exam_component(mysqli $db, int $registrationId, string $programCode, string $courseCode, ?float $examMark, string $actor): void
    {
        if ($examMark === null || !wuc_result_normalized_tables_ready($db)) {
            return;
        }

        $sql = "SELECT ac.id, ac.max_mark
                  FROM assessment_schemes s
                  JOIN assessment_components ac ON ac.assessment_scheme_id = s.id
                 WHERE s.program_code = ?
                   AND s.course_code = ?
                   AND s.status = 'active'
                   AND ac.component_name = 'Final Exam'
                 ORDER BY ac.display_order, ac.id
                 LIMIT 1";
        if (!$stmt = $db->prepare($sql)) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('ss', $programCode, $courseCode);
        $stmt->execute();
        $component = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$component) {
            return;
        }

        $componentId = (int)$component['id'];
        $maxMark = (float)$component['max_mark'];
        if ($examMark < 0 || $examMark > $maxMark) {
            throw new RuntimeException("Exam mark {$examMark} exceeds normalized component max mark {$maxMark}.");
        }

        $sql = "INSERT INTO student_assessment_marks
                    (student_course_registration_id, assessment_component_id, mark_obtained, max_mark, uploaded_by, status, uploaded_at)
                VALUES (?, ?, ?, ?, ?, 'SUBMITTED', CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                    mark_obtained = IF(status = 'LOCKED', mark_obtained, VALUES(mark_obtained)),
                    max_mark = IF(status = 'LOCKED', max_mark, VALUES(max_mark)),
                    uploaded_by = IF(status = 'LOCKED', uploaded_by, VALUES(uploaded_by)),
                    uploaded_at = IF(status = 'LOCKED', uploaded_at, VALUES(uploaded_at)),
                    status = IF(status = 'LOCKED', status, VALUES(status)),
                    updated_at = CURRENT_TIMESTAMP";
        if (!$stmt = $db->prepare($sql)) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('iidds', $registrationId, $componentId, $examMark, $maxMark, $actor);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('wuc_result_sync_normalized')) {
    function wuc_result_sync_normalized(mysqli $db, string $sid, string $courseCode, string $period, string $year, string $actor = ''): ?int
    {
        if (!wuc_result_normalized_tables_ready($db)) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT Total_CA, Exam, status, published_at
               FROM semester_assessment
              WHERE Sid = ? AND Course_Code = ? AND semester = ? AND Year = ?
              LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('ssss', $sid, $courseCode, $period, $year);
        $stmt->execute();
        $legacy = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$legacy) {
            return null;
        }

        $registration = wuc_result_resolve_registration($db, $sid, $courseCode, $period, $year);
        if ($registration === null) {
            return null;
        }

        $registrationId = (int)$registration['student_course_registration_id'];
        $programCode = (string)$registration['program_code'];
        $caTotal = $legacy['Total_CA'] !== null && $legacy['Total_CA'] !== '' ? (float)$legacy['Total_CA'] : null;
        $examMark = wuc_result_exam_written($legacy['Exam']) ? (float)$legacy['Exam'] : null;
        $finalMark = $examMark !== null ? assessment_weighting_total($db, $sid, $caTotal ?? 0.0, $examMark) : null;
        $grade = $finalMark !== null ? wuc_result_grade((float)$finalMark) : null;
        $resultStatus = $finalMark === null ? 'INCOMPLETE' : (((float)$finalMark >= 50.0) ? 'PASS' : 'FAIL');
        $publishedAt = wuc_result_visible_to_student((string)$legacy['status'])
            ? ($legacy['published_at'] ?: date('Y-m-d H:i:s'))
            : null;

        wuc_result_sync_exam_component($db, $registrationId, $programCode, $courseCode, $examMark, $actor);

        $sql = "INSERT INTO student_course_results
                    (student_course_registration_id, ca_total, exam_mark, final_mark, grade, result_status, published_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    ca_total = VALUES(ca_total),
                    exam_mark = VALUES(exam_mark),
                    final_mark = VALUES(final_mark),
                    grade = VALUES(grade),
                    result_status = VALUES(result_status),
                    published_at = VALUES(published_at),
                    updated_at = CURRENT_TIMESTAMP";
        if (!$stmt = $db->prepare($sql)) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('idddsss', $registrationId, $caTotal, $examMark, $finalMark, $grade, $resultStatus, $publishedAt);
        $stmt->execute();
        $stmt->close();

        return $registrationId;
    }
}

if (!function_exists('wuc_result_sync_normalized_by_assessment_id')) {
    function wuc_result_sync_normalized_by_assessment_id(mysqli $db, int $assessmentId, string $actor = ''): ?int
    {
        $stmt = $db->prepare('SELECT Sid, Course_Code, semester, Year FROM semester_assessment WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return wuc_result_sync_normalized(
            $db,
            (string)$row['Sid'],
            (string)$row['Course_Code'],
            (string)$row['semester'],
            (string)$row['Year'],
            $actor
        );
    }
}

/* ---------------------------------------------------------------------------
 * Audit trail
 * ------------------------------------------------------------------------- */

if (!function_exists('wuc_result_log')) {
    /**
     * Record one result action. Never throws and never blocks the caller —
     * a failed audit insert is logged to the PHP error log only.
     *
     * @param array $e Keys: assessment_id, Sid, Course_Code, semester, Year,
     *                 action, field_changed, old_value, new_value, reason,
     *                 actor_staff_id, actor_role, ip_address. Missing actor/ip
     *                 default from the current session/request.
     */
    function wuc_result_log(mysqli $db, array $e): void
    {
        try {
            if (!wuc_table_exists($db, 'result_audit_log')) {
                return;
            }
            $assessmentId = isset($e['assessment_id']) && $e['assessment_id'] !== null
                ? (int)$e['assessment_id'] : null;
            $sid     = (string)($e['Sid'] ?? '');
            $course  = (string)($e['Course_Code'] ?? '');
            $sem     = isset($e['semester']) ? (string)$e['semester'] : null;
            $year    = isset($e['Year']) ? (string)$e['Year'] : null;
            $action  = substr((string)($e['action'] ?? 'unknown'), 0, 32);
            $field   = isset($e['field_changed']) ? substr((string)$e['field_changed'], 0, 40) : null;
            $old     = isset($e['old_value']) && $e['old_value'] !== null ? substr((string)$e['old_value'], 0, 255) : null;
            $new     = isset($e['new_value']) && $e['new_value'] !== null ? substr((string)$e['new_value'], 0, 255) : null;
            $reason  = isset($e['reason']) && $e['reason'] !== null ? substr((string)$e['reason'], 0, 255) : null;
            $actor   = (string)($e['actor_staff_id'] ?? ($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? ''));
            $role    = (string)($e['actor_role'] ?? ($_SESSION['role'] ?? ''));
            $ip      = (string)($e['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
            $role    = $role !== '' ? substr($role, 0, 40) : null;
            $ip      = $ip !== '' ? substr($ip, 0, 45) : null;
            $actor   = $actor !== '' ? $actor : null;

            $stmt = $db->prepare(
                'INSERT INTO result_audit_log
                    (assessment_id, Sid, Course_Code, semester, Year, action,
                     field_changed, old_value, new_value, reason,
                     actor_staff_id, actor_role, ip_address)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            if (!$stmt) {
                return;
            }
            // 13 params: assessment_id(i) + 12 strings.
            $stmt->bind_param(
                'issssssssssss',
                $assessmentId, $sid, $course, $sem, $year, $action,
                $field, $old, $new, $reason, $actor, $role, $ip
            );
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $ex) {
            error_log('wuc_result_log failed: ' . $ex->getMessage());
        }
    }
}

/* ---------------------------------------------------------------------------
 * Persist an exam mark into the canonical table (semester_assessment).
 *
 * This replaces the dead-on-arrival writes to the non-existent `exams` table.
 * It upserts the Exam column on the student's CA row, sets the workflow status,
 * and records an audit entry. The final mark / grade are derived at read time
 * via wuc_result_compute(), so no "total" is stored.
 * ------------------------------------------------------------------------- */

if (!function_exists('result_save_exam_mark')) {
    /**
     * @return array{ok:bool,message:string,assessment_id?:int,created?:bool}
     */
    function result_save_exam_mark(
        mysqli $db,
        string $sid,
        string $courseCode,
        string $period,
        string $year,
        float $exam,
        string $programType,
        string $actor,
        string $status = 'Submitted'
    ): array {
        if ($exam < 0 || $exam > 100) {
            return ['ok' => false, 'message' => 'Exam mark must be between 0 and 100.'];
        }

        // Payment eligibility (server-side). Exam / end-of-term test marks require
        // the student to be paid up to the configured threshold (default 100%).
        // Enforced here at the single canonical save point so NO entry surface
        // (lecturer page, admin / registrar / VC bulk upload, CSV import) can
        // bypass it. Fully-sponsored students (TEVETA / CDF) are exempt and when
        // no fee is due the gate passes — see is_student_allowed_exam().
        if (!function_exists('is_student_allowed_exam')) {
            $financeGuard = __DIR__ . '/finance_guard.php';
            if (is_file($financeGuard)) {
                require_once $financeGuard;
            }
        }
        if (function_exists('is_student_allowed_exam')) {
            $examEligibility = is_student_allowed_exam($db, $sid, $year, $period);
            if (empty($examEligibility['allowed'])) {
                return ['ok' => false, 'message' => sprintf(
                    'Exam mark not saved: %s is %s%% paid for this term and full payment is required before exam marks can be entered.',
                    $sid,
                    rtrim(rtrim(number_format((float)($examEligibility['percent'] ?? 0), 2), '0'), '.')
                )];
            }
        }

        $status = wuc_result_normalize_status($status);

        $db->begin_transaction();
        try {
            $existingId = null;
            $oldExam = null;
            $oldStatus = null;
            if ($stmt = $db->prepare(
                'SELECT id, Exam, status FROM semester_assessment
                 WHERE Sid = ? AND Course_Code = ? AND semester = ? AND Year = ? LIMIT 1 FOR UPDATE'
            )) {
                $stmt->bind_param('ssss', $sid, $courseCode, $period, $year);
                $stmt->execute();
                if ($row = $stmt->get_result()->fetch_assoc()) {
                    $existingId = (int)$row['id'];
                    $oldExam = $row['Exam'];
                    $oldStatus = $row['status'];
                }
                $stmt->close();
            }

            if ($existingId !== null) {
                // Keep the original mark poster; record who submitted the exam.
                $stmt = $db->prepare(
                    'UPDATE semester_assessment
                        SET Exam = ?, status = ?, submitted_by = ?, submitted_at = NOW(),
                            posted_by = COALESCE(posted_by, ?), rejection_reason = NULL
                      WHERE id = ?'
                );
                if (!$stmt) {
                    throw new RuntimeException($db->error);
                }
                $stmt->bind_param('dsssi', $exam, $status, $actor, $actor, $existingId);
                $stmt->execute();
                $stmt->close();
                $assessmentId = $existingId;
                $created = false;
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO semester_assessment
                        (Sid, Course_Code, Exam, semester, Year, program_type,
                         status, posted_by, submitted_by, submitted_at)
                     VALUES (?,?,?,?,?,?,?,?,?,NOW())'
                );
                if (!$stmt) {
                    throw new RuntimeException($db->error);
                }
                // 9 params: Sid, Course_Code, Exam(d), semester, Year, program_type, status, posted_by, submitted_by.
                $stmt->bind_param(
                    'ssdssssss',
                    $sid, $courseCode, $exam, $period, $year, $programType, $status, $actor, $actor
                );
                $stmt->execute();
                $assessmentId = (int)$db->insert_id;
                $stmt->close();
                $created = true;
            }

            wuc_result_sync_normalized($db, $sid, $courseCode, $period, $year, $actor);

            $db->commit();
        } catch (Throwable $ex) {
            $db->rollback();
            error_log('result_save_exam_mark failed: ' . $ex->getMessage());
            return ['ok' => false, 'message' => 'Unable to save the exam mark. Please verify the registration and try again.'];
        }

        wuc_result_log($db, [
            'assessment_id' => $assessmentId,
            'Sid' => $sid,
            'Course_Code' => $courseCode,
            'semester' => $period,
            'Year' => $year,
            'action' => $created ? 'exam_entered' : 'exam_updated',
            'field_changed' => 'Exam',
            'old_value' => $created ? null : (string)$oldExam,
            'new_value' => (string)$exam,
            'actor_staff_id' => $actor,
        ]);

        wuc_academic_risk_after_student_activity($db, $sid, 'exam_mark_saved');

        return [
            'ok' => true,
            'message' => $created ? 'Exam result saved and submitted for review.' : 'Exam result updated and submitted for review.',
            'assessment_id' => $assessmentId,
            'created' => $created,
        ];
    }
}
