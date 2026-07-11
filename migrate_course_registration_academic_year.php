<?php
/**
 * Migration: add course_registration.academic_year (calendar year) and backfill.
 *
 * Background: course_registration.Year holds the YEAR OF STUDY (1..4), written
 * by the registration pipeline. The results side (CA, exams, viewCaRes) needs
 * the CALENDAR academic year (e.g. 2026), which authoritatively lives in
 * semester_registration.academic_year / students.academic_year. This adds a
 * dedicated academic_year column so the results flow can key on the real year
 * without changing what Year means for registration.
 *
 * Idempotent. Run: php migrate_course_registration_academic_year.php
 */

require 'db/connect.php';
if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
echo "course_registration.academic_year migration\n" . str_repeat('=', 60) . "\n\n";

// 1. Add the column if missing.
$hasCol = false;
if ($r = $db->query("SHOW COLUMNS FROM course_registration LIKE 'academic_year'")) {
    $hasCol = $r->num_rows > 0; $r->free();
}
if (!$hasCol) {
    if ($db->query("ALTER TABLE course_registration ADD COLUMN academic_year SMALLINT UNSIGNED NULL AFTER Year")) {
        echo "1. Added column academic_year.\n";
    } else {
        echo "ERROR adding column: {$db->error}\n"; $db->close(); exit;
    }
} else {
    echo "1. Column academic_year already present.\n";
}

// 2. Backfill from semester_registration (authoritative) via the FK.
$db->query("UPDATE course_registration cr
    JOIN semester_registration sr ON sr.id = cr.semester_registration_id
    SET cr.academic_year = sr.academic_year
    WHERE cr.academic_year IS NULL
      AND sr.academic_year REGEXP '^[0-9]{4}$'");
echo "2. Backfilled from semester_registration ({$db->affected_rows} rows).\n";

// 3. Backfill legacy rows (no FK) from the student's academic_year.
$db->query("UPDATE course_registration cr
    JOIN students s ON s.SID COLLATE utf8mb4_general_ci = cr.Sid COLLATE utf8mb4_general_ci
    SET cr.academic_year = s.academic_year
    WHERE cr.academic_year IS NULL
      AND s.academic_year REGEXP '^[0-9]{4}$'");
echo "3. Backfilled from students.academic_year ({$db->affected_rows} rows).\n";

// 4. Final fallback: the calendar year the registration row was created in.
$db->query("UPDATE course_registration
    SET academic_year = YEAR(COALESCE(created_at, NOW()))
    WHERE academic_year IS NULL");
echo "4. Filled remaining via created_at year ({$db->affected_rows} rows).\n";

$res = $db->query("SELECT academic_year, COUNT(*) n FROM course_registration GROUP BY academic_year ORDER BY academic_year");
echo "\nResulting academic_year distribution:\n";
while ($row = $res->fetch_assoc()) {
    echo "  academic_year=" . ($row['academic_year'] ?? 'NULL') . " => {$row['n']}\n";
}
echo "\nDone.\n";
$db->close();
