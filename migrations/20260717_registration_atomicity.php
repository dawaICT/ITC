<?php
/**
 * Make the canonical academic registration transaction fully rollback-capable
 * and protect year-wide course enrolment from concurrent duplicates.
 *
 * Usage:
 *   php migrations/20260717_registration_atomicity.php --dry-run
 *   php migrations/20260717_registration_atomicity.php --apply
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
    fwrite(STDERR, "Usage: php migrations/20260717_registration_atomicity.php [--dry-run|--apply]\n");
    exit(1);
}

$requiredTables = ['semester_registration', 'course_registration'];
$engines = [];
foreach ($requiredTables as $table) {
    $stmt = $db->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $engine = (string)($stmt->get_result()->fetch_assoc()['ENGINE'] ?? '');
    $stmt->close();
    if ($engine === '') {
        fwrite(STDERR, "Required table {$table} is missing.\n");
        exit(2);
    }
    $engines[$table] = strtoupper($engine);
}

$duplicateResult = $db->query(
    "SELECT COUNT(*) AS duplicate_groups
       FROM (
            SELECT Sid, course_code, Year, academic_year
              FROM course_registration
             GROUP BY Sid, course_code, Year, academic_year
            HAVING COUNT(*) > 1
       ) duplicate_enrolments"
);
$duplicateGroups = (int)($duplicateResult->fetch_assoc()['duplicate_groups'] ?? 0);
$duplicateResult->free();

$indexResult = $db->query(
    "SHOW INDEX FROM course_registration WHERE Key_name = 'uniq_course_registration_student_year'"
);
$hasCourseUnique = $indexResult && $indexResult->num_rows > 0;
if ($indexResult) {
    $indexResult->free();
}

echo '[state] semester_registration_engine=' . $engines['semester_registration']
    . '; course_registration_engine=' . $engines['course_registration']
    . '; duplicate_course_groups=' . $duplicateGroups
    . '; course_unique=' . ($hasCourseUnique ? 'present' : 'missing') . PHP_EOL;

if ($duplicateGroups > 0) {
    fwrite(STDERR, "Cannot add enrolment uniqueness while duplicate course_registration groups exist.\n");
    exit(3);
}

if ($dryRun) {
    if ($engines['semester_registration'] !== 'INNODB') {
        echo "[dry-run] ALTER TABLE semester_registration ENGINE=InnoDB\n";
    }
    if (!$hasCourseUnique) {
        echo "[dry-run] ALTER TABLE course_registration ADD UNIQUE KEY uniq_course_registration_student_year (Sid, course_code, Year, academic_year)\n";
    }
    exit(0);
}

if ($engines['semester_registration'] !== 'INNODB') {
    $db->query('ALTER TABLE semester_registration ENGINE=InnoDB');
    echo "[applied] semester_registration ENGINE=InnoDB\n";
}
if (!$hasCourseUnique) {
    $db->query(
        'ALTER TABLE course_registration
         ADD UNIQUE KEY uniq_course_registration_student_year (Sid, course_code, Year, academic_year)'
    );
    echo "[applied] uniq_course_registration_student_year\n";
}

echo "Registration atomicity migration complete.\n";
