<?php
/**
 * Migration: BEFORE INSERT trigger that always populates
 * course_registration.academic_year (the CALENDAR academic year).
 *
 * course_registration.Year is the year-of-study; the results side (CA/exams)
 * keys on academic_year. Rather than patch every registration write path, this
 * trigger derives academic_year on insert when it is not supplied:
 *   1. from the linked semester_registration.academic_year, else
 *   2. from the student's students.academic_year, else
 *   3. the current calendar year.
 *
 * Idempotent (drops first). Run: php migrate_course_registration_year_trigger.php
 */

require 'db/connect.php';
if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
echo "course_registration academic_year trigger\n" . str_repeat('=', 60) . "\n\n";

// Pre-req: the academic_year column must exist.
$hasCol = false;
if ($r = $db->query("SHOW COLUMNS FROM course_registration LIKE 'academic_year'")) {
    $hasCol = $r->num_rows > 0; $r->free();
}
if (!$hasCol) {
    echo "ERROR: course_registration.academic_year is missing. Run migrate_course_registration_academic_year.php first.\n";
    $db->close();
    exit;
}

if (!$db->query("DROP TRIGGER IF EXISTS trg_course_registration_academic_year")) {
    echo "ERROR dropping old trigger: {$db->error}\n"; $db->close(); exit;
}

$trigger = "CREATE TRIGGER trg_course_registration_academic_year
BEFORE INSERT ON course_registration
FOR EACH ROW
BEGIN
    IF NEW.academic_year IS NULL THEN
        IF NEW.semester_registration_id IS NOT NULL THEN
            SET NEW.academic_year = (
                SELECT sr.academic_year FROM semester_registration sr
                WHERE sr.id = NEW.semester_registration_id
                  AND sr.academic_year REGEXP '^[0-9]{4}\$' LIMIT 1
            );
        END IF;
        IF NEW.academic_year IS NULL THEN
            SET NEW.academic_year = (
                SELECT s.academic_year FROM students s
                WHERE s.SID = NEW.Sid
                  AND s.academic_year REGEXP '^[0-9]{4}\$' LIMIT 1
            );
        END IF;
        IF NEW.academic_year IS NULL THEN
            SET NEW.academic_year = YEAR(NOW());
        END IF;
    END IF;
END";

if ($db->query($trigger)) {
    echo "Created trigger trg_course_registration_academic_year.\n";
} else {
    echo "ERROR creating trigger: {$db->error}\n";
}
$db->close();
