<?php
/**
 * Correct a data-model mistake: several ITC catalogue entries that were imported
 * into the `courses` table are actually PROGRAMS (multi-year Diplomas and
 * Craft/Technician Certificates), not courses. They were showing up wherever the
 * app lists courses (e.g. the Add Course catalogue dropdown).
 *
 * This moves those qualification-level entries into the `programs` table and
 * removes them from `courses`, so course lists only contain real courses and the
 * programs appear as programs.
 *
 * Idempotent. Run from project root:
 *   E:\xampp\php\php.exe scripts\move_itc_programs_out_of_courses.php
 */

require_once __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DEPARTMENT = 'GEN01'; // only department defined in this install

// Identify program-level entries: name is a qualification AND/OR duration is in years.
$select = "SELECT course_code, course_name, category, duration
           FROM courses
           WHERE course_code LIKE 'ITC-%'
             AND ( course_name REGEXP '^(Diploma|Advanced Diploma|Certificate|Craft Certificate|Technician Certificate|Degree|Bachelor)'
                   OR duration LIKE '%year%' )";

function deriveType(string $name): string {
    if (preg_match('/^diploma/i', $name)) return 'Diploma';
    if (preg_match('/certificate/i', $name)) return 'Certificate';
    if (preg_match('/^(degree|bachelor)/i', $name)) return 'Degree';
    return 'Program';
}

function deriveDurationYears(?string $duration): ?float {
    if ($duration !== null && preg_match('/(\d+(?:\.\d+)?)\s*year/i', $duration, $m)) {
        return (float) $m[1];
    }
    return null;
}

try {
    $db->begin_transaction();

    $rows = [];
    $res = $db->query($select);
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    if (empty($rows)) {
        echo "No program-level entries found in courses (already corrected).\n";
        $db->commit();
        exit(0);
    }

    // Upsert into programs (program_code is PK).
    $up = $db->prepare(
        "INSERT INTO programs (program_code, program_name, department_id, program_type, study_mode, program_duration, is_active)
         VALUES (?, ?, ?, ?, 'Full Time', ?, 1)
         ON DUPLICATE KEY UPDATE
            program_name = VALUES(program_name),
            department_id = VALUES(department_id),
            program_type = VALUES(program_type),
            study_mode = VALUES(study_mode),
            program_duration = VALUES(program_duration)"
    );

    $del = $db->prepare("DELETE FROM courses WHERE course_code = ?");

    $moved = 0;
    foreach ($rows as $r) {
        $code = $r['course_code'];
        $name = $r['course_name'];
        $type = deriveType($name);
        $years = deriveDurationYears($r['duration']);
        $up->bind_param('ssssd', $code, $name, $DEPARTMENT, $type, $years);
        $up->execute();
        $del->bind_param('s', $code);
        $del->execute();
        echo "  moved {$code} -> programs ({$type}, " . ($years ?? '?') . "yr): {$name}\n";
        $moved++;
    }

    $db->commit();
    echo "DONE - moved {$moved} program-level entries out of courses.\n";
} catch (Throwable $e) {
    $db->rollback();
    echo "FAILED (rolled back): " . $e->getMessage() . "\n";
    exit(1);
}
