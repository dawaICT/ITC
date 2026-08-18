<?php
declare(strict_types=1);

/**
 * Repair curriculum course credit values used by progression GPA calculations.
 * Live schema uses courses.credits (not credit_hours). Zero/NULL credits were
 * collapsing earned-credit totals for published exam modules.
 */

require_once dirname(__DIR__) . '/db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db->query(
    "UPDATE courses c
        INNER JOIN program_courses pc ON pc.course_code = c.course_code
        SET c.credits = 3
      WHERE COALESCE(c.credits, 0) = 0"
);

echo 'Repaired course credits for curriculum modules. Rows affected: ' . $db->affected_rows . PHP_EOL;
