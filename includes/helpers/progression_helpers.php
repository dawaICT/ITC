<?php
declare(strict_types=1);

require_once __DIR__ . '/academic_structure_helpers.php';

/** The registration ledger stores the starting calendar year, not a year range. */
function wuc_progression_next_academic_year(string $academicYear): string
{
    if (!preg_match('/^(\d{4})(?:\s*[\/-]\s*(\d{4}))?$/', trim($academicYear), $match)) {
        return '';
    }
    $year = (int)$match[1];
    if ($year < 1000 || $year >= 9999 || (isset($match[2]) && (int)$match[2] !== $year + 1)) {
        return '';
    }
    return (string)($year + 1);
}

/** Annual progression is only defined for term and semester programmes. */
function wuc_progression_period_type(mysqli $db, string $programCode): string
{
    $structure = wuc_program_structure_type($db, $programCode);
    return match ($structure) {
        'TERM_BASED' => 'term',
        'SEMESTER_BASED' => 'semester',
        default => throw new RuntimeException('Annual progression requires a term-based or semester-based programme.'),
    };
}

/** Reuse matching registrations without resetting completed registration or fee checks. */
function wuc_progression_prepare_registration(mysqli $db, string $sid, string $programCode, string $periodType, int $year, string $academicYear): void
{
    $stmt = $db->prepare(
        "SELECT year_of_study FROM semester_registration
          WHERE (student_id = ? OR SID = ?) AND program_code = ?
            AND period_type = ? AND semester = '1' AND academic_year = ? FOR UPDATE"
    );
    $stmt->bind_param('sssss', $sid, $sid, $programCode, $periodType, $academicYear);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($rows !== []) {
        foreach ($rows as $row) {
            if ((int)$row['year_of_study'] !== $year) {
                throw new RuntimeException('The target academic period already has a different year of study. Contact the registrar.');
            }
        }
        return;
    }
    $stmt = $db->prepare(
        "INSERT INTO semester_registration
            (student_id, SID, program_code, semester, period_type,
             year_of_study, Year, academic_year, registration_status, fee_status)
         VALUES (?, ?, ?, '1', ?, ?, ?, ?, 'pending', 'unknown')"
    );
    $stmt->bind_param('ssssiis', $sid, $sid, $programCode, $periodType, $year, $year, $academicYear);
    $stmt->execute();
    $stmt->close();
}
