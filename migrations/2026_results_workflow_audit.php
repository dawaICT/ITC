<?php
/**
 * Migration: results status workflow + audit trail.
 *
 * Adds the workflow/audit columns the improved result-entry logic needs to the
 * canonical marks table (semester_assessment), and creates the result_audit_log
 * table that records every result action (who entered / changed / approved /
 * published a result and why).
 *
 * Idempotent: each column/table is added only when missing, so re-running is safe.
 * DDL must be applied by a user with ALTER/CREATE privileges (locally: root;
 * production: wucportal_migrator) — see includes/schema_guard.php.
 */
require_once __DIR__ . '/../db/connect.php';

/** @var mysqli $db */
function mig_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->num_rows;
    $stmt->close();
    return $exists;
}

echo "Running results workflow + audit migration...\n";

if (!function_exists('wuc_table_exists') || !wuc_table_exists($db, 'semester_assessment')) {
    fwrite(STDERR, "semester_assessment table not found; aborting.\n");
    exit(1);
}

// 1. Workflow / audit columns on the canonical marks table.
//    posted_by already records who ENTERED the mark, so it is reused as-is.
$columns = [
    'submitted_by'     => "VARCHAR(50)  NULL DEFAULT NULL AFTER posted_by",
    'submitted_at'     => "DATETIME     NULL DEFAULT NULL AFTER submitted_by",
    'published_by'     => "VARCHAR(80)  NULL DEFAULT NULL AFTER approved_at",
    'published_at'     => "DATETIME     NULL DEFAULT NULL AFTER published_by",
    'rejection_reason' => "VARCHAR(255) NULL DEFAULT NULL AFTER published_at",
];
foreach ($columns as $column => $definition) {
    if (mig_column_exists($db, 'semester_assessment', $column)) {
        echo "  semester_assessment.{$column} already present.\n";
        continue;
    }
    // AFTER references can fail if a prior column is absent; retry without it.
    $alter = "ALTER TABLE semester_assessment ADD COLUMN `{$column}` {$definition}";
    try {
        $db->query($alter);
        echo "  Added semester_assessment.{$column}.\n";
    } catch (Throwable $e) {
        $fallback = preg_replace('/\s+AFTER\s+\w+$/i', '', $definition);
        try {
            $db->query("ALTER TABLE semester_assessment ADD COLUMN `{$column}` {$fallback}");
            echo "  Added semester_assessment.{$column} (without position).\n";
        } catch (Throwable $e2) {
            fwrite(STDERR, "  Could not add semester_assessment.{$column}: " . $e2->getMessage() . "\n");
        }
    }
}

// 2. Audit trail table — one row per result action.
$auditSql = "CREATE TABLE IF NOT EXISTS result_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    assessment_id BIGINT UNSIGNED NULL,
    Sid VARCHAR(50) NOT NULL,
    Course_Code VARCHAR(50) NOT NULL,
    semester VARCHAR(20) NULL,
    Year VARCHAR(10) NULL,
    action VARCHAR(32) NOT NULL,
    field_changed VARCHAR(40) NULL,
    old_value VARCHAR(255) NULL,
    new_value VARCHAR(255) NULL,
    reason VARCHAR(255) NULL,
    actor_staff_id VARCHAR(50) NULL,
    actor_role VARCHAR(40) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_assessment (assessment_id),
    INDEX idx_audit_student (Sid, Course_Code, semester, Year),
    INDEX idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
try {
    $db->query($auditSql);
    echo "  result_audit_log table created/verified.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "  Could not create result_audit_log: " . $e->getMessage() . "\n");
}

echo "Migration complete.\n";
