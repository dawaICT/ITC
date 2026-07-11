<?php
/**
 * Migration: give `programs` an explicit registration period model.
 *
 * WHY
 * ---
 * Registration is meant to support multiple intake types (semester, term and the
 * duration-based short courses). The whole pipeline — AcademicSessionService,
 * RegistrationDataService::createRegistration, semester_registration.period_type,
 * the print registers and the student UI labels — is ALREADY period-aware and
 * routes off the value returned by getStudentProgramPeriodMode() in
 * students/includes/period_mode_helper.php.
 *
 * That helper looks for a `period_mode` / `period_type` column on `programs`,
 * but none existed. It then fell back to `study_mode` / `program_type`, which on
 * the live data hold "Full Time" and "Craft Certificate" — neither of which
 * encodes term-vs-semester — so EVERY program resolved to 'semester'. ITC's
 * trade / craft / certificate / diploma programmes actually run on TERMS, so
 * they were being registered against the wrong period model and term
 * registration could never be produced.
 *
 * WHAT
 * ----
 *  1. Adds `programs.period_mode ENUM('semester','term')` (the dedicated,
 *     unambiguous field the helper already prefers). `study_mode` is left alone
 *     to keep meaning Full Time / Part Time.
 *  2. On first creation only, seeds it: ITC / TVET qualifications -> 'term',
 *     everything else (degrees) -> 'semester'. Skipped on re-run so an admin's
 *     later manual choices are never clobbered.
 *  3. Adds a unique key on the semester_registration natural key (now including
 *     period_type) so a student can hold BOTH a term and a semester registration
 *     for the same period number, and the upsert in StudentRegistrationSystem
 *     actually dedupes. Skipped automatically if existing duplicate rows would
 *     violate it.
 *
 * Idempotent and non-destructive — safe to re-run.
 */

require __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) $stmt->get_result()->fetch_assoc()['c'] > 0;
    $stmt->close();
    return $exists;
}

function index_exists(mysqli $db, string $table, string $index): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) c FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (int) $stmt->get_result()->fetch_assoc()['c'] > 0;
    $stmt->close();
    return $exists;
}

// ── 1. programs.period_mode ────────────────────────────────────────────
$freshlyAdded = false;
if (column_exists($db, 'programs', 'period_mode')) {
    echo "`period_mode` already on programs.\n";
} else {
    $db->query(
        "ALTER TABLE programs
           ADD COLUMN period_mode ENUM('semester','term') NOT NULL DEFAULT 'semester'
           AFTER study_mode"
    );
    $freshlyAdded = true;
    echo "Added `period_mode` to programs.\n";
}

// ── 2. Seed on first creation only ─────────────────────────────────────
if ($freshlyAdded) {
    // ITC / TVET qualifications run on terms; university degrees on semesters.
    $sql = "UPDATE programs
               SET period_mode = 'term'
             WHERE program_code LIKE 'ITC-%'
                OR program_type IN ('Trade Certificate','Craft Certificate','Certificate','Diploma')";
    $db->query($sql);
    $termRows = $db->affected_rows;
    echo "Seeded period_mode: {$termRows} program(s) set to 'term', the rest remain 'semester'.\n";
} else {
    echo "period_mode already present; leaving existing values untouched.\n";
}

// ── 3. Robust dedup key on semester_registration ───────────────────────
if (!column_exists($db, 'semester_registration', 'period_type')) {
    echo "semester_registration has no period_type column; skipping unique key.\n";
} elseif (index_exists($db, 'semester_registration', 'uq_sem_reg_natural')) {
    echo "Unique key uq_sem_reg_natural already exists.\n";
} else {
    // Only add the key if the current data has no collisions under it.
    $dupRes = $db->query(
        "SELECT COUNT(*) c FROM (
            SELECT student_id, program_code, year_of_study, semester, period_type, academic_year
              FROM semester_registration
             GROUP BY student_id, program_code, year_of_study, semester, period_type, academic_year
            HAVING COUNT(*) > 1
         ) d"
    );
    $dupes = (int) $dupRes->fetch_assoc()['c'];
    if ($dupes > 0) {
        echo "Found {$dupes} duplicate natural-key group(s); NOT adding unique key. "
           . "Resolve duplicates then re-run to enforce.\n";
    } else {
        $db->query(
            "ALTER TABLE semester_registration
               ADD UNIQUE KEY uq_sem_reg_natural
               (student_id, program_code, year_of_study, semester, period_type, academic_year)"
        );
        echo "Added unique key uq_sem_reg_natural on semester_registration.\n";
    }
}

echo "Migration complete.\n";
