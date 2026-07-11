<?php
/**
 * Programme-specific assessment weighting rules.
 *
 * Transport and Logistics uses raw CA and raw final exam marks with:
 * - CA: 40%
 * - Final exam: 60%
 *
 * Other programmes keep the legacy stored-result behaviour unless they are
 * explicitly configured elsewhere.
 */

if (!function_exists('assessment_weighting_table_exists')) {
    function assessment_weighting_table_exists(mysqli $db, string $table): bool
    {
        if ($res = @$db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")) {
            $ok = $res->num_rows > 0;
            $res->free();
            return $ok;
        }
        return false;
    }
}

if (!function_exists('assessment_weighting_column_exists')) {
    function assessment_weighting_column_exists(mysqli $db, string $table, string $column): bool
    {
        if (!assessment_weighting_table_exists($db, $table)) {
            return false;
        }
        if ($res = @$db->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $db->real_escape_string($column) . "'")) {
            $ok = $res->num_rows > 0;
            $res->free();
            return $ok;
        }
        return false;
    }
}

if (!function_exists('assessment_weighting_normalize_text')) {
    function assessment_weighting_normalize_text(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['&', '/', '-', '_'], ' ', $value);
        return preg_replace('/\s+/', ' ', $value) ?: '';
    }
}

if (!function_exists('assessment_weighting_is_transport_logistics')) {
    function assessment_weighting_is_transport_logistics(string $programCode, string $programName): bool
    {
        $code = assessment_weighting_normalize_text($programCode);
        $name = assessment_weighting_normalize_text($programName);
        $combined = trim($code . ' ' . $name);

        if (preg_match('/\b(tl|tlog|translog|transport logistics|transport and logistics)\b/', $combined)) {
            return true;
        }

        return strpos($combined, 'transport') !== false && strpos($combined, 'logistic') !== false;
    }
}

if (!function_exists('assessment_weighting_student_program')) {
    function assessment_weighting_student_program(mysqli $db, string $sid): array
    {
        if ($sid === '' || !assessment_weighting_table_exists($db, 'student_program')) {
            return ['program_code' => '', 'program_name' => ''];
        }

        $spSidCol = assessment_weighting_column_exists($db, 'student_program', 'Sid') ? 'Sid'
            : (assessment_weighting_column_exists($db, 'student_program', 'SID') ? 'SID'
            : (assessment_weighting_column_exists($db, 'student_program', 'student_id') ? 'student_id' : ''));
        if ($spSidCol === '' || !assessment_weighting_column_exists($db, 'student_program', 'program_code')) {
            return ['program_code' => '', 'program_name' => ''];
        }

        $programJoin = assessment_weighting_table_exists($db, 'programs') ? "LEFT JOIN programs p ON p.program_code COLLATE utf8mb4_general_ci = sp.program_code COLLATE utf8mb4_general_ci" : '';
        $programNameSelect = $programJoin !== '' && assessment_weighting_column_exists($db, 'programs', 'program_name') ? 'COALESCE(p.program_name, \'\')' : "''";
        $statusFilter = assessment_weighting_column_exists($db, 'student_program', 'status') ? " AND COALESCE(sp.status, 'active') NOT IN ('inactive','withdrawn','suspended')" : '';

        $sql = "SELECT sp.program_code, {$programNameSelect} AS program_name
                FROM student_program sp
                {$programJoin}
                WHERE sp.`{$spSidCol}` COLLATE utf8mb4_general_ci = ?{$statusFilter}
                ORDER BY sp.program_code
                LIMIT 1";
        if (!$stmt = @$db->prepare($sql)) {
            return ['program_code' => '', 'program_name' => ''];
        }
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return [
            'program_code' => trim((string)($row['program_code'] ?? '')),
            'program_name' => trim((string)($row['program_name'] ?? '')),
        ];
    }
}

if (!function_exists('assessment_weighting_policy_for_student')) {
    function assessment_weighting_policy_for_student(mysqli $db, string $sid): array
    {
        $program = assessment_weighting_student_program($db, $sid);
        $code = assessment_weighting_normalize_text($program['program_code']);
        $name = assessment_weighting_normalize_text($program['program_name']);
        
        if (assessment_weighting_is_transport_logistics($program['program_code'], $program['program_name'])) {
            return [
                'is_weighted' => true,
                'ca_weight' => 40,
                'exam_weight' => 60,
                'program_code' => $program['program_code'],
                'program_name' => $program['program_name'],
                'label' => 'Transport and Logistics: CA 40%, Final Exam 60%',
            ];
        }

        $combined = trim($code . ' ' . $name);
        
        if (preg_match('/\b(short|shortcourse)\b/', $combined)) {
            return [
                'is_weighted' => true,
                'ca_weight' => 50,
                'exam_weight' => 50,
                'program_code' => $program['program_code'],
                'program_name' => $program['program_name'],
                'label' => 'Short Course (Internal): CA 50%, Final Exam 50%',
            ];
        }
        
        if (preg_match('/\b(diploma|certificate|cert|trade|trade test|teveta)\b/', $combined)) {
            return [
                'is_weighted' => true,
                'ca_weight' => 40,
                'exam_weight' => 60,
                'program_code' => $program['program_code'],
                'program_name' => $program['program_name'],
                'label' => 'TEVETA-aligned (Diploma/Certificate/Trade): CA 40%, Final Exam 60%',
            ];
        }

        return [
            'is_weighted' => false,
            'ca_weight' => 100,
            'exam_weight' => 100,
            'program_code' => $program['program_code'],
            'program_name' => $program['program_name'],
            'label' => 'Legacy stored marks',
        ];
    }
}

if (!function_exists('assessment_weighting_total')) {
    function assessment_weighting_total(mysqli $db, string $sid, $caMark, $examMark): float
    {
        $ca = is_numeric($caMark) ? (float)$caMark : 0.0;
        $exam = is_numeric($examMark) ? (float)$examMark : 0.0;
        $policy = assessment_weighting_policy_for_student($db, $sid);

        if (!empty($policy['is_weighted'])) {
            return round(($ca * ((float)$policy['ca_weight'] / 100)) + ($exam * ((float)$policy['exam_weight'] / 100)), 2);
        }

        return round($ca + $exam, 2);
    }
}
