<?php
/**
 * The ITC catalogue entries left in `courses` are SHORT COURSES (duration in
 * days, not term/semester based). They were wrongly modelled as academic
 * courses: 12 were attached to TEST-PROG's program_courses with credits=3 and
 * fake semesters. This relocates ALL remaining ITC short courses into the
 * proper `short_courses` subsystem (duration_value + duration_unit, no
 * semester/term) and unwinds the academic associations.
 *
 * Idempotent. Run from project root:
 *   E:\xampp\php\php.exe scripts\move_itc_short_courses.php
 */

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/short_course_db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$CREATED_BY = 'WUC900';

// Parse free-text duration like "10 days" / "20 weeks" into [value, unit].
function parseDuration(?string $raw): array {
    if ($raw !== null && preg_match('/(\d+)\s*(day|week|month|year)/i', $raw, $m)) {
        $value = (int) $m[1];
        $unit = strtolower($m[2]) . 's'; // day -> days, etc. (enum is plural)
        if ($value > 0) {
            return [$value, $unit];
        }
    }
    return [1, 'days']; // safe fallback; admin can adjust
}

function deleteMovedCourseRows(mysqli $db, string $table, array $codes): int {
    if (!$codes) {
        return 0;
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new InvalidArgumentException('Invalid table name.');
    }
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $db->prepare("DELETE FROM `{$table}` WHERE course_code IN ({$placeholders})");
    $types = str_repeat('s', count($codes));
    $stmt->bind_param($types, ...$codes);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

try {
    $db->begin_transaction();

    // Pull all ITC short courses still sitting in the academic courses table.
    $rows = [];
    $res = $db->query("SELECT course_code, course_name, category, duration FROM courses WHERE course_code LIKE 'ITC-%' ORDER BY course_code");
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    if (empty($rows)) {
        echo "No ITC short courses left in courses (already migrated).\n";
        $db->commit();
        exit(0);
    }

    // 1. Upsert into short_courses (course_code is unique).
    $up = $db->prepare(
        "INSERT INTO short_courses
            (course_code, course_name, description, duration_value, duration_unit, fee, max_capacity, delivery_mode, status, created_by)
         VALUES (?, ?, ?, ?, ?, 0.00, 30, 'full-time', 'active', ?)
         ON DUPLICATE KEY UPDATE
            course_name = VALUES(course_name),
            description = VALUES(description),
            duration_value = VALUES(duration_value),
            duration_unit = VALUES(duration_unit)"
    );
    $movedCodes = [];
    $skippedPrograms = 0;
    foreach ($rows as $r) {
        [$durVal, $durUnit] = parseDuration($r['duration']);
        if (!sc_is_short_course_duration($durVal, $durUnit)) {
            $skippedPrograms++;
            echo "  skipped {$r['course_code']} ({$durVal} {$durUnit}) as programme-length: {$r['course_name']}\n";
            continue;
        }
        $desc = (string) ($r['category'] ?? '');
        $up->bind_param('sssiss', $r['course_code'], $r['course_name'], $desc, $durVal, $durUnit, $CREATED_BY);
        $up->execute();
        $movedCodes[] = $r['course_code'];
        echo "  short_courses <- {$r['course_code']} ({$durVal} {$durUnit}): {$r['course_name']}\n";
    }
    echo "  migrated " . count($movedCodes) . " ITC short courses into short_courses\n";
    echo "  skipped {$skippedPrograms} programme-length ITC rows\n";

    if (!$movedCodes) {
        $db->commit();
        echo "DONE\n";
        exit(0);
    }

    // 2. Unwind academic associations for these codes.
    echo "  removed program_courses rows: " . deleteMovedCourseRows($db, 'program_courses', $movedCodes) . "\n";
    echo "  removed course_registration rows: " . deleteMovedCourseRows($db, 'course_registration', $movedCodes) . "\n";
    echo "  removed course_lecturer rows: " . deleteMovedCourseRows($db, 'course_lecturer', $movedCodes) . "\n";

    // 3. Remove them from the academic courses catalogue.
    echo "  removed courses rows: " . deleteMovedCourseRows($db, 'courses', $movedCodes) . "\n";

    $db->commit();
    echo "DONE\n";
} catch (Throwable $e) {
    $db->rollback();
    echo "FAILED (rolled back): " . $e->getMessage() . "\n";
    exit(1);
}
