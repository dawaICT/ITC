<?php
/**
 * Legacy student-number support helpers.
 *
 * The student-number GENERATION logic now lives in
 * includes/student_id_generator.php (the NRC-based scheme:
 * YY + Type(Y/C/S) + NRC6 + 2-digit sequence, e.g. 26Y12345601).
 *
 * Only the small set of school-code / label helpers below survive here,
 * because admin/add_student.php still uses them for school normalisation
 * and human-readable cohort labels. The old hyphenated generator,
 * validator, describer and the related constants were removed when the
 * scheme changed; see student_id_generator.php for current behaviour.
 */

if (!function_exists('wuc_known_school_codes')) {
    /**
     * Schools that may appear in a student number. Add to this list to
     * register a new institution code. The portal will then accept it as
     * a valid school for SID lookup.
     *
     * Currently the only school is ITC (Industrial Training College),
     * whose programs are stored in `programs` with the 'ITC-' code prefix.
     */
    function wuc_known_school_codes(): array
    {
        return [
            'ITC' => 'ITC (Industrial Training College)',
        ];
    }
}

if (!function_exists('wuc_normalize_school_code')) {
    /**
     * Coerce school input into a canonical upper-case 2-4 letter code.
     * Returns null for invalid / unknown codes so callers can surface
     * a form error rather than producing a malformed SID.
     */
    function wuc_normalize_school_code(?string $school): ?string
    {
        if ($school === null) return null;
        $s = strtoupper(trim($school));
        if (!preg_match('/^[A-Z]{2,4}$/', $s)) return null;
        $known = wuc_known_school_codes();
        if (!isset($known[$s])) return null;
        return $s;
    }
}

if (!function_exists('wuc_student_type_label')) {
    /**
     * Human-readable name for a cohort letter — used in success messages,
     * reports, and ID-card printing.
     */
    function wuc_student_type_label(string $letter): string
    {
        switch (strtoupper(trim($letter))) {
            case 'S': return 'Semester';
            case 'T': return 'Term';
            case 'C': return 'Short course';
        }
        return 'Student';
    }
}
