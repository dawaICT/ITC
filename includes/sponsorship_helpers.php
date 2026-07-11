<?php
/**
 * Unified Sponsorship & Scholarship Management — core helper library.
 *
 * ONE configurable framework for every funding source (Self, CDF, TEVETA,
 * Government, Company, NGO, Church, Employer, Staff Development, Other …).
 * Programmes remain normal academic programmes; funding eligibility is
 * data-driven via sponsor_types + programme_sponsorship_eligibility, never
 * hardcoded.
 *
 * Builds on the existing finance_sponsors (sponsor organisations) and
 * finance_student_sponsors (per-student sponsorship profile) tables — see
 * migrations/2026_sponsorship_management.sql.
 *
 * Conventions: MySQLi + prepared statements; academic year = calendar year
 * (e.g. "2026"); every mutating action is audit-logged via log_audit().
 */

declare(strict_types=1);

require_once __DIR__ . '/audit.php';

if (!function_exists('sponsorship_table_exists')) {
    function sponsorship_table_exists(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        $res = @$db->query("SHOW TABLES LIKE '{$safe}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) { $res->free(); }
        return $ok;
    }
}

if (!function_exists('sponsorship_column_exists')) {
    function sponsorship_column_exists(mysqli $db, string $table, string $column): bool
    {
        $safeT = str_replace('`', '', $table);
        $safeC = $db->real_escape_string($column);
        $res = @$db->query("SHOW COLUMNS FROM `{$safeT}` LIKE '{$safeC}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) { $res->free(); }
        return $ok;
    }
}

if (!function_exists('sponsorship_schema_ready')) {
    /** True when the migration has been applied (tables + key columns present). */
    function sponsorship_schema_ready(mysqli $db): bool
    {
        static $ready = null;
        if ($ready !== null) { return $ready; }
        $ready = sponsorship_table_exists($db, 'sponsor_types')
            && sponsorship_table_exists($db, 'programme_sponsorship_eligibility')
            && sponsorship_table_exists($db, 'finance_student_sponsors')
            && sponsorship_column_exists($db, 'finance_student_sponsors', 'approval_status');
        return $ready;
    }
}

if (!function_exists('sponsorship_roles_manage')) {
    /** Roles allowed to configure sponsor types / programme eligibility / records. */
    function sponsorship_roles_manage(): array
    {
        return ['Systems Admin', 'Registrar', 'Admission Officer', 'Accountant'];
    }
}

if (!function_exists('sponsorship_roles_approve')) {
    /** Roles allowed to approve / reject / cancel a student sponsorship. */
    function sponsorship_roles_approve(): array
    {
        return ['Systems Admin', 'Registrar', 'Accountant', 'Admission Officer'];
    }
}

if (!function_exists('sponsorship_audit')) {
    /** Best-effort audit trail entry; never throws. */
    function sponsorship_audit(mysqli $db, string $actor, string $action, $details = null): void
    {
        try {
            audit_log($db, $actor !== '' ? $actor : 'system', $action, $details);
        } catch (Throwable $e) {
            error_log('sponsorship_audit failed: ' . $e->getMessage());
        }
    }
}

/* ===========================================================================
 * Sponsor TYPES (configurable categories)
 * ======================================================================== */

if (!function_exists('get_sponsor_types')) {
    function get_sponsor_types(mysqli $db, bool $activeOnly = true): array
    {
        if (!sponsorship_schema_ready($db)) { return []; }
        $sql = "SELECT id, code, name, description, requires_approval, is_self_funded, is_system, is_active, sort_order
                FROM sponsor_types";
        if ($activeOnly) { $sql .= " WHERE is_active = 1"; }
        $sql .= " ORDER BY sort_order, name";
        $rows = [];
        if ($res = @$db->query($sql)) {
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
            $res->free();
        }
        return $rows;
    }
}

if (!function_exists('get_sponsor_type')) {
    function get_sponsor_type(mysqli $db, int $id): ?array
    {
        if (!sponsorship_schema_ready($db)) { return null; }
        $stmt = $db->prepare("SELECT * FROM sponsor_types WHERE id = ? LIMIT 1");
        if (!$stmt) { return null; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('get_sponsor_type_by_code')) {
    function get_sponsor_type_by_code(mysqli $db, string $code): ?array
    {
        if (!sponsorship_schema_ready($db)) { return null; }
        $stmt = $db->prepare("SELECT * FROM sponsor_types WHERE code = ? LIMIT 1");
        if (!$stmt) { return null; }
        $code = strtolower(trim($code));
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('create_sponsor_type')) {
    /**
     * Add a new configurable sponsor type. $data: code, name, description,
     * requires_approval, is_self_funded, sort_order.
     * @return array ['success'=>bool, 'id'=>?int, 'error'=>?string]
     */
    function create_sponsor_type(mysqli $db, array $data, string $actor): array
    {
        if (!sponsorship_schema_ready($db)) { return ['success' => false, 'error' => 'Sponsorship module not installed.']; }
        $code = strtolower(trim((string)($data['code'] ?? '')));
        $name = trim((string)($data['name'] ?? ''));
        if ($code === '' || !preg_match('/^[a-z0-9_]{2,40}$/', $code)) {
            return ['success' => false, 'error' => 'Code must be 2-40 chars: lowercase letters, digits, underscore.'];
        }
        if ($name === '') { return ['success' => false, 'error' => 'Name is required.']; }
        if (get_sponsor_type_by_code($db, $code)) {
            return ['success' => false, 'error' => "Sponsor type code '{$code}' already exists."];
        }
        $desc = trim((string)($data['description'] ?? '')) ?: null;
        $reqAppr = !empty($data['requires_approval']) ? 1 : 0;
        $selfFunded = !empty($data['is_self_funded']) ? 1 : 0;
        $sort = (int)($data['sort_order'] ?? 0);
        $stmt = $db->prepare("INSERT INTO sponsor_types (code, name, description, requires_approval, is_self_funded, is_system, is_active, sort_order)
                              VALUES (?, ?, ?, ?, ?, 0, 1, ?)");
        if (!$stmt) { return ['success' => false, 'error' => 'Database error.']; }
        $stmt->bind_param('sssiii', $code, $name, $desc, $reqAppr, $selfFunded, $sort);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); return ['success' => false, 'error' => $err]; }
        $id = (int)$stmt->insert_id;
        $stmt->close();
        sponsorship_audit($db, $actor, 'sponsor_type_create', ['id' => $id, 'code' => $code, 'name' => $name]);
        return ['success' => true, 'id' => $id];
    }
}

if (!function_exists('update_sponsor_type')) {
    function update_sponsor_type(mysqli $db, int $id, array $data, string $actor): array
    {
        if (!sponsorship_schema_ready($db)) { return ['success' => false, 'error' => 'Sponsorship module not installed.']; }
        $existing = get_sponsor_type($db, $id);
        if (!$existing) { return ['success' => false, 'error' => 'Sponsor type not found.']; }
        $name = trim((string)($data['name'] ?? $existing['name']));
        if ($name === '') { return ['success' => false, 'error' => 'Name is required.']; }
        $desc = array_key_exists('description', $data) ? (trim((string)$data['description']) ?: null) : $existing['description'];
        $reqAppr = array_key_exists('requires_approval', $data) ? (!empty($data['requires_approval']) ? 1 : 0) : (int)$existing['requires_approval'];
        $selfFunded = array_key_exists('is_self_funded', $data) ? (!empty($data['is_self_funded']) ? 1 : 0) : (int)$existing['is_self_funded'];
        $sort = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : (int)$existing['sort_order'];
        $stmt = $db->prepare("UPDATE sponsor_types SET name=?, description=?, requires_approval=?, is_self_funded=?, sort_order=? WHERE id=?");
        if (!$stmt) { return ['success' => false, 'error' => 'Database error.']; }
        $stmt->bind_param('ssiiii', $name, $desc, $reqAppr, $selfFunded, $sort, $id);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) { sponsorship_audit($db, $actor, 'sponsor_type_update', ['id' => $id, 'name' => $name]); }
        return ['success' => $ok];
    }
}

if (!function_exists('set_sponsor_type_active')) {
    function set_sponsor_type_active(mysqli $db, int $id, bool $active, string $actor): array
    {
        if (!sponsorship_schema_ready($db)) { return ['success' => false, 'error' => 'Sponsorship module not installed.']; }
        $flag = $active ? 1 : 0;
        $stmt = $db->prepare("UPDATE sponsor_types SET is_active=? WHERE id=?");
        if (!$stmt) { return ['success' => false, 'error' => 'Database error.']; }
        $stmt->bind_param('ii', $flag, $id);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) { sponsorship_audit($db, $actor, 'sponsor_type_' . ($active ? 'activate' : 'deactivate'), ['id' => $id]); }
        return ['success' => $ok];
    }
}

/* ===========================================================================
 * Programme eligibility
 * ======================================================================== */

if (!function_exists('get_programme_sponsor_options')) {
    /** Sponsor types a programme accepts (joined to type details). */
    function get_programme_sponsor_options(mysqli $db, string $programCode, bool $activeOnly = true): array
    {
        if (!sponsorship_schema_ready($db)) { return []; }
        $sql = "SELECT pse.id AS eligibility_id, pse.program_code, pse.sponsor_type_id,
                       pse.max_sponsored_students, pse.sponsorship_requirements, pse.academic_requirements,
                       pse.intake_restrictions, pse.is_active,
                       st.code AS sponsor_type_code, st.name AS sponsor_type_name,
                       st.is_self_funded, st.requires_approval
                FROM programme_sponsorship_eligibility pse
                JOIN sponsor_types st ON st.id = pse.sponsor_type_id
                WHERE pse.program_code = ?";
        if ($activeOnly) { $sql .= " AND pse.is_active = 1 AND st.is_active = 1"; }
        $sql .= " ORDER BY st.sort_order, st.name";
        $stmt = $db->prepare($sql);
        if (!$stmt) { return []; }
        $stmt->bind_param('s', $programCode);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('is_programme_sponsorship_eligible')) {
    function is_programme_sponsorship_eligible(mysqli $db, string $programCode, int $sponsorTypeId): bool
    {
        if (!sponsorship_schema_ready($db)) { return false; }
        $stmt = $db->prepare("SELECT 1 FROM programme_sponsorship_eligibility
                              WHERE program_code = ? AND sponsor_type_id = ? AND is_active = 1 LIMIT 1");
        if (!$stmt) { return false; }
        $stmt->bind_param('si', $programCode, $sponsorTypeId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('set_programme_eligibility')) {
    /** Upsert programme→sponsor-type eligibility. $data: program_code, sponsor_type_id,
     *  max_sponsored_students, sponsorship_requirements, academic_requirements, intake_restrictions, is_active. */
    function set_programme_eligibility(mysqli $db, array $data, string $actor): array
    {
        if (!sponsorship_schema_ready($db)) { return ['success' => false, 'error' => 'Sponsorship module not installed.']; }
        $prog = trim((string)($data['program_code'] ?? ''));
        $typeId = (int)($data['sponsor_type_id'] ?? 0);
        if ($prog === '' || $typeId <= 0) { return ['success' => false, 'error' => 'Programme and sponsor type are required.']; }
        if (!get_sponsor_type($db, $typeId)) { return ['success' => false, 'error' => 'Unknown sponsor type.']; }
        $max = isset($data['max_sponsored_students']) && $data['max_sponsored_students'] !== '' ? (int)$data['max_sponsored_students'] : null;
        $reqs = trim((string)($data['sponsorship_requirements'] ?? '')) ?: null;
        $acad = trim((string)($data['academic_requirements'] ?? '')) ?: null;
        $intake = trim((string)($data['intake_restrictions'] ?? '')) ?: null;
        $active = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1;
        $stmt = $db->prepare("INSERT INTO programme_sponsorship_eligibility
                  (program_code, sponsor_type_id, max_sponsored_students, sponsorship_requirements, academic_requirements, intake_restrictions, is_active, created_by)
                  VALUES (?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE
                    max_sponsored_students=VALUES(max_sponsored_students),
                    sponsorship_requirements=VALUES(sponsorship_requirements),
                    academic_requirements=VALUES(academic_requirements),
                    intake_restrictions=VALUES(intake_restrictions),
                    is_active=VALUES(is_active)");
        if (!$stmt) { return ['success' => false, 'error' => 'Database error.']; }
        $stmt->bind_param('siisssss', $prog, $typeId, $max, $reqs, $acad, $intake, $active, $actor);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) { sponsorship_audit($db, $actor, 'programme_eligibility_set', ['program_code' => $prog, 'sponsor_type_id' => $typeId, 'max' => $max, 'active' => $active]); }
        return ['success' => $ok];
    }
}

if (!function_exists('programme_sponsor_usage')) {
    /** Capacity usage for a programme+type: ['max'=>?int, 'used'=>int, 'available'=>?int, 'full'=>bool]. */
    function programme_sponsor_usage(mysqli $db, string $programCode, int $sponsorTypeId, string $academicYear = ''): array
    {
        $out = ['max' => null, 'used' => 0, 'available' => null, 'full' => false];
        if (!sponsorship_schema_ready($db)) { return $out; }
        $stmt = $db->prepare("SELECT max_sponsored_students FROM programme_sponsorship_eligibility
                              WHERE program_code = ? AND sponsor_type_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('si', $programCode, $sponsorTypeId);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $out['max'] = $row['max_sponsored_students'] !== null ? (int)$row['max_sponsored_students'] : null;
            }
            $stmt->close();
        }
        // Count active+approved (or pending) sponsorships consuming a slot.
        $sql = "SELECT COUNT(DISTINCT student_id) c FROM finance_student_sponsors
                WHERE program_code = ? AND sponsor_type_id = ?
                  AND approval_status IN ('pending','approved') AND status = 'active'";
        $params = [$programCode, $sponsorTypeId];
        $types = 'si';
        if ($academicYear !== '') { $sql .= " AND academic_year = ?"; $params[] = $academicYear; $types .= 's'; }
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $out['used'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        }
        if ($out['max'] !== null) {
            $out['available'] = max(0, $out['max'] - $out['used']);
            $out['full'] = $out['used'] >= $out['max'];
        }
        return $out;
    }
}

/* ===========================================================================
 * Finance engine — fee split
 * ======================================================================== */

if (!function_exists('compute_fee_split')) {
    /**
     * Split a programme fee into sponsor vs student contribution.
     * A fixed approved amount (when > 0) takes precedence over a percentage.
     *   100% → student 0 ; 75% → student 25% ; self/0% → student 100%.
     * @return array total, sponsor_contribution, student_contribution, coverage_percent
     */
    function compute_fee_split(float $totalFee, ?float $coveragePercent, ?float $amountApproved = null): array
    {
        $totalFee = max(0.0, round($totalFee, 2));
        if ($amountApproved !== null && $amountApproved > 0) {
            $sponsor = min(round($amountApproved, 2), $totalFee);
            $coverage = $totalFee > 0 ? round($sponsor / $totalFee * 100, 2) : 0.0;
        } else {
            $coverage = max(0.0, min(100.0, (float)($coveragePercent ?? 0)));
            $sponsor = round($totalFee * $coverage / 100, 2);
        }
        $student = round($totalFee - $sponsor, 2);
        if ($student < 0) { $student = 0.0; }
        return [
            'total' => $totalFee,
            'sponsor_contribution' => $sponsor,
            'student_contribution' => $student,
            'coverage_percent' => $coverage,
        ];
    }
}

if (!function_exists('sponsorship_outstanding')) {
    /** Sponsor-side outstanding = approved − released (never negative). */
    function sponsorship_outstanding(?float $amountApproved, ?float $amountReleased): float
    {
        $a = (float)($amountApproved ?? 0);
        $r = (float)($amountReleased ?? 0);
        return max(0.0, round($a - $r, 2));
    }
}

if (!function_exists('sponsorship_programme_fee')) {
    /** Best-effort programme fee lookup (fee_structure → program_fees fallback). 0 if unknown. */
    function sponsorship_programme_fee(mysqli $db, string $programCode, ?int $yearOfStudy = null, ?int $semester = null): float
    {
        $total = 0.0;
        if (sponsorship_table_exists($db, 'fee_structure')) {
            $sql = "SELECT COALESCE(SUM(amount),0) t FROM fee_structure
                    WHERE entity_type='program' AND program_code=? AND COALESCE(status,'active')='active'";
            $params = [$programCode]; $types = 's';
            if ($yearOfStudy !== null) { $sql .= " AND (year_of_study=? OR year_of_study IS NULL)"; $params[] = $yearOfStudy; $types .= 'i'; }
            if ($semester !== null)    { $sql .= " AND (semester=? OR semester IS NULL)"; $params[] = $semester; $types .= 'i'; }
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $total = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);
                $stmt->close();
            }
        }
        if ($total <= 0 && sponsorship_table_exists($db, 'program_fees')) {
            if ($stmt = $db->prepare("SELECT COALESCE(SUM(semester_fee),0) t FROM program_fees WHERE program_code=?")) {
                $stmt->bind_param('s', $programCode);
                $stmt->execute();
                $total = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);
                $stmt->close();
            }
        }
        return round($total, 2);
    }
}

if (!function_exists('sponsor_allocation_usage')) {
    /** A sponsor org's funding pool usage: ['allocation'=>?, 'committed'=>float, 'available'=>?]. */
    function sponsor_allocation_usage(mysqli $db, int $sponsorId): array
    {
        $out = ['allocation' => null, 'committed' => 0.0, 'available' => null];
        if (!sponsorship_table_exists($db, 'finance_sponsors')) { return $out; }
        if ($stmt = $db->prepare("SELECT total_allocation FROM finance_sponsors WHERE id=? LIMIT 1")) {
            $stmt->bind_param('i', $sponsorId);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $out['allocation'] = $row['total_allocation'] !== null ? (float)$row['total_allocation'] : null;
            }
            $stmt->close();
        }
        if ($stmt = $db->prepare("SELECT COALESCE(SUM(amount_approved),0) t FROM finance_student_sponsors
                                   WHERE sponsor_id=? AND approval_status IN ('pending','approved') AND status='active'")) {
            $stmt->bind_param('i', $sponsorId);
            $stmt->execute();
            $out['committed'] = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);
            $stmt->close();
        }
        if ($out['allocation'] !== null) {
            $out['available'] = round($out['allocation'] - $out['committed'], 2);
        }
        return $out;
    }
}

/* ===========================================================================
 * Student sponsorship profile
 * ======================================================================== */

if (!function_exists('list_student_sponsorships')) {
    function list_student_sponsorships(mysqli $db, string $studentId): array
    {
        if (!sponsorship_schema_ready($db)) { return []; }
        $sql = "SELECT fss.*, st.name AS sponsor_type_name, st.code AS sponsor_type_code, st.is_self_funded,
                       s.name AS sponsor_name
                FROM finance_student_sponsors fss
                LEFT JOIN sponsor_types st ON st.id = fss.sponsor_type_id
                LEFT JOIN finance_sponsors s ON s.id = fss.sponsor_id
                WHERE fss.student_id = ?
                ORDER BY fss.id DESC";
        $stmt = $db->prepare($sql);
        if (!$stmt) { return []; }
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $r['outstanding'] = sponsorship_outstanding(
                isset($r['amount_approved']) ? (float)$r['amount_approved'] : null,
                isset($r['amount_released']) ? (float)$r['amount_released'] : null
            );
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('get_student_sponsorship')) {
    /** The student's current (approved + active + unexpired) sponsorship, if any. */
    function get_student_sponsorship(mysqli $db, string $studentId, bool $approvedOnly = true): ?array
    {
        foreach (list_student_sponsorships($db, $studentId) as $row) {
            if ($approvedOnly) {
                if ($row['approval_status'] !== 'approved' || $row['status'] !== 'active') { continue; }
                if (!empty($row['end_date']) && $row['end_date'] < date('Y-m-d')) { continue; }
            }
            return $row;
        }
        return null;
    }
}

if (!function_exists('student_sponsorship_summary')) {
    /** Aggregate funding picture for a student across all approved+active sponsorships. */
    function student_sponsorship_summary(mysqli $db, string $studentId, float $totalFee = 0.0): array
    {
        $sponsorships = list_student_sponsorships($db, $studentId);
        $approved = array_values(array_filter($sponsorships, static function ($r) {
            return $r['approval_status'] === 'approved' && $r['status'] === 'active'
                && (empty($r['end_date']) || $r['end_date'] >= date('Y-m-d'));
        }));
        $sponsorContribution = 0.0;
        foreach ($approved as $r) {
            $split = compute_fee_split($totalFee, isset($r['coverage_percent']) ? (float)$r['coverage_percent'] : null,
                isset($r['amount_approved']) ? (float)$r['amount_approved'] : null);
            $sponsorContribution += $split['sponsor_contribution'];
        }
        $sponsorContribution = min(round($sponsorContribution, 2), $totalFee);
        return [
            'is_sponsored' => !empty($approved),
            'has_pending' => (bool)array_filter($sponsorships, static fn($r) => $r['approval_status'] === 'pending'),
            'active_count' => count($approved),
            'total_fee' => round($totalFee, 2),
            'sponsor_contribution' => $sponsorContribution,
            'student_contribution' => round(max(0.0, $totalFee - $sponsorContribution), 2),
            'sponsorships' => $sponsorships,
        ];
    }
}

if (!function_exists('validate_sponsorship')) {
    /**
     * Validate a prospective/edited student sponsorship. Returns array of error
     * strings (empty = valid). $data: student_id, sponsor_id, sponsor_type_id,
     * program_code, academic_year, coverage_percent, amount_approved, end_date,
     * [exclude_id] when editing, [programme_fee] optional override.
     */
    function validate_sponsorship(mysqli $db, array $data, string $actor = ''): array
    {
        $errors = [];
        if (!sponsorship_schema_ready($db)) { return ['Sponsorship module is not installed.']; }

        $studentId = trim((string)($data['student_id'] ?? ''));
        $sponsorTypeId = (int)($data['sponsor_type_id'] ?? 0);
        $sponsorId = (int)($data['sponsor_id'] ?? 0);
        $program = trim((string)($data['program_code'] ?? ''));
        $year = trim((string)($data['academic_year'] ?? ''));
        $coverage = isset($data['coverage_percent']) && $data['coverage_percent'] !== '' ? (float)$data['coverage_percent'] : null;
        $amount = isset($data['amount_approved']) && $data['amount_approved'] !== '' ? (float)$data['amount_approved'] : null;
        $endDate = trim((string)($data['end_date'] ?? ''));
        $excludeId = (int)($data['exclude_id'] ?? 0);

        if ($studentId === '') { $errors[] = 'Student is required.'; }
        $type = $sponsorTypeId > 0 ? get_sponsor_type($db, $sponsorTypeId) : null;
        if (!$type) { $errors[] = 'A valid sponsor type is required.'; }

        $isSelfFunded = $type && (int)$type['is_self_funded'] === 1;

        // Percentage bounds
        if ($coverage !== null && ($coverage < 0 || $coverage > 100)) {
            $errors[] = 'Percentage sponsored must be between 0 and 100.';
        }

        // Programme must support the sponsor type (self-funded is always allowed).
        if (!$isSelfFunded && $type && $program !== '' && !is_programme_sponsorship_eligible($db, $program, $sponsorTypeId)) {
            $errors[] = "Programme '{$program}' is not eligible for {$type['name']}.";
        }

        // Amount must not exceed the programme fee.
        $fee = array_key_exists('programme_fee', $data) ? (float)$data['programme_fee']
             : ($program !== '' ? sponsorship_programme_fee($db, $program) : 0.0);
        if ($amount !== null && $amount < 0) { $errors[] = 'Approved amount cannot be negative.'; }
        if ($amount !== null && $fee > 0 && $amount > $fee + 0.001) {
            $errors[] = 'Approved amount (' . number_format($amount, 2) . ') exceeds the programme fee (' . number_format($fee, 2) . ').';
        }

        // Not expired (bypass for admissions and registration flows)
        if ($endDate !== '' && $endDate < date('Y-m-d') && !in_array($actor, ['admissions', 'registration'], true)) {
            $errors[] = 'Sponsorship end date is in the past (expired).';
        }

        // No duplicate active/pending sponsorship for same student+type(+year).
        if ($studentId !== '' && $sponsorTypeId > 0) {
            $sql = "SELECT id FROM finance_student_sponsors
                    WHERE student_id=? AND sponsor_type_id=? AND approval_status IN ('pending','approved') AND status='active'";
            $params = [$studentId, $sponsorTypeId]; $types = 'si';
            if ($year !== '') { $sql .= " AND academic_year=?"; $params[] = $year; $types .= 's'; }
            if ($excludeId > 0) { $sql .= " AND id<>?"; $params[] = $excludeId; $types .= 'i'; }
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $errors[] = 'A pending or approved sponsorship of this type already exists for this student.';
                }
                $stmt->close();
            }
        }

        // Programme capacity (skip for self-funded).
        if (!$isSelfFunded && $program !== '' && $sponsorTypeId > 0 && $excludeId === 0) {
            $usage = programme_sponsor_usage($db, $program, $sponsorTypeId, $year);
            if ($usage['max'] !== null && $usage['full']) {
                $errors[] = "The sponsored-student cap ({$usage['max']}) for this programme and sponsor type has been reached.";
            }
        }

        // Sponsor allocation pool.
        if ($sponsorId > 0 && $amount !== null) {
            $alloc = sponsor_allocation_usage($db, $sponsorId);
            if ($alloc['allocation'] !== null) {
                $available = $alloc['available'];
                if ($excludeId === 0 && $amount > $available + 0.001) {
                    $errors[] = 'Approved amount exceeds the sponsor\'s remaining allocation (' . number_format(max(0, $available), 2) . ').';
                }
            }
        }

        return $errors;
    }
}

if (!function_exists('create_student_sponsorship')) {
    /**
     * Record a new student sponsorship. Self-funded types are auto-approved;
     * everything else starts 'pending'. Returns ['success','id','error','errors'].
     */
    function create_student_sponsorship(mysqli $db, array $data, string $actor): array
    {
        if (!sponsorship_schema_ready($db)) { return ['success' => false, 'error' => 'Sponsorship module not installed.']; }
        $errors = validate_sponsorship($db, $data, $actor);
        if ($errors) { return ['success' => false, 'errors' => $errors, 'error' => $errors[0]]; }

        $studentId = trim((string)$data['student_id']);
        $sponsorTypeId = (int)$data['sponsor_type_id'];
        $sponsorId = (int)($data['sponsor_id'] ?? 0) ?: null;
        $program = trim((string)($data['program_code'] ?? '')) ?: null;
        $year = trim((string)($data['academic_year'] ?? '')) ?: null;
        $ref = trim((string)($data['reference_number'] ?? '')) ?: null;
        $coverage = isset($data['coverage_percent']) && $data['coverage_percent'] !== '' ? (float)$data['coverage_percent'] : null;
        $amount = isset($data['amount_approved']) && $data['amount_approved'] !== '' ? (float)$data['amount_approved'] : null;
        $startDate = trim((string)($data['start_date'] ?? '')) ?: null;
        $endDate = trim((string)($data['end_date'] ?? '')) ?: null;
        $conditions = trim((string)($data['conditions'] ?? '')) ?: null;

        $type = get_sponsor_type($db, $sponsorTypeId);
        $autoApprove = $type && ((int)$type['is_self_funded'] === 1 || (int)$type['requires_approval'] === 0);
        $approvalStatus = $autoApprove ? 'approved' : 'pending';
        $approvedBy = $autoApprove ? $actor : null;
        $approvedAt = $autoApprove ? date('Y-m-d H:i:s') : null;

        $sql = "INSERT INTO finance_student_sponsors
                  (student_id, sponsor_id, sponsor_type_id, program_code, academic_year, reference_number,
                   coverage_percent, amount_approved, amount_released, start_date, end_date, conditions,
                   status, approval_status, approved_by, approved_at, created_by)
                VALUES (?,?,?,?,?,?,?,?,0,?,?,?, 'active', ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        if (!$stmt) { return ['success' => false, 'error' => 'Database error: ' . $db->error]; }
        // 15 placeholders: student_id(s) sponsor_id(i) sponsor_type_id(i) program_code(s)
        // academic_year(s) reference_number(s) coverage_percent(d) amount_approved(d)
        // start_date(s) end_date(s) conditions(s) approval_status(s) approved_by(s)
        // approved_at(s) created_by(s)
        $stmt->bind_param(
            'siisssddsssssss',
            $studentId, $sponsorId, $sponsorTypeId, $program, $year, $ref,
            $coverage, $amount, $startDate, $endDate, $conditions,
            $approvalStatus, $approvedBy, $approvedAt, $actor
        );
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); return ['success' => false, 'error' => $err]; }
        $id = (int)$stmt->insert_id;
        $stmt->close();
        sponsorship_audit($db, $actor, 'student_sponsorship_create', [
            'id' => $id, 'student_id' => $studentId, 'sponsor_type_id' => $sponsorTypeId,
            'coverage_percent' => $coverage, 'amount_approved' => $amount, 'approval_status' => $approvalStatus,
        ]);
        return ['success' => true, 'id' => $id, 'approval_status' => $approvalStatus];
    }
}

if (!function_exists('set_sponsorship_approval')) {
    /** Shared approve/reject/cancel transition with audit. $action: approve|reject|cancel. */
    function set_sponsorship_approval(mysqli $db, int $id, string $action, string $actor, string $reason = ''): array
    {
        if (!sponsorship_schema_ready($db)) { return ['success' => false, 'error' => 'Sponsorship module not installed.']; }
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'];
        if (!isset($map[$action])) { return ['success' => false, 'error' => 'Invalid action.']; }
        $approval = $map[$action];

        // status enum is (active/expired/cancelled); cancelled approval also cancels the lifecycle.
        $lifecycle = $action === 'cancel' ? 'cancelled' : 'active';
        $approvedBy = $actor;
        $approvedAt = date('Y-m-d H:i:s');
        $reasonVal = $action === 'reject' ? ($reason ?: 'Rejected') : ($action === 'cancel' ? ($reason ?: 'Cancelled') : null);

        $stmt = $db->prepare("UPDATE finance_student_sponsors
                              SET approval_status=?, status=?, approved_by=?, approved_at=?, rejection_reason=?
                              WHERE id=?");
        if (!$stmt) { return ['success' => false, 'error' => 'Database error.']; }
        $stmt->bind_param('sssssi', $approval, $lifecycle, $approvedBy, $approvedAt, $reasonVal, $id);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok) { return ['success' => false, 'error' => 'Update failed.']; }
        sponsorship_audit($db, $actor, 'student_sponsorship_' . $action, ['id' => $id, 'reason' => $reasonVal]);
        return ['success' => true, 'changed' => $affected];
    }
}

if (!function_exists('approve_student_sponsorship')) {
    function approve_student_sponsorship(mysqli $db, int $id, string $actor): array { return set_sponsorship_approval($db, $id, 'approve', $actor); }
}
if (!function_exists('reject_student_sponsorship')) {
    function reject_student_sponsorship(mysqli $db, int $id, string $actor, string $reason = ''): array { return set_sponsorship_approval($db, $id, 'reject', $actor, $reason); }
}
if (!function_exists('cancel_student_sponsorship')) {
    function cancel_student_sponsorship(mysqli $db, int $id, string $actor, string $reason = ''): array { return set_sponsorship_approval($db, $id, 'cancel', $actor, $reason); }
}

if (!function_exists('record_sponsor_release')) {
    /** Record funds released by a sponsor against a sponsorship (capped at approved). */
    function record_sponsor_release(mysqli $db, int $id, float $amount, string $actor): array
    {
        if (!sponsorship_schema_ready($db)) { return ['success' => false, 'error' => 'Sponsorship module not installed.']; }
        if ($amount <= 0) { return ['success' => false, 'error' => 'Release amount must be positive.']; }
        $stmt = $db->prepare("SELECT amount_approved, amount_released FROM finance_student_sponsors WHERE id=? LIMIT 1");
        if (!$stmt) { return ['success' => false, 'error' => 'Database error.']; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) { return ['success' => false, 'error' => 'Sponsorship not found.']; }
        $approved = (float)($row['amount_approved'] ?? 0);
        $released = (float)($row['amount_released'] ?? 0);
        $newReleased = round($released + $amount, 2);
        if ($approved > 0 && $newReleased > $approved + 0.001) {
            return ['success' => false, 'error' => 'Release would exceed the approved amount.'];
        }
        $stmt = $db->prepare("UPDATE finance_student_sponsors SET amount_released=? WHERE id=?");
        if (!$stmt) { return ['success' => false, 'error' => 'Database error.']; }
        $stmt->bind_param('di', $newReleased, $id);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) { sponsorship_audit($db, $actor, 'sponsor_release', ['id' => $id, 'amount' => $amount, 'total_released' => $newReleased]); }
        return ['success' => $ok, 'total_released' => $newReleased, 'outstanding' => sponsorship_outstanding($approved, $newReleased)];
    }
}

if (!function_exists('sps_resolve_sponsor_type_from_string')) {
    /** Map raw sponsor strings (like 'CDF', 'TEVETA', 'Self') to database-driven types. */
    function sps_resolve_sponsor_type_from_string(mysqli $db, string $sponsorStr): ?array
    {
        $clean = strtolower(trim($sponsorStr));
        if ($clean === '' || in_array($clean, ['self', 'self sponsored', 'self-sponsored', 'none', 'not specified', 'not_specified'], true)) {
            return get_sponsor_type_by_code($db, 'self');
        }

        // Try mapping exact code or name first
        $stmt = $db->prepare("SELECT * FROM sponsor_types WHERE LOWER(code) = ? OR LOWER(name) = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ss', $clean, $clean);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) { return $row; }
        }

        // Fallback: substring matching
        $all = get_sponsor_types($db, false);
        foreach ($all as $t) {
            $typeCode = strtolower($t['code']);
            $typeName = strtolower($t['name']);
            if (strpos($clean, $typeCode) !== false || strpos($typeCode, $clean) !== false ||
                strpos($clean, $typeName) !== false || strpos($typeName, $clean) !== false) {
                return $t;
            }
        }

        // Default to 'other' if no match
        return get_sponsor_type_by_code($db, 'other');
    }
}
