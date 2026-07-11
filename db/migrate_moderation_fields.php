<?php
/**
 * Database Migration - Phase 8 Exam Moderation Fields
 */

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';

if (!isset($db) || $db->connect_error) {
    die("Database connection failed.\n");
}

echo "=== Moderation Fields Migration Started ===\n";

function column_exists(mysqli $db, string $table, string $column): bool {
    $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

$columnsToAdd = [
    'internal_moderation_status' => "ALTER TABLE `semester_assessment` ADD COLUMN `internal_moderation_status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER `published_at`HTML",
    'internal_moderator_id'      => "ALTER TABLE `semester_assessment` ADD COLUMN `internal_moderator_id` VARCHAR(64) NULL AFTER `internal_moderation_status`",
    'internal_moderated_at'      => "ALTER TABLE `semester_assessment` ADD COLUMN `internal_moderated_at` DATETIME NULL AFTER `internal_moderator_id`",
    'internal_moderation_notes'   => "ALTER TABLE `semester_assessment` ADD COLUMN `internal_moderation_notes` TEXT NULL AFTER `internal_moderated_at`",
    'external_moderation_status' => "ALTER TABLE `semester_assessment` ADD COLUMN `external_moderation_status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER `internal_moderation_notes`",
    'external_moderator_id'      => "ALTER TABLE `semester_assessment` ADD COLUMN `external_moderator_id` VARCHAR(64) NULL AFTER `external_moderation_status`",
    'external_moderated_at'      => "ALTER TABLE `semester_assessment` ADD COLUMN `external_moderated_at` DATETIME NULL AFTER `external_moderator_id`",
    'external_moderation_notes'   => "ALTER TABLE `semester_assessment` ADD COLUMN `external_moderation_notes` TEXT NULL AFTER `external_moderated_at`"
];

// Clean HTML typo in ENUM definition
$columnsToAdd['internal_moderation_status'] = "ALTER TABLE `semester_assessment` ADD COLUMN `internal_moderation_status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER `published_at`";

foreach ($columnsToAdd as $col => $sql) {
    if (!column_exists($db, 'semester_assessment', $col)) {
        if ($db->query($sql)) {
            echo "[PASS] Column '$col' successfully added.\n";
        } else {
            die("[FAIL] Adding column '$col': " . $db->error . "\n");
        }
    } else {
        echo "[NOTE] Column '$col' already exists.\n";
    }
}

echo "=== Moderation Fields Migration Completed Successfully ===\n";
?>
