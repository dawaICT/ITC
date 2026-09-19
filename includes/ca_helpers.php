<?php
require_once __DIR__ . '/finance_guard.php';
require_once __DIR__ . '/academic_risk_engine.php';

function ca_table_exists(mysqli $db, string $table): bool
{
    return function_exists('wuc_table_exists') ? wuc_table_exists($db, $table) : false;
}

function ca_column_exists(mysqli $db, string $table, string $column): bool
{
    return function_exists('wuc_column_exists') ? wuc_column_exists($db, $table, $column) : false;
}

/**
 * Resolve the student-id column on student_courses.
 * Live schema uses student_id (not Sid). Returns null when the table/column is unusable.
 */
function ca_student_courses_sid_column(mysqli $db): ?string
{
    if (!ca_table_exists($db, 'student_courses')) {
        return null;
    }
    if (ca_column_exists($db, 'student_courses', 'student_id')) {
        return 'student_id';
    }
    if (ca_column_exists($db, 'student_courses', 'Sid')) {
        return 'Sid';
    }
    if (ca_column_exists($db, 'student_courses', 'SID')) {
        return 'SID';
    }
    return null;
}

function ca_ensure_schema(mysqli $db): void
{
    // All schema mutations are owned by migrations. This legacy body remains
    // below temporarily for compatibility archaeology but is unreachable.
    if (!ca_table_exists($db, 'semester_assessment') || !ca_table_exists($db, 'portal_settings')) {
        error_log('CA schema is incomplete; run migrations.');
        return;
    }
    foreach (['A1', 'A2', 'A3', 'T1', 'T2', 'Exam', 'Total_CA', 'semester', 'Year'] as $requiredColumn) {
        if (!ca_column_exists($db, 'semester_assessment', $requiredColumn)) {
            error_log("semester_assessment.{$requiredColumn} is missing; run migrations.");
            return;
        }
    }
    return;

    @$db->query("CREATE TABLE IF NOT EXISTS semester_assessment (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        Sid VARCHAR(50) NOT NULL,
        Course_Code VARCHAR(50) NOT NULL,
        A1 DECIMAL(6,2) NULL DEFAULT NULL,
        A2 DECIMAL(6,2) NULL DEFAULT NULL,
        A3 DECIMAL(6,2) NULL DEFAULT NULL,
        T1 DECIMAL(6,2) NULL DEFAULT NULL,
        T2 DECIMAL(6,2) NULL DEFAULT NULL,
        Exam DECIMAL(6,2) NULL DEFAULT NULL,
        Total_CA DECIMAL(6,2) NULL DEFAULT NULL,
        semester VARCHAR(20) NOT NULL,
        Year VARCHAR(10) NOT NULL,
        program_type VARCHAR(20) DEFAULT 'semester',
        posted_by VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sid_term (Sid, Course_Code, semester, Year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach (['A1', 'A2', 'A3', 'T1', 'T2', 'Exam', 'Total_CA'] as $col) {
        if (!ca_column_exists($db, 'semester_assessment', $col)) {
            @$db->query("ALTER TABLE semester_assessment ADD COLUMN `{$col}` DECIMAL(6,2) NULL DEFAULT NULL");
        } else {
            @$db->query("ALTER TABLE semester_assessment MODIFY COLUMN `{$col}` DECIMAL(6,2) NULL DEFAULT NULL");
        }
    }
    foreach ([
        'semester' => "VARCHAR(20) NOT NULL",
        'Year' => "VARCHAR(10) NOT NULL",
        'program_type' => "VARCHAR(20) DEFAULT 'semester'",
        'posted_by' => "VARCHAR(50) DEFAULT NULL",
        'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
    ] as $col => $definition) {
        if (!ca_column_exists($db, 'semester_assessment', $col)) {
            @$db->query("ALTER TABLE semester_assessment ADD COLUMN `{$col}` {$definition}");
        }
    }

    @$db->query("UPDATE semester_assessment
        SET
            Exam = NULL,
            Total_CA = CASE
                WHEN ((A1 IS NOT NULL) + (A2 IS NOT NULL) + (A3 IS NOT NULL) + (T1 IS NOT NULL) + (T2 IS NOT NULL)) > 0
                THEN ROUND((COALESCE(A1, 0) + COALESCE(A2, 0) + COALESCE(A3, 0) + COALESCE(T1, 0) + COALESCE(T2, 0)) /
                    ((A1 IS NOT NULL) + (A2 IS NOT NULL) + (A3 IS NOT NULL) + (T1 IS NOT NULL) + (T2 IS NOT NULL)), 2)
                ELSE NULL
            END");

    if (ca_table_exists($db, 'course_registration')) {
        foreach ([
            'tuition_total' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
            'amount_paid' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
            'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
            'updated_at' => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
        ] as $col => $definition) {
            if (!ca_column_exists($db, 'course_registration', $col)) {
                @$db->query("ALTER TABLE course_registration ADD COLUMN `{$col}` {$definition}");
            }
        }
    }

    @$db->query("CREATE TABLE IF NOT EXISTS portal_settings (
        setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ca_setting(mysqli $db, string $key, string $default = '1'): string
{
    $value = $default;
    if ($stmt = @$db->prepare("SELECT setting_value FROM portal_settings WHERE setting_key = ? LIMIT 1")) {
        $stmt->bind_param('s', $key);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $value = (string)$res->fetch_assoc()['setting_value'];
            }
        }
        $stmt->close();
    }
    return $value;
}

function ca_component_map(): array
{
    return [
        'CA1_SEM' => ['col' => 'A1', 'window' => 'A1', 'label' => 'Assignment 1'],
        'CA2_SEM' => ['col' => 'A2', 'window' => 'A2', 'label' => 'Assignment 2'],
        'Test_SEM' => ['col' => 'T1', 'window' => 'T1', 'label' => 'Test'],
        'CA1_TERM' => ['col' => 'A1', 'window' => 'A1', 'label' => 'Assignment 1'],
        'CA2_TERM' => ['col' => 'A2', 'window' => 'A2', 'label' => 'Assignment 2'],
        'Test1_TERM' => ['col' => 'T1', 'window' => 'T1', 'label' => 'Test 1'],
        'Test2_TERM' => ['col' => 'T2', 'window' => 'T2', 'label' => 'Test 2'],
    ];
}

function ca_empty_components(): array
{
    return ['A1' => null, 'A2' => null, 'A3' => null, 'T1' => null, 'T2' => null, 'Exam' => null];
}

function ca_merge_component_values(array $existing, array $provided): array
{
    $merged = ca_empty_components();
    foreach ($merged as $component => $_) {
        if (array_key_exists($component, $existing) && $existing[$component] !== null && $existing[$component] !== '') {
            $merged[$component] = (float)$existing[$component];
        }
    }
    foreach ($provided as $component => $mark) {
        if ($component === 'Exam' || !array_key_exists($component, $merged) || $mark === null || $mark === '') {
            continue;
        }
        $merged[$component] = round((float)$mark, 2);
    }
    $merged['Exam'] = null;
    return $merged;
}

function ca_calculate_total_ca(array $components): ?float
{
    $localComponents = ['A1', 'A2', 'A3', 'T1', 'T2'];
    $entered = [];
    foreach ($localComponents as $key) {
        if (array_key_exists($key, $components) && $components[$key] !== null && $components[$key] !== '') {
            $entered[] = (float)$components[$key];
        }
    }
    if (!$entered) {
        return null;
    }
    return round(array_sum($entered) / count($entered), 2);
}

/**
 * Resolve raw-mark limits for a student's course. Scheme weights determine
 * aggregation; max_mark determines what a lecturer may enter.
 *
 * @return array{components:array<string,float>,total:float}
 */
function ca_course_component_limits(mysqli $db, string $sid, string $courseCode): array
{
    $limits = [
        'A1' => 100.0,
        'A2' => 100.0,
        'A3' => 100.0,
        'T1' => 100.0,
        'T2' => 100.0,
    ];
    $total = 100.0;

    if (!ca_table_exists($db, 'student_program')
        || !ca_table_exists($db, 'assessment_schemes')
        || !ca_table_exists($db, 'assessment_components')) {
        return ['components' => $limits, 'total' => $total];
    }

    $sql = "SELECT ac.component_name, ac.component_type, ac.max_mark
              FROM student_program sp
              JOIN assessment_schemes sch ON sch.program_code = sp.program_code
                                         AND sch.course_code = ?
                                         AND sch.status = 'active'
              JOIN assessment_components ac ON ac.assessment_scheme_id = sch.id
             WHERE sp.Sid = ?
             ORDER BY ac.display_order, ac.id";
    if (!$stmt = $db->prepare($sql)) {
        return ['components' => $limits, 'total' => $total];
    }
    $stmt->bind_param('ss', $courseCode, $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    $resolved = $limits;
    $found = false;
    while ($row = $res->fetch_assoc()) {
        if (strtoupper((string)$row['component_type']) === 'EXAM') {
            continue;
        }
        $found = true;
        $name = strtolower(trim((string)$row['component_name']));
        $maxMark = min(100.0, max(0.0, (float)$row['max_mark']));
        if (str_contains($name, 'assignment 1') || $name === 'a1') {
            $resolved['A1'] = $maxMark;
        } elseif (str_contains($name, 'assignment 2') || $name === 'a2') {
            $resolved['A2'] = $maxMark;
        } elseif (str_contains($name, 'assignment 3') || $name === 'a3') {
            $resolved['A3'] = $maxMark;
        } elseif (str_contains($name, 'test') || str_contains($name, 'practical')) {
            $resolved['T1'] = $maxMark;
            $resolved['T2'] = $maxMark;
        }
    }
    $stmt->close();

    return ['components' => $found ? $resolved : $limits, 'total' => $found ? $total : 100.0];
}

/** @return array<string,float> */
function ca_course_component_weights(mysqli $db, string $sid, string $courseCode): array
{
    // Default CA mix when no programme-specific assessment scheme is
    // configured: assignments carry 25% each and tests/practicals 50% each.
    // The calculation normalizes by the components actually entered.
    $weights = ['A1' => 25.0, 'A2' => 25.0, 'A3' => 25.0, 'T1' => 50.0, 'T2' => 50.0];
    if (!ca_table_exists($db, 'student_program')
        || !ca_table_exists($db, 'assessment_schemes')
        || !ca_table_exists($db, 'assessment_components')) {
        return $weights;
    }
    $sql = "SELECT ac.component_name, ac.component_type, ac.weight
              FROM student_program sp
              JOIN assessment_schemes sch ON sch.program_code = sp.program_code
                                         AND sch.course_code = ?
                                         AND sch.status = 'active'
              JOIN assessment_components ac ON ac.assessment_scheme_id = sch.id
             WHERE sp.Sid = ?
             ORDER BY ac.display_order, ac.id";
    if (!$stmt = $db->prepare($sql)) {
        return $weights;
    }
    $stmt->bind_param('ss', $courseCode, $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    $resolved = ['A1' => 0.0, 'A2' => 0.0, 'A3' => 0.0, 'T1' => 0.0, 'T2' => 0.0];
    $found = false;
    while ($row = $res->fetch_assoc()) {
        if (strtoupper((string)$row['component_type']) === 'EXAM') {
            continue;
        }
        $found = true;
        $name = strtolower(trim((string)$row['component_name']));
        $weight = max(0.0, (float)$row['weight']);
        if (str_contains($name, 'assignment 1') || $name === 'a1') {
            $resolved['A1'] += $weight;
        } elseif (str_contains($name, 'assignment 2') || $name === 'a2') {
            $resolved['A2'] += $weight;
        } elseif (str_contains($name, 'assignment 3') || $name === 'a3') {
            $resolved['A3'] += $weight;
        } elseif (str_contains($name, 'test') || str_contains($name, 'practical')) {
            $resolved['T1'] += $weight;
            $resolved['T2'] += $weight;
        }
    }
    $stmt->close();
    return $found ? $resolved : $weights;
}

function ca_calculate_course_total(mysqli $db, string $sid, string $courseCode, array $components): ?float
{
    $weights = ca_course_component_weights($db, $sid, $courseCode);
    $weightedMarks = 0.0;
    $enteredWeight = 0.0;
    foreach (['A1', 'A2', 'A3', 'T1', 'T2'] as $component) {
        if (!array_key_exists($component, $components) || $components[$component] === null || $components[$component] === '') {
            continue;
        }
        $weight = (float)($weights[$component] ?? 0.0);
        if ($weight <= 0.0) {
            continue;
        }
        $weightedMarks += (float)$components[$component] * $weight;
        $enteredWeight += $weight;
    }
    if ($enteredWeight <= 0.0) {
        return ca_calculate_total_ca($components);
    }
    return min(100.0, round($weightedMarks / $enteredWeight, 2));
}

function ca_validate_component_limits(mysqli $db, string $sid, string $courseCode, array $components): array
{
    $scheme = ca_course_component_limits($db, $sid, $courseCode);
    foreach ($scheme['components'] as $component => $limit) {
        $value = $components[$component] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        if ((float)$value < 0 || (float)$value > $limit + 0.0001) {
            return ['ok' => false, 'message' => "{$component} must be between 0 and " . rtrim(rtrim(number_format($limit, 2), '0'), '.') . '.'];
        }
    }
    $total = ca_calculate_course_total($db, $sid, $courseCode, $components);
    if ($total !== null && $total > $scheme['total'] + 0.0001) {
        return ['ok' => false, 'message' => 'Period CA percentage cannot exceed 100.'];
    }
    return ['ok' => true, 'message' => 'OK', 'total' => $total];
}

function ca_validate_annual_total(mysqli $db, string $sid, string $courseCode, string $period, string $year, ?float $proposedTotal): array
{
    if ($proposedTotal === null) {
        return ['ok' => true, 'message' => 'OK', 'annual_total' => null];
    }
    $otherTotal = 0.0;
    $otherCount = 0;
    $sql = "SELECT COALESCE(SUM(Total_CA), 0) AS total, COUNT(Total_CA) AS periods
              FROM semester_assessment
             WHERE Sid = ? AND Course_Code = ? AND Year = ? AND semester <> ?
             FOR UPDATE";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('ssss', $sid, $courseCode, $year, $period);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $otherTotal = (float)($row['total'] ?? 0.0);
        $otherCount = (int)($row['periods'] ?? 0);
        $stmt->close();
    }
    $annualTotal = round(($otherTotal + $proposedTotal) / ($otherCount + 1), 2);
    if ($annualTotal > 100.0001) {
        return ['ok' => false, 'message' => "Annual CA percentage would be {$annualTotal}. It cannot exceed 100 for the course.", 'annual_total' => $annualTotal];
    }
    return ['ok' => true, 'message' => 'OK', 'annual_total' => $annualTotal];
}

function ca_windows(mysqli $db): array
{
    $windows = [];
    foreach (['A1', 'A2', 'T1', 'T2'] as $component) {
        $start = ca_setting($db, "ca_{$component}_start", '');
        $duration = (int)ca_setting($db, "ca_{$component}_duration_days", '5');
        $windows[$component] = [
            'enabled' => ca_setting($db, "ca_{$component}_enabled", '1') === '1',
            'start' => $start,
            'end' => ($start !== '' && $duration > 0) ? date('Y-m-d H:i:s', strtotime($start . ' +' . $duration . ' days')) : '',
        ];
    }
    return $windows;
}

function ca_window_allows(array $windows, string $component, float $value): array
{
    if ($value <= 0 || empty($windows[$component]) || empty($windows[$component]['enabled'])) {
        return ['ok' => true, 'message' => 'OK'];
    }
    $start = (string)$windows[$component]['start'];
    $end = (string)$windows[$component]['end'];
    if ($start === '' || $end === '') {
        return ['ok' => true, 'message' => 'OK'];
    }
    $now = date('Y-m-d H:i:s');
    if ($now < $start || $now > $end) {
        return ['ok' => false, 'message' => "Upload window for {$component} is closed ({$start} to {$end})."];
    }
    return ['ok' => true, 'message' => 'OK'];
}

function ca_validate_period(string $period, string $year, string $programType = 'semester'): array
{
    if ($programType !== 'short_course') {
        if (!preg_match('/^[1-3]$/', $period)) {
            return ['ok' => false, 'message' => 'Period must be 1, 2, or 3.'];
        }
    } else {
        if (trim($period) === '') {
            return ['ok' => false, 'message' => 'Intake batch/period cannot be empty.'];
        }
    }
    // The academic year is now the calendar year (course_registration.academic_year),
    // so a 4-digit year is required. This catches a year-of-study value (e.g. "1")
    // being passed where the academic year is expected.
    if (!preg_match('/^\d{4}$/', $year)) {
        return ['ok' => false, 'message' => 'Academic year must be a 4-digit year (e.g. ' . date('Y') . ').'];
    }
    return ['ok' => true, 'message' => 'OK'];
}

function ca_student_structure_type(mysqli $db, string $sid, string $courseCode): string
{
    if (!ca_table_exists($db, 'student_program')
        || !ca_table_exists($db, 'programs')
        || !ca_table_exists($db, 'program_courses')
        || !ca_table_exists($db, 'assessment_schemes')) {
        return '';
    }
    $sql = "SELECT p.structure_type
              FROM student_program sp
              JOIN programs p ON p.program_code = sp.program_code
             WHERE sp.Sid = ?
               AND COALESCE(sp.status, 'active') NOT IN ('inactive','withdrawn','suspended')
               AND (
                    EXISTS (SELECT 1 FROM program_courses pc WHERE pc.program_code = sp.program_code AND pc.course_code = ?)
                 OR EXISTS (SELECT 1 FROM assessment_schemes sch WHERE sch.program_code = sp.program_code AND sch.course_code = ? AND sch.status = 'active')
               )
             ORDER BY sp.id DESC
             LIMIT 1";
    if (!$stmt = $db->prepare($sql)) {
        return '';
    }
    $stmt->bind_param('sss', $sid, $courseCode, $courseCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return strtoupper(trim((string)($row['structure_type'] ?? '')));
}

function ca_validate_period_component_shape(mysqli $db, string $sid, string $courseCode, string $period, array $components): array
{
    if (ca_student_structure_type($db, $sid, $courseCode) !== 'TERM_BASED') {
        return ['ok' => true, 'message' => 'OK'];
    }
    $entered = static fn(string $key): bool => array_key_exists($key, $components)
        && $components[$key] !== null
        && $components[$key] !== '';

    if ($period === '1' && $entered('T2')) {
        return ['ok' => false, 'message' => 'Term 1 uses T1. Leave T2 blank.'];
    }
    if ($period === '2' && $entered('T1')) {
        return ['ok' => false, 'message' => 'Term 2 uses T2. Leave T1 blank.'];
    }
    if ($period === '3' && ($entered('T1') || $entered('T2'))) {
        return ['ok' => false, 'message' => 'Term 3 accepts assignment marks only; leave T1 and T2 blank.'];
    }
    return ['ok' => true, 'message' => 'OK'];
}

function ca_registration_active_sql(mysqli $db, string $tableAlias = ''): string
{
    $prefix = $tableAlias !== '' ? rtrim($tableAlias, '.') . '.' : '';
    if (!ca_table_exists($db, 'course_registration')) {
        return '';
    }
    $hasIsActive = ca_column_exists($db, 'course_registration', 'is_active');
    if ($hasIsActive) {
        return " AND ({$prefix}is_active = 1 OR {$prefix}status IN ('active','registered'))";
    }
    return " AND ({$prefix}status IS NULL OR {$prefix}status NOT IN ('dropped','cancelled','withdrawn'))";
}

/**
 * Whether the course is configured as full-year in the canonical curriculum
 * (preferred) or the legacy program_courses map. Full-year courses stay
 * visible in every period of the academic year, so CA class lists and
 * registration guards must not filter them by the enrolment-period stamp.
 */
function ca_course_is_full_year(mysqli $db, string $courseCode): bool
{
    static $cache = [];
    $courseCode = trim($courseCode);
    if ($courseCode === '') {
        return false;
    }
    if (array_key_exists($courseCode, $cache)) {
        return $cache[$courseCode];
    }

    $fullYear = false;
    if (ca_table_exists($db, 'curriculum_courses') && ca_column_exists($db, 'curriculum_courses', 'is_full_year')) {
        if ($stmt = $db->prepare('SELECT 1 FROM curriculum_courses WHERE course_code = ? AND COALESCE(is_full_year, 0) = 1 LIMIT 1')) {
            $stmt->bind_param('s', $courseCode);
            $stmt->execute();
            $stmt->store_result();
            $fullYear = $stmt->num_rows > 0;
            $stmt->close();
        }
    }
    if (!$fullYear && ca_table_exists($db, 'program_courses') && ca_column_exists($db, 'program_courses', 'is_full_year')) {
        if ($stmt = $db->prepare('SELECT 1 FROM program_courses WHERE course_code = ? AND COALESCE(is_full_year, 0) = 1 LIMIT 1')) {
            $stmt->bind_param('s', $courseCode);
            $stmt->execute();
            $stmt->store_result();
            $fullYear = $stmt->num_rows > 0;
            $stmt->close();
        }
    }

    return $cache[$courseCode] = $fullYear;
}

function ca_course_period_mode(mysqli $db, string $courseCode): array
{
    $periodMode = 'semester';
    $examinationType = null;
    $isShortCourse = false;
    $programStructure = null;

    if (ca_table_exists($db, 'program_courses') && ca_table_exists($db, 'programs')) {
        $hasPeriodMode = ca_column_exists($db, 'programs', 'period_mode');
        $hasAcademicStructure = ca_column_exists($db, 'programs', 'academic_structure');
        $hasExaminationType = ca_column_exists($db, 'programs', 'examination_type');
        $hasIsShortCourse = ca_column_exists($db, 'programs', 'is_short_course');

        $select = ['pc.program_code'];
        $select[] = $hasPeriodMode ? 'p.period_mode' : "NULL AS period_mode";
        $select[] = $hasAcademicStructure ? 'p.academic_structure' : "NULL AS academic_structure";
        $select[] = $hasExaminationType ? 'p.examination_type' : "NULL AS examination_type";
        $select[] = $hasIsShortCourse ? 'p.is_short_course' : '0 AS is_short_course';

        $orderBits = [];
        if ($hasAcademicStructure) {
            $orderBits[] = "(p.academic_structure = 'short_course') DESC";
        }
        if ($hasIsShortCourse) {
            $orderBits[] = 'p.is_short_course DESC';
        }
        $orderBits[] = 'pc.program_code';
        $orderSql = implode(', ', $orderBits);

        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM program_courses pc'
            . ' JOIN programs p ON p.program_code = pc.program_code'
            . ' WHERE pc.course_code = ?'
            . ' ORDER BY ' . $orderSql
            . ' LIMIT 1';

        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $courseCode);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    $programStructure = (string)($row['academic_structure'] ?? '');
                    $examinationType = (string)($row['examination_type'] ?? '');
                    $flaggedShort = !empty($row['is_short_course']);
                    if ($programStructure === 'short_course' || $flaggedShort) {
                        $isShortCourse = true;
                        $periodMode = 'short_course';
                    } elseif ($programStructure === 'semester_exception' || ($row['period_mode'] ?? '') === 'semester') {
                        $periodMode = 'semester';
                    } else {
                        $periodMode = 'term';
                    }
                }
            }
            $stmt->close();
        }
    }

    // Prefer standalone short_courses catalogue as the source of truth.
    // Programme flags alone are not required when the course lives in short_courses.
    if (!$isShortCourse && ca_table_exists($db, 'short_courses')) {
        if ($stmt = $db->prepare("SELECT 1 FROM short_courses WHERE course_code = ? AND status IN ('active','upcoming') LIMIT 1")) {
            $stmt->bind_param('s', $courseCode);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $isShortCourse = true;
                    $periodMode = 'short_course';
                }
            }
            $stmt->close();
        }
    }

    $courseType = '';
    if (ca_table_exists($db, 'courses') && ca_column_exists($db, 'courses', 'course_type')) {
        if ($stmt = $db->prepare('SELECT course_type FROM courses WHERE course_code = ? LIMIT 1')) {
            $stmt->bind_param('s', $courseCode);
            if ($stmt->execute()) {
                $courseRow = $stmt->get_result()->fetch_assoc();
                $courseType = strtolower(trim((string)($courseRow['course_type'] ?? '')));
            }
            $stmt->close();
        }
    }

    // courses.course_type is a weak signal — only treat as short course when a programme
    // or short_courses row confirms it. Many legacy rows are mis-tagged "short course".
    if ($courseType === 'short course' && ($programStructure === 'short_course' || $isShortCourse)) {
        $isShortCourse = true;
        $periodMode = 'short_course';
    } elseif ($courseType === 'short course' && $programStructure === null && ca_table_exists($db, 'short_courses')) {
        if ($stmt = $db->prepare("SELECT 1 FROM short_courses WHERE course_code = ? AND status = 'active' LIMIT 1")) {
            $stmt->bind_param('s', $courseCode);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $isShortCourse = true;
                    $periodMode = 'short_course';
                }
            }
            $stmt->close();
        }
    }

    if ($examinationType === null || $examinationType === '') {
        $examinationType = 'internal';
    }

    return [
        'period_mode' => $periodMode,
        'examination_type' => $examinationType,
        'is_short_course' => $isShortCourse,
    ];
}

function ca_is_external_short_course(mysqli $db, string $courseCode): bool
{
    $mode = ca_course_period_mode($db, $courseCode);
    return !empty($mode['is_short_course']) && ($mode['examination_type'] ?? '') === 'external';
}

function ca_short_course_upload_path(): string
{
    return 'upload_ca_short.php';
}

function ca_period_label(string $periodMode, string $period): string
{
    if ($periodMode === 'short_course') {
        return 'Intake Batch ' . $period;
    }
    if ($periodMode === 'term') {
        return 'Term ' . $period;
    }
    return 'Semester ' . $period;
}

function ca_normalized_registration_tables_ready(mysqli $db): bool
{
    foreach (['student_program', 'student_course_registrations', 'course_offerings', 'academic_periods', 'curriculum_courses'] as $table) {
        if (!ca_table_exists($db, $table)) {
            return false;
        }
    }
    return true;
}

function ca_course_has_normalized_registrations(mysqli $db, string $courseCode): bool
{
    if (!ca_normalized_registration_tables_ready($db)) {
        return false;
    }
    $sql = "SELECT 1
              FROM student_course_registrations scr
              JOIN course_offerings co ON co.id = scr.course_offering_id
              JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
             WHERE cc.course_code = ?
               AND scr.registration_status IN ('REGISTERED', 'REPEATING')
               AND (co.status IS NULL OR co.status <> 'cancelled')
             LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $courseCode);
    $stmt->execute();
    $stmt->store_result();
    $found = $stmt->num_rows > 0;
    $stmt->close();
    return $found;
}

function ca_fetch_course_students(mysqli $db, string $courseCode, string $period, string $year): array
{
    $students = [];
    $seen = [];

    $studentSidCol = ca_column_exists($db, 'students', 'SID') ? 'SID'
        : (ca_column_exists($db, 'students', 'Sid') ? 'Sid' : 'SID');

    $appendStudent = static function (array $row) use (&$students, &$seen): void {
        $sid = (string)($row['Sid'] ?? '');
        if ($sid === '' || isset($seen[$sid])) {
            return;
        }
        $seen[$sid] = true;
        $students[] = [
            'Sid' => $sid,
            'name' => trim((string)($row['Fname'] ?? '') . ' ' . (string)($row['Lname'] ?? '')),
        ];
    };

    // Merge normalized offering registrations with legacy course_registration.
    // Year auto-enrol primarily writes legacy rows; normalized period numbers can
    // lag or differ. Early-return on normalized-only left lecturers with empty
    // class lists for assigned term/semester periods.
    // Full-year courses stay visible in every period of the academic year:
    // only period-specific courses are filtered by the requested period.
    $fullYearCourse = ca_course_is_full_year($db, $courseCode);

    if (ca_course_has_normalized_registrations($db, $courseCode)) {
        $periodPredicate = $fullYearCourse ? '' : 'AND CAST(ap.period_number AS CHAR) = ?';
        $sql = "SELECT sp.Sid, COALESCE(s.Fname, '') AS Fname, COALESCE(s.Lname, '') AS Lname
                  FROM student_course_registrations scr
                  JOIN student_program sp ON sp.id = scr.student_programme_id
                  JOIN course_offerings co ON co.id = scr.course_offering_id
                  JOIN academic_periods ap ON ap.id = co.academic_period_id
                  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
             LEFT JOIN students s ON s.`{$studentSidCol}` COLLATE utf8mb4_general_ci = sp.Sid COLLATE utf8mb4_general_ci
                 WHERE cc.course_code = ?
                   AND COALESCE(NULLIF(ap.academic_year, ''), CAST(sp.academic_year AS CHAR), '') = ?
                   {$periodPredicate}
                   AND scr.registration_status IN ('REGISTERED', 'REPEATING')
                   AND (co.status IS NULL OR co.status <> 'cancelled')
                 ORDER BY sp.Sid";
        $stmt = $db->prepare($sql);
        if ($fullYearCourse) {
            $stmt->bind_param('ss', $courseCode, $year);
        } else {
            $stmt->bind_param('sss', $courseCode, $year, $period);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $appendStudent($row);
        }
        $stmt->close();
    }

    if (ca_table_exists($db, 'course_registration')) {
        $hasAcadYear = ca_column_exists($db, 'course_registration', 'academic_year');
        $yearCol = $hasAcadYear ? 'academic_year' : 'Year';
        $activeSql = ca_registration_active_sql($db, 'cr');

        $sql = "SELECT cr.Sid, COALESCE(s.Fname, '') AS Fname, COALESCE(s.Lname, '') AS Lname
                FROM course_registration cr
                LEFT JOIN students s ON s.`{$studentSidCol}` COLLATE utf8mb4_general_ci = cr.Sid COLLATE utf8mb4_general_ci
                WHERE cr.course_code = ?
                  AND CAST(cr.`{$yearCol}` AS CHAR) = ?";
        $params = [$courseCode, $year];
        $types = 'ss';

        if ($period !== '' && !$fullYearCourse) {
            $sql .= ' AND CAST(cr.semester AS CHAR) = ?';
            $params[] = $period;
            $types .= 's';
        }
        $sql .= $activeSql . ' ORDER BY cr.Sid';

        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $appendStudent($row);
                }
            }
            $stmt->close();
        }
    }

    if (($scSidCol = ca_student_courses_sid_column($db)) !== null) {
        $sql = "SELECT sc.`{$scSidCol}` AS Sid, COALESCE(s.Fname, '') AS Fname, COALESCE(s.Lname, '') AS Lname
                FROM student_courses sc
                LEFT JOIN students s ON s.`{$studentSidCol}` COLLATE utf8mb4_general_ci = sc.`{$scSidCol}` COLLATE utf8mb4_general_ci
                WHERE sc.course_code = ?
                  AND CAST(sc.academic_year AS CHAR) = ?
                  AND (sc.status IS NULL OR sc.status NOT IN ('dropped','cancelled','withdrawn'))";
        $params = [$courseCode, $year];
        $types = 'ss';

        if ($period !== '' && !$fullYearCourse) {
            $sql .= ' AND CAST(sc.semester AS CHAR) = ?';
            $params[] = $period;
            $types .= 's';
        }
        $sql .= ' ORDER BY sc.`' . $scSidCol . '`';

        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $appendStudent($row);
                }
            }
            $stmt->close();
        }
    }

    return ['students' => $students, 'count' => count($students)];
}

function ca_registration_diagnostics(mysqli $db, string $courseCode): array
{
    $diagnostics = [];

    foreach (ca_registration_coverage_rows($db) as $row) {
        if ($row['course_code'] === $courseCode) {
            $diagnostics[] = [
                'semester' => $row['semester'],
                'year' => $row['academic_year'],
            ];
        }
    }

    return $diagnostics;
}

/**
 * Read active registration coverage from the normalized registration model and
 * the legacy course_registration table. When a course exists in the normalized
 * model, those offering/period rows are authoritative; legacy rows are retained
 * only for courses that have not yet been migrated.
 *
 * @return array<int, array{course_code:string, academic_year:string, semester:string, count:int, source:string}>
 */
function ca_registration_coverage_rows(mysqli $db): array
{
    $normalized = [];
    $normalizedCourses = [];
    $normalizedReady = ca_normalized_registration_tables_ready($db);

    if ($normalizedReady) {
        $sql = "SELECT cc.course_code,
                       COALESCE(NULLIF(ap.academic_year, ''), CAST(sp.academic_year AS CHAR), '') AS year_val,
                       CAST(ap.period_number AS CHAR) AS semester,
                       COUNT(DISTINCT sp.Sid) AS n
                  FROM student_course_registrations scr
                  JOIN student_program sp ON sp.id = scr.student_programme_id
                  JOIN course_offerings co ON co.id = scr.course_offering_id
                  JOIN academic_periods ap ON ap.id = co.academic_period_id
                  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                 WHERE scr.registration_status IN ('REGISTERED', 'REPEATING')
                   AND (co.status IS NULL OR co.status <> 'cancelled')
                 GROUP BY cc.course_code, year_val, ap.period_number";
        if ($res = $db->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $courseCode = (string)($row['course_code'] ?? '');
                if ($courseCode === '') {
                    continue;
                }
                $normalizedCourses[$courseCode] = true;
                $normalized[] = [
                    'course_code' => $courseCode,
                    'academic_year' => (string)($row['year_val'] ?? ''),
                    'semester' => (string)($row['semester'] ?? ''),
                    'count' => (int)$row['n'],
                    'source' => 'Normalized',
                ];
            }
            $res->free();
        }
    }

    $legacy = [];
    $legacySelects = [];
    if (ca_table_exists($db, 'course_registration')) {
        $hasAcadYear = ca_column_exists($db, 'course_registration', 'academic_year');
        $yearCol = $hasAcadYear ? 'academic_year' : 'Year';
        $activeSql = ca_registration_active_sql($db);
        $legacySelects[] = "SELECT course_code,
                                  CAST(`{$yearCol}` AS CHAR) AS year_val,
                                  CAST(semester AS CHAR) AS semester,
                                  CAST(Sid AS CHAR) AS student_id
                             FROM course_registration
                            WHERE 1=1{$activeSql}";
    }
    if (($studentCourseSid = ca_student_courses_sid_column($db)) !== null) {
        $legacySelects[] = "SELECT course_code,
                                  CAST(academic_year AS CHAR) AS year_val,
                                  CAST(semester AS CHAR) AS semester,
                                  CAST(`{$studentCourseSid}` AS CHAR) AS student_id
                             FROM student_courses
                            WHERE status IS NULL
                               OR status = ''
                               OR status NOT IN ('dropped', 'cancelled', 'withdrawn')";
    }

    if ($legacySelects !== []) {
        $sql = "SELECT course_code, year_val, semester, COUNT(DISTINCT student_id) AS n
                  FROM (" . implode(' UNION ALL ', $legacySelects) . ") legacy_registrations
                 GROUP BY course_code, year_val, semester";
        if ($res = $db->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $courseCode = (string)($row['course_code'] ?? '');
                if ($courseCode === '' || isset($normalizedCourses[$courseCode])) {
                    continue;
                }
                $legacy[] = [
                    'course_code' => $courseCode,
                    'academic_year' => (string)($row['year_val'] ?? ''),
                    'semester' => (string)($row['semester'] ?? ''),
                    'count' => (int)$row['n'],
                    'source' => 'Legacy',
                ];
            }
            $res->free();
        }
    }

    $rows = array_merge($normalized, $legacy);
    usort($rows, static function (array $a, array $b): int {
        $yearOrder = strnatcasecmp($b['academic_year'], $a['academic_year']);
        if ($yearOrder !== 0) {
            return $yearOrder;
        }
        $courseOrder = strnatcasecmp($a['course_code'], $b['course_code']);
        return $courseOrder !== 0 ? $courseOrder : strnatcasecmp($a['semester'], $b['semester']);
    });
    return $rows;
}

/**
 * @param string[] $courseCodes
 * @return array<string, array<int, array{academic_year:string, semester:string, count:int, source:string}>>
 */
function ca_registration_overview(mysqli $db, array $courseCodes): array
{
    $overview = [];
    foreach ($courseCodes as $courseCode) {
        $overview[(string)$courseCode] = [];
    }
    foreach (ca_registration_coverage_rows($db) as $row) {
        if (isset($overview[$row['course_code']])) {
            $overview[$row['course_code']][] = $row;
        }
    }
    return $overview;
}

/**
 * @return array{rows: array<int, array{course_code:string, academic_year:string, semester:string, count:int, source:string}>, capped: bool}
 */
function ca_registration_coverage_all(mysqli $db, int $limit = 100): array
{
    $rows = ca_registration_coverage_rows($db);
    $capped = count($rows) > $limit;
    return [
        'rows' => $capped ? array_slice($rows, 0, $limit) : $rows,
        'capped' => $capped,
    ];
}

function ca_build_no_students_message(
    string $courseName,
    string $periodLabel,
    string $year,
    string $periodMode,
    array $diagnostics
): string {
    $msg = 'No students registered for ' . $courseName
        . ' · ' . $periodLabel
        . ' · Academic Year ' . $year . '.';

    if ($diagnostics === []) {
        return $msg . ' No enrolment records found for this course.';
    }

    $otherPeriodsInYear = [];
    $otherYears = [];
    foreach ($diagnostics as $d) {
        if (($d['year'] ?? '') === $year) {
            $label = ca_period_label($periodMode, (string)($d['semester'] ?? ''));
            if ($label !== '' && !in_array($label, $otherPeriodsInYear, true)) {
                $otherPeriodsInYear[] = $label;
            }
        } elseif (($d['year'] ?? '') !== '' && !in_array((string)$d['year'], $otherYears, true)) {
            $otherYears[] = (string)$d['year'];
        }
    }

    if ($otherPeriodsInYear !== []) {
        return $msg . ' Students are registered in ' . implode(', ', $otherPeriodsInYear) . ' for this year — try that period.';
    }
    if ($otherYears !== []) {
        return $msg . ' Students are registered in other years (' . implode(', ', $otherYears) . ') — check the academic year.';
    }

    return $msg;
}

function ca_student_registered(mysqli $db, string $sid, string $courseCode, string $period, string $year): bool
{
    // Full-year courses match registrations from any period of the academic
    // year; period-specific courses stay period-strict.
    $fullYearCourse = ca_course_is_full_year($db, $courseCode);

    // Prefer normalized offering model when it has a hit for this period, but
    // always fall through to legacy course_registration. Auto-enrol writes
    // legacy rows (often year-scoped semester) that may not match normalized
    // period numbers yet — without fallthrough, lecturers cannot enter CA.
    if (ca_course_has_normalized_registrations($db, $courseCode)) {
        $periodPredicate = $fullYearCourse ? '' : 'AND CAST(ap.period_number AS CHAR) = ?';
        $sql = "SELECT 1
                  FROM student_course_registrations scr
                  JOIN student_program sp ON sp.id = scr.student_programme_id
                  JOIN course_offerings co ON co.id = scr.course_offering_id
                  JOIN academic_periods ap ON ap.id = co.academic_period_id
                  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                 WHERE sp.Sid = ?
                   AND cc.course_code = ?
                   AND COALESCE(NULLIF(ap.academic_year, ''), CAST(sp.academic_year AS CHAR), '') = ?
                   {$periodPredicate}
                   AND scr.registration_status IN ('REGISTERED', 'REPEATING')
                   AND (co.status IS NULL OR co.status <> 'cancelled')
                 LIMIT 1";
        $stmt = $db->prepare($sql);
        if ($fullYearCourse) {
            $stmt->bind_param('sss', $sid, $courseCode, $year);
        } else {
            $stmt->bind_param('ssss', $sid, $courseCode, $year, $period);
        }
        $stmt->execute();
        $stmt->store_result();
        $registered = $stmt->num_rows > 0;
        $stmt->close();
        if ($registered) {
            return true;
        }
    }

    if (ca_table_exists($db, 'course_registration')) {
        $hasAcadYear = ca_column_exists($db, 'course_registration', 'academic_year');
        $yearCol = $hasAcadYear ? 'academic_year' : 'Year';
        $activeSql = ca_registration_active_sql($db);

        $sql = "SELECT 1 FROM course_registration
                WHERE Sid = ? AND course_code = ?
                  AND CAST(`{$yearCol}` AS CHAR) = ?";
        $params = [$sid, $courseCode, $year];
        $types = 'sss';

        if ($period !== '' && !$fullYearCourse) {
            $sql .= ' AND CAST(semester AS CHAR) = ?';
            $params[] = $period;
            $types .= 's';
        }
        $sql .= $activeSql . ' LIMIT 1';

        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->store_result();
            $ok = $stmt->num_rows > 0;
            $stmt->close();
            if ($ok) {
                return true;
            }
        }
    }

    if (($scSidCol = ca_student_courses_sid_column($db)) !== null) {
        $sql = "SELECT 1 FROM student_courses
                WHERE `{$scSidCol}` = ? AND course_code = ?
                  AND CAST(academic_year AS CHAR) = ?
                  AND (status IS NULL OR status NOT IN ('dropped','cancelled','withdrawn'))";
        $params = [$sid, $courseCode, $year];
        $types = 'sss';

        if ($period !== '' && !$fullYearCourse) {
            $sql .= ' AND CAST(semester AS CHAR) = ?';
            $params[] = $period;
            $types .= 's';
        }
        $sql .= ' LIMIT 1';

        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->store_result();
            $ok = $stmt->num_rows > 0;
            $stmt->close();
            if ($ok) {
                return true;
            }
        }
    }

    // Check if student is actively assigned to a program that includes this course in its curriculum
    if (ca_table_exists($db, 'student_program') && ca_table_exists($db, 'program_courses')) {
        $progSql = "SELECT 1 FROM student_program sp
                    INNER JOIN program_courses pc ON pc.program_code = sp.program_code
                    WHERE sp.Sid COLLATE utf8mb4_general_ci = ?
                      AND UPPER(pc.course_code) = UPPER(?)
                      AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')
                    LIMIT 1";
        if ($progStmt = $db->prepare($progSql)) {
            $progStmt->bind_param('ss', $sid, $courseCode);
            $progStmt->execute();
            $progStmt->store_result();
            $isProgCourse = $progStmt->num_rows > 0;
            $progStmt->close();
            if ($isProgCourse) {
                return true;
            }
        }
    }

    return false;
}

function ca_normalize_component(string $assessmentType, float $rawMark): array
{
    $map = ca_component_map();
    if (!isset($map[$assessmentType])) {
        return ['ok' => false, 'message' => 'Invalid assessment type.'];
    }
    if ($rawMark < 0 || $rawMark > 100) {
        return ['ok' => false, 'message' => 'Mark must be between 0 and 100.'];
    }
    $info = $map[$assessmentType];
    $value = round($rawMark, 2);
    return ['ok' => true, 'component' => $info['col'], 'window' => $info['window'], 'value' => $value, 'label' => $info['label']];
}

function ca_normalized_tables_ready(mysqli $db): bool
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
        if (!ca_table_exists($db, $table)) {
            return false;
        }
    }
    return true;
}

/**
 * Ensure an active legacy course registration has the equivalent normalized
 * programme -> offering -> student registration chain used by marks/results.
 */
function ca_ensure_normalized_registration_bridge(mysqli $db, string $sid, string $courseCode, string $period, string $year): ?int
{
    if (!ca_normalized_tables_ready($db) || !ca_table_exists($db, 'course_registration')) {
        return null;
    }

    $sql = "SELECT sp.id AS student_programme_id,
                   sp.program_code,
                   sp.intake_id,
                   cc.id AS curriculum_course_id,
                   ap.id AS academic_period_id,
                   ap.academic_year_id
              FROM course_registration cr
              JOIN student_program sp ON sp.Sid = cr.Sid
                                     AND COALESCE(sp.status, 'active') NOT IN ('inactive','withdrawn','suspended')
              JOIN programs p ON p.program_code = sp.program_code
              JOIN curriculum_versions cv ON cv.program_code = sp.program_code
                                         AND (
                                              cv.id = sp.curriculum_version_id
                                           OR (sp.curriculum_version_id IS NULL AND cv.status = 'active')
                                         )
              JOIN curriculum_courses cc ON cc.curriculum_version_id = cv.id
                                        AND cc.course_code = cr.course_code
                                        AND (cc.year_number IS NULL OR cc.year_number = cr.Year)
              JOIN academic_periods ap ON ap.academic_year = CAST(cr.academic_year AS CHAR)
                                      AND ap.period_number = IF(COALESCE(cc.is_full_year, 0) = 1, CAST(? AS UNSIGNED), cr.semester)
                                      AND (
                                           (p.structure_type = 'TERM_BASED' AND ap.period_type = 'term')
                                        OR (p.structure_type = 'SEMESTER_BASED' AND ap.period_type = 'semester')
                                        OR (p.structure_type = 'TRADE_TEST_LEVEL' AND ap.period_type = 'trade_test_level')
                                      )
             WHERE cr.Sid = ?
               AND cr.course_code = ?
               AND CAST(cr.academic_year AS CHAR) = ?
               AND (COALESCE(cc.is_full_year, 0) = 1 OR CAST(cr.semester AS CHAR) = ?)
               AND (cr.is_active = 1 OR cr.status IN ('active','registered'))
               AND (
                    cc.is_full_year = 1
                 OR (p.structure_type = 'TERM_BASED' AND cc.term_number = cr.semester)
                 OR (p.structure_type = 'SEMESTER_BASED' AND cc.semester_number = cr.semester)
                 OR p.structure_type = 'TRADE_TEST_LEVEL'
               )
             ORDER BY sp.id, cc.id
             LIMIT 1";
    if (!$stmt = $db->prepare($sql)) {
        throw new RuntimeException($db->error);
    }
    // Placeholder order: the academic-period join binds the requested period
    // first (used for full-year courses), then the WHERE clause binds it again.
    $stmt->bind_param('sssss', $period, $sid, $courseCode, $year, $period);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$target) {
        return null;
    }

    $studentProgrammeId = (int)$target['student_programme_id'];
    $programCode = (string)$target['program_code'];
    $intakeId = $target['intake_id'] === null ? null : (int)$target['intake_id'];
    $curriculumCourseId = (int)$target['curriculum_course_id'];
    $academicPeriodId = (int)$target['academic_period_id'];
    $academicYearId = $target['academic_year_id'] === null ? null : (int)$target['academic_year_id'];

    $offeringId = null;
    $sql = "SELECT id
              FROM course_offerings
             WHERE curriculum_course_id = ?
               AND program_code = ?
               AND academic_period_id = ?
               AND intake_id <=> ?
               AND class_group_id IS NULL
             ORDER BY id
             LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('isii', $curriculumCourseId, $programCode, $academicPeriodId, $intakeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $offeringId = (int)$row['id'];
    } else {
        $stmt = $db->prepare(
            "INSERT INTO course_offerings
                (curriculum_course_id, program_code, intake_id, academic_year_id, academic_period_id, class_group_id, status)
             VALUES (?, ?, ?, ?, ?, NULL, 'active')"
        );
        $stmt->bind_param('isiii', $curriculumCourseId, $programCode, $intakeId, $academicYearId, $academicPeriodId);
        $stmt->execute();
        $offeringId = (int)$stmt->insert_id;
        $stmt->close();
    }

    $stmt = $db->prepare(
        'INSERT INTO student_course_registrations
            (student_programme_id, course_offering_id, registration_status, registered_at)
         VALUES (?, ?, \'REGISTERED\', CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            registration_status = IF(registration_status = \'COMPLETED\', registration_status, \'REGISTERED\'),
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->bind_param('ii', $studentProgrammeId, $offeringId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare(
        'SELECT id FROM student_course_registrations WHERE student_programme_id = ? AND course_offering_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $studentProgrammeId, $offeringId);
    $stmt->execute();
    $registration = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $registration ? (int)$registration['id'] : null;
}

/** @return string[] */
function ca_component_column_labels(string $component): array
{
    $labels = [
        'A1' => ['Assignment 1'],
        'A2' => ['Assignment 2'],
        'A3' => ['Assignment 3'],
        // The canonical scheme has one weighted Practical Test per offering.
        // Test 1/Test 2 are zero-weight legacy aliases retained for old data.
        'T1' => ['Practical Test', 'Test 1'],
        'T2' => ['Practical Test', 'Test 2'],
        'Exam' => ['Final Exam'],
    ];
    return $labels[$component] ?? [];
}

function ca_resolve_normalized_mark_target(mysqli $db, string $sid, string $courseCode, string $period, string $year, string $component): ?array
{
    $componentNames = ca_component_column_labels($component);
    if ($componentNames === [] || !ca_normalized_tables_ready($db)) {
        return null;
    }

    $primaryName = $componentNames[0];
    $fallbackName = $componentNames[1] ?? $primaryName;

    $sql = "SELECT scr.id AS student_course_registration_id,
                   ac.id AS assessment_component_id,
                   ac.max_mark
              FROM student_program sp
              JOIN programs p ON p.program_code = sp.program_code
              JOIN student_course_registrations scr ON scr.student_programme_id = sp.id
              JOIN course_offerings co ON co.id = scr.course_offering_id
                                       AND co.program_code = p.program_code
              JOIN academic_periods ap ON ap.id = co.academic_period_id
              JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
              JOIN assessment_schemes sch ON sch.program_code = p.program_code
                                         AND sch.course_code = cc.course_code
                                         AND sch.status = 'active'
              JOIN assessment_components ac ON ac.assessment_scheme_id = sch.id
                                           AND ac.component_name IN (?, ?)
             WHERE sp.Sid = ?
               AND cc.course_code = ?
               AND ap.academic_year = ?
               AND scr.registration_status IN ('REGISTERED','COMPLETED','REPEATING')
               AND (
                    (p.structure_type = 'TERM_BASED' AND ap.period_type = 'term' AND ap.period_number = CAST(? AS UNSIGNED))
                 OR (p.structure_type = 'SEMESTER_BASED' AND ap.period_type = 'semester' AND ap.period_number = CAST(? AS UNSIGNED))
                 OR (p.structure_type = 'TRADE_TEST_LEVEL' AND ap.period_type = 'trade_test_level')
               )
             ORDER BY CASE ac.component_name WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END,
                      ac.display_order,
                      ac.id
             LIMIT 1";

    if (!$stmt = $db->prepare($sql)) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param(
        'sssssssss',
        $primaryName,
        $fallbackName,
        $sid,
        $courseCode,
        $year,
        $period,
        $period,
        $primaryName,
        $fallbackName
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function ca_sync_normalized_component(mysqli $db, string $sid, string $courseCode, string $period, string $year, string $component, ?float $value, string $postedBy): ?int
{
    if ($value === null || $component === 'Exam') {
        return null;
    }

    $target = ca_resolve_normalized_mark_target($db, $sid, $courseCode, $period, $year, $component);
    if ($target === null) {
        return null;
    }

    $registrationId = (int)$target['student_course_registration_id'];
    $componentId = (int)$target['assessment_component_id'];
    $maxMark = (float)$target['max_mark'];
    if ($value < 0 || $value > $maxMark) {
        throw new RuntimeException("Mark {$value} exceeds normalized component max mark {$maxMark}.");
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
    $stmt->bind_param('iidds', $registrationId, $componentId, $value, $maxMark, $postedBy);
    $stmt->execute();
    $stmt->close();

    return $registrationId;
}

function ca_normalized_ca_points(mysqli $db, int $studentCourseRegistrationId, ?float $caPercentage): ?float
{
    if ($caPercentage === null) {
        return null;
    }

    $sql = "SELECT sch.ca_weight
              FROM student_course_registrations scr
              JOIN student_program sp ON sp.id = scr.student_programme_id
              JOIN course_offerings co ON co.id = scr.course_offering_id
                                      AND co.program_code = sp.program_code
              JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
              JOIN assessment_schemes sch ON sch.program_code = sp.program_code
                                         AND sch.course_code = cc.course_code
                                         AND sch.status = 'active'
             WHERE scr.id = ?
             ORDER BY sch.id
             LIMIT 1";
    if (!$stmt = $db->prepare($sql)) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('i', $studentCourseRegistrationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $caWeight = $row ? (float)$row['ca_weight'] : 100.0;
    return round($caPercentage * ($caWeight / 100.0), 2);
}

function ca_sync_normalized_result(mysqli $db, int $studentCourseRegistrationId, ?float $totalCa): void
{
    if (!ca_table_exists($db, 'student_course_results')) {
        return;
    }

    $caPoints = ca_normalized_ca_points($db, $studentCourseRegistrationId, $totalCa);
    $status = 'INCOMPLETE';
    $sql = "INSERT INTO student_course_results
                (student_course_registration_id, ca_total, exam_mark, final_mark, grade, result_status)
            VALUES (?, ?, NULL, NULL, NULL, ?)
            ON DUPLICATE KEY UPDATE
                ca_total = VALUES(ca_total),
                result_status = IF(published_at IS NULL, VALUES(result_status), result_status),
                updated_at = CURRENT_TIMESTAMP";
    if (!$stmt = $db->prepare($sql)) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('ids', $studentCourseRegistrationId, $caPoints, $status);
    $stmt->execute();
    $stmt->close();
}

function ca_exam_component_payment_check(mysqli $db, string $sid, string $period, string $year, array $components): array
{
    $testComponents = [];
    foreach (['T1', 'T2'] as $component) {
        if (array_key_exists($component, $components) && $components[$component] !== null && $components[$component] !== '') {
            $testComponents[] = $component;
        }
    }
    if (!$testComponents) {
        return ['ok' => true, 'message' => 'OK'];
    }
    if (!function_exists('is_student_allowed_exam')) {
        return ['ok' => false, 'message' => 'Payment eligibility service is unavailable. Test marks were not saved.'];
    }
    $elig = is_student_allowed_exam($db, $sid, $year, $period);
    if (!empty($elig['allowed'])) {
        return ['ok' => true, 'message' => 'OK'];
    }

    $percent = rtrim(rtrim(number_format((float)($elig['percent'] ?? 0), 2), '0'), '.');
    return [
        'ok' => false,
        'message' => 'Test marks were not saved: student is ' . $percent . '% paid for this term and full payment is required before test marks can be entered.',
    ];
}

function ca_payment_check(mysqli $db, string $sid, string $period, string $year, array $components): array
{
    $caComponents = [];
    foreach (['A1', 'A2', 'A3'] as $component) {
        if (array_key_exists($component, $components) && $components[$component] !== null && $components[$component] !== '') {
            $caComponents[] = $component;
        }
    }

    if ($caComponents) {
        if (!function_exists('is_student_allowed_ca')) {
            return ['ok' => false, 'message' => 'Payment eligibility service is unavailable. CA marks were not saved.'];
        }
        $elig = is_student_allowed_ca($db, $sid, $year, $period);
        if (empty($elig['allowed'])) {
            $percent = rtrim(rtrim(number_format((float)($elig['percent'] ?? 0), 2), '0'), '.');
            $required = isset($elig['required_percent'])
                ? (float)$elig['required_percent']
                : (function_exists('wuc_period_required_payment_percent')
                    ? wuc_period_required_payment_percent(
                        $db,
                        $sid,
                        function_exists('wuc_payment_rule_percent')
                            ? wuc_payment_rule_percent($db, 'assessment.ca.minimum_payment_percent', 'min_ca_paid_percent', 50.0)
                            : 50.0,
                        is_numeric($period) ? (int)$period : null
                    )
                    : 50.0);
            $requiredText = rtrim(rtrim(number_format($required, 2), '0'), '.');
            return [
                'ok' => false,
                'message' => 'CA marks were not saved: student is ' . $percent . '% paid for this period and at least '
                    . $requiredText . '% payment is required before CA marks can be entered.',
            ];
        }
    }

    return ca_exam_component_payment_check($db, $sid, $period, $year, $components);
}

function ca_save_entry_guard(mysqli $db, string $sid, string $courseCode, string $period, string $year, string $programType): array
{
    $periodCheck = ca_validate_period($period, $year, $programType === 'short_course' ? 'short_course' : 'semester');
    if (!$periodCheck['ok']) {
        return $periodCheck;
    }

    if ($programType === 'short_course') {
        if (!function_exists('result_validate_short_course_entry')) {
            $resultHelpers = __DIR__ . '/result_entry_helpers.php';
            if (is_file($resultHelpers)) {
                require_once $resultHelpers;
            }
        }
        if (function_exists('result_validate_short_course_entry')) {
            $shortCourseCheck = result_validate_short_course_entry($db, $sid, $courseCode, date('Y-m-d'));
            if (empty($shortCourseCheck['ok'])) {
                return [
                    'ok' => false,
                    'message' => (string)($shortCourseCheck['message'] ?? 'CA marks were not saved because the student is not enrolled for this short course.'),
                ];
            }
        } else {
            return ['ok' => false, 'message' => 'Short-course enrolment validation is unavailable. CA marks were not saved.'];
        }
        return ['ok' => true, 'message' => 'OK'];
    }

    if (!ca_student_registered($db, $sid, $courseCode, $period, $year)) {
        return [
            'ok' => false,
            'message' => 'CA marks were not saved because the student is not registered for this course in the selected academic period.',
        ];
    }

    return ['ok' => true, 'message' => 'OK'];
}

function ca_save_component(mysqli $db, string $sid, string $courseCode, string $period, string $year, string $programType, string $component, float $value, string $postedBy): array
{
    $components = ca_empty_components();
    $exists = false;

    $guard = ca_save_entry_guard($db, $sid, $courseCode, $period, $year, $programType);
    if (!$guard['ok']) {
        return ['ok' => false, 'message' => $guard['message']];
    }

    $paymentCheck = ca_payment_check($db, $sid, $period, $year, [$component => $value]);
    if (!$paymentCheck['ok']) {
        return ['ok' => false, 'message' => $paymentCheck['message']];
    }
    $shapeCheck = ca_validate_period_component_shape($db, $sid, $courseCode, $period, [$component => $value]);
    if (!$shapeCheck['ok']) {
        return $shapeCheck;
    }

    $db->begin_transaction();
    try {
        $normalizedRegistrationId = ca_ensure_normalized_registration_bridge($db, $sid, $courseCode, $period, $year);
        if ($programType !== 'short_course' && ca_normalized_tables_ready($db) && $normalizedRegistrationId === null) {
            throw new DomainException('CA marks were not saved because the normalized course registration could not be resolved. Ask the Registrar to repair the programme/course offering.');
        }

        if ($stmt = $db->prepare("SELECT A1,A2,A3,T1,T2,Exam,status FROM semester_assessment WHERE Sid=? AND Course_Code=? AND semester=? AND Year=? LIMIT 1 FOR UPDATE")) {
            $stmt->bind_param('ssss', $sid, $courseCode, $period, $year);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                // Auto-published rows remain editable by staff; only externally
                // approved (signed-off) results are immutable.
                if (strtolower(trim((string)$row['status'])) === 'approved') {
                    throw new DomainException('Approved CA marks are locked. Return the result for correction before editing.');
                }
                foreach ($components as $key => $_) {
                    $components[$key] = $row[$key] === null ? null : (float)$row[$key];
                }
                $exists = true;
            }
            $stmt->close();
        }

        $components[$component] = $value;
        $components['Exam'] = null;
        $limitCheck = ca_validate_component_limits($db, $sid, $courseCode, $components);
        if (!$limitCheck['ok']) {
            throw new DomainException($limitCheck['message']);
        }
        $totalCa = $limitCheck['total'];
        $annualCheck = ca_validate_annual_total($db, $sid, $courseCode, $period, $year, $totalCa);
        if (!$annualCheck['ok']) {
            throw new DomainException($annualCheck['message']);
        }
        // Auto-publish: marks become Published immediately on save so students
        // see them as soon as a lecturer enters them. Never demote Approved
        // (or other advanced statuses) on lecturer re-save.
        $entryStatus = 'Published';
        $statusSql = ca_assessment_status_on_save_sql();

        if ($exists) {
            $stmt = $db->prepare("UPDATE semester_assessment SET A1=?, A2=?, A3=?, T1=?, T2=?, Exam=?, Total_CA=?, program_type=?, posted_by=?, status={$statusSql} WHERE Sid=? AND Course_Code=? AND semester=? AND Year=?");
            if (!$stmt) {
                throw new RuntimeException($db->error);
            }
            $stmt->bind_param('dddddddsssssss', $components['A1'], $components['A2'], $components['A3'], $components['T1'], $components['T2'], $components['Exam'], $totalCa, $programType, $postedBy, $entryStatus, $sid, $courseCode, $period, $year);
        } else {
            $stmt = $db->prepare("INSERT INTO semester_assessment (Sid, Course_Code, A1, A2, A3, T1, T2, Exam, Total_CA, semester, Year, program_type, posted_by, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if (!$stmt) {
                throw new RuntimeException($db->error);
            }
            $stmt->bind_param('ssdddddddsssss', $sid, $courseCode, $components['A1'], $components['A2'], $components['A3'], $components['T1'], $components['T2'], $components['Exam'], $totalCa, $period, $year, $programType, $postedBy, $entryStatus);
        }
        $stmt->execute();
        $stmt->close();

        $registrationId = ca_sync_normalized_component($db, $sid, $courseCode, $period, $year, $component, $value, $postedBy);
        if ($registrationId !== null) {
            ca_sync_normalized_result($db, $registrationId, $totalCa);
        }

        $db->commit();
        wuc_academic_risk_after_student_activity($db, $sid, 'ca_component_saved');
        return ['ok' => true, 'message' => $exists ? 'CA mark updated successfully.' : 'CA mark saved successfully.', 'total_ca' => $totalCa];
    } catch (Throwable $e) {
        $db->rollback();
        error_log('ca_save_component failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => $e instanceof DomainException ? $e->getMessage() : 'Failed to save CA mark.'];
    }
}

function ca_save_components(mysqli $db, string $sid, string $courseCode, string $period, string $year, string $programType, array $newComponents, string $postedBy): array
{
    $components = ca_empty_components();
    $providedComponents = [];
    foreach ($newComponents as $key => $value) {
        if (!array_key_exists($key, $components)) {
            return ['ok' => false, 'message' => "Unknown CA component {$key}."];
        }
        if ($key === 'Exam') {
            continue;
        }
        if ($value === null || $value === '') {
            continue;
        }
        $value = (float)$value;
        if ($value < 0 || $value > 100) {
            return ['ok' => false, 'message' => "{$key} must be between 0 and 100."];
        }
        $providedComponents[$key] = round($value, 2);
    }

    if ($providedComponents === []) {
        return ['ok' => false, 'message' => 'Enter at least one CA component mark.'];
    }

    $guard = ca_save_entry_guard($db, $sid, $courseCode, $period, $year, $programType);
    if (!$guard['ok']) {
        return ['ok' => false, 'message' => $guard['message']];
    }

    $paymentCheck = ca_payment_check($db, $sid, $period, $year, $providedComponents);
    if (!$paymentCheck['ok']) {
        return ['ok' => false, 'message' => $paymentCheck['message']];
    }
    $shapeCheck = ca_validate_period_component_shape($db, $sid, $courseCode, $period, $providedComponents);
    if (!$shapeCheck['ok']) {
        return $shapeCheck;
    }

    $db->begin_transaction();
    try {
        $normalizedRegistrationId = ca_ensure_normalized_registration_bridge($db, $sid, $courseCode, $period, $year);
        if ($programType !== 'short_course' && ca_normalized_tables_ready($db) && $normalizedRegistrationId === null) {
            throw new DomainException('CA marks were not saved because the normalized course registration could not be resolved. Ask the Registrar to repair the programme/course offering.');
        }

        $exists = false;
        if ($stmt = $db->prepare("SELECT A1,A2,A3,T1,T2,Exam,status FROM semester_assessment WHERE Sid=? AND Course_Code=? AND semester=? AND Year=? LIMIT 1 FOR UPDATE")) {
            $stmt->bind_param('ssss', $sid, $courseCode, $period, $year);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                // Auto-published rows remain editable by staff; only externally
                // approved (signed-off) results are immutable.
                if (strtolower(trim((string)$row['status'])) === 'approved') {
                    throw new DomainException('Approved CA marks are locked. Return the result for correction before editing.');
                }
                foreach ($components as $key => $_) {
                    $components[$key] = $row[$key] === null ? null : (float)$row[$key];
                }
                $exists = true;
            }
            $stmt->close();
        }

        // Blank CSV cells mean "leave unchanged" on an existing row. This
        // prevents a later component upload from erasing marks already saved.
        // Blank cells are not deletions: merge the supplied values into the
        // locked row so later CSV uploads cannot erase earlier components.
        $components = ca_merge_component_values($components, $providedComponents);

        $limitCheck = ca_validate_component_limits($db, $sid, $courseCode, $components);
        if (!$limitCheck['ok']) {
            throw new DomainException($limitCheck['message']);
        }
        $totalCa = $limitCheck['total'];

        $annualCheck = ca_validate_annual_total($db, $sid, $courseCode, $period, $year, $totalCa);
        if (!$annualCheck['ok']) {
            throw new DomainException($annualCheck['message']);
        }

        // Auto-publish: marks become Published immediately on save so students
        // see them as soon as a lecturer enters them. Never demote Approved
        // (or other advanced statuses) on lecturer re-save.
        $entryStatus = 'Published';
        $statusSql = ca_assessment_status_on_save_sql();
        if ($exists) {
            $stmt = $db->prepare("UPDATE semester_assessment SET A1=?, A2=?, A3=?, T1=?, T2=?, Exam=?, Total_CA=?, program_type=?, posted_by=?, status={$statusSql} WHERE Sid=? AND Course_Code=? AND semester=? AND Year=?");
            if (!$stmt) {
                throw new RuntimeException($db->error);
            }
            $stmt->bind_param('dddddddsssssss', $components['A1'], $components['A2'], $components['A3'], $components['T1'], $components['T2'], $components['Exam'], $totalCa, $programType, $postedBy, $entryStatus, $sid, $courseCode, $period, $year);
        } else {
            $stmt = $db->prepare("INSERT INTO semester_assessment (Sid, Course_Code, A1, A2, A3, T1, T2, Exam, Total_CA, semester, Year, program_type, posted_by, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if (!$stmt) {
                throw new RuntimeException($db->error);
            }
            $stmt->bind_param('ssdddddddsssss', $sid, $courseCode, $components['A1'], $components['A2'], $components['A3'], $components['T1'], $components['T2'], $components['Exam'], $totalCa, $period, $year, $programType, $postedBy, $entryStatus);
        }
        $stmt->execute();
        $stmt->close();

        $registrationIds = [];
        foreach ($providedComponents as $component => $mark) {
            $registrationId = ca_sync_normalized_component($db, $sid, $courseCode, $period, $year, $component, $mark, $postedBy);
            if ($registrationId !== null) {
                $registrationIds[$registrationId] = $registrationId;
            }
        }
        foreach ($registrationIds as $registrationId) {
            ca_sync_normalized_result($db, (int)$registrationId, $totalCa);
        }

        $db->commit();
        wuc_academic_risk_after_student_activity($db, $sid, 'ca_components_saved');
        return ['ok' => true, 'message' => $exists ? 'CA row updated.' : 'CA row saved.', 'total_ca' => $totalCa];
    } catch (Throwable $e) {
        $db->rollback();
        error_log('ca_save_components failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => $e instanceof DomainException ? $e->getMessage() : 'Failed to save CA row.'];
    }
}

/**
 * SQL expression for status on UPDATE (expects one bound string param = 'Published').
 * Preserves Approved/Published (and any other non-draft status); only promotes
 * blank/Pending/Draft/Rejected/Submitted → Published.
 */
function ca_assessment_status_on_save_sql(): string
{
    return "CASE
        WHEN status IN ('Published', 'Approved') THEN status
        WHEN status IS NULL OR TRIM(status) = '' OR status IN ('Pending', 'Draft', 'Rejected', 'Submitted') THEN ?
        ELSE status
    END";
}

/**
 * Publish CA marks so they appear on the student continuous-assessment report.
 * Used by moderation/admin flows and CLI workflow tests after lecturer entry.
 *
 * @return array{ok:bool,message:string,affected?:int}
 */
function ca_publish_assessment(
    mysqli $db,
    string $sid,
    string $courseCode,
    string $period,
    string $year,
    string $publishedBy = 'system'
): array {
    if ($sid === '' || $courseCode === '' || $period === '' || $year === '') {
        return ['ok' => false, 'message' => 'Student, course, period and year are required to publish CA.'];
    }
    if (!ca_table_exists($db, 'semester_assessment')) {
        return ['ok' => false, 'message' => 'Assessment table is unavailable.'];
    }

    $sql = "UPDATE semester_assessment
               SET status = 'Published',
                   published_by = ?,
                   published_at = NOW()
             WHERE Sid = ?
               AND Course_Code = ?
               AND semester = ?
               AND Year = ?";
    if (!$stmt = $db->prepare($sql)) {
        return ['ok' => false, 'message' => 'Unable to prepare CA publish statement.'];
    }
    $stmt->bind_param('sssss', $publishedBy, $sid, $courseCode, $period, $year);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        return ['ok' => false, 'message' => 'Failed to publish CA: ' . $err];
    }
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected < 1) {
        return ['ok' => false, 'message' => 'No CA row found to publish for this student/course/period.'];
    }

    // Keep workflow status and weighted result points aligned with the
    // normalized store whenever this compatibility helper publishes a row.
    if (!function_exists('wuc_result_sync_normalized')) {
        require_once __DIR__ . '/grading_helpers.php';
    }
    if (function_exists('wuc_result_sync_normalized')) {
        try {
            wuc_result_sync_normalized($db, $sid, $courseCode, $period, $year, $publishedBy);
        } catch (Throwable $e) {
            error_log('CA publish normalized sync failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'CA was published, but the normalized result could not be synchronized. Run the CA integrity migration.'];
        }
    }
    return ['ok' => true, 'message' => 'CA marks published for student view.', 'affected' => $affected];
}
