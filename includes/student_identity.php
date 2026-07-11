<?php
/**
 * Student identity protection — single source of truth for the rule:
 *
 *   "Student identity data must be created once, protected afterward, and only
 *    changed through an authorized correction process with audit logging."
 *
 * Protected identity fields can NEVER be changed by an ordinary edit. They are
 * guarded on three layers that all funnel through this file:
 *
 *   1. UI         — editStudent.php renders these fields read-only.
 *   2. Backend    — the edit handler rejects any attempt to change them.
 *   3. Database   — a BEFORE UPDATE trigger on `students` blocks the change
 *                   unless the session variable @allow_identity_change = 1,
 *                   which ONLY applyStudentIdentityCorrection() ever sets.
 *
 * Corrections are the single sanctioned path: an authorized administrator
 * (Systems Admin / Registrar / Administrator) supplies a reason; every changed
 * field is written to `student_identity_audit` (field, old, new, who, when, why)
 * inside the same transaction that performs the update.
 */

require_once __DIR__ . '/student_id_generator.php';

if (!defined('WUC_IDENTITY_FIELDS')) {
    /**
     * Columns on `students` that constitute protected identity data. Ordinary
     * edits may never touch these; only an authorized correction may.
     *
     *   SID           student number (permanent, system-generated)
     *   nrc_pass      NRC / passport number
     *   Fname,Lname   legal name
     *   dob           date of birth
     *   sex           gender
     *   program       programme enrolled
     *   academic_year year of enrolment
     *   intake        academic period of enrolment
     */
    define('WUC_IDENTITY_FIELDS', [
        'SID', 'nrc_pass', 'Fname', 'Lname', 'dob', 'sex',
        'program', 'academic_year', 'intake',
    ]);
}

if (!defined('WUC_IDENTITY_CORRECTION_ROLES')) {
    /** Position names allowed to correct identity data. */
    define('WUC_IDENTITY_CORRECTION_ROLES', ['Systems Admin', 'Registrar', 'Administrator']);
}

if (!function_exists('wuc_is_identity_field')) {
    /** True when $field is a protected identity column (case-insensitive). */
    function wuc_is_identity_field(string $field): bool
    {
        foreach (WUC_IDENTITY_FIELDS as $f) {
            if (strcasecmp($f, $field) === 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('wuc_canonical_identity_field')) {
    /**
     * Map a loosely-cased field name to its exact column spelling, or null when
     * it is not a protected identity field. Prevents a caller from sneaking a
     * change through by varying the case of the column name.
     */
    function wuc_canonical_identity_field(string $field): ?string
    {
        foreach (WUC_IDENTITY_FIELDS as $f) {
            if (strcasecmp($f, $field) === 0) {
                return $f;
            }
        }
        return null;
    }
}

if (!function_exists('wuc_staff_can_correct_identity')) {
    /**
     * True when the staff member holds a role authorized to correct identity
     * data. Data-driven: checks staff_positions → positions.PosName against
     * WUC_IDENTITY_CORRECTION_ROLES, so new accounts and the all-roles superuser
     * keep working without hard-coded IDs.
     */
    function wuc_staff_can_correct_identity(mysqli $db, ?string $staffId): bool
    {
        if ($staffId === null || $staffId === '') {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count(WUC_IDENTITY_CORRECTION_ROLES), '?'));
        $sql = "SELECT 1
                  FROM staff_positions sp
                  JOIN positions p ON p.PosID = sp.PosID
                 WHERE sp.staff_id = ?
                   AND p.PosName IN ($placeholders)
                 LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $types = 's' . str_repeat('s', count(WUC_IDENTITY_CORRECTION_ROLES));
        $params = array_merge([$staffId], WUC_IDENTITY_CORRECTION_ROLES);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->store_result();
        $ok = $stmt->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('wuc_validate_identity_value')) {
    /**
     * Validate a proposed new value for a protected field before it is written,
     * so a correction can never mint a structurally invalid identity value.
     * Returns a (possibly normalised) value, or throws InvalidArgumentException.
     */
    function wuc_validate_identity_value(string $field, $value): string
    {
        $field = wuc_canonical_identity_field($field) ?? $field;
        $value = trim((string)$value);

        switch ($field) {
            case 'SID':
                if (!wuc_validate_student_number($value)) {
                    throw new InvalidArgumentException(
                        'The student number must match the approved ITC format.'
                    );
                }
                break;
            case 'nrc_pass':
                if (wuc_extract_nrc6($value) === null) {
                    throw new InvalidArgumentException(
                        'The NRC/passport must contain at least six digits.'
                    );
                }
                break;
            case 'Fname':
            case 'Lname':
                if ($value === '') {
                    throw new InvalidArgumentException('Name fields cannot be blank.');
                }
                break;
            case 'dob':
                $d = DateTime::createFromFormat('Y-m-d', $value);
                if (!$d || $d->format('Y-m-d') !== $value) {
                    throw new InvalidArgumentException('Date of birth must be a valid Y-m-d date.');
                }
                break;
            case 'sex':
                if ($value === '') {
                    throw new InvalidArgumentException('Gender cannot be blank.');
                }
                break;
            case 'academic_year':
                if (!preg_match('/^\d{4}$/', $value)) {
                    throw new InvalidArgumentException('Year of enrolment must be a 4-digit year.');
                }
                break;
            // program / intake: free-form references, accepted as trimmed text.
        }
        return $value;
    }
}

if (!function_exists('applyStudentIdentityCorrection')) {
    /**
     * The ONE sanctioned path to change protected identity data.
     *
     * Authorizes the actor, validates every proposed value, then inside a single
     * transaction: lifts the DB guard (@allow_identity_change = 1), applies the
     * changes to `students`, writes one audit row per changed field, and drops
     * the guard again — even on failure.
     *
     * @param mysqli $db
     * @param string $sid       student whose record is corrected
     * @param array  $changes   [ column => newValue ] (identity fields only)
     * @param string $staffId   acting staff_id (must be an authorized role)
     * @param string $reason    mandatory justification, recorded in the audit
     * @return array            [ column => ['old'=>..,'new'=>..] ] actually changed
     * @throws InvalidArgumentException|RuntimeException
     */
    function applyStudentIdentityCorrection(
        mysqli $db,
        string $sid,
        array $changes,
        string $staffId,
        string $reason
    ): array {
        if (!wuc_staff_can_correct_identity($db, $staffId)) {
            throw new RuntimeException('You are not authorized to correct student identity data.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required for every identity correction.');
        }

        // Normalise to canonical columns + validated values; ignore non-identity keys.
        $clean = [];
        foreach ($changes as $field => $value) {
            $col = wuc_canonical_identity_field((string)$field);
            if ($col === null) {
                continue; // not an identity field — not handled here
            }
            $clean[$col] = wuc_validate_identity_value($col, $value);
        }
        if (!$clean) {
            return [];
        }

        // Load current values so we only write real changes and capture old values.
        $cols = array_keys($clean);
        $select = 'SELECT `' . implode('`,`', $cols) . '` FROM students WHERE SID = ? LIMIT 1';
        $stmt = $db->prepare($select);
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$current) {
            throw new RuntimeException("Student {$sid} not found.");
        }

        $diff = [];
        foreach ($clean as $col => $newVal) {
            $oldVal = (string)($current[$col] ?? '');
            if ($oldVal !== (string)$newVal) {
                $diff[$col] = ['old' => $oldVal, 'new' => (string)$newVal];
            }
        }
        if (!$diff) {
            return []; // nothing actually changed
        }

        $db->begin_transaction();
        try {
            // Lift the DB-level guard for the duration of this transaction only.
            $db->query('SET @allow_identity_change = 1');

            // Apply the change to students. If SID itself changes, cascade to every
            // table that references it (FK checks off, mirroring the migration).
            $sidChanging = isset($diff['SID']);

            $set = [];
            $params = [];
            $types = '';
            foreach ($diff as $col => $vals) {
                $set[] = "`{$col}` = ?";
                $params[] = $vals['new'];
                $types .= 's';
            }
            $params[] = $sid;
            $types .= 's';
            $sql = 'UPDATE students SET ' . implode(', ', $set) . ' WHERE SID = ?';
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            if ($sidChanging) {
                $newSid = $diff['SID']['new'];
                $db->query('SET FOREIGN_KEY_CHECKS = 0');
                foreach (wuc_student_sid_columns($db) as [$tbl, $col]) {
                    $u = $db->prepare("UPDATE `{$tbl}` SET `{$col}` = ? WHERE `{$col}` = ?");
                    $u->bind_param('ss', $newSid, $sid);
                    $u->execute();
                    $u->close();
                }
                $db->query('SET FOREIGN_KEY_CHECKS = 1');
            }

            // Audit: one row per changed field. The SID we key audits on is the
            // post-change SID so the trail stays attached to the record.
            $auditSid = $sidChanging ? $diff['SID']['new'] : $sid;
            $ins = $db->prepare(
                'INSERT INTO student_identity_audit
                    (student_sid, field_changed, old_value, new_value, changed_by, reason)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($diff as $col => $vals) {
                $ins->bind_param('ssssss', $auditSid, $col, $vals['old'], $vals['new'], $staffId, $reason);
                $ins->execute();
            }
            $ins->close();

            $db->query('SET @allow_identity_change = NULL');
            $db->commit();
        } catch (Throwable $e) {
            $db->query('SET @allow_identity_change = NULL');
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            $db->rollback();
            throw $e;
        }

        return $diff;
    }
}

if (!function_exists('wuc_student_sid_columns')) {
    /**
     * Every (table, column) that stores a student number, discovered live, so a
     * SID correction cascades everywhere and nothing is orphaned. Excludes the
     * students PK column (updated explicitly by the caller).
     */
    function wuc_student_sid_columns(mysqli $db): array
    {
        $sql = "SELECT TABLE_NAME, COLUMN_NAME
                  FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND COLUMN_NAME IN ('SID','Sid','sid','student_id','studentId','student_number')";
        $cols = [];
        $res = $db->query($sql);
        while ($row = $res->fetch_assoc()) {
            if ($row['TABLE_NAME'] === 'students' && $row['COLUMN_NAME'] === 'SID') {
                continue;
            }
            $cols[] = [$row['TABLE_NAME'], $row['COLUMN_NAME']];
        }
        return $cols;
    }
}
