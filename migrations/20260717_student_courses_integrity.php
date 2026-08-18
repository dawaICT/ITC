<?php
/**
 * Archive exact duplicate legacy student_courses rows, normalize invalid enum
 * statuses, and enforce one mirror row per student/course/academic period.
 *
 * Usage:
 *   php migrations/20260717_student_courses_integrity.php --dry-run
 *   php migrations/20260717_student_courses_integrity.php --apply
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../db/connect.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$apply = in_array('--apply', $argv ?? [], true);
if (!$dryRun && !$apply) {
    fwrite(STDERR, "Usage: php migrations/20260717_student_courses_integrity.php [--dry-run|--apply]\n");
    exit(1);
}

$table = $db->query("SHOW TABLES LIKE 'student_courses'");
if (!$table || $table->num_rows === 0) {
    fwrite(STDERR, "student_courses is missing.\n");
    exit(2);
}
$table->free();

$duplicateResult = $db->query(
    "SELECT COUNT(*) AS duplicate_groups, COALESCE(SUM(copies - 1), 0) AS duplicate_rows
       FROM (
            SELECT COUNT(*) AS copies
              FROM student_courses
             GROUP BY student_id, course_code, academic_year, semester
            HAVING COUNT(*) > 1
       ) duplicate_sets"
);
$duplicateState = $duplicateResult->fetch_assoc() ?: [];
$duplicateResult->free();
$duplicateGroups = (int)($duplicateState['duplicate_groups'] ?? 0);
$duplicateRows = (int)($duplicateState['duplicate_rows'] ?? 0);

$invalidStatusResult = $db->query(
    "SELECT COUNT(*) AS total FROM student_courses WHERE status IS NULL OR status = ''"
);
$invalidStatuses = (int)($invalidStatusResult->fetch_assoc()['total'] ?? 0);
$invalidStatusResult->free();

$indexResult = $db->query("SHOW INDEX FROM student_courses WHERE Key_name = 'uniq_student_courses_period'");
$hasUnique = $indexResult && $indexResult->num_rows > 0;
if ($indexResult) {
    $indexResult->free();
}

$constraintStmt = $db->prepare(
    "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'student_courses'
        AND CONSTRAINT_NAME = 'chk_student_courses_status_valid'
        AND CONSTRAINT_TYPE = 'CHECK'
      LIMIT 1"
);
$constraintStmt->execute();
$hasStatusCheck = (bool)$constraintStmt->get_result()->fetch_row();
$constraintStmt->close();

$archiveResult = $db->query("SHOW TABLES LIKE 'student_courses_duplicate_archive_20260717'");
$hasArchive = $archiveResult && $archiveResult->num_rows > 0;
if ($archiveResult) {
    $archiveResult->free();
}

echo '[state] duplicate_groups=' . $duplicateGroups
    . '; duplicate_rows=' . $duplicateRows
    . '; invalid_statuses=' . $invalidStatuses
    . '; archive=' . ($hasArchive ? 'present' : 'missing')
    . '; unique=' . ($hasUnique ? 'present' : 'missing')
    . '; status_check=' . ($hasStatusCheck ? 'present' : 'missing') . PHP_EOL;

if ($dryRun) {
    if (!$hasArchive) {
        echo "[dry-run] CREATE TABLE student_courses_duplicate_archive_20260717 LIKE student_courses\n";
    }
    if ($duplicateRows > 0) {
        echo "[dry-run] archive and remove {$duplicateRows} duplicate row(s)\n";
    }
    if ($invalidStatuses > 0) {
        echo "[dry-run] normalize {$invalidStatuses} blank/null status row(s) to registered\n";
    }
    if (!$hasUnique) {
        echo "[dry-run] add uniq_student_courses_period (student_id, course_code, academic_year, semester)\n";
    }
    if (!$hasStatusCheck) {
        echo "[dry-run] add chk_student_courses_status_valid\n";
    }
    exit(0);
}

if (!$hasArchive) {
    $db->query('CREATE TABLE student_courses_duplicate_archive_20260717 LIKE student_courses');
    echo "[applied] student_courses_duplicate_archive_20260717\n";
}

if ($duplicateRows > 0) {
    $duplicateJoin =
        " FROM student_courses sc
          JOIN (
               SELECT student_id, course_code, academic_year, semester, MIN(id) AS keep_id
                 FROM student_courses
                GROUP BY student_id, course_code, academic_year, semester
               HAVING COUNT(*) > 1
          ) duplicate_sets
            ON duplicate_sets.student_id = sc.student_id
           AND duplicate_sets.course_code = sc.course_code
           AND duplicate_sets.academic_year = sc.academic_year
           AND duplicate_sets.semester = sc.semester
           AND sc.id <> duplicate_sets.keep_id";

    $db->begin_transaction();
    try {
        $db->query(
            'INSERT IGNORE INTO student_courses_duplicate_archive_20260717 SELECT sc.*' . $duplicateJoin
        );
        $archived = $db->affected_rows;
        $db->query('DELETE sc' . $duplicateJoin);
        $deleted = $db->affected_rows;
        if ($deleted !== $duplicateRows || $archived < $duplicateRows) {
            throw new RuntimeException(
                "Duplicate archive mismatch: expected {$duplicateRows}, archived {$archived}, deleted {$deleted}."
            );
        }
        $db->commit();
        echo "[applied] archived={$archived}; removed={$deleted}\n";
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

if ($invalidStatuses > 0) {
    $db->query("UPDATE student_courses SET status = 'registered' WHERE status IS NULL OR status = ''");
    echo '[applied] normalized_statuses=' . $db->affected_rows . PHP_EOL;
}

if (!$hasUnique) {
    $db->query(
        'ALTER TABLE student_courses
         ADD UNIQUE KEY uniq_student_courses_period (student_id, course_code, academic_year, semester)'
    );
    echo "[applied] uniq_student_courses_period\n";
}

if (!$hasStatusCheck) {
    $db->query(
        "ALTER TABLE student_courses
         ADD CONSTRAINT chk_student_courses_status_valid
         CHECK (status IS NULL OR status IN ('registered','dropped','completed','failed','incomplete'))"
    );
    echo "[applied] chk_student_courses_status_valid\n";
}

echo "student_courses integrity migration complete.\n";
