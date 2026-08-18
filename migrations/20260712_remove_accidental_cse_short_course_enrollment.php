<?php
/**
 * Remove the exact short-course test enrolment accidentally attached to the
 * Diploma test student. All identifying fields are matched so a legitimate
 * enrolment cannot be removed if the row has since been repurposed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$studentId = 'CSE26456789';
$courseCode = 'PRN1130';
$enrolledBy = 'ITC907';
$notes = 'e2e test';

$stmt = $db->prepare(
    "DELETE e
       FROM short_course_enrollments e
       INNER JOIN short_courses sc ON sc.id = e.short_course_id
      WHERE e.student_id = ?
        AND sc.course_code = ?
        AND e.enrolled_by = ?
        AND e.notes = ?"
);
$stmt->bind_param('ssss', $studentId, $courseCode, $enrolledBy, $notes);
$stmt->execute();
$removed = $stmt->affected_rows;
$stmt->close();

echo "Removed accidental short-course enrolments: {$removed}\n";
