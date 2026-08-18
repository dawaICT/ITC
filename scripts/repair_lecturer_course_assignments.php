<?php
declare(strict_types=1);

/**
 * Promote orphan lecturer_courses rows into course_lecturer and report state.
 * CLI only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/helpers/lecturer_course_helpers.php';

$changed = wuc_sync_legacy_lecturer_courses_table($db);
echo "Synced orphan lecturer_courses → course_lecturer: {$changed} change(s)\n";

$r = $db->query(
    "SELECT lc.lecturer_id, lc.course_code
     FROM lecturer_courses lc
     LEFT JOIN course_lecturer cl
       ON cl.staff_id = lc.lecturer_id AND cl.course_code = lc.course_code
     WHERE cl.id IS NULL"
);
$remaining = 0;
while ($row = $r->fetch_assoc()) {
    echo 'REMAINING: ' . $row['lecturer_id'] . '|' . $row['course_code'] . PHP_EOL;
    $remaining++;
}
echo "Remaining orphans: {$remaining}\n";

$nullCtx = (int)$db->query(
    "SELECT COUNT(*) c FROM course_lecturer WHERE COALESCE(program_code,'') = ''"
)->fetch_assoc()['c'];
echo "Null-context course_lecturer rows: {$nullCtx}\n";
echo "Done.\n";
