<?php
/**
 * Migration: fix short-course schema integration (idempotent).
 *
 * Brings an environment to the state applied manually on 2026-05-22:
 *   1. Normalises short_course_enrollments.student_id collation to
 *      utf8mb4_unicode_ci so it matches students.SID (removes the
 *      "Illegal mix of collations" errors that forced COLLATE casts).
 *   2. Adds foreign keys on short_course_enrollments (student + course).
 *   3. Adds student_login.Email (used for short-course student accounts).
 *   4. Adds the fee_structure entity_type / short_course_id / fee_type
 *      columns, indexes, FK and CHECK constraint, relaxing the
 *      program-only NOT NULL columns so short-course fees can be stored.
 *
 * Safe to re-run: every step checks current state before altering.
 */
require_once __DIR__ . '/../db/connect.php';

echo "Running short-course schema fix migration...\n";

/** Helpers -------------------------------------------------------------- */
function msc_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function msc_column_collation(mysqli $db, string $table, string $column): ?string
{
    $stmt = $db->prepare('SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->bind_result($collation);
    $found = $stmt->fetch();
    $stmt->close();
    return $found ? $collation : null;
}

function msc_constraint_exists(mysqli $db, string $table, string $constraint): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $constraint);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function msc_run(mysqli $db, string $sql, string $label): void
{
    if ($db->query($sql)) {
        echo "  ✓ {$label}\n";
    } else {
        echo "  ✗ {$label}: " . $db->error . "\n";
    }
}

/** 1. Collation normalisation ----------------------------------------- */
if (msc_column_exists($db, 'short_course_enrollments', 'student_id')) {
    $collation = msc_column_collation($db, 'short_course_enrollments', 'student_id');
    if ($collation !== 'utf8mb4_unicode_ci') {
        msc_run(
            $db,
            'ALTER TABLE short_course_enrollments MODIFY student_id VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
            'short_course_enrollments.student_id -> utf8mb4_unicode_ci'
        );
    } else {
        echo "  • student_id collation already utf8mb4_unicode_ci\n";
    }
}

/** 2. Foreign keys on enrollments ------------------------------------- */
if (!msc_constraint_exists($db, 'short_course_enrollments', 'fk_sce_student')) {
    msc_run(
        $db,
        'ALTER TABLE short_course_enrollments ADD CONSTRAINT fk_sce_student FOREIGN KEY (student_id) REFERENCES students(SID) ON DELETE CASCADE',
        'add fk_sce_student'
    );
} else {
    echo "  • fk_sce_student already present\n";
}
if (!msc_constraint_exists($db, 'short_course_enrollments', 'fk_sce_course')) {
    msc_run(
        $db,
        'ALTER TABLE short_course_enrollments ADD CONSTRAINT fk_sce_course FOREIGN KEY (short_course_id) REFERENCES short_courses(id) ON DELETE CASCADE',
        'add fk_sce_course'
    );
} else {
    echo "  • fk_sce_course already present\n";
}

/** 3. student_login.Email -------------------------------------------- */
if (!msc_column_exists($db, 'student_login', 'Email')) {
    msc_run($db, 'ALTER TABLE student_login ADD COLUMN Email VARCHAR(255) NULL AFTER Password', 'add student_login.Email');
} else {
    echo "  • student_login.Email already present\n";
}

/** 3b. student_login.must_change_password ---------------------------- */
// Short-course accounts are created with a default password and must change it
// on first login.
if (!msc_column_exists($db, 'student_login', 'must_change_password')) {
    msc_run($db, 'ALTER TABLE student_login ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER Password', 'add student_login.must_change_password');
} else {
    echo "  • student_login.must_change_password already present\n";
}

/** 4. fee_structure entity columns + constraints --------------------- */
if (!msc_column_exists($db, 'fee_structure', 'entity_type')) {
    msc_run($db, "ALTER TABLE fee_structure ADD COLUMN entity_type ENUM('program','short_course') NOT NULL DEFAULT 'program' AFTER id", 'add fee_structure.entity_type');
}
if (!msc_column_exists($db, 'fee_structure', 'short_course_id')) {
    msc_run($db, 'ALTER TABLE fee_structure ADD COLUMN short_course_id INT NULL AFTER program_code', 'add fee_structure.short_course_id');
}
if (!msc_column_exists($db, 'fee_structure', 'fee_type')) {
    msc_run($db, 'ALTER TABLE fee_structure ADD COLUMN fee_type VARCHAR(50) NULL AFTER semester', 'add fee_structure.fee_type');
}

// Relax program-only NOT NULL columns so short-course rows can be stored.
msc_run($db, 'ALTER TABLE fee_structure MODIFY COLUMN program_code VARCHAR(50) NULL', 'fee_structure.program_code nullable');
msc_run($db, 'ALTER TABLE fee_structure MODIFY COLUMN year_of_study TINYINT(3) UNSIGNED NULL', 'fee_structure.year_of_study nullable');
msc_run($db, 'ALTER TABLE fee_structure MODIFY COLUMN semester TINYINT(3) UNSIGNED NULL', 'fee_structure.semester nullable');

if (!msc_constraint_exists($db, 'fee_structure', 'fk_sc_fee')) {
    msc_run($db, 'ALTER TABLE fee_structure ADD INDEX idx_fee_entity_type_status (entity_type, status), ADD INDEX idx_fee_short_course_status (short_course_id, status), ADD CONSTRAINT fk_sc_fee FOREIGN KEY (short_course_id) REFERENCES short_courses(id) ON DELETE CASCADE', 'add fee_structure indexes + fk_sc_fee');
} else {
    echo "  • fk_sc_fee already present\n";
}
if (!msc_constraint_exists($db, 'fee_structure', 'chk_fee_structure_entity')) {
    msc_run(
        $db,
        "ALTER TABLE fee_structure ADD CONSTRAINT chk_fee_structure_entity CHECK ("
        . "(entity_type='program' AND program_code IS NOT NULL AND short_course_id IS NULL) OR "
        . "(entity_type='short_course' AND short_course_id IS NOT NULL AND program_code IS NULL))",
        'add chk_fee_structure_entity'
    );
} else {
    echo "  • chk_fee_structure_entity already present\n";
}

echo "Migration complete.\n";
