<?php
declare(strict_types=1);
require_once __DIR__ . '/course_availability_helpers.php';
/**
 * Academic structure helpers — the single source of truth for how a programme's
 * `structure_type` controls valid academic periods and curriculum reads.
 *
 * Introduced with the ITC Academic Data Structure (Phase 1). Encodes the spec's
 * central rule: academic logic is driven by programmes.structure_type, NOT by
 * hardcoded programme names.
 *
 *   TERM_BASED        -> year + term   (terms 1..3)
 *   SEMESTER_BASED    -> year + semester (semesters 1..2)  e.g. Transport & Logistics
 *   SHORT_COURSE      -> flexible cycle  (no fixed period numbers)
 *   TRADE_TEST_LEVEL  -> level           (levels 1..3)
 *
 * All queries use prepared statements. Functions are guarded so the file can be
 * required more than once.
 */

if (!defined('WUC_STRUCTURE_TYPES')) {
    define('WUC_STRUCTURE_TYPES', ['TERM_BASED', 'SEMESTER_BASED', 'SHORT_COURSE', 'TRADE_TEST_LEVEL']);
}

if (!function_exists('wuc_program_structure_type')) {
    /**
     * Resolve a programme's structure_type. Falls back to deriving it from the
     * legacy flags (period_mode / is_short_course) if the column is unset, so the
     * helper is correct even for rows a migration has not yet backfilled.
     *
     * @return string one of WUC_STRUCTURE_TYPES, or '' if the programme is unknown.
     */
    function wuc_program_structure_type(mysqli $db, string $program_code): string
    {
        $program_code = trim($program_code);
        if ($program_code === '') {
            return '';
        }
        $sql = "SELECT structure_type, period_mode, uses_semesters, is_short_course
                  FROM programs WHERE program_code = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return '';
        }
        $stmt->bind_param('s', $program_code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return '';
        }
        $st = (string)($row['structure_type'] ?? '');
        if (in_array($st, WUC_STRUCTURE_TYPES, true)) {
            return $st;
        }
        // Derive from legacy flags when structure_type has not been set.
        if ((int)($row['is_short_course'] ?? 0) === 1) {
            return 'SHORT_COURSE';
        }
        if (($row['period_mode'] ?? '') === 'semester' || (int)($row['uses_semesters'] ?? 0) === 1) {
            return 'SEMESTER_BASED';
        }
        return 'TERM_BASED';
    }
}

if (!function_exists('wuc_structure_period_kind')) {
    /**
     * The period dimension a structure type uses: 'term', 'semester', 'cycle'
     * (short course) or 'level' (trade test). '' for an unknown structure type.
     */
    function wuc_structure_period_kind(string $structure_type): string
    {
        switch ($structure_type) {
            case 'TERM_BASED':       return 'term';
            case 'SEMESTER_BASED':   return 'semester';
            case 'SHORT_COURSE':     return 'cycle';
            case 'TRADE_TEST_LEVEL': return 'level';
            default:                 return '';
        }
    }
}

if (!function_exists('wuc_structure_valid_period_numbers')) {
    /**
     * Allowed period numbers for a structure type. Empty array = flexible (short
     * course), so the caller should not enforce a fixed set.
     *
     * @return int[]
     */
    function wuc_structure_valid_period_numbers(string $structure_type): array
    {
        switch ($structure_type) {
            case 'TERM_BASED':       return [1, 2, 3];
            case 'SEMESTER_BASED':   return [1, 2];
            case 'TRADE_TEST_LEVEL': return [1, 2, 3];
            case 'SHORT_COURSE':     return []; // flexible cycle
            default:                 return [];
        }
    }
}

if (!function_exists('wuc_structure_validate_period')) {
    /**
     * Validate that a (structure_type, period_number) combination is allowed.
     * Short courses accept any/none. Prevents the invalid combinations the spec
     * §22 lists (e.g. Transport registered under Term 1, trade test under Term 1).
     */
    function wuc_structure_validate_period(string $structure_type, $period_number): bool
    {
        $valid = wuc_structure_valid_period_numbers($structure_type);
        if ($valid === []) {
            return true; // flexible / not period-constrained
        }
        return in_array((int)$period_number, $valid, true);
    }
}

if (!function_exists('wuc_program_max_curriculum_year')) {
    /**
     * Upper bound for a programme's year number: the greater of its declared
     * duration (ceil of program_duration, in years) and the years its existing
     * curriculum already spans. Falls back to 8 when neither is known so
     * legacy data cannot lock out registration entirely.
     */
    function wuc_program_max_curriculum_year(mysqli $db, string $program_code): int
    {
        $program_code = trim($program_code);
        if ($program_code === '') {
            return 8;
        }
        $max = 0;
        if ($stmt = $db->prepare('SELECT COALESCE(program_duration, 0) FROM programs WHERE program_code = ? LIMIT 1')) {
            $stmt->bind_param('s', $program_code);
            $stmt->execute();
            $stmt->bind_result($duration);
            if ($stmt->fetch()) {
                $max = (int)ceil((float)$duration);
            }
            $stmt->close();
        }
        if ($stmt = $db->prepare('SELECT COALESCE(MAX(year), 0) FROM program_courses WHERE program_code = ?')) {
            $stmt->bind_param('s', $program_code);
            $stmt->execute();
            $stmt->bind_result($curriculumMax);
            if ($stmt->fetch()) {
                $max = max($max, (int)$curriculumMax);
            }
            $stmt->close();
        }
        return $max > 0 ? min($max, 8) : 8;
    }
}

if (!function_exists('wuc_validate_curriculum_period')) {
    /**
     * Validate a curriculum placement (year + period) against the programme's
     * real structure BEFORE writing program_courses rows:
     *   - term programmes accept terms 1-3, semester programmes semesters 1-2;
     *   - short courses are duration-based and take no curriculum periods;
     *   - the year must fit the programme's duration / curriculum span.
     * Returns ['ok' => bool, 'reason' => string, 'period_kind' => string].
     */
    function wuc_validate_curriculum_period(mysqli $db, string $program_code, $year, $period): array
    {
        $program_code = trim($program_code);
        $structure = wuc_program_structure_type($db, $program_code);
        $kind = wuc_structure_period_kind($structure);
        $label = $kind !== '' ? $kind : 'period';

        if ($structure === '') {
            return [
                'ok' => false,
                'reason' => 'Unknown programme "' . $program_code . '" — assign courses to a valid active programme.',
                'period_kind' => $kind,
            ];
        }
        if ($structure === 'SHORT_COURSE') {
            return [
                'ok' => false,
                'reason' => 'Programme ' . $program_code . ' is a duration-based short course — it has no term/semester curriculum. Manage its content under Short Courses instead.',
                'period_kind' => $kind,
            ];
        }
        if (!wuc_structure_validate_period($structure, $period)) {
            $valid = implode(', ', wuc_structure_valid_period_numbers($structure));
            return [
                'ok' => false,
                'reason' => 'Programme ' . $program_code . ' is ' . strtolower(str_replace('_', '-', $structure))
                    . ' — the ' . $label . ' number must be one of: ' . $valid . '.',
                'period_kind' => $kind,
            ];
        }
        if ($year !== null && trim((string)$year) !== '') {
            $yearInt = (int)$year;
            $maxYear = wuc_program_max_curriculum_year($db, $program_code);
            if ($yearInt < 1 || $yearInt > $maxYear) {
                return [
                    'ok' => false,
                    'reason' => 'Year ' . $yearInt . ' is outside programme ' . $program_code . '\'s duration (valid: Year 1-' . $maxYear . ').',
                    'period_kind' => $kind,
                ];
            }
        }
        return ['ok' => true, 'reason' => '', 'period_kind' => $kind];
    }
}

if (!function_exists('wuc_structure_normalize_period_number')) {
    /**
     * Convert legacy labels such as "Term1", "January 2026", or "June" into
     * the numeric period used by structure-aware student_program fields.
     */
    function wuc_structure_normalize_period_number($period_number, string $period_kind): ?string
    {
        if ($period_number === null) {
            return null;
        }

        $raw = trim((string)$period_number);
        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            return (string)(int)$raw;
        }

        $lower = strtolower($raw);
        if ($period_kind === 'term') {
            foreach (['january' => '1', 'term 1' => '1', 'term1' => '1', 'may' => '2', 'term 2' => '2', 'term2' => '2', 'september' => '3', 'term 3' => '3', 'term3' => '3'] as $needle => $number) {
                if (strpos($lower, $needle) !== false) {
                    return $number;
                }
            }
        } elseif ($period_kind === 'semester') {
            foreach (['january' => '1', 'semester 1' => '1', 'semester1' => '1', 'june' => '2', 'july' => '2', 'semester 2' => '2', 'semester2' => '2'] as $needle => $number) {
                if (strpos($lower, $needle) !== false) {
                    return $number;
                }
            }
        }

        if (preg_match('/\b([1-9][0-9]*)\b/', $raw, $match)) {
            return (string)(int)$match[1];
        }

        return null;
    }
}

if (!function_exists('wuc_student_program_period_input')) {
    /**
     * Pick the best available period value for a programme from request/row data.
     * The caller still validates the result with wuc_student_program_period_payload().
     */
    function wuc_student_program_period_input(mysqli $db, string $program_code, array $source = [], $fallback = 1)
    {
        $kind = wuc_structure_period_kind(wuc_program_structure_type($db, $program_code));
        if ($kind === 'cycle') {
            return null;
        }

        $keys = ['academic_period', 'academic_period_number', 'period_number', 'period'];
        if ($kind === 'term') {
            $keys = array_merge($keys, ['current_term_number', 'term', 'semester', 'intake']);
        } elseif ($kind === 'semester') {
            $keys = array_merge($keys, ['current_semester_number', 'semester', 'term', 'intake']);
        } elseif ($kind === 'level') {
            $keys = array_merge($keys, ['current_level_number', 'level_number', 'trade_test_level', 'level', 'term', 'semester', 'intake']);
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $normalized = wuc_structure_normalize_period_number($source[$key], $kind);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return $fallback;
    }
}

if (!function_exists('wuc_active_curriculum_version_id')) {
    /**
     * The active curriculum version id for a programme (most recent effective
     * year among active versions). Returns null if none exists.
     */
    function wuc_active_curriculum_version_id(mysqli $db, string $program_code): ?int
    {
        $program_code = trim($program_code);
        if ($program_code === '') {
            return null;
        }
        $sql = "SELECT id FROM curriculum_versions
                 WHERE program_code = ? AND status = 'active'
                 ORDER BY effective_year DESC, id DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $program_code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }
}

if (!function_exists('wuc_registration_guard')) {
    /**
     * Validate that a student may register for a course offering (spec §14/§22):
     *   - both records exist,
     *   - the offering's programme matches the student's programme,
     *   - the offering's period is valid for the programme's structure type.
     * Returns ['ok' => bool, 'reason' => string]. Call BEFORE inserting into
     * student_course_registrations so invalid academic combinations are blocked.
     */
    function wuc_registration_guard(mysqli $db, int $student_programme_id, int $course_offering_id): array
    {
        $sql = "SELECT sp.program_code AS student_pc,
                       o.program_code  AS offer_pc,
                       cc.term_number, cc.semester_number, cc.level_number
                  FROM student_program sp
                  JOIN course_offerings o   ON o.id = ?
                  LEFT JOIN curriculum_courses cc ON cc.id = o.curriculum_course_id
                 WHERE sp.id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return ['ok' => false, 'reason' => 'Could not prepare validation query.'];
        }
        $stmt->bind_param('ii', $course_offering_id, $student_programme_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['ok' => false, 'reason' => 'Student programme or course offering not found.'];
        }
        if ((string)$row['student_pc'] !== (string)$row['offer_pc']) {
            return ['ok' => false, 'reason' => 'Offering belongs to a different programme than the student.'];
        }

        $structure = wuc_program_structure_type($db, (string)$row['student_pc']);
        // The period number this offering sits in, per the programme structure.
        $periodNo = null;
        switch (wuc_structure_period_kind($structure)) {
            case 'term':     $periodNo = $row['term_number'];     break;
            case 'semester': $periodNo = $row['semester_number']; break;
            case 'level':    $periodNo = $row['level_number'];    break;
            // 'cycle' (short course) is flexible — no fixed period to validate.
        }
        if ($periodNo !== null && !wuc_structure_validate_period($structure, $periodNo)) {
            return ['ok' => false, 'reason' => "Invalid period $periodNo for a {$structure} programme."];
        }
        return ['ok' => true, 'reason' => ''];
    }
}

if (!function_exists('wuc_student_program_active_for_program')) {
    function wuc_student_program_active_for_program(mysqli $db, string $student_id, string $program_code): ?array
    {
        $student_id = trim($student_id);
        $program_code = trim($program_code);
        if ($student_id === '' || $program_code === '') {
            return null;
        }

        $sql = "SELECT id, Sid, program_code, term, semester, current_term_number,
                       current_semester_number, current_level_number, status
                  FROM student_program
                 WHERE Sid = ?
                   AND program_code = ?
                   AND LOWER(COALESCE(status, 'active')) = 'active'
                 ORDER BY id DESC
                 LIMIT 1";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ss', $student_id, $program_code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('wuc_legacy_course_registration_guard')) {
    /**
     * Validate legacy semester_registration/course_registration writes.
     *
     * The legacy tables still name the period column "semester", but this
     * function treats it as the structure-aware period number:
     * term, semester, trade-test level, or short-course cycle.
     *
     * @param string[] $course_codes optional selected course codes to verify
     * @return array{ok:bool,reason:string,structure_type:string,period_kind:string,period_type:string}
     */
    function wuc_legacy_course_registration_guard(mysqli $db, string $student_id, string $program_code, $year_number, $period_number, array $course_codes = []): array
    {
        $structure = wuc_program_structure_type($db, $program_code);
        $kind = wuc_structure_period_kind($structure);
        $periodType = 'semester';
        if ($structure === 'TERM_BASED') {
            $periodType = 'term';
        } elseif ($structure === 'TRADE_TEST_LEVEL') {
            $periodType = 'trade_test_level';
        } elseif ($structure === 'SHORT_COURSE') {
            $periodType = 'short_course_cycle';
        }

        if (!wuc_student_program_active_for_program($db, $student_id, $program_code)) {
            return [
                'ok' => false,
                'reason' => 'The student is not actively enrolled in the selected programme.',
                'structure_type' => $structure,
                'period_kind' => $kind,
                'period_type' => $periodType,
            ];
        }

        $periodPayload = wuc_student_program_period_payload($db, $program_code, $period_number);
        if (!$periodPayload['ok']) {
            return [
                'ok' => false,
                'reason' => $periodPayload['reason'],
                'structure_type' => $structure,
                'period_kind' => $kind,
                'period_type' => $periodType,
            ];
        }

        $normalizedPeriod = wuc_structure_normalize_period_number($period_number, $kind);
        $period = $normalizedPeriod !== null ? (int)$normalizedPeriod : null;
        $year = (int)$year_number;
        $maxYear = wuc_program_max_curriculum_year($db, $program_code);
        if ($year < 1 || $year > $maxYear) {
            return [
                'ok' => false,
                'reason' => 'Invalid year of study for this programme (valid: Year 1-' . $maxYear . ').',
                'structure_type' => $structure,
                'period_kind' => $kind,
                'period_type' => $periodType,
            ];
        }

        $course_codes = array_values(array_unique(array_filter(array_map(static function ($code): string {
            return strtoupper(trim((string)$code));
        }, $course_codes))));

        if ($course_codes !== []) {
            $placeholders = implode(',', array_fill(0, count($course_codes), '?'));
            $types = 's' . str_repeat('s', count($course_codes));
            $params = array_merge([$program_code], $course_codes);
            $where = ["cv.program_code = ?", "UPPER(TRIM(cc.course_code)) IN ({$placeholders})"];

            $ccCols = wuc_course_availability_columns($db, 'curriculum_courses');
            $periodColumn = null;
            if ($structure === 'TERM_BASED') {
                $where[] = 'cc.year_number = ?';
                $types .= 'i';
                $params[] = $year;
                $periodColumn = $ccCols['term_number'] ?? null;
            } elseif ($structure === 'SEMESTER_BASED') {
                $where[] = 'cc.year_number = ?';
                $types .= 'i';
                $params[] = $year;
                $periodColumn = $ccCols['semester_number'] ?? null;
            } elseif ($structure === 'TRADE_TEST_LEVEL') {
                $where[] = 'cc.level_number = ?';
                $types .= 'i';
                $params[] = $period;
            }
            $periodFilter = wuc_course_availability_period_filter($ccCols, 'cc', $periodColumn, $period);
            if ($periodFilter['sql'] !== '1=1') {
                $where[] = $periodFilter['sql'];
                $types .= $periodFilter['types'];
                $params = array_merge($params, $periodFilter['params']);
            }

            $sql = "SELECT DISTINCT UPPER(TRIM(cc.course_code)) AS course_code
                      FROM curriculum_courses cc
                      JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
                     WHERE " . implode(' AND ', $where);
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return [
                    'ok' => false,
                    'reason' => 'Could not validate curriculum course registration.',
                    'structure_type' => $structure,
                    'period_kind' => $kind,
                    'period_type' => $periodType,
                ];
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $found = [];
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $found[] = (string)$row['course_code'];
            }
            $stmt->close();

            $missing = array_values(array_diff($course_codes, $found));
            if ($missing !== []) {
                return [
                    'ok' => false,
                    'reason' => 'Course(s) are not valid for this programme period: ' . implode(', ', $missing) . '.',
                    'structure_type' => $structure,
                    'period_kind' => $kind,
                    'period_type' => $periodType,
                ];
            }
        }

        return [
            'ok' => true,
            'reason' => '',
            'structure_type' => $structure,
            'period_kind' => $kind,
            'period_type' => $periodType,
        ];
    }
}

if (!function_exists('wuc_resolve_course_offering_for_legacy_registration')) {
    /**
     * Resolve the canonical course_offerings row represented by legacy
     * course_registration columns (program + course + year + generic period).
     */
    function wuc_resolve_course_offering_for_legacy_registration(mysqli $db, string $program_code, string $course_code, $year_number, $period_number): ?array
    {
        $structure = wuc_program_structure_type($db, $program_code);
        $kind = wuc_structure_period_kind($structure);
        $period = wuc_structure_normalize_period_number($period_number, $kind);
        if ($structure === '' || $kind === '' || ($kind !== 'cycle' && $period === null)) {
            return null;
        }

        $where = [
            "co.program_code = ?",
            "UPPER(TRIM(cc.course_code)) = UPPER(TRIM(?))",
            "co.status IN ('active', 'planned')",
        ];
        $types = 'ss';
        $params = [$program_code, $course_code];

        $ccCols = wuc_course_availability_columns($db, 'curriculum_courses');
        $periodColumn = null;
        if ($structure === 'TERM_BASED') {
            $where[] = 'cc.year_number = ?';
            $types .= 'i';
            $params[] = (int)$year_number;
            $periodColumn = $ccCols['term_number'] ?? null;
        } elseif ($structure === 'SEMESTER_BASED') {
            $where[] = 'cc.year_number = ?';
            $types .= 'i';
            $params[] = (int)$year_number;
            $periodColumn = $ccCols['semester_number'] ?? null;
        } elseif ($structure === 'TRADE_TEST_LEVEL') {
            $where[] = 'cc.level_number = ?';
            $types .= 'i';
            $params[] = (int)$period;
        }
        $periodFilter = wuc_course_availability_period_filter($ccCols, 'cc', $periodColumn, $period);
        if ($periodFilter['sql'] !== '1=1') {
            $where[] = $periodFilter['sql'];
            $types .= $periodFilter['types'];
            $params = array_merge($params, $periodFilter['params']);
        }

        $sql = "SELECT co.id AS course_offering_id,
                       co.program_code,
                       cc.id AS curriculum_course_id,
                       cc.course_code,
                       cc.year_number,
                       cc.term_number,
                       cc.semester_number,
                       cc.level_number
                  FROM course_offerings co
                  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY co.status = 'active' DESC, co.id DESC
                 LIMIT 1";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('wuc_sync_legacy_course_registration_to_canonical')) {
    /**
     * Mirror a legacy course_registration enrollment into the canonical
     * student_course_registrations table used by CA/results/eLearning.
     *
     * @return array{ok:bool,synced:bool,reason:string,student_programme_id:?int,course_offering_id:?int}
     */
    function wuc_sync_legacy_course_registration_to_canonical(mysqli $db, string $student_id, string $program_code, string $course_code, $year_number, $period_number, string $registration_status = 'REGISTERED'): array
    {
        $empty = [
            'ok' => false,
            'synced' => false,
            'reason' => '',
            'student_programme_id' => null,
            'course_offering_id' => null,
        ];

        $programRow = wuc_student_program_active_for_program($db, $student_id, $program_code);
        if (!$programRow) {
            $empty['reason'] = 'Active student programme not found for canonical registration sync.';
            return $empty;
        }

        $offering = wuc_resolve_course_offering_for_legacy_registration($db, $program_code, $course_code, $year_number, $period_number);
        if (!$offering) {
            $empty['reason'] = 'Course offering not found for canonical registration sync.';
            $empty['student_programme_id'] = (int)$programRow['id'];
            return $empty;
        }

        $guard = wuc_registration_guard($db, (int)$programRow['id'], (int)$offering['course_offering_id']);
        if (!$guard['ok']) {
            $empty['reason'] = $guard['reason'];
            $empty['student_programme_id'] = (int)$programRow['id'];
            $empty['course_offering_id'] = (int)$offering['course_offering_id'];
            return $empty;
        }

        $allowedStatuses = ['REGISTERED', 'DROPPED', 'DEFERRED', 'COMPLETED', 'REPEATING'];
        $status = strtoupper(trim($registration_status));
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'REGISTERED';
        }

        $studentProgrammeId = (int)$programRow['id'];
        $courseOfferingId = (int)$offering['course_offering_id'];
        $sql = "INSERT INTO student_course_registrations
                    (student_programme_id, course_offering_id, registration_status, registered_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    registration_status = VALUES(registration_status),
                    updated_at = CURRENT_TIMESTAMP";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $empty['reason'] = 'Could not prepare canonical registration sync.';
            $empty['student_programme_id'] = $studentProgrammeId;
            $empty['course_offering_id'] = $courseOfferingId;
            return $empty;
        }
        $stmt->bind_param('iis', $studentProgrammeId, $courseOfferingId, $status);
        $stmt->execute();
        $stmt->close();

        return [
            'ok' => true,
            'synced' => true,
            'reason' => '',
            'student_programme_id' => $studentProgrammeId,
            'course_offering_id' => $courseOfferingId,
        ];
    }
}

if (!function_exists('wuc_student_program_period_payload')) {
    /**
     * Normalize a student_program period input according to the programme's
     * structure_type. This prevents legacy forms from saving Transport as Term
     * 3, trade tests as semester records, or term-based programmes as semesters.
     *
     * @return array{ok:bool,reason:string,structure_type:string,period_kind:string,fields:array<string,mixed>}
     */
    function wuc_student_program_period_payload(mysqli $db, string $program_code, $period_number): array
    {
        $structure = wuc_program_structure_type($db, $program_code);
        $kind = wuc_structure_period_kind($structure);
        $fields = [
            'term' => null,
            'semester' => null,
            'current_term_number' => null,
            'current_semester_number' => null,
            'current_level_number' => null,
        ];

        if ($structure === '' || $kind === '') {
            return [
                'ok' => false,
                'reason' => 'Unknown programme structure. Select a valid active programme.',
                'structure_type' => $structure,
                'period_kind' => $kind,
                'fields' => $fields,
            ];
        }

        if ($kind === 'cycle') {
            return [
                'ok' => true,
                'reason' => '',
                'structure_type' => $structure,
                'period_kind' => $kind,
                'fields' => $fields,
            ];
        }

        $normalizedPeriod = wuc_structure_normalize_period_number($period_number, $kind);
        if ($normalizedPeriod === null || trim((string)$normalizedPeriod) === '') {
            return [
                'ok' => false,
                'reason' => "A {$kind} number is required for {$structure} programmes.",
                'structure_type' => $structure,
                'period_kind' => $kind,
                'fields' => $fields,
            ];
        }

        if (!ctype_digit((string)$normalizedPeriod)) {
            return [
                'ok' => false,
                'reason' => "The {$kind} value must be a number.",
                'structure_type' => $structure,
                'period_kind' => $kind,
                'fields' => $fields,
            ];
        }

        $period = (int)$normalizedPeriod;
        if (!wuc_structure_validate_period($structure, $period)) {
            $valid = implode(', ', wuc_structure_valid_period_numbers($structure));
            return [
                'ok' => false,
                'reason' => "Invalid {$kind} {$period} for {$structure}. Allowed values: {$valid}.",
                'structure_type' => $structure,
                'period_kind' => $kind,
                'fields' => $fields,
            ];
        }

        if ($kind === 'term') {
            $fields['term'] = (string)$period;
            $fields['current_term_number'] = $period;
        } elseif ($kind === 'semester') {
            $fields['semester'] = $period;
            $fields['current_semester_number'] = $period;
        } elseif ($kind === 'level') {
            $fields['current_level_number'] = $period;
        }

        return [
            'ok' => true,
            'reason' => '',
            'structure_type' => $structure,
            'period_kind' => $kind,
            'fields' => $fields,
        ];
    }
}

if (!function_exists('wuc_curriculum_courses')) {
    /**
     * The courses in a curriculum version, ordered for display. Joins courses for
     * the human-readable name. Returns [] for an unknown version.
     *
     * @return array<int,array<string,mixed>>
     */
    function wuc_curriculum_courses(mysqli $db, int $curriculum_version_id): array
    {
        $sql = "SELECT cc.id, cc.course_code, c.course_name,
                       cc.year_number, cc.term_number, cc.semester_number,
                       cc.level_number, cc.is_core, cc.credit_value, cc.assessment_mode,
                       cc.display_order
                  FROM curriculum_courses cc
                  LEFT JOIN courses c ON c.course_code = cc.course_code
                 WHERE cc.curriculum_version_id = ?
                 ORDER BY cc.year_number, cc.term_number, cc.semester_number,
                          cc.level_number, cc.display_order, c.course_name";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $curriculum_version_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
