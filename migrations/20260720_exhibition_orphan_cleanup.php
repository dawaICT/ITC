<?php
declare(strict_types=1);

/**
 * Archive and remove credential/course mirror rows whose student no longer
 * exists. The archive tables make the cleanup reversible and auditable.
 *
 * Usage: php migrations/20260720_exhibition_orphan_cleanup.php --dry-run|--apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../db/connect.php';

$apply = in_array('--apply', $argv ?? [], true);
$dryRun = in_array('--dry-run', $argv ?? [], true);
if (!$apply && !$dryRun) {
    fwrite(STDERR, "Usage: php migrations/20260720_exhibition_orphan_cleanup.php --dry-run|--apply\n");
    exit(1);
}

$loginOrphans = (int)$db->query(
    'SELECT COUNT(*) total FROM student_login sl LEFT JOIN students s ON s.SID=sl.Sid WHERE s.SID IS NULL'
)->fetch_assoc()['total'];
$courseOrphans = (int)$db->query(
    'SELECT COUNT(*) total FROM student_courses sc LEFT JOIN students s ON s.SID=sc.student_id WHERE s.SID IS NULL'
)->fetch_assoc()['total'];

echo "[state] orphan_logins={$loginOrphans}; orphan_legacy_courses={$courseOrphans}\n";
if ($dryRun) {
    echo "[dry-run] would archive then delete these orphan rows\n";
    exit(0);
}

try {
    $db->query('CREATE TABLE IF NOT EXISTS exhibition_orphan_student_login_archive LIKE student_login');
    if ($db->query("SHOW COLUMNS FROM exhibition_orphan_student_login_archive LIKE 'archived_at'")->num_rows === 0) {
        $db->query('ALTER TABLE exhibition_orphan_student_login_archive ADD archived_at DATETIME NULL, ADD archive_reason VARCHAR(120) NULL');
    }
    $db->query('CREATE TABLE IF NOT EXISTS exhibition_orphan_student_courses_archive LIKE student_courses');
    if ($db->query("SHOW COLUMNS FROM exhibition_orphan_student_courses_archive LIKE 'archived_at'")->num_rows === 0) {
        $db->query('ALTER TABLE exhibition_orphan_student_courses_archive ADD archived_at DATETIME NULL, ADD archive_reason VARCHAR(120) NULL');
    }

    $db->begin_transaction();
    $db->query(
        "INSERT IGNORE INTO exhibition_orphan_student_login_archive
         SELECT sl.*, NOW(), 'Student row missing during exhibition integrity cleanup'
         FROM student_login sl LEFT JOIN students s ON s.SID=sl.Sid WHERE s.SID IS NULL"
    );
    $archivedLogins = $db->affected_rows;
    $db->query(
        "INSERT IGNORE INTO exhibition_orphan_student_courses_archive
         SELECT sc.*, NOW(), 'Student row missing during exhibition integrity cleanup'
         FROM student_courses sc LEFT JOIN students s ON s.SID=sc.student_id WHERE s.SID IS NULL"
    );
    $archivedCourses = $db->affected_rows;
    $db->query('DELETE sl FROM student_login sl LEFT JOIN students s ON s.SID=sl.Sid WHERE s.SID IS NULL');
    $deletedLogins = $db->affected_rows;
    $db->query('DELETE sc FROM student_courses sc LEFT JOIN students s ON s.SID=sc.student_id WHERE s.SID IS NULL');
    $deletedCourses = $db->affected_rows;
    $db->commit();

    $remainingLogins = (int)$db->query(
        'SELECT COUNT(*) total FROM student_login sl LEFT JOIN students s ON s.SID=sl.Sid WHERE s.SID IS NULL'
    )->fetch_assoc()['total'];
    $remainingCourses = (int)$db->query(
        'SELECT COUNT(*) total FROM student_courses sc LEFT JOIN students s ON s.SID=sc.student_id WHERE s.SID IS NULL'
    )->fetch_assoc()['total'];
    if ($remainingLogins !== 0 || $remainingCourses !== 0) {
        throw new RuntimeException('Orphan rows remain after cleanup.');
    }
    echo "[applied] archived_logins={$archivedLogins}; deleted_logins={$deletedLogins}; archived_courses={$archivedCourses}; deleted_courses={$deletedCourses}\n";
    echo "[verified] orphan_logins=0; orphan_legacy_courses=0\n";
} catch (Throwable $e) {
    if ($db->query('SELECT @@in_transaction active')->fetch_assoc()['active'] ?? false) {
        $db->rollback();
    }
    fwrite(STDERR, '[failed] ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
