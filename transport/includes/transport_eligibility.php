<?php
/**
 * Transport admission-eligibility engine (spec §7 / BR003–BR007).
 *
 * Screens an applicant against a transport program's entry requirements BEFORE
 * they can be enrolled into a cohort. Pure functions taking a `mysqli $db`; no
 * output, no session reads — the caller owns the gate decision and any override.
 *
 * Requirement columns live on `transport_programs`:
 *   minimum_age, required_licence_class (comma list of accepted ENTRY classes),
 *   requires_nrc, requires_grade_12, requires_driver_licence,
 *   requires_medical_certificate.
 *
 * Applicant facts come from the linked `students` row (dob -> age, nrc_pass ->
 * NRC) plus the enrol form (existing_license_class, medical_clearance_status).
 *
 * te_check_eligibility() splits findings into:
 *   - reasons  : HARD failures (reliable data) -> block enrolment
 *   - warnings : SOFT items (no reliable data source, e.g. Grade 12) -> flag only
 */

/** Whole years between $dob and today, or null when the date is unusable. */
function te_calculate_age(?string $dob): ?int
{
    $dob = trim((string)$dob);
    if ($dob === '' || $dob === '0000-00-00') {
        return null;
    }
    $ts = strtotime($dob);
    if ($ts === false) {
        return null;
    }
    try {
        $birth = new DateTimeImmutable('@' . $ts);
        $now   = new DateTimeImmutable('now');
        $age   = (int)$now->diff($birth)->y;
        // Guard against nonsense (future DOB / typo) -> treat as unknown.
        if ($age < 0 || $age > 120) {
            return null;
        }
        return $age;
    } catch (Throwable $e) {
        return null;
    }
}

/** Load a program's entry-requirement profile, or null if the program is gone. */
function te_program_requirements(mysqli $db, int $programId): ?array
{
    $stmt = $db->prepare(
        "SELECT id, program_code, program_name, minimum_age, required_licence_class,
                requires_nrc, requires_grade_12, requires_driver_licence,
                requires_medical_certificate
         FROM transport_programs WHERE id = ? LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Normalise an applicant into the shape te_check_eligibility() expects.
 * $studentRecord is a `students` row (Fname/Lname/dob/nrc_pass ...).
 * $form is the enrol POST (existing_license_class, medical_clearance_status).
 */
function te_applicant_from_student(array $studentRecord, array $form): array
{
    $licence = trim((string)($form['existing_license_class'] ?? ''));
    $medical = (string)($form['medical_clearance_status'] ?? 'pending');
    if (!in_array($medical, ['pending', 'cleared', 'not_required', 'failed'], true)) {
        $medical = 'pending';
    }
    return [
        'age'                => te_calculate_age($studentRecord['dob'] ?? null),
        'dob_present'        => trim((string)($studentRecord['dob'] ?? '')) !== ''
                                && (string)($studentRecord['dob'] ?? '') !== '0000-00-00',
        'nrc'               => trim((string)($studentRecord['nrc_pass'] ?? '')),
        'licence_class'      => $licence,
        'has_driver_licence' => $licence !== '',
        'medical'            => $medical,
    ];
}

/** Split a comma/slash/space separated licence-class list into a clean array. */
function te_parse_licence_classes(?string $list): array
{
    $parts = preg_split('/[,\/;\s]+/', strtoupper(trim((string)$list)), -1, PREG_SPLIT_NO_EMPTY);
    return $parts ? array_values(array_unique($parts)) : [];
}

/**
 * Core §7 algorithm.
 * @return array{eligible:bool, hard_fail:bool, status:string, reasons:string[], warnings:string[]}
 */
function te_check_eligibility(array $applicant, array $program): array
{
    $reasons  = [];
    $warnings = [];

    // 3. Minimum age (BR003). DOB must be present and meet the floor.
    $minAge = $program['minimum_age'] ?? null;
    if ($minAge !== null && $minAge !== '' && (int)$minAge > 0) {
        $minAge = (int)$minAge;
        if (empty($applicant['dob_present']) || $applicant['age'] === null) {
            $reasons[] = "Date of birth is missing — cannot confirm the minimum age of {$minAge}.";
        } elseif ((int)$applicant['age'] < $minAge) {
            $reasons[] = "Applicant is below the required age (minimum {$minAge}, applicant is {$applicant['age']}).";
        }
    }

    // 4. NRC (BR004).
    if (!empty($program['requires_nrc']) && trim((string)$applicant['nrc']) === '') {
        $reasons[] = 'NRC / passport number is required for this course.';
    }

    // 5. Education — Grade 12 (BR006). No reliable structured data source -> soft.
    if (!empty($program['requires_grade_12'])) {
        $warnings[] = 'Grade 12 certificate is required — verify the academic document before training.';
    }

    // 8. Driver licence present (BR005).
    if (!empty($program['requires_driver_licence']) && empty($applicant['has_driver_licence'])) {
        $reasons[] = 'A valid driver licence is required for this course.';
    }

    // 9. Required licence class (BR005).
    $required = te_parse_licence_classes($program['required_licence_class'] ?? '');
    if (!empty($required)) {
        $have = strtoupper(trim((string)$applicant['licence_class']));
        if ($have === '' || !in_array($have, $required, true)) {
            $reasons[] = 'Required licence class not met (accepted: ' . implode(', ', $required) . ').';
        }
    }

    // 11. Medical certificate (BR005). Required -> must be explicitly cleared.
    if (!empty($program['requires_medical_certificate'])) {
        switch ($applicant['medical']) {
            case 'cleared':
                break;
            case 'failed':
                $reasons[] = 'Medical clearance failed — applicant cannot be admitted to this course.';
                break;
            default: // pending | not_required
                $reasons[] = 'Medical clearance is required and not yet cleared (current: ' . $applicant['medical'] . ').';
                break;
        }
    }

    $eligible = empty($reasons);
    return [
        'eligible'  => $eligible,
        'hard_fail' => !$eligible,
        'status'    => $eligible ? 'Eligible' : 'Not Eligible',
        'reasons'   => $reasons,
        'warnings'  => $warnings,
    ];
}
