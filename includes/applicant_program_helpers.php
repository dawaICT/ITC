<?php
declare(strict_types=1);

/**
 * Resolve an online_applicants.program value (code or catalogue name) to a
 * canonical program_code + program_name from the programs table.
 *
 * @return array{code: string, name: string, valid: bool}
 */
if (!function_exists('wuc_resolve_applicant_program')) {
    function wuc_resolve_applicant_program(mysqli $db, string $programField): array
    {
        $raw = trim($programField);
        if ($raw === '') {
            return ['code' => '', 'name' => '', 'valid' => false];
        }

        if ($stmt = $db->prepare('SELECT program_code, program_name FROM programs WHERE program_code = ? LIMIT 1')) {
            $stmt->bind_param('s', $raw);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return [
                    'code' => (string)$row['program_code'],
                    'name' => (string)$row['program_name'],
                    'valid' => true,
                ];
            }
        }

        if ($stmt = $db->prepare('SELECT program_code, program_name FROM programs WHERE program_name = ? LIMIT 1')) {
            $stmt->bind_param('s', $raw);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return [
                    'code' => (string)$row['program_code'],
                    'name' => (string)$row['program_name'],
                    'valid' => true,
                ];
            }
        }

        return ['code' => $raw, 'name' => '', 'valid' => false];
    }
}

if (!function_exists('wuc_processed_applicant_program_join_sql')) {
    /** SQL fragments for resolving program on processed_applicants rows (alias `pa`). */
    function wuc_processed_applicant_program_join_sql(): array
    {
        return [
            'join' => 'LEFT JOIN programs p1 ON p1.program_code = pa.program_code
                       LEFT JOIN programs p2 ON p2.program_code = pa.program
                       LEFT JOIN programs p3 ON p3.program_name = pa.program',
            'select' => 'COALESCE(p1.program_name, p2.program_name, p3.program_name) AS program_name,
                         COALESCE(p1.program_code, p2.program_code, pa.program_code, pa.program) AS program_code_resolved',
        ];
    }
}

if (!function_exists('wuc_applicant_program_join_sql')) {
    /** SQL fragments for resolving program on applicant rows (alias `oa`). */
    function wuc_applicant_program_join_sql(): array
    {
        return [
            'join' => 'LEFT JOIN programs p_code ON p_code.program_code = oa.program
                       LEFT JOIN programs p_name ON p_name.program_name = oa.program',
            'select' => 'COALESCE(p_code.program_name, p_name.program_name) AS program_name,
                         COALESCE(p_code.program_code, p_name.program_code, oa.program) AS program_code',
        ];
    }
}
