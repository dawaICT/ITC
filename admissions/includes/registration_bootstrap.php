<?php
/**
 * Shared new-student registration bootstrap.
 *
 * Included by BOTH admissions/regNewStud.php and admin/regNewStud.php so the two
 * pages drive the exact same data and the exact same backend. Sets:
 *   - $csrf_token   : CSRF token for the AJAX calls
 *   - $programs_data: [program_code => ['name' => ..., 'period_mode' => semester|term]]
 *
 * Requiring registration_handlers.php also pulls in the shared student-ID
 * generator and upload validator used by the wizard partials.
 */

require_once __DIR__ . '/registration_handlers.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Build the program list with a schema-safe period mode. The authoritative
// source is programs.period_mode; study_mode (Full Time/Part Time) is only used
// as a legacy fallback when it happens to hold 'semester'/'term'.
$programs_data = [];
if (isset($db) && $db instanceof mysqli) {
    $program_cols = [];
    if ($program_meta = $db->query('SHOW COLUMNS FROM programs')) {
        while ($col = $program_meta->fetch_assoc()) {
            $program_cols[strtolower((string)$col['Field'])] = (string)$col['Field'];
        }
        $program_meta->free();
    }

    $period_select = isset($program_cols['period_mode'])
        ? 'period_mode'
        : (isset($program_cols['period_type']) ? 'period_type AS period_mode' : 'NULL AS period_mode');
    $study_select  = isset($program_cols['study_mode']) ? 'study_mode' : 'NULL AS study_mode';
    $active_filter = isset($program_cols['is_active'])
        ? "WHERE COALESCE(is_active, 1) = 1 AND program_code NOT IN ('CSE', 'ICT-002')"
        : "WHERE program_code NOT IN ('CSE', 'ICT-002')";

    $programs_query = "SELECT program_code, program_name, {$period_select}, {$study_select},
                              academic_structure, duration_value, duration_unit, uses_terms,
                              uses_semesters, is_short_course, is_transport_exception, examination_type
                       FROM programs {$active_filter} ORDER BY program_name ASC";
    if ($programs_result = $db->query($programs_query)) {
        while ($row = $programs_result->fetch_object()) {
            $resolved_period_mode = $row->period_mode ?? '';
            if ($resolved_period_mode === '' && in_array($row->study_mode ?? '', ['semester', 'term'], true)) {
                $resolved_period_mode = $row->study_mode;
            }
            if ($resolved_period_mode === '') {
                $resolved_period_mode = 'semester';
            }
            $programs_data[$row->program_code] = [
                'name'                   => $row->program_name,
                'period_mode'            => $resolved_period_mode,
                'academic_structure'     => $row->academic_structure,
                'duration_value'         => $row->duration_value,
                'duration_unit'          => $row->duration_unit,
                'uses_terms'             => (int)($row->uses_terms ?? 0),
                'uses_semesters'         => (int)($row->uses_semesters ?? 0),
                'is_short_course'        => (int)($row->is_short_course ?? 0),
                'is_transport_exception' => (int)($row->is_transport_exception ?? 0),
                'examination_type'       => $row->examination_type ?? 'external',
            ];
        }
    }

    // Transport driving courses live in their own `transport_programs` table.
    // admissionsResolveProgram() already registers them (as term-based) and the
    // transport module builds its trainee candidate list from student_program
    // joined to transport_programs — but they were never offered in the wizard,
    // so transport trainees could not be enrolled here. Surface the active ones
    // (suffixed "(Transport)") so the SAME registration flow enrols them. A code
    // already present in `programs` is not overwritten.
    $hasTransport = $db->query("SHOW TABLES LIKE 'transport_programs'");
    if ($hasTransport && $hasTransport->num_rows > 0) {
        $maxShortCourseDays = (int)SC_MAX_SHORT_COURSE_DAYS;
        if ($transport_result = $db->query("SELECT program_code, program_name FROM transport_programs WHERE status = 'active' AND COALESCE(duration_days, 0) BETWEEN 1 AND {$maxShortCourseDays} ORDER BY program_name ASC")) {
            while ($row = $transport_result->fetch_object()) {
                if (isset($programs_data[$row->program_code])) {
                    continue;
                }
                $programs_data[$row->program_code] = [
                    'name'        => $row->program_name . ' (Transport)',
                    // Transport courses admit on a rolling basis (enrol any time);
                    // the wizard offers a single rolling-intake option for them.
                    'period_mode' => 'rolling',
                ];
            }
            $transport_result->free();
        }
    }
}
