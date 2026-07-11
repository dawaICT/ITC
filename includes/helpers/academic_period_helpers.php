<?php
declare(strict_types=1);
/**
 * Academic period helpers — single source of truth for term vs semester logic.
 *
 * Wraps academic_structure_helpers + period_mode_helper so portal modules can
 * resolve programme period type without reading study_mode (Full Time/Part Time).
 */

require_once __DIR__ . '/academic_structure_helpers.php';
require_once __DIR__ . '/course_availability_helpers.php';
require_once dirname(__DIR__, 2) . '/students/includes/period_mode_helper.php';

if (!function_exists('getProgramAcademicStructure')) {
    /**
     * Resolve a programme's academic structure for UI and validation.
     *
     * @return array{
     *   structure_type:string,
     *   period_kind:string,
     *   period_mode:string,
     *   period_label:string,
     *   period_short_label:string,
     *   max_periods:int,
     *   valid_periods:int[]
     * }
     */
    function getProgramAcademicStructure(mysqli $db, string $program_code): array
    {
        $program_code = trim($program_code);
        $structureType = $program_code !== '' ? wuc_program_structure_type($db, $program_code) : '';
        $periodKind = wuc_structure_period_kind($structureType);
        $periodMode = $program_code !== '' ? getProgramPeriodMode($db, $program_code) : 'semester';
        $valid = wuc_structure_valid_period_numbers($structureType);
        $maxPeriods = $valid !== [] ? max($valid) : ($periodMode === 'term' ? 3 : 2);

        return [
            'structure_type' => $structureType,
            'period_kind' => $periodKind,
            'period_mode' => $periodMode,
            'period_label' => wuc_period_label_from_structure($periodMode, true),
            'period_short_label' => wuc_period_short_label_from_structure($periodMode),
            'max_periods' => $maxPeriods,
            'valid_periods' => $valid !== [] ? $valid : ($periodMode === 'term' ? [1, 2, 3] : [1, 2]),
        ];
    }
}

if (!function_exists('getValidAcademicPeriods')) {
    /** @return int[] */
    function getValidAcademicPeriods(mysqli $db, string $program_code): array
    {
        return getProgramAcademicStructure($db, $program_code)['valid_periods'];
    }
}

if (!function_exists('validateCoursePeriodAssignment')) {
    /**
     * Validate year + period for a programme before saving program_courses rows.
     *
     * @return array{ok:bool,message:string,period_label:string,max_periods:int}
     */
    function validateCoursePeriodAssignment(mysqli $db, string $program_code, int $year, int $period): array
    {
        $meta = getProgramAcademicStructure($db, $program_code);
        if ($meta['structure_type'] === '') {
            return [
                'ok' => false,
                'message' => 'Unknown or inactive programme.',
                'period_label' => $meta['period_label'],
                'max_periods' => $meta['max_periods'],
            ];
        }
        $maxYear = function_exists('wuc_program_max_curriculum_year')
            ? wuc_program_max_curriculum_year($db, $program_code)
            : 10;
        if ($year < 1 || $year > $maxYear) {
            return [
                'ok' => false,
                'message' => "Year of study must be between 1 and {$maxYear} for this programme (its duration / curriculum span).",
                'period_label' => $meta['period_label'],
                'max_periods' => $meta['max_periods'],
            ];
        }
        if ($meta['period_kind'] === 'cycle') {
            return ['ok' => true, 'message' => '', 'period_label' => $meta['period_label'], 'max_periods' => $meta['max_periods']];
        }
        if (!wuc_structure_validate_period($meta['structure_type'], $period)) {
            $allowed = implode(', ', $meta['valid_periods']);
            return [
                'ok' => false,
                'message' => "{$meta['period_label']} must be one of: {$allowed} for this programme.",
                'period_label' => $meta['period_label'],
                'max_periods' => $meta['max_periods'],
            ];
        }
        return ['ok' => true, 'message' => '', 'period_label' => $meta['period_label'], 'max_periods' => $meta['max_periods']];
    }
}

if (!function_exists('getCoursesForProgramYearOfStudy')) {
    /**
     * All programme courses for a year of study (academic-year ownership).
     * Term/semester are not applied — use getCoursesForProgramYear() when a
     * delivery-period filter is needed.
     *
     * @return array<int,array<string,mixed>>
     */
    function getCoursesForProgramYearOfStudy(mysqli $db, string $program_code, int $year): array
    {
        return getCoursesForProgramYear($db, $program_code, $year, null);
    }
}

if (!function_exists('getCoursesForProgramYear')) {
    /**
     * Courses mapped to a programme for a year of study.
     *
     * When $period is null, returns the full academic-year catalogue.
     * When $period is set, period-specific rows are included only for that
     * delivery period; full-year rows remain visible in every period.
     *
     * @return array<int,array<string,mixed>>
     */
    function getCoursesForProgramYear(mysqli $db, string $program_code, int $year, ?int $period = null): array
    {
        $program_code = trim($program_code);
        if ($program_code === '' || $year < 1) {
            return [];
        }

        if ($period !== null && $period > 0) {
            $check = validateCoursePeriodAssignment($db, $program_code, $year, $period);
            if (!$check['ok']) {
                return [];
            }
        } else {
            $meta = getProgramAcademicStructure($db, $program_code);
            if ($meta['structure_type'] === '') {
                return [];
            }
            $maxYear = function_exists('wuc_program_max_curriculum_year')
                ? wuc_program_max_curriculum_year($db, $program_code)
                : 10;
            if ($year < 1 || $year > $maxYear) {
                return [];
            }
        }

        // Prefer canonical curriculum when populated.
        if (function_exists('wuc_active_curriculum_version_id')) {
            $versionId = wuc_active_curriculum_version_id($db, $program_code);
            if ($versionId !== null) {
                $structure = wuc_program_structure_type($db, $program_code);
                $kind = wuc_structure_period_kind($structure);
                $where = ['cc.curriculum_version_id = ?', 'cc.year_number = ?'];
                $types = 'ii';
                $params = [$versionId, $year];
                $ccCols = wuc_course_availability_columns($db, 'curriculum_courses');
                $periodColumn = $kind === 'term'
                    ? ($ccCols['term_number'] ?? null)
                    : ($kind === 'semester' ? ($ccCols['semester_number'] ?? null) : null);
                if ($period !== null && $period > 0) {
                    $periodFilter = wuc_course_availability_period_filter($ccCols, 'cc', $periodColumn, $period);
                    if ($periodFilter['sql'] !== '1=1') {
                        $where[] = $periodFilter['sql'];
                        $types .= $periodFilter['types'];
                        $params = array_merge($params, $periodFilter['params']);
                    } elseif ($kind === 'level') {
                        $where[] = 'cc.level_number = ?';
                        $types .= 'i';
                        $params[] = $period;
                    }
                }
                $sql = "SELECT cc.course_code, c.course_name, cc.year_number, cc.term_number,
                               cc.semester_number, cc.level_number, cc.is_core, cc.credit_value
                          FROM curriculum_courses cc
                          LEFT JOIN courses c ON c.course_code = cc.course_code
                         WHERE " . implode(' AND ', $where) . "
                         ORDER BY cc.display_order, c.course_name";
                $stmt = $db->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    if ($rows !== []) {
                        return $rows;
                    }
                }
            }
        }

        // Legacy program_courses fallback. Without explicit specificity flags,
        // `semester` is a delivery/reporting hint, not an availability boundary.
        $pcCols = wuc_course_availability_columns($db, 'program_courses');
        $where = ['pc.program_code = ?', 'pc.year = ?'];
        $types = 'si';
        $params = [$program_code, $year];
        if ($period !== null && $period > 0) {
            $periodFilter = wuc_course_availability_period_filter($pcCols, 'pc', $pcCols['semester'] ?? null, $period);
            if ($periodFilter['sql'] !== '1=1') {
                $where[] = $periodFilter['sql'];
                $types .= $periodFilter['types'];
                $params = array_merge($params, $periodFilter['params']);
            }
        }
        $sql = "SELECT pc.course_code, c.course_name, pc.year AS year_number, pc.semester AS period_number,
                       pc.is_required, c.credits AS credit_value
                  FROM program_courses pc
                  LEFT JOIN courses c ON c.course_code = pc.course_code
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY pc.course_code";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('wuc_program_period_mode_sql')) {
    /**
     * SQL expression returning 'term' or 'semester' from a programs alias.
     * Uses structure_type / period_mode — never study_mode (attendance mode).
     */
    function wuc_program_period_mode_sql(string $programsAlias = 'p'): string
    {
        $p = preg_replace('/[^a-zA-Z0-9_]/', '', $programsAlias) ?: 'p';
        return "CASE
            WHEN {$p}.structure_type = 'TERM_BASED' THEN 'term'
            WHEN {$p}.structure_type = 'SEMESTER_BASED' THEN 'semester'
            WHEN {$p}.period_mode = 'term' THEN 'term'
            WHEN {$p}.period_mode = 'semester' THEN 'semester'
            ELSE 'semester'
        END";
    }
}
