<?php
declare(strict_types=1);

/**
 * Deterministic academic-risk scoring for the LMS.
 *
 * This is intentionally not a generative AI feature. It is an explainable
 * rules engine that reads existing LMS data, calculates risk points, stores an
 * audit trail when the support tables exist, and lets staff make the decision.
 */

require_once __DIR__ . '/assessment_weighting_helpers.php';
require_once __DIR__ . '/helpers/lecturer_course_helpers.php';
require_once __DIR__ . '/elearning_access.php';

if (!function_exists('wuc_risk_table_exists')) {
    function wuc_risk_table_exists(mysqli $db, string $table): bool
    {
        static $memo = [];
        $table = trim($table);
        if ($table === '') {
            return false;
        }
        if (array_key_exists($table, $memo)) {
            return $memo[$table];
        }
        if (function_exists('wuc_table_exists')) {
            return $memo[$table] = wuc_table_exists($db, $table);
        }
        if ($res = @$db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")) {
            $exists = $res->num_rows > 0;
            $res->free();
            return $memo[$table] = $exists;
        }
        return $memo[$table] = false;
    }
}

if (!function_exists('wuc_risk_load_thresholds')) {
    function wuc_risk_load_thresholds(mysqli $db): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $defaults = [
            'attendance_threshold' => 60,
            'marks_threshold' => 50,
            'assignment_threshold' => 50,
            'inactive_days_threshold' => 14,
            'course_progress_threshold' => 50,
            'medium_risk_threshold' => 40,
            'high_risk_threshold' => 70,
        ];

        $producer = static function () use ($db, $defaults): array {
            $thresholds = $defaults;
            if (!wuc_risk_table_exists($db, 'ai_threshold_settings')) {
                return $thresholds;
            }
            try {
                $res = $db->query('SELECT setting_name, setting_value FROM ai_threshold_settings');
                while ($row = $res->fetch_assoc()) {
                    $name = (string)($row['setting_name'] ?? '');
                    if (array_key_exists($name, $thresholds) && is_numeric($row['setting_value'])) {
                        $thresholds[$name] = (float)$row['setting_value'];
                    }
                }
                $res->free();
            } catch (Throwable $e) {
                error_log('wuc_risk_load_thresholds failed: ' . $e->getMessage());
            }
            return $thresholds;
        };

        if (function_exists('wuc_cache_remember')) {
            return $cached = wuc_cache_remember('risk_thresholds', $producer, 300);
        }

        return $cached = $producer();
    }
}

if (!function_exists('wuc_risk_bind_values')) {
    function wuc_risk_bind_values(mysqli_stmt $stmt, string $types, array $values): void
    {
        $refs = [];
        foreach ($values as $key => $value) {
            $refs[$key] = &$values[$key];
        }
        $stmt->bind_param($types, ...$refs);
    }
}

if (!function_exists('wuc_risk_student_courses')) {
    function wuc_risk_student_courses(mysqli $db, string $studentId): array
    {
        if (function_exists('getStudentEnrolledCourses')) {
            $resolved = getStudentEnrolledCourses($db, $studentId);
            $resolved = array_values(array_unique(array_filter(array_map(
                static fn($code): string => trim((string)$code),
                is_array($resolved) ? $resolved : []
            ))));
            if ($resolved) {
                return $resolved;
            }
        }

        if (!wuc_risk_table_exists($db, 'course_registration')) {
            return [];
        }

        $courses = [];
        $sql = "SELECT DISTINCT course_code FROM course_registration
                WHERE Sid = ? AND COALESCE(is_active, 1) = 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $code = trim((string)($row['course_code'] ?? ''));
                if ($code !== '') {
                    $courses[] = $code;
                }
            }
            $stmt->close();
        }

        return array_values(array_unique($courses));
    }
}

if (!function_exists('wuc_risk_student_program')) {
    function wuc_risk_student_program(mysqli $db, string $studentId): array
    {
        $program = [
            'program_code' => '',
            'program_name' => '',
            'department_id' => null,
            'examination_type' => '',
        ];

        if (!wuc_risk_table_exists($db, 'student_program')) {
            return $program;
        }

        $sql = "SELECT sp.program_code, COALESCE(p.program_name, '') AS program_name,
                       p.department_id, COALESCE(p.examination_type, '') AS examination_type
                FROM student_program sp
                LEFT JOIN programs p ON p.program_code = sp.program_code
                WHERE sp.Sid = ?
                  AND (sp.status IS NULL OR TRIM(sp.status) = '' OR LOWER(TRIM(sp.status)) IN ('active', 'current'))
                ORDER BY sp.id DESC
                LIMIT 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $program['program_code'] = trim((string)($row['program_code'] ?? ''));
            $program['program_name'] = trim((string)($row['program_name'] ?? ''));
            $program['department_id'] = isset($row['department_id']) ? (int)$row['department_id'] : null;
            $program['examination_type'] = strtolower(trim((string)($row['examination_type'] ?? '')));
        }

        return $program;
    }
}

if (!function_exists('wuc_risk_attendance_metrics')) {
    function wuc_risk_attendance_metrics(mysqli $db, string $studentId, array $courseCodes): array
    {
        $metrics = [
            'total_classes' => 0,
            'classes_attended' => 0,
            'attendance_percentage' => null,
            'consecutive_absences' => 0,
            'source' => 'none',
        ];

        if (!$courseCodes || !wuc_risk_table_exists($db, 'el_live_sessions') || !wuc_risk_table_exists($db, 'el_attendance')) {
            return $metrics;
        }

        $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
        $types = str_repeat('s', count($courseCodes));

        $sql = "SELECT ls.id, ls.start_time,
                       MAX(CASE WHEN ea.actor_id IS NULL THEN 0 ELSE 1 END) AS attended
                FROM el_live_sessions ls
                LEFT JOIN el_attendance ea ON ea.session_id = ls.id
                    AND ea.actor_type = 'student'
                    AND ea.actor_id = ?
                WHERE ls.course_code IN ($placeholders)
                  AND ls.start_time <= NOW()
                  AND COALESCE(ls.status, 'scheduled') NOT IN ('cancelled', 'canceled')
                GROUP BY ls.id, ls.start_time
                ORDER BY ls.start_time DESC";
        if (!$stmt = $db->prepare($sql)) {
            return $metrics;
        }

        $params = array_merge([$studentId], $courseCodes);
        wuc_risk_bind_values($stmt, 's' . $types, $params);
        $stmt->execute();
        $res = $stmt->get_result();
        $total = 0;
        $attended = 0;
        $consecutive = 0;
        $stillAbsentRun = true;
        while ($row = $res->fetch_assoc()) {
            $total++;
            $wasPresent = (int)($row['attended'] ?? 0) === 1;
            if ($wasPresent) {
                $attended++;
                $stillAbsentRun = false;
            } elseif ($stillAbsentRun) {
                $consecutive++;
            }
        }
        $stmt->close();

        $metrics['total_classes'] = $total;
        $metrics['classes_attended'] = $attended;
        $metrics['attendance_percentage'] = $total > 0 ? round(($attended / $total) * 100, 2) : null;
        $metrics['consecutive_absences'] = $consecutive;
        $metrics['source'] = $total > 0 ? 'el_live_sessions' : 'none';

        return $metrics;
    }
}

if (!function_exists('wuc_risk_assessment_metrics')) {
    function wuc_risk_assessment_metrics(mysqli $db, string $studentId, array $courseCodes): array
    {
        $marksByCourse = [];
        $failedRecent = 0;

        if ($courseCodes && wuc_risk_table_exists($db, 'exams')) {
            $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
            $sql = "SELECT Course_Code, Total_marks, Total_CA, Exam_marks,
                           COALESCE(updated_at, created_at) AS recorded_at
                    FROM exams
                    WHERE Sid = ? AND Course_Code IN ($placeholders)
                    ORDER BY COALESCE(updated_at, created_at) DESC, id DESC";
            if ($stmt = $db->prepare($sql)) {
                $params = array_merge([$studentId], $courseCodes);
                wuc_risk_bind_values($stmt, 's' . str_repeat('s', count($courseCodes)), $params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $code = trim((string)($row['Course_Code'] ?? ''));
                    if ($code === '' || isset($marksByCourse[$code])) {
                        continue;
                    }
                    if (is_numeric($row['Total_marks'])) {
                        $mark = max(0.0, min(100.0, (float)$row['Total_marks']));
                    } elseif (is_numeric($row['Total_CA'])) {
                        // External-exam programmes often hold only internal CA
                        // until results return. Total_CA is on a 40-point scale;
                        // applying final-result weighting again produced values
                        // such as 5.29% from a valid 13.42/40 CA total.
                        $totalCa = (float)$row['Total_CA'];
                        $scale = $totalCa <= 40 ? 40.0 : 100.0;
                        $mark = round(min(100, ($totalCa / $scale) * 100), 2);
                    } else {
                        continue;
                    }
                    $marksByCourse[$code] = [
                        'mark' => $mark,
                        'recorded_at' => (string)($row['recorded_at'] ?? ''),
                    ];
                }
                $stmt->close();
            }
        }

        if ($courseCodes && wuc_risk_table_exists($db, 'semester_assessment')) {
            $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
            $sql = "SELECT Course_Code, Total_CA, A1, A2, A3, T1, T2,
                           COALESCE(updated_at, created_at) AS recorded_at
                    FROM semester_assessment
                    WHERE Sid = ? AND Course_Code IN ($placeholders)
                    ORDER BY COALESCE(updated_at, created_at) DESC, id DESC";
            if ($stmt = $db->prepare($sql)) {
                $params = array_merge([$studentId], $courseCodes);
                wuc_risk_bind_values($stmt, 's' . str_repeat('s', count($courseCodes)), $params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $code = trim((string)($row['Course_Code'] ?? ''));
                    if ($code === '' || isset($marksByCourse[$code])) {
                        continue;
                    }
                    if (!is_numeric($row['Total_CA'])) {
                        continue;
                    }
                    $totalCa = (float)$row['Total_CA'];
                    $scale = $totalCa <= 40 ? 40.0 : 100.0;
                    $marksByCourse[$code] = [
                        'mark' => round(min(100, ($totalCa / $scale) * 100), 2),
                        'recorded_at' => (string)($row['recorded_at'] ?? ''),
                    ];
                }
                $stmt->close();
            }
        }

        if ($courseCodes && wuc_risk_table_exists($db, 'short_course_assessment')) {
            $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
            $sql = "SELECT course_code, Total_CA, A1, A2, A3, T1, T2,
                           COALESCE(updated_at, created_at) AS recorded_at
                    FROM short_course_assessment
                    WHERE student_id COLLATE utf8mb4_general_ci = ? AND course_code IN ($placeholders)
                    ORDER BY COALESCE(updated_at, created_at) DESC, id DESC";
            if ($stmt = $db->prepare($sql)) {
                $params = array_merge([$studentId], $courseCodes);
                wuc_risk_bind_values($stmt, 's' . str_repeat('s', count($courseCodes)), $params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $code = trim((string)($row['course_code'] ?? ''));
                    if ($code === '' || isset($marksByCourse[$code])) {
                        continue;
                    }
                    if (!is_numeric($row['Total_CA'])) {
                        continue;
                    }
                    $totalCa = (float)$row['Total_CA'];
                    $scale = $totalCa <= 40 ? 40.0 : 100.0;
                    $marksByCourse[$code] = [
                        'mark' => round(min(100, ($totalCa / $scale) * 100), 2),
                        'recorded_at' => (string)($row['recorded_at'] ?? ''),
                    ];
                }
                $stmt->close();
            }
        }

        uasort($marksByCourse, static fn(array $a, array $b): int => strcmp($b['recorded_at'], $a['recorded_at']));
        $marks = array_map(static fn(array $row): float => (float)$row['mark'], array_values($marksByCourse));
        foreach (array_slice($marks, 0, 2) as $mark) {
            if ($mark < 50) {
                $failedRecent++;
            }
        }

        return [
            'average_mark' => $marks ? round(array_sum($marks) / count($marks), 2) : null,
            'assessment_count' => count($marks),
            'failed_recent_assessments' => $failedRecent,
        ];
    }
}

if (!function_exists('wuc_risk_assignment_metrics')) {
    function wuc_risk_assignment_metrics(mysqli $db, string $studentId, array $courseCodes): array
    {
        $metrics = [
            'total_assignments' => 0,
            'submitted_assignments' => 0,
            'missing_assignments' => 0,
            'late_submissions' => 0,
            'submission_rate' => null,
        ];

        if (!$courseCodes || !wuc_risk_table_exists($db, 'el_assignments') || !wuc_risk_table_exists($db, 'el_submissions')) {
            return $metrics;
        }

        $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
        $sql = "SELECT COUNT(DISTINCT a.id) AS total_assignments,
                       COUNT(DISTINCT s.id) AS submitted_assignments,
                       SUM(CASE WHEN s.id IS NOT NULL AND a.due_at IS NOT NULL AND s.submitted_at > a.due_at THEN 1 ELSE 0 END) AS late_submissions
                FROM el_assignments a
                LEFT JOIN el_submissions s ON s.assignment_id = a.id AND s.Sid = ?
                WHERE a.course_code IN ($placeholders)
                  AND (a.due_at IS NULL OR a.due_at <= NOW())";
        if ($stmt = $db->prepare($sql)) {
            $params = array_merge([$studentId], $courseCodes);
            wuc_risk_bind_values($stmt, 's' . str_repeat('s', count($courseCodes)), $params);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $total = (int)($row['total_assignments'] ?? 0);
            $submitted = (int)($row['submitted_assignments'] ?? 0);
            $metrics['total_assignments'] = $total;
            $metrics['submitted_assignments'] = $submitted;
            $metrics['missing_assignments'] = max(0, $total - $submitted);
            $metrics['late_submissions'] = (int)($row['late_submissions'] ?? 0);
            $metrics['submission_rate'] = $total > 0 ? round(($submitted / $total) * 100, 2) : null;
        }

        return $metrics;
    }
}

if (!function_exists('wuc_risk_activity_metrics')) {
    function wuc_risk_activity_metrics(mysqli $db, string $studentId): array
    {
        $metrics = [
            'last_activity_at' => null,
            'days_since_last_activity' => null,
            'activity_count' => 0,
        ];

        if (!wuc_risk_table_exists($db, 'el_analytics_events')) {
            return $metrics;
        }

        $sql = "SELECT MAX(created_at) AS last_activity_at, COUNT(*) AS activity_count
                FROM el_analytics_events
                WHERE actor_type = 'student' AND actor_id = ?";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $metrics['last_activity_at'] = $row['last_activity_at'] ?? null;
            $metrics['activity_count'] = (int)($row['activity_count'] ?? 0);
            if (!empty($metrics['last_activity_at'])) {
                $last = new DateTime((string)$metrics['last_activity_at']);
                $metrics['days_since_last_activity'] = (int)$last->diff(new DateTime())->format('%a');
            }
        }

        return $metrics;
    }
}

if (!function_exists('wuc_risk_progress_metrics')) {
    function wuc_risk_progress_metrics(mysqli $db, string $studentId, array $courseCodes): array
    {
        $metrics = [
            'course_progress_percentage' => null,
            'progress_records' => 0,
        ];

        if (!$courseCodes || !wuc_risk_table_exists($db, 'el_course_progress')) {
            return $metrics;
        }

        $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
        $sql = "SELECT AVG(progress_percent) AS avg_progress, COUNT(*) AS records
                FROM el_course_progress
                WHERE Sid = ? AND course_code IN ($placeholders)";
        if ($stmt = $db->prepare($sql)) {
            $params = array_merge([$studentId], $courseCodes);
            wuc_risk_bind_values($stmt, 's' . str_repeat('s', count($courseCodes)), $params);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $metrics['progress_records'] = (int)($row['records'] ?? 0);
            $metrics['course_progress_percentage'] = $metrics['progress_records'] > 0 ? round((float)$row['avg_progress'], 2) : null;
        }

        return $metrics;
    }
}

if (!function_exists('wuc_risk_fee_metrics')) {
    /**
     * Unpaid-fee signal from student_fee_accounts (live columns: student_id,
     * balance, total_payable, payment_status, status).
     */
    function wuc_risk_fee_metrics(mysqli $db, string $studentId): array
    {
        $metrics = [
            'outstanding_balance' => null,
            'total_payable' => null,
            'fee_accounts' => 0,
            'unpaid_accounts' => 0,
        ];

        if (!wuc_risk_table_exists($db, 'student_fee_accounts')) {
            return $metrics;
        }

        $sql = "SELECT COUNT(*) AS accounts,
                       SUM(CASE WHEN balance > 0 AND payment_status IN ('Unpaid', 'Partially Paid') THEN 1 ELSE 0 END) AS unpaid_accounts,
                       SUM(GREATEST(balance, 0)) AS outstanding,
                       SUM(total_payable) AS payable
                FROM student_fee_accounts
                WHERE student_id = ? AND COALESCE(status, 'active') = 'active'";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $metrics['fee_accounts'] = (int)($row['accounts'] ?? 0);
            if ($metrics['fee_accounts'] > 0) {
                $metrics['unpaid_accounts'] = (int)($row['unpaid_accounts'] ?? 0);
                $metrics['outstanding_balance'] = round((float)($row['outstanding'] ?? 0), 2);
                $metrics['total_payable'] = round((float)($row['payable'] ?? 0), 2);
            }
        }

        return $metrics;
    }
}

if (!function_exists('wuc_risk_registration_metrics')) {
    /**
     * Registration-completeness signal: has the student registered the current
     * academic year's semester/term, and do active course registrations exist?
     * semester_registration keeps the student key in BOTH student_id and SID.
     */
    function wuc_risk_registration_metrics(mysqli $db, string $studentId, array $courseCodes = []): array
    {
        $metrics = [
            'has_semester_registration' => null,
            'latest_registration_year' => null,
            'current_year_registered' => null,
            'active_course_registrations' => 0,
        ];

        if (function_exists('isShortCourseStudent') && isShortCourseStudent($db, $studentId)) {
            $metrics['has_semester_registration'] = true;
            $metrics['latest_registration_year'] = date('Y');
            $metrics['current_year_registered'] = true;
            $metrics['active_course_registrations'] = count($courseCodes);
            return $metrics;
        }

        if (wuc_risk_table_exists($db, 'semester_registration')) {
            $sql = "SELECT MAX(academic_year) AS latest_year, COUNT(*) AS total
                    FROM semester_registration
                    WHERE student_id = ? OR SID = ?";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('ss', $studentId, $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                $total = (int)($row['total'] ?? 0);
                $metrics['has_semester_registration'] = $total > 0;
                $metrics['latest_registration_year'] = $row['latest_year'] !== null ? (string)$row['latest_year'] : null;
                $metrics['current_year_registered'] = $metrics['latest_registration_year'] === date('Y');
            }
        }

        if ($courseCodes) {
            $metrics['active_course_registrations'] = count(array_unique($courseCodes));
        } elseif (wuc_risk_table_exists($db, 'course_registration')) {
            $sql = "SELECT COUNT(*) AS total FROM course_registration
                    WHERE Sid = ? AND COALESCE(is_active, 1) = 1";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                $metrics['active_course_registrations'] = (int)($row['total'] ?? 0);
            }
        }

        return $metrics;
    }
}

if (!function_exists('wuc_risk_failed_course_metrics')) {
    /**
     * Failed/repeat-course signal from semester_assessment (canonical results
     * store). Uses the same Total_CA scaling convention as
     * wuc_risk_assessment_metrics: totals on a 40-point CA scale are
     * normalised to 100 before the <50% fail check.
     */
    function wuc_risk_failed_course_metrics(mysqli $db, string $studentId, array $courseCodes = []): array
    {
        $metrics = [
            'failed_courses' => 0,
            'repeat_failed_courses' => 0,
            'failed_course_codes' => [],
        ];

        if (!$courseCodes || !wuc_risk_table_exists($db, 'semester_assessment')) {
            return $metrics;
        }

        $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
        $sql = "SELECT Course_Code, Total_CA
                FROM semester_assessment
                WHERE Sid = ? AND Course_Code IN ($placeholders) AND Total_CA IS NOT NULL";
        if (!$stmt = $db->prepare($sql)) {
            return $metrics;
        }
        $params = array_merge([$studentId], $courseCodes);
        wuc_risk_bind_values($stmt, 's' . str_repeat('s', count($courseCodes)), $params);
        $stmt->execute();
        $res = $stmt->get_result();
        $failCounts = [];
        while ($row = $res->fetch_assoc()) {
            $code = trim((string)($row['Course_Code'] ?? ''));
            if ($code === '' || !is_numeric($row['Total_CA'])) {
                continue;
            }
            $totalCa = (float)$row['Total_CA'];
            $scale = $totalCa <= 40 ? 40.0 : 100.0;
            $mark = min(100, ($totalCa / $scale) * 100);
            if ($mark < 50) {
                $failCounts[$code] = ($failCounts[$code] ?? 0) + 1;
            }
        }
        $stmt->close();

        $metrics['failed_courses'] = count($failCounts);
        $metrics['repeat_failed_courses'] = count(array_filter($failCounts, static function (int $n): bool {
            return $n >= 2;
        }));
        $metrics['failed_course_codes'] = array_keys($failCounts);

        return $metrics;
    }
}

if (!function_exists('wuc_risk_recommendation')) {
    function wuc_risk_recommendation(array $reasonKeys): string
    {
        if (!$reasonKeys) {
            return 'Keep monitoring normal attendance, coursework, and eLearning activity.';
        }

        if (count($reasonKeys) >= 3) {
            return 'Arrange academic counselling, agree on a weekly catch-up plan, and monitor progress every week.';
        }
        if (in_array('attendance', $reasonKeys, true)) {
            return 'Contact the learner, confirm the reason for missed classes, and agree on attendance support.';
        }
        if (in_array('marks', $reasonKeys, true) || in_array('failed_recent', $reasonKeys, true)) {
            return 'Schedule remedial support and give targeted revision exercises before the next assessment.';
        }
        if (in_array('assignments', $reasonKeys, true)) {
            return 'Send an assignment reminder and follow up on missing or late submissions.';
        }
        if (in_array('inactive', $reasonKeys, true)) {
            return 'Check LMS access, remind the learner to use materials, and confirm login support is not needed.';
        }
        if (in_array('progress', $reasonKeys, true)) {
            return 'Create a catch-up study plan and recommend the next course materials to complete.';
        }
        if (in_array('fees', $reasonKeys, true)) {
            return 'Refer the learner to the accounts office to agree a payment plan before registration deadlines.';
        }
        if (in_array('registration', $reasonKeys, true)) {
            return 'Contact the learner to complete semester/course registration before the deadline.';
        }
        if (in_array('failed_courses', $reasonKeys, true)) {
            return 'Review failed courses with the learner and plan retakes or remedial support.';
        }

        return 'Review the learner with the lecturer and choose the most appropriate support action.';
    }
}

if (!function_exists('wuc_risk_student_guidance')) {
    function wuc_risk_student_guidance(array $reasonKeys, string $level = 'Medium'): string
    {
        $reasonKeys = array_values(array_unique($reasonKeys));

        if (!$reasonKeys) {
            return 'Your academic record currently looks stable. Keep attending classes, using your course materials, and submitting work on time.';
        }

        if (count($reasonKeys) >= 3 || $level === 'High') {
            return 'Your recent academic activity shows that extra support may help. Please contact your lecturer or academic advisor to agree on a manageable catch-up plan.';
        }
        if (in_array('attendance', $reasonKeys, true)) {
            return 'Your recent class attendance needs attention. Please contact your lecturer or academic advisor if you need help catching up.';
        }
        if (in_array('marks', $reasonKeys, true) || in_array('failed_recent', $reasonKeys, true)) {
            return 'Your recent assessment results show that extra revision support may help. Please speak with your lecturer about areas to revise before the next assessment.';
        }
        if (in_array('assignments', $reasonKeys, true)) {
            return 'Some coursework may need attention. Please review your pending assignments and ask your lecturer for guidance if you are stuck.';
        }
        if (in_array('inactive', $reasonKeys, true)) {
            return 'Your eLearning activity has been low recently. Please log in, review your course materials, and ask for help if you cannot access anything.';
        }
        if (in_array('progress', $reasonKeys, true)) {
            return 'Your course progress needs a quick catch-up. Please review the next materials in your courses and ask your lecturer which topics to prioritise.';
        }
        if (in_array('fees', $reasonKeys, true)) {
            return 'Your fee account shows an outstanding balance. Please visit the accounts office to review your balance or agree on a payment plan.';
        }
        if (in_array('registration', $reasonKeys, true)) {
            return 'Your registration looks incomplete. Please complete your semester and course registration, or contact the registrar for help.';
        }
        if (in_array('failed_courses', $reasonKeys, true)) {
            return 'Some of your past course results are below the pass mark. Please speak with your lecturer or advisor about retake options and revision support.';
        }

        return 'Please review your academic progress and contact your lecturer or academic advisor if you need support.';
    }
}

if (!function_exists('wuc_academic_risk_analyze_student')) {
    function wuc_academic_risk_analyze_student(mysqli $db, string $studentId, bool $persist = true): array
    {
        $studentId = trim($studentId);
        $thresholds = wuc_risk_load_thresholds($db);
        $courses = wuc_risk_student_courses($db, $studentId);
        $program = wuc_risk_student_program($db, $studentId);

        $attendance = wuc_risk_attendance_metrics($db, $studentId, $courses);
        $assessment = wuc_risk_assessment_metrics($db, $studentId, $courses);
        $assignments = wuc_risk_assignment_metrics($db, $studentId, $courses);
        $activity = wuc_risk_activity_metrics($db, $studentId);
        $progress = wuc_risk_progress_metrics($db, $studentId, $courses);
        $fees = wuc_risk_fee_metrics($db, $studentId);
        $registration = wuc_risk_registration_metrics($db, $studentId, $courses);
        $failedCourses = wuc_risk_failed_course_metrics($db, $studentId, $courses);
        $usesExternalExams = ($program['examination_type'] ?? '') === 'external';

        $score = 0;
        $reasons = [];
        $reasonKeys = [];
        $dataNotes = [];

        if ($attendance['attendance_percentage'] === null) {
            $dataNotes[] = 'No completed live-session attendance records found.';
        } elseif ($attendance['attendance_percentage'] < $thresholds['attendance_threshold']) {
            $score += 30;
            $reasonKeys[] = 'attendance';
            $reasons[] = 'Attendance is below ' . (int)$thresholds['attendance_threshold'] . '% (' . $attendance['attendance_percentage'] . '%).';
        }

        if ((int)$attendance['consecutive_absences'] >= 3) {
            $score += 20;
            $reasonKeys[] = 'attendance';
            $reasons[] = 'Learner missed ' . (int)$attendance['consecutive_absences'] . ' consecutive live sessions.';
        }

        if ($assessment['average_mark'] === null) {
            $dataNotes[] = 'No assessment or exam marks found for registered courses.';
        } elseif ($assessment['average_mark'] < $thresholds['marks_threshold']) {
            $score += 30;
            $reasonKeys[] = 'marks';
            $reasons[] = $usesExternalExams
                ? 'Average internal CA is below the ' . (int)$thresholds['marks_threshold'] . '% support threshold (' . $assessment['average_mark'] . '%). External examination results determine the final outcome.'
                : 'Average mark is below ' . (int)$thresholds['marks_threshold'] . '% (' . $assessment['average_mark'] . '%).';
        }

        if ((int)$assessment['failed_recent_assessments'] >= 2) {
            $score += 20;
            $reasonKeys[] = 'failed_recent';
            $reasons[] = $usesExternalExams
                ? 'The latest two internal CA records are below the support threshold.'
                : 'The latest two assessment records are below the pass threshold.';
        }

        if ($assignments['submission_rate'] === null) {
            $dataNotes[] = 'No due eLearning assignments found for registered courses.';
        } elseif ($assignments['submission_rate'] < $thresholds['assignment_threshold']) {
            $score += 25;
            $reasonKeys[] = 'assignments';
            $reasons[] = 'Assignment submission rate is below ' . (int)$thresholds['assignment_threshold'] . '% (' . $assignments['submission_rate'] . '%).';
        }

        if ($activity['days_since_last_activity'] === null) {
            $dataNotes[] = 'No eLearning activity has been logged yet.';
        } elseif ($activity['days_since_last_activity'] > $thresholds['inactive_days_threshold']) {
            $score += 15;
            $reasonKeys[] = 'inactive';
            $reasons[] = 'No eLearning activity for ' . (int)$activity['days_since_last_activity'] . ' days.';
        }

        if ($progress['course_progress_percentage'] === null) {
            $dataNotes[] = 'No course progress records found.';
        } elseif ($progress['course_progress_percentage'] < $thresholds['course_progress_threshold']) {
            $score += 20;
            $reasonKeys[] = 'progress';
            $reasons[] = 'Course progress is below ' . (int)$thresholds['course_progress_threshold'] . '% (' . $progress['course_progress_percentage'] . '%).';
        }

        if ($fees['outstanding_balance'] === null) {
            $dataNotes[] = 'No active fee account found for this learner.';
        } elseif ($fees['unpaid_accounts'] > 0 && $fees['outstanding_balance'] > 0) {
            $score += 15;
            $reasonKeys[] = 'fees';
            $reasons[] = 'Outstanding fee balance of ' . number_format((float)$fees['outstanding_balance'], 2)
                . ' across ' . (int)$fees['unpaid_accounts'] . ' fee account(s).';
        }

        if ($registration['has_semester_registration'] === null) {
            $dataNotes[] = 'Semester registration records are unavailable.';
        } elseif (!$registration['has_semester_registration'] || $registration['current_year_registered'] === false) {
            $score += 15;
            $reasonKeys[] = 'registration';
            $reasons[] = !$registration['has_semester_registration']
                ? 'No semester registration is on record.'
                : 'No semester registration found for the current academic year (latest: ' . (string)$registration['latest_registration_year'] . ').';
        } elseif ((int)$registration['active_course_registrations'] === 0) {
            $score += 10;
            $reasonKeys[] = 'registration';
            $reasons[] = 'Semester registration exists but no active course registrations were found.';
        }

        if ((int)$failedCourses['repeat_failed_courses'] >= 1) {
            $score += 15;
            $reasonKeys[] = 'failed_courses';
            $reasons[] = (int)$failedCourses['repeat_failed_courses'] . ' course(s) have been failed more than once ('
                . implode(', ', array_slice($failedCourses['failed_course_codes'], 0, 4)) . ').';
        } elseif ((int)$failedCourses['failed_courses'] >= 2) {
            $score += 10;
            $reasonKeys[] = 'failed_courses';
            $reasons[] = $usesExternalExams
                ? (int)$failedCourses['failed_courses'] . ' active modules have internal CA below the support threshold. External examination results determine the final outcome.'
                : (int)$failedCourses['failed_courses'] . ' registered courses have results below the pass mark.';
        }

        if ($score >= $thresholds['high_risk_threshold']) {
            $level = 'High';
        } elseif ($score >= $thresholds['medium_risk_threshold']) {
            $level = 'Medium';
        } else {
            $level = 'Low';
        }

        if (!$reasons) {
            $reasons[] = 'No risk rule has been triggered by the available LMS data.';
        }

        $uniqueReasonKeys = array_values(array_unique($reasonKeys));

        $result = [
            'student_id' => $studentId,
            'course_codes' => $courses,
            'program' => $program,
            'risk_score' => $score,
            'risk_level' => $level,
            'risk_reasons' => $reasons,
            'reason_keys' => $uniqueReasonKeys,
            'recommended_action' => wuc_risk_recommendation($uniqueReasonKeys),
            'student_guidance' => wuc_risk_student_guidance($uniqueReasonKeys, $level),
            'data_notes' => $dataNotes,
            'metrics' => [
                'attendance' => $attendance,
                'assessment' => $assessment,
                'assignments' => $assignments,
                'activity' => $activity,
                'progress' => $progress,
                'fees' => $fees,
                'registration' => $registration,
                'failed_courses' => $failedCourses,
            ],
            'generated_at' => date('Y-m-d H:i:s'),
            'saved' => false,
        ];

        if ($persist) {
            wuc_academic_risk_persist($db, $result);
        }

        return $result;
    }
}

if (!function_exists('wuc_academic_risk_read_cached_summary')) {
    /**
     * Read the newest persisted risk summary when it is still fresh.
     * Returns null when the table is missing, the row is stale, or read fails.
     *
     * @return array<string,mixed>|null
     */
    function wuc_academic_risk_read_cached_summary(mysqli $db, string $studentId, int $maxAgeSeconds = 900): ?array
    {
        $studentId = trim($studentId);
        if ($studentId === '' || $maxAgeSeconds < 1 || !wuc_risk_table_exists($db, 'student_risk_summary')) {
            return null;
        }

        try {
            $stmt = $db->prepare(
                'SELECT risk_score, risk_level, risk_reason, recommended_action, data_quality, generated_at
                 FROM student_risk_summary
                 WHERE student_id = ?
                 ORDER BY generated_at DESC
                 LIMIT 1'
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$row) {
                return null;
            }

            $generatedAt = strtotime((string)($row['generated_at'] ?? ''));
            if ($generatedAt === false || (time() - $generatedAt) > $maxAgeSeconds) {
                return null;
            }

            $reasons = preg_split("/\r\n|\n|\r/", (string)($row['risk_reason'] ?? '')) ?: [];
            $reasons = array_values(array_filter(array_map('trim', $reasons), static fn($r) => $r !== ''));
            $level = (string)($row['risk_level'] ?? 'Low');
            $recommended = trim((string)($row['recommended_action'] ?? ''));

            return [
                'student_id' => $studentId,
                'risk_score' => (int)($row['risk_score'] ?? 0),
                'risk_level' => $level,
                'risk_reasons' => $reasons,
                'reason_keys' => [],
                'recommended_action' => $recommended,
                'student_guidance' => $recommended !== ''
                    ? $recommended
                    : wuc_risk_student_guidance([], $level),
                'data_notes' => preg_split("/\r\n|\n|\r/", (string)($row['data_quality'] ?? '')) ?: [],
                'generated_at' => (string)($row['generated_at'] ?? ''),
                'saved' => true,
                'from_cache' => true,
            ];
        } catch (Throwable $e) {
            error_log('wuc_academic_risk_read_cached_summary failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wuc_academic_risk_for_dashboard')) {
    /**
     * Dashboard-optimized risk insight: reuse a fresh persisted summary when
     * available, otherwise compute and persist. Avoids re-running the full
     * multi-metric engine on every student home-page load.
     *
     * @return array<string,mixed>
     */
    function wuc_academic_risk_for_dashboard(mysqli $db, string $studentId, int $maxAgeSeconds = 900): array
    {
        $cached = wuc_academic_risk_read_cached_summary($db, $studentId, $maxAgeSeconds);
        if (is_array($cached)) {
            return $cached;
        }
        return wuc_academic_risk_analyze_student($db, $studentId, true);
    }
}

if (!function_exists('wuc_academic_risk_persist')) {
    function wuc_academic_risk_persist(mysqli $db, array &$result): void
    {
        if (!wuc_risk_table_exists($db, 'student_risk_summary')) {
            return;
        }

        try {
            $studentId = (string)$result['student_id'];
            $courseCode = null;
            $programCode = (string)($result['program']['program_code'] ?? '');
            $departmentId = $result['program']['department_id'];
            $riskScore = (int)$result['risk_score'];
            $riskLevel = (string)$result['risk_level'];
            $riskReason = implode("\n", $result['risk_reasons']);
            $recommendedAction = (string)$result['recommended_action'];
            $dataQuality = implode("\n", $result['data_notes']);

            $stmt = $db->prepare(
                'INSERT INTO student_risk_summary
                 (student_id, course_code, program_code, department_id, risk_score, risk_level, risk_reason, recommended_action, data_quality)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                return;
            }
            $stmt->bind_param(
                'sssiissss',
                $studentId,
                $courseCode,
                $programCode,
                $departmentId,
                $riskScore,
                $riskLevel,
                $riskReason,
                $recommendedAction,
                $dataQuality
            );
            $stmt->execute();
            $stmt->close();
            $result['saved'] = true;

            if ($riskLevel === 'High') {
                wuc_academic_risk_create_alert_and_intervention($db, $result);
            }
            if (in_array($riskLevel, ['Medium', 'High'], true)) {
                wuc_academic_risk_create_portal_alert($db, $result);
            }
        } catch (Throwable $e) {
            error_log('wuc_academic_risk_persist failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wuc_academic_risk_create_alert_and_intervention')) {
    function wuc_academic_risk_create_alert_and_intervention(mysqli $db, array $result): void
    {
        $studentId = (string)$result['student_id'];
        $message = 'High academic risk: ' . implode(' ', $result['risk_reasons']);
        $departmentId = $result['program']['department_id'] ?? null;

        if (wuc_risk_table_exists($db, 'academic_alerts')) {
            $exists = 0;
            if ($stmt = $db->prepare(
                "SELECT COUNT(*) AS total FROM academic_alerts
                 WHERE student_id = ?
                   AND alert_category = 'Academic Risk'
                   AND alert_status IN ('Unread', 'Read', 'In Progress')
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            )) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                $exists = (int)($row['total'] ?? 0);
            }
            if ($exists === 0 && $stmt = $db->prepare(
                "INSERT INTO academic_alerts
                 (student_id, staff_id, course_code, department_id, alert_category, alert_message, alert_status)
                 VALUES (?, NULL, NULL, ?, 'Academic Risk', ?, 'Unread')"
            )) {
                $stmt->bind_param('sis', $studentId, $departmentId, $message);
                $stmt->execute();
                $stmt->close();
            }
        }

        if (wuc_risk_table_exists($db, 'student_interventions')) {
            $exists = 0;
            if ($stmt = $db->prepare(
                "SELECT COUNT(*) AS total FROM student_interventions
                 WHERE student_id = ?
                   AND intervention_type = 'Academic Risk'
                   AND intervention_status IN ('Pending', 'In Progress', 'Escalated')"
            )) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                $exists = (int)($row['total'] ?? 0);
            }
            if ($exists === 0 && $stmt = $db->prepare(
                "INSERT INTO student_interventions
                 (student_id, staff_id, course_code, department_id, intervention_type, intervention_note, intervention_status, follow_up_date)
                 VALUES (?, NULL, NULL, ?, 'Academic Risk', ?, 'Pending', DATE_ADD(CURDATE(), INTERVAL 7 DAY))"
            )) {
                $note = (string)$result['recommended_action'];
                $stmt->bind_param('sis', $studentId, $departmentId, $note);
                $stmt->execute();
                $stmt->close();
            }
        }

        wuc_academic_risk_create_student_notification($db, $result);
    }
}

if (!function_exists('wuc_academic_risk_create_student_notification')) {
    function wuc_academic_risk_create_student_notification(mysqli $db, array $result): void
    {
        if (!wuc_risk_table_exists($db, 'el_student_notifications')) {
            return;
        }

        $studentId = (string)($result['student_id'] ?? '');
        if ($studentId === '') {
            return;
        }

        $level = (string)($result['risk_level'] ?? 'Low');
        if (!in_array($level, ['Medium', 'High'], true)) {
            return;
        }

        $reasonKeys = is_array($result['reason_keys'] ?? null) ? $result['reason_keys'] : [];
        $courseCode = 'GENERAL';
        $type = 'academic_risk';
        $title = $level === 'High' ? 'Academic support recommended' : 'Academic progress check';
        $body = (string)($result['student_guidance'] ?? wuc_risk_student_guidance($reasonKeys, $level));
        $url = '/wucportal/students/index.php';

        try {
            $exists = 0;
            if ($stmt = $db->prepare(
                "SELECT COUNT(*) AS total FROM el_student_notifications
                 WHERE student_id = ?
                   AND type = ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            )) {
                $stmt->bind_param('ss', $studentId, $type);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                $exists = (int)($row['total'] ?? 0);
            }

            if ($exists === 0 && $stmt = $db->prepare(
                "INSERT INTO el_student_notifications
                 (student_id, course_code, session_id, type, title, body, url)
                 VALUES (?, ?, NULL, ?, ?, ?, ?)"
            )) {
                $stmt->bind_param('ssssss', $studentId, $courseCode, $type, $title, $body, $url);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('wuc_academic_risk_create_student_notification failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wuc_academic_risk_create_portal_alert')) {
    /**
     * Mirror a Medium/High risk result into the unified portal_alerts hub so
     * the learner sees an actionable, dismissible alert on their dashboard.
     * Deduplicated per student for 7 days by wuc_portal_alert_create().
     */
    function wuc_academic_risk_create_portal_alert(mysqli $db, array $result): void
    {
        require_once __DIR__ . '/portal_alerts.php';

        $studentId = trim((string)($result['student_id'] ?? ''));
        $level = (string)($result['risk_level'] ?? 'Low');
        if ($studentId === '' || !in_array($level, ['Medium', 'High'], true)) {
            return;
        }

        $reasonKeys = is_array($result['reason_keys'] ?? null) ? $result['reason_keys'] : [];
        $created = wuc_portal_alert_upsert_current($db, [
            'user_id' => $studentId,
            'user_role' => 'student',
            'alert_type' => 'academic_risk',
            'severity' => $level === 'High' ? 'critical' : 'warning',
            'title' => $level === 'High' ? 'Academic support recommended' : 'Academic progress check',
            'message' => (string)($result['student_guidance'] ?? wuc_risk_student_guidance($reasonKeys, $level)),
            'entity_type' => 'student',
            'entity_id' => $studentId,
            'action_url' => '/wucportal/students/index.php',
        ]);

        // Earlier dashboard and eLearning bridges created parallel copies of
        // the same academic-risk notice. Keep the canonical academic_risk row
        // and retire those legacy duplicates from the unread feed.
        if ($stmt = $db->prepare(
            "UPDATE portal_alerts SET status = 'dismissed'
             WHERE user_id = ? AND alert_type IN ('student_dashboard_ai_academic_insight', 'el_academic_risk')
               AND status IN ('unread', 'read')"
        )) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $stmt->close();
        }

        if ($created) {
            wuc_ai_decision_log($db, [
                'feature' => 'academic_risk_engine',
                'decision_type' => 'portal_alert_created',
                'entity_type' => 'student',
                'entity_id' => $studentId,
                'input_summary' => 'Risk score ' . (int)($result['risk_score'] ?? 0) . ' (' . $level . ')',
                'outcome' => 'Alert created for ' . $level . ' academic risk',
                'reasons' => $result['risk_reasons'] ?? [],
            ]);
        }
    }
}

if (!function_exists('wuc_academic_risk_after_student_activity')) {
    function wuc_academic_risk_after_student_activity(mysqli $db, string $studentId, string $trigger = 'manual'): ?array
    {
        $studentId = trim($studentId);
        if ($studentId === '' || !preg_match('/^[A-Za-z0-9\/\-_]+$/', $studentId)) {
            return null;
        }

        try {
            $risk = wuc_academic_risk_analyze_student($db, $studentId, true);
            if (wuc_risk_table_exists($db, 'academic_alerts') && in_array($risk['risk_level'], ['Medium', 'High'], true)) {
                error_log('AI academic risk recalculated after ' . $trigger . ' for ' . $studentId . ': ' . $risk['risk_level'] . ' (' . $risk['risk_score'] . ')');
            }
            return $risk;
        } catch (Throwable $e) {
            error_log('wuc_academic_risk_after_student_activity failed after ' . $trigger . ' for ' . $studentId . ': ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wuc_academic_risk_level_class')) {
    function wuc_academic_risk_level_class(string $level): string
    {
        if ($level === 'High') {
            return 'danger';
        }
        if ($level === 'Medium') {
            return 'warning';
        }
        return 'success';
    }
}

if (!function_exists('wuc_academic_risk_render_student_card')) {
    function wuc_academic_risk_render_student_card(array $risk): string
    {
        $level = (string)($risk['risk_level'] ?? 'Low');
        $class = wuc_academic_risk_level_class($level);
        $reasons = $risk['risk_reasons'] ?? [];
        $dataNotes = $risk['data_notes'] ?? [];
        $reasonKeys = is_array($risk['reason_keys'] ?? null) ? $risk['reason_keys'] : [];
        $studentGuidance = (string)($risk['student_guidance'] ?? wuc_risk_student_guidance($reasonKeys, $level));

        ob_start();
        ?>
        <article class="card">
            <div class="card-hdr">
                <h3><i class="fas fa-brain"></i> AI Academic Insight</h3>
                <span class="badge bg-<?= htmlspecialchars($class) ?>"><?= htmlspecialchars($level) ?> Risk</span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                    <div>
                        <div class="text-muted small">Risk Score</div>
                        <div class="fs-3 fw-bold text-<?= htmlspecialchars($class) ?>"><?= htmlspecialchars((string)($risk['risk_score'] ?? 0)) ?></div>
                    </div>
                    <div class="flex-grow-1">
                        <div class="text-muted small">Recommended Support</div>
                        <div><?= htmlspecialchars($studentGuidance) ?></div>
                    </div>
                </div>
                <div class="mb-2 fw-semibold">Reason</div>
                <ul class="mb-3 ps-3">
                    <?php foreach ($reasons as $reason): ?>
                        <li><?= htmlspecialchars((string)$reason) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($dataNotes): ?>
                    <div class="alert alert-light border mb-0 small">
                        <strong>Data note:</strong>
                        <?= htmlspecialchars(implode(' ', array_slice($dataNotes, 0, 2))) ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('wuc_academic_risk_lecturer_summary')) {
    function wuc_academic_risk_lecturer_summary(mysqli $db, string $staffId): array
    {
        $summary = [
            'assigned_courses' => [],
            'students_checked' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'top_learners' => [],
        ];

        if (!wuc_risk_table_exists($db, 'course_lecturer') || !wuc_risk_table_exists($db, 'course_registration')) {
            return $summary;
        }

        $summary['assigned_courses'] = wuc_lecturer_resolved_course_codes($db, $staffId);
        if (!$summary['assigned_courses']) {
            return $summary;
        }

        $placeholders = implode(',', array_fill(0, count($summary['assigned_courses']), '?'));
        $sql = "SELECT DISTINCT cr.Sid, CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, '')) AS student_name
                FROM course_registration cr
                LEFT JOIN students s ON s.SID = cr.Sid
                WHERE cr.course_code IN ($placeholders)
                  AND COALESCE(cr.is_active, 1) = 1
                ORDER BY cr.Sid
                LIMIT 80";
        if (!$stmt = $db->prepare($sql)) {
            return $summary;
        }
        wuc_risk_bind_values($stmt, str_repeat('s', count($summary['assigned_courses'])), $summary['assigned_courses']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid = trim((string)($row['Sid'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $risk = wuc_academic_risk_analyze_student($db, $sid, false);
            $summary['students_checked']++;
            $levelKey = strtolower((string)$risk['risk_level']);
            if (isset($summary[$levelKey])) {
                $summary[$levelKey]++;
            }
            if ($risk['risk_level'] !== 'Low') {
                $summary['top_learners'][] = [
                    'sid' => $sid,
                    'name' => trim((string)($row['student_name'] ?? '')) ?: $sid,
                    'score' => (int)$risk['risk_score'],
                    'level' => (string)$risk['risk_level'],
                    'action' => (string)$risk['recommended_action'],
                ];
            }
        }
        $stmt->close();

        usort($summary['top_learners'], static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });
        $summary['top_learners'] = array_slice($summary['top_learners'], 0, 5);

        return $summary;
    }
}

if (!function_exists('wuc_academic_risk_department_summary')) {
    function wuc_academic_risk_department_summary(mysqli $db, $departmentId = null, string $departmentCode = ''): array
    {
        $summary = [
            'students_checked' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'top_learners' => [],
            'scope_label' => 'Department',
        ];

        if (!wuc_risk_table_exists($db, 'student_program') || !wuc_risk_table_exists($db, 'programs')) {
            return $summary;
        }

        $where = [];
        $types = '';
        $params = [];
        if ($departmentId !== null && $departmentId !== '' && is_numeric($departmentId)) {
            $where[] = 'p.department_id = ?';
            $types .= 'i';
            $params[] = (int)$departmentId;
        }
        if ($departmentCode !== '') {
            $where[] = 'CAST(p.department_id AS CHAR) = ?';
            $types .= 's';
            $params[] = $departmentCode;
        }
        if (!$where) {
            return $summary;
        }

        $sql = "SELECT DISTINCT sp.Sid, CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, '')) AS student_name
                FROM student_program sp
                INNER JOIN programs p ON p.program_code = sp.program_code
                LEFT JOIN students s ON s.SID = sp.Sid
                WHERE (" . implode(' OR ', $where) . ")
                  AND COALESCE(sp.status, 'active') NOT IN ('inactive', 'withdrawn', 'suspended')
                ORDER BY sp.Sid
                LIMIT 150";
        if (!$stmt = $db->prepare($sql)) {
            return $summary;
        }
        wuc_risk_bind_values($stmt, $types, $params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid = trim((string)($row['Sid'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $risk = wuc_academic_risk_analyze_student($db, $sid, false);
            $summary['students_checked']++;
            $levelKey = strtolower((string)$risk['risk_level']);
            if (isset($summary[$levelKey])) {
                $summary[$levelKey]++;
            }
            if ($risk['risk_level'] !== 'Low') {
                $summary['top_learners'][] = [
                    'sid' => $sid,
                    'name' => trim((string)($row['student_name'] ?? '')) ?: $sid,
                    'score' => (int)$risk['risk_score'],
                    'level' => (string)$risk['risk_level'],
                    'action' => (string)$risk['recommended_action'],
                ];
            }
        }
        $stmt->close();

        usort($summary['top_learners'], static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });
        $summary['top_learners'] = array_slice($summary['top_learners'], 0, 8);

        return $summary;
    }
}

if (!function_exists('wuc_academic_risk_course_scope_summary')) {
    function wuc_academic_risk_course_scope_summary(mysqli $db, array $courseCodes): array
    {
        $summary = [
            'students_checked' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'top_learners' => [],
            'scope_label' => 'Course scope',
        ];

        $courseCodes = array_values(array_unique(array_filter(array_map(static function ($code): string {
            return trim((string)$code);
        }, $courseCodes))));

        if (!$courseCodes || !wuc_risk_table_exists($db, 'course_registration')) {
            return $summary;
        }

        $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
        $sql = "SELECT DISTINCT cr.Sid, CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, '')) AS student_name
                FROM course_registration cr
                LEFT JOIN students s ON s.SID = cr.Sid
                WHERE cr.course_code IN ($placeholders)
                  AND COALESCE(cr.is_active, 1) = 1
                ORDER BY cr.Sid
                LIMIT 150";
        if (!$stmt = $db->prepare($sql)) {
            return $summary;
        }
        wuc_risk_bind_values($stmt, str_repeat('s', count($courseCodes)), $courseCodes);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid = trim((string)($row['Sid'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $risk = wuc_academic_risk_analyze_student($db, $sid, false);
            $summary['students_checked']++;
            $levelKey = strtolower((string)$risk['risk_level']);
            if (isset($summary[$levelKey])) {
                $summary[$levelKey]++;
            }
            if ($risk['risk_level'] !== 'Low') {
                $summary['top_learners'][] = [
                    'sid' => $sid,
                    'name' => trim((string)($row['student_name'] ?? '')) ?: $sid,
                    'score' => (int)$risk['risk_score'],
                    'level' => (string)$risk['risk_level'],
                    'action' => (string)$risk['recommended_action'],
                ];
            }
        }
        $stmt->close();

        usort($summary['top_learners'], static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });
        $summary['top_learners'] = array_slice($summary['top_learners'], 0, 8);

        return $summary;
    }
}

if (!function_exists('wuc_academic_risk_open_alerts')) {
    function wuc_academic_risk_open_alerts(mysqli $db, array $filters = [], int $limit = 8): array
    {
        if (!wuc_risk_table_exists($db, 'academic_alerts')) {
            return [];
        }

        $where = ["a.alert_status IN ('Unread', 'Read', 'In Progress')"];
        $types = '';
        $params = [];

        if (!empty($filters['student_id'])) {
            $where[] = 'a.student_id = ?';
            $types .= 's';
            $params[] = (string)$filters['student_id'];
        }
        if (!empty($filters['staff_id'])) {
            $where[] = '(a.staff_id = ? OR a.staff_id IS NULL)';
            $types .= 's';
            $params[] = (string)$filters['staff_id'];
        }
        if (isset($filters['department_id']) && $filters['department_id'] !== '' && $filters['department_id'] !== null) {
            $where[] = 'a.department_id = ?';
            $types .= 'i';
            $params[] = (int)$filters['department_id'];
        }
        if (!empty($filters['department_ids']) && is_array($filters['department_ids'])) {
            $deptIds = array_values(array_filter(array_map('intval', $filters['department_ids']), static function ($v) { return $v > 0; }));
            if ($deptIds) {
                $where[] = 'a.department_id IN (' . implode(',', array_fill(0, count($deptIds), '?')) . ')';
                $types .= str_repeat('i', count($deptIds));
                foreach ($deptIds as $deptId) {
                    $params[] = $deptId;
                }
            }
        }

        $limit = max(1, min(25, $limit));
        $sql = "SELECT a.*, CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, '')) AS student_name
                FROM academic_alerts a
                LEFT JOIN students s ON s.SID = a.student_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.created_at DESC
                LIMIT {$limit}";
        if (!$stmt = $db->prepare($sql)) {
            return [];
        }
        if ($types !== '') {
            wuc_risk_bind_values($stmt, $types, $params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $alerts = [];
        while ($row = $res->fetch_assoc()) {
            $alerts[] = $row;
        }
        $stmt->close();

        return $alerts;
    }
}

if (!function_exists('wuc_academic_risk_lecturer_alerts')) {
    function wuc_academic_risk_lecturer_alerts(mysqli $db, string $staffId, int $limit = 8): array
    {
        if (!wuc_risk_table_exists($db, 'academic_alerts') || !wuc_risk_table_exists($db, 'course_lecturer') || !wuc_risk_table_exists($db, 'course_registration')) {
            return [];
        }

        $limit = max(1, min(25, $limit));
        $sql = "SELECT DISTINCT a.*, CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, '')) AS student_name
                FROM academic_alerts a
                INNER JOIN course_registration cr ON cr.Sid = a.student_id
                INNER JOIN course_lecturer cl ON cl.course_code = cr.course_code
                INNER JOIN courses c ON c.course_code = cl.course_code
                LEFT JOIN students s ON s.SID = a.student_id
                WHERE cl.staff_id = ?
                  AND COALESCE(cl.status, 'active') <> 'inactive'
                  AND COALESCE(cr.is_active, 1) = 1
                  AND a.alert_status IN ('Unread', 'Read', 'In Progress')
                ORDER BY a.created_at DESC
                LIMIT {$limit}";
        if (!$stmt = $db->prepare($sql)) {
            return [];
        }
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $res = $stmt->get_result();
        $alerts = [];
        while ($row = $res->fetch_assoc()) {
            $alerts[] = $row;
        }
        $stmt->close();

        return $alerts;
    }
}

if (!function_exists('wuc_academic_risk_course_alerts')) {
    function wuc_academic_risk_course_alerts(mysqli $db, array $courseCodes, int $limit = 8): array
    {
        $courseCodes = array_values(array_unique(array_filter(array_map(static function ($code): string {
            return trim((string)$code);
        }, $courseCodes))));

        if (!$courseCodes || !wuc_risk_table_exists($db, 'academic_alerts') || !wuc_risk_table_exists($db, 'course_registration')) {
            return [];
        }

        $limit = max(1, min(25, $limit));
        $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
        $sql = "SELECT DISTINCT a.*, CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, '')) AS student_name
                FROM academic_alerts a
                INNER JOIN course_registration cr ON cr.Sid = a.student_id
                LEFT JOIN students s ON s.SID = a.student_id
                WHERE cr.course_code IN ($placeholders)
                  AND COALESCE(cr.is_active, 1) = 1
                  AND a.alert_status IN ('Unread', 'Read', 'In Progress')
                ORDER BY a.created_at DESC
                LIMIT {$limit}";
        if (!$stmt = $db->prepare($sql)) {
            return [];
        }
        wuc_risk_bind_values($stmt, str_repeat('s', count($courseCodes)), $courseCodes);
        $stmt->execute();
        $res = $stmt->get_result();
        $alerts = [];
        while ($row = $res->fetch_assoc()) {
            $alerts[] = $row;
        }
        $stmt->close();

        return $alerts;
    }
}

if (!function_exists('wuc_academic_risk_render_lecturer_panel')) {
    function wuc_academic_risk_render_lecturer_panel(array $summary): string
    {
        ob_start();
        ?>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary"><i class="fas fa-brain me-2"></i>AI Teaching Insight</h5>
                <span class="badge bg-primary"><?= htmlspecialchars((string)$summary['students_checked']) ?> learners checked</span>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-danger bg-opacity-10">
                            <div class="text-muted small">High-risk learners</div>
                            <div class="fs-4 fw-bold text-danger"><?= htmlspecialchars((string)$summary['high']) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-warning bg-opacity-10">
                            <div class="text-muted small">Medium-risk learners</div>
                            <div class="fs-4 fw-bold text-warning"><?= htmlspecialchars((string)$summary['medium']) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-success bg-opacity-10">
                            <div class="text-muted small">Low-risk learners</div>
                            <div class="fs-4 fw-bold text-success"><?= htmlspecialchars((string)$summary['low']) ?></div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($summary['top_learners'])): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Learner</th>
                                    <th>Level</th>
                                    <th>Score</th>
                                    <th>Recommended action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($summary['top_learners'] as $learner): ?>
                                    <?php $class = wuc_academic_risk_level_class((string)$learner['level']); ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$learner['name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars((string)$learner['sid']) ?></small>
                                        </td>
                                        <td><span class="badge bg-<?= htmlspecialchars($class) ?>"><?= htmlspecialchars((string)$learner['level']) ?></span></td>
                                        <td><?= htmlspecialchars((string)$learner['score']) ?></td>
                                        <td><?= htmlspecialchars((string)$learner['action']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-muted"><i class="fas fa-circle-check me-1"></i>No medium or high-risk learner detected from available LMS data.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('wuc_academic_risk_render_department_panel')) {
    function wuc_academic_risk_render_department_panel(array $summary): string
    {
        ob_start();
        ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary"><i class="fas fa-brain me-2"></i>AI Department Insight</h5>
                <span class="badge bg-primary"><?= htmlspecialchars((string)$summary['students_checked']) ?> learners checked</span>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-danger bg-opacity-10">
                            <div class="text-muted small">High-risk learners</div>
                            <div class="fs-4 fw-bold text-danger"><?= htmlspecialchars((string)$summary['high']) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-warning bg-opacity-10">
                            <div class="text-muted small">Medium-risk learners</div>
                            <div class="fs-4 fw-bold text-warning"><?= htmlspecialchars((string)$summary['medium']) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-success bg-opacity-10">
                            <div class="text-muted small">Low-risk learners</div>
                            <div class="fs-4 fw-bold text-success"><?= htmlspecialchars((string)$summary['low']) ?></div>
                        </div>
                    </div>
                </div>
                <?php if (!empty($summary['top_learners'])): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Learner</th>
                                    <th>Level</th>
                                    <th>Score</th>
                                    <th>Recommended intervention</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($summary['top_learners'] as $learner): ?>
                                    <?php $class = wuc_academic_risk_level_class((string)$learner['level']); ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$learner['name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars((string)$learner['sid']) ?></small>
                                        </td>
                                        <td><span class="badge bg-<?= htmlspecialchars($class) ?>"><?= htmlspecialchars((string)$learner['level']) ?></span></td>
                                        <td><?= htmlspecialchars((string)$learner['score']) ?></td>
                                        <td><?= htmlspecialchars((string)$learner['action']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-muted"><i class="fas fa-circle-check me-1"></i>No department-level medium or high-risk learner detected from available LMS data.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('wuc_academic_risk_render_alerts_panel')) {
    function wuc_academic_risk_render_alerts_panel(array $alerts, string $title = 'AI Academic Alerts'): string
    {
        ob_start();
        ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary"><i class="fas fa-bell me-2"></i><?= htmlspecialchars($title) ?></h5>
                <span class="badge bg-danger"><?= htmlspecialchars((string)count($alerts)) ?> open</span>
            </div>
            <div class="card-body">
                <?php if ($alerts): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($alerts as $alert): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <strong><?= htmlspecialchars(trim((string)($alert['student_name'] ?? '')) ?: (string)$alert['student_id']) ?></strong>
                                        <div class="small text-muted"><?= htmlspecialchars((string)$alert['student_id']) ?> &middot; <?= htmlspecialchars((string)$alert['alert_category']) ?></div>
                                    </div>
                                    <span class="badge bg-warning text-dark align-self-start"><?= htmlspecialchars((string)$alert['alert_status']) ?></span>
                                </div>
                                <div class="small mt-2"><?= htmlspecialchars((string)$alert['alert_message']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted"><i class="fas fa-circle-check me-1"></i>No open AI academic alerts in this scope.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('wuc_academic_risk_report_summary')) {
    function wuc_academic_risk_report_summary(mysqli $db, array $studentIds, string $reportType, string $referenceId = '', bool $persist = true): array
    {
        $studentIds = array_values(array_unique(array_filter(array_map(static function ($sid): string {
            return trim((string)$sid);
        }, $studentIds))));

        $summary = [
            'report_type' => $reportType,
            'students_checked' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'summary_text' => 'No learners were available for AI academic risk summarisation.',
            'saved' => false,
        ];

        foreach (array_slice($studentIds, 0, 150) as $sid) {
            if (!preg_match('/^[A-Za-z0-9\/\-_]+$/', $sid)) {
                continue;
            }
            $risk = wuc_academic_risk_analyze_student($db, $sid, false);
            $summary['students_checked']++;
            $levelKey = strtolower((string)$risk['risk_level']);
            if (isset($summary[$levelKey])) {
                $summary[$levelKey]++;
            }
        }

        if ($summary['students_checked'] > 0) {
            $riskShare = round((($summary['high'] + $summary['medium']) / $summary['students_checked']) * 100, 1);
            $summary['summary_text'] = 'AI report summary: ' . $summary['students_checked'] . ' learner(s) were checked. '
                . $summary['high'] . ' are High risk, ' . $summary['medium'] . ' are Medium risk, and '
                . $summary['low'] . ' are Low risk. ' . $riskShare
                . '% need monitoring or intervention. Recommended action: prioritise high-risk follow-up first, then monitor medium-risk learners weekly.';
        }

        if ($persist && wuc_risk_table_exists($db, 'ai_report_summaries')) {
            try {
                $stmt = $db->prepare(
                    'INSERT INTO ai_report_summaries (report_type, reference_id, summary_text, generated_by)
                     VALUES (?, ?, ?, ?)'
                );
                if ($stmt) {
                    $generatedBy = 'System AI';
                    $stmt->bind_param('ssss', $reportType, $referenceId, $summary['summary_text'], $generatedBy);
                    $stmt->execute();
                    $stmt->close();
                    $summary['saved'] = true;
                }
            } catch (Throwable $e) {
                error_log('wuc_academic_risk_report_summary failed to save: ' . $e->getMessage());
            }
        }

        return $summary;
    }
}

if (!function_exists('wuc_academic_risk_render_report_summary')) {
    function wuc_academic_risk_render_report_summary(array $summary): string
    {
        ob_start();
        ?>
        <div class="alert alert-primary d-flex align-items-start gap-2">
            <i class="fas fa-brain mt-1"></i>
            <div>
                <div class="fw-semibold mb-1">AI Report Summary</div>
                <div><?= htmlspecialchars((string)$summary['summary_text']) ?></div>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}
