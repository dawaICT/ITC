<?php
/**
 * Shared helpers for identifying and describing short-course students.
 *
 * Single source of truth so the dashboard, registration page, navigation and
 * any future callers agree on who is a short-course student and what they are
 * enrolled in.
 *
 * A *short-course-only* student has no academic program (no student_program
 * row) yet is enrolled in at least one short course, and therefore sits outside
 * the semester/term registration workflow.
 */

require_once __DIR__ . '/short_course_db.php'; // sc_table_exists / sc_has_column / sc_insert

if (!function_exists('sc_tables_present')) {
    function sc_tables_present(mysqli $db): bool
    {
        static $present = null;
        if ($present !== null) {
            return $present;
        }
        $present = false;
        $a = @$db->query("SHOW TABLES LIKE 'short_course_enrollments'");
        $b = @$db->query("SHOW TABLES LIKE 'short_courses'");
        if ($a && $b) {
            $present = ($a->num_rows > 0 && $b->num_rows > 0);
            $a->free();
            $b->free();
        }
        return $present;
    }
}

if (!function_exists('sc_student_has_program')) {
    /** True when the student has an academic program (a student_program row). */
    function sc_student_has_program(mysqli $db, string $sid): bool
    {
        if ($sid === '') {
            return false;
        }
        if ($stmt = @$db->prepare("SELECT 1 FROM student_program WHERE Sid = ? LIMIT 1")) {
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $stmt->store_result();
            $has = $stmt->num_rows > 0;
            $stmt->close();
            return $has;
        }
        return false;
    }
}

if (!function_exists('sc_student_enrolments')) {
    /**
     * Active short-course enrolments for a student, most recent first.
     * @return array<int,array<string,mixed>>
     */
    function sc_student_enrolments(mysqli $db, string $sid): array
    {
        if ($sid === '' || !sc_tables_present($db)) {
            return [];
        }
        $sql = "SELECT sc.id AS short_course_id, sc.course_code, sc.course_name,
                       sc.duration_value, sc.duration_unit, sc.delivery_mode,
                       sc.fee, sc.start_date, sc.end_date, sc.status AS course_status,
                       sce.status, sce.enrollment_date, sce.completion_date,
                       sce.certificate_issued, sce.updated_at AS enrollment_updated_at
                FROM short_course_enrollments sce
                JOIN short_courses sc ON sce.short_course_id = sc.id
                WHERE sce.student_id = ?
                  AND sce.status IN ('enrolled','active','completed')
                ORDER BY FIELD(sce.status, 'active', 'enrolled', 'completed'), sce.enrollment_date DESC, sce.id DESC";
        $rows = [];
        if ($stmt = @$db->prepare($sql)) {
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            if ($res = $stmt->get_result()) {
                while ($r = $res->fetch_assoc()) {
                    $rows[] = $r;
                }
            }
            $stmt->close();
        }
        return $rows;
    }
}

if (!function_exists('isShortCourseStudent')) {
    /**
     * True for a short-course-only student: enrolled in at least one short
     * course AND without an academic program.
     */
    function isShortCourseStudent(mysqli $db, string $sid): bool
    {
        if (sc_student_has_program($db, $sid)) {
            return false;
        }
        return sc_student_enrolments($db, $sid) !== [];
    }
}

if (!function_exists('sc_student_fee_summary')) {
    /**
     * Fee obligation for a short-course student, derived from the headline fee
     * on each enrolled course (short_courses.fee, which the accounts module
     * keeps in sync with any short_course fee_structure rows).
     *
     * @return array{total_due:float,items:array<int,array<string,mixed>>,has_fees:bool}
     */
    function sc_student_fee_summary(mysqli $db, string $sid): array
    {
        $items = [];
        $totalDue = 0.0;
        foreach (sc_student_enrolments($db, $sid) as $e) {
            $fee = (float)($e['fee'] ?? 0);
            $items[] = [
                'course_code' => (string)($e['course_code'] ?? ''),
                'course_name' => (string)($e['course_name'] ?? ''),
                'status'      => (string)($e['status'] ?? ''),
                'fee'         => $fee,
            ];
            $totalDue += $fee;
        }
        return [
            'total_due' => $totalDue,
            'items'     => $items,
            'has_fees'  => $totalDue > 0,
        ];
    }
}

if (!function_exists('sc_ensure_fee_invoice')) {
    /**
     * Ensure a short-course student has an open invoice for their outstanding
     * course fee, so the existing invoice/payment flow (students/payment.php)
     * can collect it. Idempotent: reuses an existing unpaid short-course invoice
     * and keeps its amount in sync with the current total. Returns
     * ['invoice_no' => string, 'amount' => float] or null when not applicable.
     */
    function sc_ensure_fee_invoice(mysqli $db, string $sid): ?array
    {
        if ($sid === '' || !sc_table_exists($db, 'invoices')) {
            return null;
        }
        // Only proceed on a recognisable invoices schema.
        if (!sc_has_column($db, 'invoices', 'invoice_no')
            || !sc_has_column($db, 'invoices', 'student_id')
            || !sc_has_column($db, 'invoices', 'amount')) {
            return null;
        }

        $summary = sc_student_fee_summary($db, $sid);
        if (empty($summary['has_fees'])) {
            return null;
        }
        $due = round((float) $summary['total_due'], 2);
        $desc = 'Short Course Fees';
        $hasDesc = sc_has_column($db, 'invoices', 'description');
        $hasStatus = sc_has_column($db, 'invoices', 'status');

        // Reuse an existing open short-course invoice if present.
        if ($hasDesc) {
            $sql = "SELECT id, invoice_no, amount FROM invoices WHERE student_id = ? AND description = ?";
            if ($hasStatus) {
                $sql .= " AND LOWER(status) NOT IN ('paid','completed','cleared','cancelled','void')";
            }
            $sql .= " ORDER BY id DESC LIMIT 1";
            if ($st = $db->prepare($sql)) {
                $st->bind_param('ss', $sid, $desc);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row) {
                    if (abs((float) $row['amount'] - $due) > 0.005) {
                        if ($u = $db->prepare('UPDATE invoices SET amount = ? WHERE id = ?')) {
                            $u->bind_param('di', $due, $row['id']);
                            $u->execute();
                            $u->close();
                        }
                    }
                    return ['invoice_no' => (string) $row['invoice_no'], 'amount' => $due];
                }
            }
        }

        // Create a new invoice.
        $invoiceNo = 'SC-' . preg_replace('/[^A-Za-z0-9]/', '', $sid) . '-' . date('ymdHis');
        $data = ['student_id' => $sid, 'invoice_no' => $invoiceNo, 'amount' => $due];
        if ($hasDesc) {
            $data['description'] = $desc;
        }
        if (sc_has_column($db, 'invoices', 'invoice_date')) {
            $data['invoice_date'] = date('Y-m-d H:i:s');
        }
        if ($hasStatus) {
            $data['status'] = 'unpaid';
        }
        if (sc_has_column($db, 'invoices', 'created_at')) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        try {
            sc_insert($db, 'invoices', $data);
        } catch (Throwable $e) {
            return null;
        }
        return ['invoice_no' => $invoiceNo, 'amount' => $due];
    }
}

if (!function_exists('sc_derive_end_date')) {
    /**
     * Resolve a short course end date. Honours an explicit end date; otherwise
     * derives it from start_date + duration so scheduling and result-entry
     * windows are always defined. Returns 'Y-m-d', or null when undeterminable.
     */
    function sc_derive_end_date(?string $start, $durationValue, $durationUnit, ?string $end = null): ?string
    {
        $end = $end !== null ? trim($end) : '';
        if ($end !== '') {
            return $end;
        }
        $start = $start !== null ? trim($start) : '';
        $durationValue = (int)$durationValue;
        $durationUnit = strtolower(trim((string)$durationUnit));
        $validUnits = ['days', 'weeks', 'months'];
        if ($start === '' || $durationValue <= 0 || !in_array($durationUnit, $validUnits, true)) {
            return null;
        }
        $startTs = strtotime($start);
        if ($startTs === false) {
            return null;
        }
        $endTs = strtotime("+{$durationValue} {$durationUnit}", $startTs);
        return $endTs ? date('Y-m-d', $endTs) : null;
    }
}

if (!function_exists('sc_format_duration')) {
    /** Human-readable duration, e.g. "3 Months". Empty string when unknown. */
    function sc_format_duration($value, $unit): string
    {
        $value = (int)$value;
        $unit = trim((string)$unit);
        if ($value <= 0 || $unit === '') {
            return '';
        }
        return $value . ' ' . ucfirst($unit);
    }
}
