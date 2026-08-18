<?php
/**
 * Test Timetable module schema.
 *
 * Tables:
 *   - assessment_periods  (activation window + lifecycle status)
 *   - test_timetable      (individual test slots)
 *
 * Run: php migrations/20260804_test_timetable.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function tt_mig_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

$statements = [
    "CREATE TABLE IF NOT EXISTS assessment_periods (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        academic_year VARCHAR(20) NOT NULL,
        term_label VARCHAR(80) NOT NULL,
        academic_period_id INT UNSIGNED NULL,
        scheduling_start_date DATE NOT NULL,
        publication_date DATE NOT NULL,
        test_start_date DATE NOT NULL,
        test_end_date DATE NOT NULL,
        status ENUM('draft','scheduling','published','active','closed','archived') NOT NULL DEFAULT 'draft',
        created_by VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ap_year_status (academic_year, status),
        KEY idx_ap_pub_end (publication_date, test_end_date),
        KEY idx_ap_academic_period (academic_period_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS test_timetable (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        assessment_period_id INT UNSIGNED NOT NULL,
        academic_year VARCHAR(20) NOT NULL,
        term_label VARCHAR(80) NOT NULL,
        test_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        program_code VARCHAR(50) NOT NULL,
        year_of_study TINYINT UNSIGNED NOT NULL DEFAULT 1,
        course_code VARCHAR(50) NOT NULL,
        section_label VARCHAR(80) NULL,
        classroom_id INT UNSIGNED NULL,
        lecturer_staff_id VARCHAR(50) NULL,
        status ENUM('draft','scheduled','cancelled') NOT NULL DEFAULT 'scheduled',
        created_by VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_tt_period_course_prog_year (assessment_period_id, course_code, program_code, year_of_study),
        KEY idx_tt_period (assessment_period_id),
        KEY idx_tt_date_time (test_date, start_time, end_time),
        KEY idx_tt_program_year (program_code, year_of_study),
        KEY idx_tt_course (course_code),
        KEY idx_tt_lecturer (lecturer_staff_id),
        KEY idx_tt_room (classroom_id),
        CONSTRAINT fk_tt_period FOREIGN KEY (assessment_period_id)
            REFERENCES assessment_periods (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($statements as $sql) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS `?([A-Za-z0-9_]+)`?/i', $sql, $m)
        && tt_mig_table_exists($db, $m[1])) {
        echo "exists: {$m[1]}\n";
        continue;
    }
    $db->query($sql);
    echo "applied: " . ($m[1] ?? 'statement') . "\n";
}

echo "DONE\n";
