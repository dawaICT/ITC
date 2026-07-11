<?php
/**
 * Automated Database Backup Utility (Pure PHP)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/connect.php';

echo "Starting database backup process...\n";

// Resolve backup directory
$backupDir = 'C:\\xampp\\wucportal-var\\backups';
if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0755, true)) {
        echo "[ERROR] Failed to create backups directory.\n";
        exit(1);
    }
}

$backupFile = $backupDir . DIRECTORY_SEPARATOR . 'wucportal_backup_' . date('Y-m-d_H-i-s') . '.sql';
$fp = fopen($backupFile, 'w');
if (!$fp) {
    echo "[ERROR] Failed to open backup file for writing.\n";
    exit(1);
}

// Write file header
fwrite($fp, "-- ITC WUCPortal Automated Database Backup\n");
fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
fwrite($fp, "-- Database: wucportal\n\n");
fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

// Fetch all tables
$tables = [];
if ($res = $db->query("SHOW TABLES")) {
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
    $res->free();
}

foreach ($tables as $table) {
    echo "Processing table: $table...\n";
    
    // Write table drop/create schema
    fwrite($fp, "-- -----------------------------------------------------\n");
    fwrite($fp, "-- Table structure for `$table`\n");
    fwrite($fp, "-- -----------------------------------------------------\n");
    fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
    
    $resCreate = $db->query("SHOW CREATE TABLE `$table`");
    if ($resCreate) {
        $rowCreate = $resCreate->fetch_row();
        fwrite($fp, $rowCreate[1] . ";\n\n");
        $resCreate->free();
    }
    
    // Write table insert records
    fwrite($fp, "-- Records of `$table`\n");
    $resData = $db->query("SELECT * FROM `$table`");
    if ($resData) {
        $fieldsCount = $resData->field_count;
        while ($row = $resData->fetch_row()) {
            $vals = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $vals[] = "NULL";
                } else {
                    $vals[] = "'" . $db->real_escape_string((string)$val) . "'";
                }
            }
            fwrite($fp, "INSERT INTO `$table` VALUES (" . implode(", ", $vals) . ");\n");
        }
        fwrite($fp, "\n");
        $resData->free();
    }
}

fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fp);

echo "[SUCCESS] Database backup saved to: $backupFile\n";
?>
