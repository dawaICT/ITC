<?php
declare(strict_types=1);

/**
 * Backfill: mirror short_courses into courses catalogue.
 * CLI only. Does not invent lecturer assignments.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/short_course_db.php';

$mirrored = 0;
$skipped = 0;
$failed = 0;

$res = $db->query('SELECT id, course_code, course_name, fee, status FROM short_courses ORDER BY id');
if (!$res) {
    fwrite(STDERR, "Cannot read short_courses\n");
    exit(1);
}

while ($row = $res->fetch_assoc()) {
    $code = trim((string)$row['course_code']);
    if ($code === '' || strlen($code) > 20) {
        $skipped++;
        echo "SKIP long/empty code id={$row['id']} code={$code}\n";
        continue;
    }
    if (sc_ensure_courses_mirror($db, $row)) {
        $mirrored++;
    } else {
        $failed++;
        echo "FAIL id={$row['id']} code={$code}\n";
    }
}

echo "Mirrored={$mirrored} skipped={$skipped} failed={$failed}\n";

// Verify a known enrollment course is mirrored and assignable.
$r = $db->query(
    "SELECT sc.id, sc.course_code,
            (SELECT COUNT(*) FROM courses c WHERE c.course_code = sc.course_code) AS in_courses,
            (SELECT COUNT(*) FROM course_lecturer cl WHERE cl.course_code = sc.course_code) AS lecturers
     FROM short_courses sc
     INNER JOIN short_course_enrollments e ON e.short_course_id = sc.id
     LIMIT 5"
);
while ($row = $r->fetch_assoc()) {
    echo 'ENROLLMENT_COURSE ' . implode('|', $row) . PHP_EOL;
}
