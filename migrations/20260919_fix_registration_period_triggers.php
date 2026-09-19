<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this migration from the command line.');
}
define('IS_SCRIPT', true);
require_once dirname(__DIR__) . '/db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// MariaDB/XAMPP: replace each trigger atomically; rerunning is safe.
// Do not rewrite historical registrations or advance any student here.
foreach (['INSERT' => 'trg_semester_registration_period_type',
          'UPDATE' => 'trg_semester_registration_period_type_update'] as $event => $name) {
    $db->query("CREATE OR REPLACE TRIGGER `{$name}` BEFORE {$event} ON semester_registration
        FOR EACH ROW
        BEGIN
            DECLARE resolved_type VARCHAR(30);
            SELECT CASE
                WHEN structure_type = 'TERM_BASED' THEN 'term'
                WHEN structure_type = 'SEMESTER_BASED' THEN 'semester'
                WHEN structure_type = 'SHORT_COURSE' THEN 'short_course_cycle'
                WHEN structure_type = 'TRADE_TEST_LEVEL' THEN 'trade_test_level'
                WHEN is_short_course = 1 THEN 'short_course_cycle'
                WHEN period_mode = 'semester' OR uses_semesters = 1 THEN 'semester'
                ELSE 'term'
            END INTO resolved_type
            FROM programs WHERE program_code = NEW.program_code LIMIT 1;
            IF resolved_type IS NOT NULL THEN
                SET NEW.period_type = resolved_type;
            END IF;
        END");
    echo "Updated {$name}.\n";
}
