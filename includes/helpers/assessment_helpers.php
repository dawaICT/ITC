<?php
declare(strict_types=1);
/**
 * Assessment helpers — lecturer-assignment gate, scheme-weight validation, and
 * mark validation for the offering-based CA flow (ITC Academic Structure §13/§15/§16).
 *
 * Grade computation reuses the canonical scale in includes/grading_helpers.php
 * (wuc_result_grade). All queries use prepared statements. Functions are guarded.
 */

if (!function_exists('wuc_lecturer_assigned_to_offering')) {
    /**
     * §13/§16: a lecturer may only act on (upload CA for) an offering they are
     * actively assigned to. Returns true only for an active assignment.
     */
    function wuc_lecturer_assigned_to_offering(mysqli $db, string $staff_id, int $course_offering_id): bool
    {
        $staff_id = trim($staff_id);
        if ($staff_id === '') {
            return false;
        }
        $sql = "SELECT 1 FROM lecturer_course_assignments
                 WHERE staff_id = ? AND course_offering_id = ? AND status = 'active' LIMIT 1";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('si', $staff_id, $course_offering_id);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('wuc_scheme_weights_valid')) {
    /**
     * §15: an assessment scheme's component weights must not exceed 100%, and the
     * scheme's ca_weight + exam_weight must not exceed 100%. Returns
     * ['ok' => bool, 'reason' => string, 'component_total' => float].
     */
    function wuc_scheme_weights_valid(mysqli $db, int $scheme_id): array
    {
        $stmt = $db->prepare("SELECT ca_weight, exam_weight FROM assessment_schemes WHERE id = ? LIMIT 1");
        if ($stmt === false) {
            return ['ok' => false, 'reason' => 'Query error.', 'component_total' => 0.0];
        }
        $stmt->bind_param('i', $scheme_id);
        $stmt->execute();
        $scheme = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$scheme) {
            return ['ok' => false, 'reason' => 'Scheme not found.', 'component_total' => 0.0];
        }
        if ((float)$scheme['ca_weight'] + (float)$scheme['exam_weight'] > 100.0) {
            return ['ok' => false, 'reason' => 'CA + Exam weight exceeds 100%.', 'component_total' => 0.0];
        }

        $stmt = $db->prepare("SELECT COALESCE(SUM(weight),0) AS total FROM assessment_components WHERE assessment_scheme_id = ?");
        $stmt->bind_param('i', $scheme_id);
        $stmt->execute();
        $total = (float)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        if ($total > 100.0) {
            return ['ok' => false, 'reason' => "Component weights total {$total}%, exceeding 100%.", 'component_total' => $total];
        }
        return ['ok' => true, 'reason' => '', 'component_total' => $total];
    }
}

if (!function_exists('wuc_validate_mark')) {
    /**
     * §16: a mark must be numeric, not negative, and not exceed the maximum.
     */
    function wuc_validate_mark($mark, $max_mark): bool
    {
        if ($mark === null || $mark === '' || !is_numeric($mark) || !is_numeric($max_mark)) {
            return false;
        }
        $m = (float)$mark;
        return $m >= 0.0 && $m <= (float)$max_mark;
    }
}

if (!function_exists('wuc_compute_course_result')) {
    /**
     * Compute ca_total / exam_mark / final_mark / grade / result_status from a
     * registration's component marks against its scheme. CA is the sum of
     * non-EXAM component marks scaled to ca_weight; EXAM scaled to exam_weight.
     * Grade uses the canonical wuc_result_grade() when available.
     *
     * @return array{ca_total:float,exam_mark:?float,final_mark:float,grade:string,result_status:string}
     */
    function wuc_compute_course_result(mysqli $db, int $registration_id, int $scheme_id): array
    {
        $stmt = $db->prepare("SELECT ca_weight, exam_weight, pass_mark FROM assessment_schemes WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $scheme_id);
        $stmt->execute();
        $scheme = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$scheme) {
            return ['ca_total' => 0.0, 'exam_mark' => null, 'final_mark' => 0.0, 'grade' => '', 'result_status' => 'INCOMPLETE'];
        }

        // Pull this registration's marks grouped by component type.
        $sql = "SELECT ac.component_type, ac.weight, ac.max_mark, sam.mark_obtained
                  FROM student_assessment_marks sam
                  JOIN assessment_components ac ON ac.id = sam.assessment_component_id
                 WHERE sam.student_course_registration_id = ?
                   AND ac.assessment_scheme_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $registration_id, $scheme_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $caWeighted = 0.0; $caWeightSum = 0.0;
        $examPct = null; $examWritten = false;
        foreach ($rows as $r) {
            if ($r['mark_obtained'] === null) {
                continue;
            }
            $pct = (float)$r['max_mark'] > 0 ? ((float)$r['mark_obtained'] / (float)$r['max_mark']) : 0.0;
            if ($r['component_type'] === 'EXAM') {
                $examPct = $pct; $examWritten = true;
            } else {
                $caWeighted += $pct * (float)$r['weight'];
                $caWeightSum += (float)$r['weight'];
            }
        }

        // CA total as a percentage out of ca_weight; exam scaled to exam_weight.
        $caTotal = $caWeighted; // already weight-scaled points toward 100
        $examPoints = $examWritten ? ($examPct * (float)$scheme['exam_weight']) : 0.0;
        $final = round($caTotal + $examPoints, 2);

        $grade = '';
        if (function_exists('wuc_result_grade')) {
            $grade = $examWritten ? wuc_result_grade($final) : (defined('WUC_RESULT_NOT_EXAMINED') ? WUC_RESULT_NOT_EXAMINED : '');
        }
        $status = !$examWritten ? 'INCOMPLETE' : ($final >= (float)$scheme['pass_mark'] ? 'PASS' : 'REFERRED');

        return [
            'ca_total'      => round($caTotal, 2),
            'exam_mark'     => $examWritten ? round($examPct * 100, 2) : null,
            'final_mark'    => $final,
            'grade'         => $grade,
            'result_status' => $status,
        ];
    }
}
