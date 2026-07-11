<?php
/**
 * Repair orphan lecturer assignments and duplicate test rows for ITC900/ITC907.
 *
 * Usage:
 *   C:\xampp\php\php.exe tools/repair_lecturer_orphan_assignments.php
 *   C:\xampp\php\php.exe tools/repair_lecturer_orphan_assignments.php --apply
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$apply = in_array('--apply', $argv ?? [], true);
$envFile = dirname(__DIR__, 3) . '/wucportal-var/config/environment.php';
if (!is_file($envFile)) {
    fwrite(STDERR, "Missing {$envFile}\n");
    exit(1);
}
/** @var array<string,string> $env */
$env = require $envFile;
$db = new mysqli(
    $env['WUC_DB_HOST'] ?? '127.0.0.1',
    $env['WUC_DB_USER'] ?? 'root',
    $env['WUC_DB_PASSWORD'] ?? '',
    $env['WUC_DB_NAME'] ?? 'wucportal',
    (int)($env['WUC_DB_PORT'] ?? 3306)
);
if ($db->connect_error) {
    fwrite(STDERR, 'DB connect failed: ' . $db->connect_error . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');

echo ($apply ? 'APPLY' : 'DRY-RUN') . ": repair lecturer orphan assignments\n\n";

$orphans = [];
$r = $db->query(
    "SELECT cl.staff_id, cl.course_code, cl.status, cl.semester, cl.academic_year, cl.program_code
     FROM course_lecturer cl
     LEFT JOIN courses c ON c.course_code = cl.course_code
     WHERE c.course_code IS NULL
     ORDER BY cl.staff_id, cl.course_code"
);
while ($row = $r->fetch_assoc()) {
    $orphans[] = $row;
    echo 'ORPHAN: ' . json_encode($row) . "\n";
}

$dupes = [];
$r = $db->query(
    "SELECT staff_id, course_code, academic_year, COUNT(*) AS c
     FROM course_lecturer
     WHERE staff_id = 'ITC900' AND course_code = 'COM101'
     GROUP BY staff_id, course_code, academic_year"
);
while ($row = $r->fetch_assoc()) {
    $dupes[] = $row;
    echo 'COM101 ITC900 row: ' . json_encode($row) . "\n";
}

if (!$apply) {
    echo "\nDry-run only. Re-run with --apply to deactivate orphans and remove bad COM101 duplicate.\n";
    exit(0);
}

$db->begin_transaction();
try {
    $orphanUpdated = 0;
    if ($orphans) {
        $stmt = $db->prepare(
            "UPDATE course_lecturer cl
             LEFT JOIN courses c ON c.course_code = cl.course_code
             SET cl.status = 'inactive'
             WHERE c.course_code IS NULL AND COALESCE(cl.status, 'active') <> 'inactive'"
        );
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        $stmt->execute();
        $orphanUpdated = $stmt->affected_rows;
        $stmt->close();
    }

    $dupeDeleted = 0;
    $stmt = $db->prepare(
        "DELETE FROM course_lecturer
         WHERE staff_id = 'ITC900' AND course_code = 'COM101' AND academic_year = 1
         LIMIT 1"
    );
    $stmt->execute();
    $dupeDeleted = $stmt->affected_rows;
    $stmt->close();

    $db->commit();
    echo "\nDone: orphan_rows_deactivated={$orphanUpdated}, duplicate_com101_deleted={$dupeDeleted}\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/helpers/lecturer_course_helpers.php';
require_once dirname(__DIR__) . '/includes/lecturer_insights_engine.php';
require_once dirname(__DIR__) . '/includes/academic_risk_engine.php';

foreach (['ITC907', 'ITC900'] as $sid) {
    $codes = wuc_lecturer_resolved_course_codes($db, $sid);
    $insights = wuc_lecturer_insights($db, $sid);
    $risk = wuc_academic_risk_lecturer_summary($db, $sid);
    echo "{$sid}: resolved_courses=" . count($codes)
        . " insights_assigned=" . ($insights['totals']['assigned_courses'] ?? 0)
        . " insights_students=" . ($insights['totals']['registered_students'] ?? 0)
        . " risk_courses=" . count($risk['assigned_courses'] ?? [])
        . " risk_checked=" . ($risk['students_checked'] ?? 0) . "\n";
}
