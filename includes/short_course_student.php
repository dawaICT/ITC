<?php
/**
 * Shared helpers for identifying and describing short-course students.
 *
 * Portal separation:
 * - Long-term programmes live in `programs` (term/semester/trade-test) + student_program
 *   and use the academic student portal (registration, courseReg, annual CA, …).
 * - Short courses live in `short_courses` + short_course_enrollments and use the
 *   short-course portal (short_courses.php, short-course registration/CA).
 * - A student with an active long programme is never classified as a short-course
 *   portal user, even if they also have short-course enrolments (secondary only).
 * - programmes flagged is_short_course / structure_type SHORT_COURSE do not count
 *   as long-term programmes.
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

if (!function_exists('sc_program_is_short_course')) {
    /**
     * Whether a course/programme code represents a short-course entry
     * (should not drive long-term term/semester workflows).
     */
    function sc_program_is_short_course(mysqli $db, string $programCode): bool
    {
        $programCode = trim($programCode);
        if ($programCode === '') {
            return false;
        }
        static $cache = [];
        if (array_key_exists($programCode, $cache)) {
            return $cache[$programCode];
        }

        // 1. Direct check in short_courses catalogue table
        if (sc_table_exists($db, 'short_courses')) {
            if ($stmtSc = @$db->prepare("SELECT 1 FROM short_courses WHERE course_code = ? LIMIT 1")) {
                $stmtSc->bind_param('s', $programCode);
                $stmtSc->execute();
                $stmtSc->store_result();
                $isSc = $stmtSc->num_rows > 0;
                $stmtSc->close();
                if ($isSc) {
                    return $cache[$programCode] = true;
                }
            }
        }

        // 2. Check in courses table for course_type = 'short course'
        if (sc_table_exists($db, 'courses')) {
            if ($stmtC = @$db->prepare("SELECT 1 FROM courses WHERE course_code = ? AND (course_type = 'short course' OR LOWER(category) LIKE '%short%') LIMIT 1")) {
                $stmtC->bind_param('s', $programCode);
                $stmtC->execute();
                $stmtC->store_result();
                $isScC = $stmtC->num_rows > 0;
                $stmtC->close();
                if ($isScC) {
                    return $cache[$programCode] = true;
                }
            }
        }

        // 3. Check in programs table for short course flags
        if (sc_table_exists($db, 'programs')) {
            $hasShortFlag = sc_has_column($db, 'programs', 'is_short_course');
            $hasStructure = sc_has_column($db, 'programs', 'structure_type');
            $hasAcademicStructure = sc_has_column($db, 'programs', 'academic_structure');
            $hasType = sc_has_column($db, 'programs', 'program_type');

            if ($hasShortFlag || $hasStructure || $hasAcademicStructure || $hasType) {
                $select = [];
                if ($hasShortFlag) {
                    $select[] = 'is_short_course';
                }
                if ($hasStructure) {
                    $select[] = 'structure_type';
                }
                if ($hasAcademicStructure) {
                    $select[] = 'academic_structure';
                }
                if ($hasType) {
                    $select[] = 'program_type';
                }
                $sql = 'SELECT ' . implode(', ', $select) . ' FROM programs WHERE program_code = ? LIMIT 1';
                if ($stmt = @$db->prepare($sql)) {
                    $stmt->bind_param('s', $programCode);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc() ?: null;
                    $stmt->close();
                    if ($row) {
                        if ($hasShortFlag && (int)($row['is_short_course'] ?? 0) === 1) {
                            return $cache[$programCode] = true;
                        }
                        $structure = strtoupper(trim((string)($row['structure_type'] ?? '')));
                        if ($structure === 'SHORT_COURSE') {
                            return $cache[$programCode] = true;
                        }
                        $academicStructure = strtolower(trim((string)($row['academic_structure'] ?? '')));
                        if ($academicStructure === 'short_course') {
                            return $cache[$programCode] = true;
                        }
                        $ptype = strtolower(trim((string)($row['program_type'] ?? '')));
                        if ($ptype !== '' && (str_contains($ptype, 'short course') || $ptype === 'short_course')) {
                            return $cache[$programCode] = true;
                        }
                    }
                }
            }
        }

        return $cache[$programCode] = false;
    }
}

if (!function_exists('sc_sql_programs_long_only_predicate')) {
    /**
     * SQL fragment (no leading AND) that keeps only long-term programme rows.
     * Alias defaults to `p` for programs.
     */
    function sc_sql_programs_long_only_predicate(mysqli $db, string $alias = 'p'): string
    {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'p';
        $parts = [];
        if (sc_has_column($db, 'programs', 'is_short_course')) {
            $parts[] = "COALESCE({$alias}.is_short_course, 0) = 0";
        }
        if (sc_has_column($db, 'programs', 'structure_type')) {
            $parts[] = "({$alias}.structure_type IS NULL OR {$alias}.structure_type = '' OR UPPER({$alias}.structure_type) <> 'SHORT_COURSE')";
        }
        if (sc_has_column($db, 'programs', 'academic_structure')) {
            $parts[] = "({$alias}.academic_structure IS NULL OR LOWER({$alias}.academic_structure) <> 'short_course')";
        }
        if (sc_has_column($db, 'programs', 'program_type')) {
            $parts[] = "({$alias}.program_type IS NULL OR LOWER({$alias}.program_type) NOT LIKE '%short course%' AND LOWER({$alias}.program_type) <> 'short_course')";
        }
        return $parts !== [] ? implode(' AND ', $parts) : '1=1';
    }
}

if (!function_exists('sc_student_has_program')) {
    /**
     * True when the student has any student_program row (legacy; includes short
     * programme flags). Prefer sc_student_has_long_program() for portal routing.
     */
    function sc_student_has_program(mysqli $db, string $sid): bool
    {
        if ($sid === '') {
            return false;
        }
        if ($stmt = @$db->prepare('SELECT 1 FROM student_program WHERE Sid = ? LIMIT 1')) {
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

if (!function_exists('sc_student_has_long_program')) {
    /**
     * True when the student is assigned an active long-term (non-short) programme.
     */
    function sc_student_has_long_program(mysqli $db, string $sid): bool
    {
        if ($sid === '') {
            return false;
        }
        if (!sc_table_exists($db, 'student_program')) {
            return false;
        }

        $hasPrograms = sc_table_exists($db, 'programs');
        $statusFilter = sc_has_column($db, 'student_program', 'status')
            ? " AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')"
            : '';

        if ($hasPrograms) {
            $longPred = sc_sql_programs_long_only_predicate($db, 'p');
            $sql = "SELECT sp.program_code
                      FROM student_program sp
                      INNER JOIN programs p ON p.program_code = sp.program_code
                     WHERE sp.Sid = ? {$statusFilter}
                       AND ({$longPred})
                     LIMIT 1";
        } else {
            $sql = "SELECT sp.program_code FROM student_program sp WHERE sp.Sid = ? {$statusFilter} LIMIT 1";
        }

        if (!$stmt = @$db->prepare($sql)) {
            return false;
        }
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $res = $stmt->get_result();
        $hasLong = false;
        if ($res && ($row = $res->fetch_assoc())) {
            $pCode = (string)($row['program_code'] ?? '');
            if (!sc_program_is_short_course($db, $pCode)) {
                $hasLong = true;
            }
        }
        $stmt->close();
        return $hasLong;
    }
}

if (!function_exists('sc_resolve_course_meta')) {
    /**
     * Helper to look up short course title, duration, delivery mode, and fee
     * across short_courses, courses, and programs tables.
     * @return array{id:int,name:string,duration_value:int,duration_unit:string,delivery_mode:string,fee:float}
     */
    function sc_resolve_course_meta(mysqli $db, string $code): array
    {
        $res = [
            'id' => 0,
            'name' => $code,
            'duration_value' => 0,
            'duration_unit' => 'days',
            'delivery_mode' => 'full-time',
            'fee' => 0.0,
        ];
        if ($code === '') {
            return $res;
        }

        if (sc_table_exists($db, 'short_courses')) {
            if ($st = @$db->prepare("SELECT id, course_name, duration_value, duration_unit, delivery_mode, fee FROM short_courses WHERE course_code = ? LIMIT 1")) {
                $st->bind_param('s', $code);
                $st->execute();
                if ($r = $st->get_result()->fetch_assoc()) {
                    $res['id'] = (int)($r['id'] ?? 0);
                    if (!empty($r['course_name'])) { $res['name'] = (string)$r['course_name']; }
                    if (!empty($r['duration_value'])) { $res['duration_value'] = (int)$r['duration_value']; }
                    if (!empty($r['duration_unit'])) { $res['duration_unit'] = (string)$r['duration_unit']; }
                    if (!empty($r['delivery_mode'])) { $res['delivery_mode'] = (string)$r['delivery_mode']; }
                    $res['fee'] = (float)($r['fee'] ?? 0);
                    $st->close();
                    return $res;
                }
                $st->close();
            }
        }

        if (sc_table_exists($db, 'courses')) {
            if ($stC = @$db->prepare("SELECT id, course_name, duration, duration_unit, course_fee FROM courses WHERE course_code = ? LIMIT 1")) {
                $stC->bind_param('s', $code);
                $stC->execute();
                if ($rC = $stC->get_result()->fetch_assoc()) {
                    if (!empty($rC['course_name'])) { $res['name'] = (string)$rC['course_name']; }
                    if (!empty($rC['duration']) && is_numeric($rC['duration'])) {
                        $res['duration_value'] = (int)$rC['duration'];
                    }
                    if (!empty($rC['duration_unit'])) { $res['duration_unit'] = (string)$rC['duration_unit']; }
                    if (!empty($rC['course_fee'])) { $res['fee'] = (float)$rC['course_fee']; }
                    $stC->close();
                    return $res;
                }
                $stC->close();
            }
        }

        if (sc_table_exists($db, 'programs')) {
            if ($stP = @$db->prepare("SELECT program_name, duration_value, duration_unit FROM programs WHERE program_code = ? LIMIT 1")) {
                $stP->bind_param('s', $code);
                $stP->execute();
                if ($rP = $stP->get_result()->fetch_assoc()) {
                    if (!empty($rP['program_name'])) { $res['name'] = (string)$rP['program_name']; }
                    if (!empty($rP['duration_value'])) { $res['duration_value'] = (int)$rP['duration_value']; }
                    if (!empty($rP['duration_unit'])) { $res['duration_unit'] = (string)$rP['duration_unit']; }
                }
                $stP->close();
            }
        }

        return $res;
    }
}

if (!function_exists('sc_student_enrolments')) {
    /**
     * Active short-course enrolments for a student, most recent first.
     * Consolidates short_course_enrollments, student_program short-course assignments,
     * students.program short-course assignments, and course_registration short-courses.
     * @return array<int,array<string,mixed>>
     */
    function sc_student_enrolments(mysqli $db, string $sid): array
    {
        $sid = trim($sid);
        if ($sid === '') {
            return [];
        }

        $enrolmentsByCode = [];

        // 1. Canonical source: short_course_enrollments JOIN short_courses
        if (sc_tables_present($db)) {
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
            if ($stmt = @$db->prepare($sql)) {
                $stmt->bind_param('s', $sid);
                $stmt->execute();
                if ($res = $stmt->get_result()) {
                    while ($r = $res->fetch_assoc()) {
                        $codeKey = strtoupper(trim((string)$r['course_code']));
                        if ($codeKey !== '') {
                            $enrolmentsByCode[$codeKey] = $r;
                        }
                    }
                }
                $stmt->close();
            }
        }

        // 2. Fallback / supplementary source: student_program table
        if (sc_table_exists($db, 'student_program')) {
            $statusFilter = sc_has_column($db, 'student_program', 'status')
                ? "AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')"
                : '';
            $sqlSp = "SELECT sp.program_code, sp.id AS sp_id, sp.registration_date, sp.created_at
                      FROM student_program sp
                      WHERE sp.Sid = ? {$statusFilter}
                      ORDER BY sp.id DESC";
            if ($stSp = @$db->prepare($sqlSp)) {
                $stSp->bind_param('s', $sid);
                $stSp->execute();
                $resSp = $stSp->get_result();
                while ($spRow = $resSp->fetch_assoc()) {
                    $progCode = trim((string)$spRow['program_code']);
                    $codeKey = strtoupper($progCode);
                    if ($progCode !== '' && !isset($enrolmentsByCode[$codeKey]) && sc_program_is_short_course($db, $progCode)) {
                        $meta = sc_resolve_course_meta($db, $progCode);
                        $enrolDate = !empty($spRow['registration_date']) ? (string)$spRow['registration_date'] : (!empty($spRow['created_at']) ? (string)$spRow['created_at'] : date('Y-m-d H:i:s'));
                        $enrolmentsByCode[$codeKey] = [
                            'short_course_id' => $meta['id'],
                            'course_code' => $progCode,
                            'course_name' => $meta['name'],
                            'duration_value' => $meta['duration_value'],
                            'duration_unit' => $meta['duration_unit'],
                            'delivery_mode' => $meta['delivery_mode'],
                            'fee' => $meta['fee'],
                            'start_date' => null,
                            'end_date' => null,
                            'course_status' => 'active',
                            'status' => 'enrolled',
                            'enrollment_date' => $enrolDate,
                            'completion_date' => null,
                            'certificate_issued' => 0,
                            'enrollment_updated_at' => $enrolDate,
                        ];
                    }
                }
                $stSp->close();
            }
        }

        // 3. Fallback / supplementary source: students.program column
        if (sc_table_exists($db, 'students')) {
            $sqlStu = "SELECT s.program, s.dte_adm, s.created_at FROM students s WHERE s.SID = ? LIMIT 1";
            if ($stStu = @$db->prepare($sqlStu)) {
                $stStu->bind_param('s', $sid);
                $stStu->execute();
                $resStu = $stStu->get_result();
                if ($stuRow = $resStu->fetch_assoc()) {
                    $stuProg = trim((string)($stuRow['program'] ?? ''));
                    $codeKey = strtoupper($stuProg);
                    if ($stuProg !== '' && !isset($enrolmentsByCode[$codeKey]) && sc_program_is_short_course($db, $stuProg)) {
                        $meta = sc_resolve_course_meta($db, $stuProg);
                        $enrolDate = !empty($stuRow['dte_adm']) ? (string)$stuRow['dte_adm'] : (!empty($stuRow['created_at']) ? (string)$stuRow['created_at'] : date('Y-m-d H:i:s'));
                        $enrolmentsByCode[$codeKey] = [
                            'short_course_id' => $meta['id'],
                            'course_code' => $stuProg,
                            'course_name' => $meta['name'],
                            'duration_value' => $meta['duration_value'],
                            'duration_unit' => $meta['duration_unit'],
                            'delivery_mode' => $meta['delivery_mode'],
                            'fee' => $meta['fee'],
                            'start_date' => null,
                            'end_date' => null,
                            'course_status' => 'active',
                            'status' => 'enrolled',
                            'enrollment_date' => $enrolDate,
                            'completion_date' => null,
                            'certificate_issued' => 0,
                            'enrollment_updated_at' => $enrolDate,
                        ];
                    }
                }
                $stStu->close();
            }
        }

        // 4. Fallback / supplementary source: course_registration
        if (sc_table_exists($db, 'course_registration')) {
            $sqlCr = "SELECT course_code, registration_date, created_at FROM course_registration WHERE Sid = ? AND COALESCE(is_active, 1) = 1 ORDER BY id DESC";
            if ($stCr = @$db->prepare($sqlCr)) {
                $stCr->bind_param('s', $sid);
                $stCr->execute();
                $resCr = $stCr->get_result();
                while ($crRow = $resCr->fetch_assoc()) {
                    $crCode = trim((string)($crRow['course_code'] ?? ''));
                    $codeKey = strtoupper($crCode);
                    if ($crCode !== '' && !isset($enrolmentsByCode[$codeKey]) && sc_program_is_short_course($db, $crCode)) {
                        $meta = sc_resolve_course_meta($db, $crCode);
                        $enrolDate = !empty($crRow['registration_date']) ? (string)$crRow['registration_date'] : (!empty($crRow['created_at']) ? (string)$crRow['created_at'] : date('Y-m-d H:i:s'));
                        $enrolmentsByCode[$codeKey] = [
                            'short_course_id' => $meta['id'],
                            'course_code' => $crCode,
                            'course_name' => $meta['name'],
                            'duration_value' => $meta['duration_value'],
                            'duration_unit' => $meta['duration_unit'],
                            'delivery_mode' => $meta['delivery_mode'],
                            'fee' => $meta['fee'],
                            'start_date' => null,
                            'end_date' => null,
                            'course_status' => 'active',
                            'status' => 'enrolled',
                            'enrollment_date' => $enrolDate,
                            'completion_date' => null,
                            'certificate_issued' => 0,
                            'enrollment_updated_at' => $enrolDate,
                        ];
                    }
                }
                $stCr->close();
            }
        }

        return array_values($enrolmentsByCode);
    }
}

if (!function_exists('sc_student_portal_mode')) {
    /**
     * Primary student portal mode for navigation and page gates.
     *
     * @return 'long_program'|'short_course'|'none'
     */
    function sc_student_portal_mode(mysqli $db, string $sid): string
    {
        if ($sid === '') {
            return 'none';
        }
        if (sc_student_has_long_program($db, $sid)) {
            return 'long_program';
        }
        if (sc_student_enrolments($db, $sid) !== []) {
            return 'short_course';
        }
        // student_program only for short-flagged programmes still maps to short portal
        if (sc_student_has_program($db, $sid)) {
            // Has assignment but not long → treat as short-oriented / blocked long path
            return 'short_course';
        }
        return 'none';
    }
}

if (!function_exists('isShortCourseStudent')) {
    /**
     * True when the student's *primary* portal is short-course (no long programme).
     * Long-programme students with secondary short-course enrolments return false.
     */
    function isShortCourseStudent(mysqli $db, string $sid): bool
    {
        return sc_student_portal_mode($db, $sid) === 'short_course';
    }
}

if (!function_exists('isLongProgramStudent')) {
    /** True when the student's primary portal is long-term academic. */
    function isLongProgramStudent(mysqli $db, string $sid): bool
    {
        return sc_student_portal_mode($db, $sid) === 'long_program';
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
