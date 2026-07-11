<?php
/**
 * Migration Script for Phase 2 Academic Structure Refactoring.
 * Creates config tables, seeds academic periods, updates programs,
 * corrects legacy period types, and sets up automatic triggers.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts\migrate_academic_settings.php
 */

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';

if (php_sapi_name() !== 'cli') {
    echo "Run this script from the command line.\n";
    exit(1);
}

echo "Starting Phase 2 database migrations...\n";

// 1. Create academic_settings table
$db->query("
    CREATE TABLE IF NOT EXISTS academic_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        description VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "1. Checked/Created 'academic_settings' table.\n";

// 2. Insert default settings
$settings = [
    ['transport_semester_duration_months', '6', 'Duration of a semester in months for Transport exception programs'],
    ['default_term_duration_months', '3', 'Default duration of a term in months for TEVETA programs'],
    ['default_semester_duration_months', '6', 'Default duration of a semester in months'],
    ['short_course_max_duration_months', '6', 'Maximum duration in months allowed for a short course'],
    ['default_examination_type', 'external', 'Default examination type for programs (internal/external)']
];

$stmt = $db->prepare("INSERT INTO academic_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE description=VALUES(description)");
foreach ($settings as $s) {
    $stmt->bind_param("sss", $s[0], $s[1], $s[2]);
    $stmt->execute();
}
$stmt->close();
echo "2. Seeded academic settings rows.\n";

// 3. Align programs table
// Set DTL & TRANS-014 to semester-based
$db->query("
    UPDATE programs 
    SET period_mode = 'semester', 
        is_transport_exception = 1, 
        uses_semesters = 1, 
        uses_terms = 0,
        examination_type = 'external'
    WHERE program_code IN ('DTL', 'TRANS-014')
");

// Set other non-short-course programs to term-based
$db->query("
    UPDATE programs 
    SET period_mode = 'term', 
        is_transport_exception = 0, 
        uses_semesters = 0, 
        uses_terms = 1,
        examination_type = 'external'
    WHERE program_code NOT IN ('DTL', 'TRANS-014') AND (is_short_course IS NULL OR is_short_course = 0)
");
echo "3. Aligned program parameters (Transport -> semester, others -> term).\n";

// 4. Seed academic periods for 2026
$periods = [
    ['2026', 'semester', '1', '2026-01-01', '2026-06-30', 1, 'active'],
    ['2026', 'semester', '2', '2026-07-01', '2026-12-31', 0, 'upcoming'],
    ['2026', 'term', '1', '2026-01-01', '2026-04-30', 0, 'completed'],
    ['2026', 'term', '2', '2026-05-01', '2026-08-31', 1, 'active'],
    ['2026', 'term', '3', '2026-09-01', '2026-12-31', 0, 'upcoming']
];

foreach ($periods as $p) {
    // Check if period already exists
    $chk = $db->prepare("SELECT id FROM academic_periods WHERE academic_year = ? AND period_type = ? AND semester_term = ? LIMIT 1");
    $chk->bind_param("sss", $p[0], $p[1], $p[2]);
    $chk->execute();
    $res = $chk->get_result();
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $upd = $db->prepare("UPDATE academic_periods SET start_date = ?, end_date = ?, is_current = ?, status = ? WHERE id = ?");
        $upd->bind_param("ssisi", $p[3], $p[4], $p[5], $p[6], $row['id']);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $db->prepare("INSERT INTO academic_periods (academic_year, period_type, semester_term, start_date, end_date, is_current, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ins->bind_param("sssssis", $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6]);
        $ins->execute();
        $ins->close();
    }
    $chk->close();
}
echo "4. Seeded/Updated 2026 academic periods.\n";

// 5. Update existing semester_registration records' period_type
$db->query("
    UPDATE semester_registration sr 
    JOIN programs p ON p.program_code = sr.program_code 
    SET sr.period_type = p.period_mode
");
echo "5. Aligned period_type columns on existing registrations.\n";

// 6. Create Triggers to automate future inserts/updates
// MySQL BEFORE INSERT Trigger
$db->query("DROP TRIGGER IF EXISTS trg_semester_registration_period_type");
$db->query("
    CREATE TRIGGER trg_semester_registration_period_type
    BEFORE INSERT ON semester_registration
    FOR EACH ROW
    BEGIN
        DECLARE p_mode VARCHAR(50);
        SELECT period_mode INTO p_mode FROM programs WHERE program_code = NEW.program_code LIMIT 1;
        IF p_mode IS NOT NULL THEN
            SET NEW.period_type = p_mode;
        END IF;
    END;
");

// MySQL BEFORE UPDATE Trigger
$db->query("DROP TRIGGER IF EXISTS trg_semester_registration_period_type_update");
$db->query("
    CREATE TRIGGER trg_semester_registration_period_type_update
    BEFORE UPDATE ON semester_registration
    FOR EACH ROW
    BEGIN
        DECLARE p_mode VARCHAR(50);
        SELECT period_mode INTO p_mode FROM programs WHERE program_code = NEW.program_code LIMIT 1;
        IF p_mode IS NOT NULL THEN
            SET NEW.period_type = p_mode;
        END IF;
    END;
");
echo "6. Created MySQL BEFORE INSERT and BEFORE UPDATE triggers on 'semester_registration'.\n";

echo "Database migrations completed successfully!\n";
?>
