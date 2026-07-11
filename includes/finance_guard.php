<?php
// Finance guard helpers for CA eligibility
// Usage: require_once dirname(__DIR__) . '/includes/finance_guard.php'; then call is_student_allowed_ca($db, $sid, $academicYear, $semester)

if (!function_exists('wuc_table_exists')) {
    function wuc_table_exists(mysqli $db, string $table): bool {
        if ($res = @$db->query("SHOW TABLES LIKE '".$db->real_escape_string($table)."'")) { $ok = ($res->num_rows > 0); @$res->free(); return $ok; }
        return false;
    }
}

if (!function_exists('wuc_column_exists')) {
    function wuc_column_exists(mysqli $db, string $table, string $column): bool {
        if ($res = @$db->query("SHOW COLUMNS FROM `{$table}` LIKE '".$db->real_escape_string($column)."'")) { $ok = ($res->num_rows > 0); @$res->free(); return $ok; }
        return false;
    }
}

if (!function_exists('wuc_get_setting')) {
    function wuc_get_setting(mysqli $db, string $key, string $default = '1'): string {
        $val = $default;
        if (!wuc_table_exists($db, 'portal_settings')) {
            error_log('portal_settings table is missing; run migrations.');
            return $val;
        }
        if ($st = @$db->prepare("SELECT setting_value FROM portal_settings WHERE setting_key = ? LIMIT 1")) {
            $st->bind_param('s', $key);
            if ($st->execute()) { $res = $st->get_result(); if ($res && $res->num_rows) { $val = (string)$res->fetch_assoc()['setting_value']; } }
            $st->close();
        }
        return $val;
    }
}

if (!function_exists('wuc_payment_rule_percent')) {
    function wuc_payment_rule_percent(mysqli $db, string $ruleKey, string $legacySettingKey, float $default, string $module = 'assessment'): float {
        if (!function_exists('wuc_business_rule')) {
            $enterpriseServices = __DIR__ . '/enterprise_services.php';
            if (is_file($enterpriseServices)) {
                require_once $enterpriseServices;
            }
        }

        if (function_exists('wuc_business_rule')) {
            $rule = wuc_business_rule($db, $ruleKey, null, $module);
            if (is_array($rule) && isset($rule['percent']) && is_numeric($rule['percent'])) {
                return max(0.0, min(100.0, (float)$rule['percent']));
            }
        }

        $legacy = wuc_get_setting($db, $legacySettingKey, (string)$default);
        return max(0.0, min(100.0, is_numeric($legacy) ? (float)$legacy : $default));
    }
}

if (!function_exists('wuc_get_student_term_paid')) {
    function wuc_get_student_term_paid(mysqli $db, string $sid, string $academicYear, string $semester): float {
        $paid = 0.0;
        if (wuc_table_exists($db, 'payments') && wuc_column_exists($db, 'payments', 'amount')) {
            $sql = "SELECT SUM(amount) AS total FROM payments WHERE student_id COLLATE utf8mb4_general_ci = ?";
            $types = 's';
            $params = [$sid];
            if (wuc_column_exists($db, 'payments', 'academic_year')) {
                $sql .= " AND academic_year = ?";
                $types .= 's';
                $params[] = $academicYear;
            }
            if (wuc_column_exists($db, 'payments', 'semester')) {
                $sql .= " AND semester = ?";
                $types .= 's';
                $params[] = $semester;
            }
            if (wuc_column_exists($db, 'payments', 'status')) {
                $sql .= " AND status IN ('posted','completed','confirmed')";
            }
            if ($st = @$db->prepare($sql)) {
                $st->bind_param($types, ...$params);
                if ($st->execute() && ($rs = $st->get_result()) && ($row = $rs->fetch_assoc())) { $paid += (float)($row['total'] ?? 0.0); }
                $st->close();
            }
        }

        if (wuc_table_exists($db, 'student_payments')) {
            $amountCol = wuc_column_exists($db, 'student_payments', 'amount_paid') ? 'amount_paid' : (wuc_column_exists($db, 'student_payments', 'amount') ? 'amount' : null);
            $sidCol = wuc_column_exists($db, 'student_payments', 'Sid') ? 'Sid' : (wuc_column_exists($db, 'student_payments', 'SID') ? 'SID' : null);
            if ($amountCol === null || $sidCol === null) {
                return $paid;
            }
            $hasAy = wuc_column_exists($db, 'student_payments', 'academic_year');
            $hasSem = wuc_column_exists($db, 'student_payments', 'semester_term');
            $statusCol = wuc_column_exists($db, 'student_payments', 'payment_status') ? 'payment_status' : (wuc_column_exists($db, 'student_payments', 'status') ? 'status' : null);
            $sql = "SELECT SUM(`{$amountCol}`) AS total FROM student_payments WHERE `{$sidCol}` = ?";
            if ($hasAy) { $sql .= " AND academic_year = ?"; }
            if ($hasSem) { $sql .= " AND semester_term = ?"; }
            if ($statusCol) { $sql .= " AND `{$statusCol}` IN ('posted','completed','confirmed')"; }
            if ($st = @$db->prepare($sql)) {
                if ($hasAy && $hasSem) { $st->bind_param('sss', $sid, $academicYear, $semester); }
                elseif ($hasAy && !$hasSem) { $st->bind_param('ss', $sid, $academicYear); }
                elseif (!$hasAy && $hasSem) { $st->bind_param('ss', $sid, $semester); }
                else { $st->bind_param('s', $sid); }
                if ($st->execute() && ($rs = $st->get_result()) && ($row = $rs->fetch_assoc())) { $paid = (float)($row['total'] ?? 0.0); }
                $st->close();
            }
        }
        return $paid;
    }
}

if (!function_exists('wuc_get_student_term_due')) {
    function wuc_get_student_term_due(mysqli $db, string $sid, string $academicYear, string $semester): float {
        // Prefer invoices amount
        if (wuc_table_exists($db, 'invoices') && wuc_column_exists($db, 'invoices', 'amount')) {
            $sidCol = wuc_column_exists($db, 'invoices', 'student_id') ? 'student_id' : (wuc_column_exists($db, 'invoices', 'SID') ? 'SID' : null);
            $ayCol = wuc_column_exists($db, 'invoices', 'academic_year') ? 'academic_year' : null;
            $semCol = wuc_column_exists($db, 'invoices', 'semester') ? 'semester' : null;
            if ($sidCol) {
                $sql = "SELECT SUM(amount) AS total FROM invoices WHERE `$sidCol` COLLATE utf8mb4_general_ci = ?";
                $types = 's'; $params = [$sid];
                if ($ayCol) { $sql .= " AND `$ayCol` = ?"; $types .= 's'; $params[] = $academicYear; }
                if ($semCol) { $sql .= " AND `$semCol` = ?"; $types .= 's'; $params[] = $semester; }
                if ($st = @$db->prepare($sql)) {
                    $st->bind_param($types, ...$params);
                    if ($st->execute() && ($rs = $st->get_result()) && ($row = $rs->fetch_assoc())) { return (float)($row['total'] ?? 0.0); }
                    $st->close();
                }
            }
        }
        // Fallback: program_fees.semester_fee
        if (wuc_table_exists($db, 'program_fees') && wuc_column_exists($db, 'program_fees', 'semester_fee')) {
            // Find student's program_code
            if (wuc_table_exists($db, 'student_program')) {
                $sidCol = wuc_column_exists($db, 'student_program', 'Sid') ? 'Sid' : (wuc_column_exists($db, 'student_program', 'SID') ? 'SID' : null);
                $progCol = wuc_column_exists($db, 'student_program', 'program_code') ? 'program_code' : null;
                if ($sidCol && $progCol) {
                    if ($st = @$db->prepare("SELECT `$progCol` FROM student_program WHERE `$sidCol` COLLATE utf8mb4_general_ci = ? LIMIT 1")) {
                        $st->bind_param('s', $sid);
                        if ($st->execute() && ($rs = $st->get_result()) && ($row = $rs->fetch_assoc())) {
                            $pc = (string)$row[$progCol];
                            if ($st2 = @$db->prepare("SELECT semester_fee FROM program_fees WHERE program_code = ? LIMIT 1")) {
                                $st2->bind_param('s', $pc);
                                if ($st2->execute() && ($rs2 = $st2->get_result()) && ($row2 = $rs2->fetch_assoc())) { return (float)($row2['semester_fee'] ?? 0.0); }
                                $st2->close();
                            }
                        }
                        $st->close();
                    }
                }
            }
        }
        // Fallback: fee_structure (flat extras)
        if (wuc_table_exists($db, 'fee_structure')) {
            // Try sum of active fees for student's program and semester
            $due = 0.0;
            $progCode = null;
            if (wuc_table_exists($db, 'student_program') && wuc_column_exists($db, 'student_program', 'program_code')) {
                $sidCol = wuc_column_exists($db, 'student_program', 'Sid') ? 'Sid' : (wuc_column_exists($db, 'student_program', 'SID') ? 'SID' : null);
                if ($sidCol) {
                    if ($st = @$db->prepare("SELECT program_code FROM student_program WHERE `$sidCol` COLLATE utf8mb4_general_ci = ? LIMIT 1")) {
                        $st->bind_param('s', $sid);
                        if ($st->execute() && ($rs = $st->get_result()) && ($row = $rs->fetch_assoc())) { $progCode = $row['program_code']; }
                        $st->close();
                    }
                }
            }
            if ($progCode) {
                if ($st = @$db->prepare("SELECT SUM(amount) AS total FROM fee_structure WHERE program_code = ? AND semester = ? AND status = 'active'")) {
                    $st->bind_param('ss', $progCode, $semester);
                    if ($st->execute() && ($rs = $st->get_result()) && ($row = $rs->fetch_assoc())) { $due = (float)($row['total'] ?? 0.0); }
                    $st->close();
                }
            }
            return $due;
        }
        return 0.0;
    }
}

if (!function_exists('wuc_resolve_latest_term')) {
    function wuc_resolve_latest_term(mysqli $db, string $sid): array {
        // Try latest student_payments
        if (wuc_table_exists($db, 'student_payments')) {
            $sidCol = wuc_column_exists($db, 'student_payments', 'Sid') ? 'Sid' : (wuc_column_exists($db, 'student_payments', 'SID') ? 'SID' : null);
            $ayCol = wuc_column_exists($db, 'student_payments', 'academic_year') ? 'academic_year' : null;
            $semCol = wuc_column_exists($db, 'student_payments', 'semester_term') ? 'semester_term' : null;
            $orderCol = wuc_column_exists($db, 'student_payments', 'payment_id') ? 'payment_id' : (wuc_column_exists($db, 'student_payments', 'id') ? 'id' : null);
            if ($sidCol && $ayCol && $semCol && $orderCol) {
                if ($st = @$db->prepare("SELECT `$ayCol` AS ay, `$semCol` AS sem FROM student_payments WHERE `{$sidCol}` = ? ORDER BY `$orderCol` DESC LIMIT 1")) {
                    $st->bind_param('s', $sid);
                    if ($st->execute() && ($rs = $st->get_result()) && ($row = $rs->fetch_assoc())) { return array('academic_year' => (string)$row['ay'], 'semester' => (string)$row['sem']); }
                    $st->close();
                }
            }
        }
        // Try semester_registration
        if (wuc_table_exists($db, 'semester_registration')) {
            $sidCol = wuc_column_exists($db, 'semester_registration', 'student_id') ? 'student_id' : (wuc_column_exists($db, 'semester_registration', 'Sid') ? 'Sid' : (wuc_column_exists($db, 'semester_registration', 'SID') ? 'SID' : null));
            $ayCol = wuc_column_exists($db, 'semester_registration', 'academic_year') ? 'academic_year' : null;
            $semCol = wuc_column_exists($db, 'semester_registration', 'semester') ? 'semester' : null;
            $orderCol = wuc_column_exists($db, 'semester_registration', 'id') ? 'id' : null;
            if ($sidCol && $ayCol && $semCol) {
                $sql = "SELECT `$ayCol` AS ay, `$semCol` AS sem FROM semester_registration WHERE `{$sidCol}` = ?" . ($orderCol?" ORDER BY `$orderCol` DESC":"") . " LIMIT 1";
                if ($st = @$db->prepare($sql)) { $st->bind_param('s', $sid); if ($st->execute() && ($rs = $st->get_result()) && ($row = $rs->fetch_assoc())) { return array('academic_year' => (string)$row['ay'], 'semester' => (string)$row['sem']); } $st->close(); }
            }
        }
        // Fallback: default to Year 1 and semester by month
        $m = (int)date('n'); $sem = ($m >= 1 && $m <= 6) ? '1' : '2';
        return array('academic_year' => '1', 'semester' => (string)$sem);
    }
}

if (!function_exists('wuc_student_payment_eligibility')) {
    /**
     * Shared core for payment-based mark-entry eligibility.
     *
     * Returns ['allowed'=>bool, 'percent'=>float, 'reason'=>string]. The threshold
     * is supplied by the caller so the same computation backs both the CA gate
     * (>= 50% by default) and the exam/test gate (100% by default) without
     * duplicating the sponsorship, course_registration and fallback logic.
     */
    function wuc_student_payment_eligibility(mysqli $db, string $sid, ?string $academicYear, ?string $semester, float $minPercent, bool $enforce): array {
        if (!$enforce) { return array('allowed' => true, 'percent' => 100.0, 'reason' => 'Enforcement disabled'); }
        if ($academicYear === null || $semester === null) {
            $term = wuc_resolve_latest_term($db, $sid); $academicYear = $academicYear ?? $term['academic_year']; $semester = $semester ?? $term['semester'];
        }

        // Check sponsorship — fully-sponsored students are exempt from the fee gate.
        if (wuc_table_exists($db, 'students') && wuc_column_exists($db, 'students', 'sponsor')) {
            $sqlSponsor = "SELECT sponsor FROM students WHERE SID = ? LIMIT 1";
            if ($stSponsor = @$db->prepare($sqlSponsor)) {
                $stSponsor->bind_param('s', $sid);
                if ($stSponsor->execute() && ($rsSponsor = $stSponsor->get_result()) && ($rowSponsor = $rsSponsor->fetch_assoc())) {
                    $sponsor = strtoupper(trim((string)($rowSponsor['sponsor'] ?? '')));
                    if (in_array($sponsor, ['TEVETA', 'CDF'], true)) {
                        $stSponsor->close();
                        return array('allowed' => true, 'percent' => 100.0, 'reason' => 'Sponsorship: ' . $sponsor);
                    }
                }
                $stSponsor->close();
            }
        }

        // Try course_registration table first (most specific: per-course fee tracking)
        if (wuc_table_exists($db, 'course_registration') &&
            wuc_column_exists($db, 'course_registration', 'tuition_total') &&
            wuc_column_exists($db, 'course_registration', 'amount_paid')) {

            // Sum all active registrations for this student in this term.
            // course_registration.Year is the year-of-study; the calendar academic
            // year is academic_year, so match on that when the column exists
            // (tolerating NULL for rows not yet backfilled).
            $yearPredicate = wuc_column_exists($db, 'course_registration', 'academic_year')
                ? '(academic_year = ? OR academic_year IS NULL)'
                : 'Year = ?';
            if ($st = @$db->prepare("SELECT SUM(tuition_total) AS total_due, SUM(amount_paid) AS total_paid
                                     FROM course_registration
                                     WHERE Sid = ? AND {$yearPredicate} AND semester = ? AND (is_active = 1 OR status = 'active')")) {
                $st->bind_param('sss', $sid, $academicYear, $semester);
                if ($st->execute() && ($rs = $st->get_result()) && ($row = $rs->fetch_assoc())) {
                    $due = (float)($row['total_due'] ?? 0.0);
                    $paid = (float)($row['total_paid'] ?? 0.0);
                    if ($due > 0.0) {
                        $percent = ($paid / $due) * 100.0;
                        $st->close();
                        return array(
                            'allowed' => ($percent + 1e-6) >= $minPercent,
                            'percent' => round($percent, 2),
                            'reason' => 'Course registration: '.$paid.' of '.$due.' paid'
                        );
                    }
                }
                $st->close();
            }
        }

        // Fallback to general payment tracking
        $due = wuc_get_student_term_due($db, $sid, $academicYear, $semester);
        $paid = wuc_get_student_term_paid($db, $sid, $academicYear, $semester);
        if ($due <= 0.0) { return array('allowed' => true, 'percent' => 100.0, 'reason' => 'No due amount'); }
        $percent = ($paid / $due) * 100.0;
        return array('allowed' => ($percent + 1e-6) >= $minPercent, 'percent' => round($percent, 2), 'reason' => $paid.' of '.$due.' paid');
    }
}

if (!function_exists('wuc_period_required_payment_percent')) {
    /** Period-based threshold (50% first period, 100% later) from the workflow service. */
    function wuc_period_required_payment_percent(mysqli $db, string $sid, float $fallback = 50.0): float {
        $workflowPath = dirname(__DIR__) . '/students/includes/StudentAcademicWorkflowService.php';
        if (!is_file($workflowPath)) {
            return $fallback;
        }
        require_once $workflowPath;
        $svc = new StudentAcademicWorkflowService($db);
        $period = $svc->getActiveAcademicPeriod($sid);
        if (!$period['ok']) {
            return $fallback;
        }
        return StudentAcademicWorkflowService::requiredFeePercentage(
            (string)$period['calendar_type'],
            (int)$period['period_number']
        );
    }
}

if (!function_exists('is_student_allowed_ca')) {
    /** Continuous Assessment gate: uses centralized period-based fee threshold. */
    function is_student_allowed_ca(mysqli $db, string $sid, ?string $academicYear = null, ?string $semester = null): array {
        $enforce = wuc_get_setting($db, 'enforce_ca_payment', '1') === '1';
        $minPercent = wuc_period_required_payment_percent($db, $sid, wuc_payment_rule_percent($db, 'assessment.ca.minimum_payment_percent', 'min_ca_paid_percent', 50.0));
        return wuc_student_payment_eligibility($db, $sid, $academicYear, $semester, $minPercent, $enforce);
    }
}

if (!function_exists('is_student_allowed_exam')) {
    /** Exam / test gate: same period-based threshold as registration and exam slip. */
    function is_student_allowed_exam(mysqli $db, string $sid, ?string $academicYear = null, ?string $semester = null): array {
        $enforce = wuc_get_setting($db, 'enforce_exam_payment', '1') === '1';
        $minPercent = wuc_period_required_payment_percent($db, $sid, wuc_payment_rule_percent($db, 'assessment.exam.minimum_payment_percent', 'min_exam_paid_percent', 100.0));
        return wuc_student_payment_eligibility($db, $sid, $academicYear, $semester, $minPercent, $enforce);
    }
}
?>
