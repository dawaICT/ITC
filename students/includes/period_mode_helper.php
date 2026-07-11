<?php
/**
 * Period Mode Helper Functions
 * 
 * Provides functions to determine if a student's program is term-based or semester-based.
 * Include this file in student pages that need to display "Term" vs "Semester" labels.
 */

require_once __DIR__ . '/../../includes/helpers/academic_structure_helpers.php';

if (!function_exists('wuc_period_table_exists')) {
    function wuc_period_table_exists(mysqli $db, string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $safe = $db->real_escape_string($table);
        $result = @$db->query("SHOW TABLES LIKE '{$safe}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $cache[$table] = $exists;
    }
}

if (!function_exists('wuc_period_columns')) {
    function wuc_period_columns(mysqli $db, string $table): array {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $columns = [];
        if (!wuc_period_table_exists($db, $table)) {
            return $cache[$table] = $columns;
        }

        if ($result = @$db->query("SHOW COLUMNS FROM `{$table}`")) {
            while ($row = $result->fetch_assoc()) {
                $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
            }
            $result->free();
        }
        return $cache[$table] = $columns;
    }
}

if (!function_exists('normalizeProgramPeriodMode')) {
    function normalizeProgramPeriodMode($value): string {
        $mode = strtolower(trim((string)$value));
        if ($mode === '') {
            return 'semester';
        }
        if (in_array($mode, ['short', 'short course', 'short_course', 'short-course'], true)) {
            return 'short_course';
        }
        if (in_array($mode, ['intake', 'intake based', 'intake-based', 'intake_based'], true)) {
            return 'intake';
        }
        if (preg_match('/\b(day|days|week|weeks|month|months|3-month|10-day)\b/', $mode)) {
            return 'duration';
        }
        if (in_array($mode, ['term', 'terms', 'termly', 'term-based', 'term based'], true)) {
            return 'term';
        }
        if (in_array($mode, ['semester', 'semesters', 'semesterly', 'semester-based', 'semester based'], true)) {
            return 'semester';
        }
        if (strpos($mode, 'short') !== false) {
            return 'short_course';
        }
        if (strpos($mode, 'intake') !== false) {
            return 'intake';
        }
        if (strpos($mode, 'term') !== false) {
            return 'term';
        }
        if (strpos($mode, 'semester') !== false) {
            return 'semester';
        }
        if (in_array($mode, ['trade_test_level', 'trade test level', 'trade-test-level', 'level'], true)) {
            return 'trade_test_level';
        }
        if (strpos($mode, 'trade') !== false || strpos($mode, 'level') !== false) {
            return 'trade_test_level';
        }
        return 'semester';
    }
}

if (!function_exists('wuc_legacy_period_type')) {
    function wuc_legacy_period_type(string $structure): string {
        $structure = strtoupper(trim($structure));
        if ($structure === 'TERM_BASED' || strtolower($structure) === 'term') {
            return 'term';
        }
        if ($structure === 'SEMESTER_BASED' || strtolower($structure) === 'semester') {
            return 'semester';
        }
        if ($structure === 'TRADE_TEST_LEVEL' || strtolower($structure) === 'trade_test_level') {
            return 'trade_test_level';
        }
        if ($structure === 'SHORT_COURSE' || in_array(strtolower($structure), ['short_course', 'short_course_cycle', 'cycle'], true)) {
            return 'short_course_cycle';
        }
        return 'semester';
    }
}

if (!function_exists('wuc_period_label_from_structure')) {
    function wuc_period_label_from_structure(string $structure, bool $capitalize = true): string {
        $labels = [
            'semester' => 'semester',
            'term' => 'term',
            'short_course' => 'short course',
            'short_course_cycle' => 'short course cycle',
            'trade_test_level' => 'trade test level',
            'intake' => 'intake',
            'duration' => 'period',
        ];
        $label = $labels[$structure] ?? 'period';
        return $capitalize ? ucwords($label) : $label;
    }
}

if (!function_exists('wuc_period_short_label_from_structure')) {
    function wuc_period_short_label_from_structure(string $structure): string {
        if ($structure === 'semester') {
            return 'Sem';
        }
        if ($structure === 'term') {
            return 'Term';
        }
        if ($structure === 'intake') {
            return 'Intake';
        }
        if ($structure === 'trade_test_level') {
            return 'Level';
        }
        return 'Period';
    }
}

if (!function_exists('getProgramPeriodMode')) {
    function getProgramPeriodMode(mysqli $db, string $programCode): string {
        if ($programCode === '' || !wuc_period_table_exists($db, 'programs')) {
            return 'semester';
        }

        if (function_exists('wuc_program_structure_type')) {
            $structure = wuc_program_structure_type($db, $programCode);
            if ($structure !== '') {
                return wuc_legacy_period_type($structure);
            }
        }

        $columns = wuc_period_columns($db, 'programs');
        $selects = [];
        foreach (['academic_structure', 'period_mode', 'period_type', 'study_mode', 'program_type', 'term_based'] as $candidate) {
            if (isset($columns[$candidate])) {
                $selects[] = "`{$columns[$candidate]}` AS {$candidate}";
            }
        }
        if (!$selects) {
            return 'semester';
        }

        $sql = 'SELECT ' . implode(', ', $selects) . ' FROM programs WHERE program_code = ? LIMIT 1';
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $programCode);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                return 'semester';
            }

            foreach (['academic_structure', 'period_mode', 'period_type', 'study_mode'] as $field) {
                if (!empty($row[$field])) {
                    $mode = normalizeProgramPeriodMode($row[$field]);
                    if (in_array($mode, ['term', 'semester', 'short_course', 'intake', 'duration'], true)) {
                        return $mode;
                    }
                    if ($row[$field] === 'semester_exception') {
                        return 'semester'; // exception maps to semester rules
                    }
                }
            }
            if (!empty($row['term_based'])) {
                return 'term';
            }
            if (array_key_exists('program_type', $row)) {
                return normalizeProgramPeriodMode($row['program_type']);
            }
        }

        return 'semester';
    }
}

if (!function_exists('getStudentProgramPeriodMode')) {
    /**
     * Get the period mode for a student's assigned program
     * 
     * @param mysqli $db Database connection
     * @param string $studentId Student ID
     * @return string Academic structure: semester, term, short_course, intake, or duration.
     */
    function getStudentProgramPeriodMode(mysqli $db, string $studentId): string {
        if (empty($studentId)) {
            return 'semester';
        }

        if (!wuc_period_table_exists($db, 'student_program')) {
            return 'semester';
        }

        $spColumns = wuc_period_columns($db, 'student_program');
        $sidCol = $spColumns['sid'] ?? ($spColumns['student_id'] ?? ($spColumns['student'] ?? null));
        $programCol = $spColumns['program_code'] ?? null;
        if (!$sidCol || !$programCol) {
            return 'semester';
        }

        $statusCol = $spColumns['status'] ?? null;
        $idCol = $spColumns['id'] ?? null;
        $hasProgramCourses = wuc_period_table_exists($db, 'program_courses');
        $pcColumns = $hasProgramCourses ? wuc_period_columns($db, 'program_courses') : [];
        $pcCourseCol = $pcColumns['course_code'] ?? null;

        // Resolve the same program context used for course registration:
        // active assignments with curriculum mappings win over a newer empty
        // assignment, so term/semester labels follow the real program.
        $mappedSelect = ($hasProgramCourses && $pcCourseCol) ? "COUNT(pc.`{$pcCourseCol}`)" : '0';
        $join = ($hasProgramCourses && $pcCourseCol)
            ? " LEFT JOIN program_courses pc ON pc.program_code = sp.`{$programCol}`"
            : '';
        $query = "SELECT sp.`{$programCol}` AS program_code, {$mappedSelect} AS mapped_courses
                  FROM student_program sp{$join}
                  WHERE sp.`{$sidCol}` = ?";
        if ($statusCol) {
            $query .= " AND (sp.`{$statusCol}` IS NULL OR sp.`{$statusCol}` = '' OR LOWER(sp.`{$statusCol}`) = 'active')";
        }
        $query .= " GROUP BY sp.`{$programCol}`";
        if ($idCol) {
            $query .= ", sp.`{$idCol}`";
        }
        $query .= " ORDER BY mapped_courses DESC";
        if ($idCol) {
            $query .= ", sp.`{$idCol}` DESC";
        }
        $query .= " LIMIT 1";
        if ($stmt = $db->prepare($query)) {
            $stmt->bind_param("s", $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['program_code'])) {
                return getProgramPeriodMode($db, (string)$row['program_code']);
            }
        }

        return 'semester';
    }
}

if (!function_exists('isTermBasedProgram')) {
    /**
     * Check if a student's program is term-based
     * 
     * @param mysqli $db Database connection
     * @param string $studentId Student ID
     * @return bool True if term-based, false if semester-based
     */
    function isTermBasedProgram(mysqli $db, string $studentId): bool {
        return getStudentProgramPeriodMode($db, $studentId) === 'term';
    }
}

if (!function_exists('isPeriodRegistrationProgram')) {
    function isPeriodRegistrationProgram(mysqli $db, string $studentId): bool {
        return in_array(getStudentProgramPeriodMode($db, $studentId), ['semester', 'term', 'intake', 'duration'], true);
    }
}

if (!function_exists('getPeriodLabel')) {
    /**
     * Get the appropriate period label based on program type
     * 
     * @param mysqli $db Database connection
     * @param string $studentId Student ID
     * @param bool $capitalize Whether to capitalize the label
     * @return string 'Term' or 'Semester' (or lowercase if capitalize=false)
     */
    function getPeriodLabel(mysqli $db, string $studentId, bool $capitalize = true): string {
        return wuc_period_label_from_structure(getStudentProgramPeriodMode($db, $studentId), $capitalize);
    }
}

if (!function_exists('getPeriodLabelShort')) {
    /**
     * Get the short period label for compact displays
     * 
     * @param mysqli $db Database connection
     * @param string $studentId Student ID
     * @return string 'Term' or 'Sem'
     */
    function getPeriodLabelShort(mysqli $db, string $studentId): string {
        return wuc_period_short_label_from_structure(getStudentProgramPeriodMode($db, $studentId));
    }
}

if (!function_exists('getPeriodTypeBadge')) {
    /**
     * Get an HTML badge indicating the program's period type
     * 
     * @param mysqli $db Database connection
     * @param string $studentId Student ID
     * @return string HTML badge markup
     */
    function getPeriodTypeBadge(mysqli $db, string $studentId): string {
        $text = htmlspecialchars(getPeriodTypeBadgeText($db, $studentId), ENT_QUOTES, 'UTF-8');
        return '<span style="background:#6f42c1;color:white;padding:2px 8px;border-radius:12px;font-size:0.75rem;">' . $text . '</span>';
    }
}

if (!function_exists('getPeriodTypeBadgeText')) {
    /**
     * Get plain text indicating the program's period type (for use in banners/headers)
     * 
     * @param mysqli $db Database connection
     * @param string $studentId Student ID
     * @return string Text like "Term-Based" or "Semester-Based"
     */
    function getPeriodTypeBadgeText(mysqli $db, string $studentId): string {
        $structure = getStudentProgramPeriodMode($db, $studentId);
        if ($structure === 'semester') {
            return 'Semester-Based';
        }
        if ($structure === 'term') {
            return 'Term-Based';
        }
        if ($structure === 'short_course') {
            return 'Short Course';
        }
        if ($structure === 'intake') {
            return 'Intake-Based';
        }
        if ($structure === 'trade_test_level') {
            return 'Trade-Test-Level';
        }
        if ($structure === 'short_course_cycle') {
            return 'Short-Course-Cycle';
        }
        return 'Duration-Based';
    }
}
?>
