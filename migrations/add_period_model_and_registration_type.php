<?php
/**
 * Migration: add the missing period model and generalize registration to terms.
 *
 *  1. Creates `academic_periods` (the unified period model that code references
 *     but which was missing from the live DB) supporting both semester and term
 *     periods, and seeds a current period of each type.
 *  2. Adds `period_type` ENUM('semester','term') to `semester_registration` so
 *     the same table can record term registrations. Existing rows default to
 *     'semester', so the current semester flow is unaffected.
 *
 * Idempotent and non-destructive — safe to re-run.
 */

require __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ── 1. academic_periods ───────────────────────────────────────────────
$db->query("
    CREATE TABLE IF NOT EXISTS academic_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        academic_year VARCHAR(20) NOT NULL,
        period_type ENUM('semester','term') NOT NULL DEFAULT 'semester',
        period_number TINYINT UNSIGNED NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        is_current TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('upcoming','open','closed') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_period (academic_year, period_type, period_number),
        KEY idx_current (is_current)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "academic_periods table ready.\n";

// Seed one current period per type if none exist yet.
$count = (int) $db->query("SELECT COUNT(*) c FROM academic_periods")->fetch_assoc()['c'];
if ($count === 0) {
    // Derive an academic year label spanning the current calendar year.
    $y = (int) date('Y');
    $ay = ($y - 1) . '/' . $y; // e.g. 2025/2026
    $stmt = $db->prepare("
        INSERT INTO academic_periods (academic_year, period_type, period_number, is_current, status)
        VALUES (?, ?, ?, 1, 'open')
    ");
    foreach (['semester', 'term'] as $type) {
        $num = 1;
        $stmt->bind_param('ssi', $ay, $type, $num);
        $stmt->execute();
    }
    $stmt->close();
    echo "Seeded current semester + term periods for $ay.\n";
} else {
    echo "academic_periods already has $count row(s); not seeding.\n";
}

// ── 2. semester_registration.period_type ──────────────────────────────
$check = $db->prepare("
    SELECT COUNT(*) c FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'semester_registration' AND COLUMN_NAME = 'period_type'
");
$check->execute();
$exists = (int) $check->get_result()->fetch_assoc()['c'] > 0;
$check->close();

if ($exists) {
    echo "`period_type` already on semester_registration.\n";
} else {
    $db->query("ALTER TABLE semester_registration ADD COLUMN period_type ENUM('semester','term') NOT NULL DEFAULT 'semester' AFTER semester");
    echo "Added `period_type` to semester_registration.\n";
}

echo "Migration complete.\n";
