<?php
/**
 * Migration: add external-catalogue metadata columns to `courses`.
 *
 * The `courses` table previously held only course_code/course_name/credits/status,
 * so scraped external-institution catalogues (e.g. ITC Lusaka) lost their
 * institution, category, duration, entry requirements, fees and source URL.
 * This adds those as nullable columns. WUC's own courses simply leave them NULL.
 *
 * Idempotent: each column is added only if missing (checked via information_schema),
 * so the migration is safe to re-run.
 */

require __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$columns = [
    'institution'        => "VARCHAR(150) NULL AFTER course_name",
    'category'           => "VARCHAR(100) NULL AFTER institution",
    'duration'           => "VARCHAR(50) NULL AFTER category",
    'entry_requirements' => "VARCHAR(255) NULL AFTER duration",
    'fees'               => "VARCHAR(100) NULL AFTER entry_requirements",
    'source_url'         => "VARCHAR(500) NULL AFTER fees",
];

$check = $db->prepare(
    "SELECT COUNT(*) AS c
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'courses'
        AND COLUMN_NAME = ?"
);

$added = 0;
$skipped = 0;
foreach ($columns as $name => $definition) {
    $check->bind_param('s', $name);
    $check->execute();
    $exists = (int) $check->get_result()->fetch_assoc()['c'] > 0;

    if ($exists) {
        echo "  - `$name` already exists, skipping\n";
        $skipped++;
        continue;
    }

    // Column name is from a fixed whitelist above, not user input.
    $db->query("ALTER TABLE `courses` ADD COLUMN `$name` $definition");
    echo "  + added `$name`\n";
    $added++;
}
$check->close();

echo "Migration complete. Added: $added, already present: $skipped.\n";
