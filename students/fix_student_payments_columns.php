<?php
/**
 * Migration helper: add missing columns to student_payments table
 * Run this once from the project root: php students/fix_student_payments_columns.php
 */

// Load configuration constants (DB_HOST, DB_USER, etc.) and Database class
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';

echo "Checking student_payments table columns...\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    $needed = [
        'invoice' => "VARCHAR(100) NULL",
        'narration' => "TEXT NULL",
        'Year' => "VARCHAR(10) NULL",
        'dte_time' => "DATETIME NULL"
    ];

    $stmt = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_payments'");
    $stmt->execute();
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $toAdd = [];
    foreach ($needed as $col => $definition) {
        if (!in_array($col, $cols, true)) {
            $toAdd[$col] = $definition;
        }
    }

    if (empty($toAdd)) {
        echo "All required columns already exist.\n";
        exit(0);
    }

    foreach ($toAdd as $col => $def) {
        $sql = "ALTER TABLE student_payments ADD COLUMN `$col` $def";
        echo "Adding column $col... ";
        $conn->exec($sql);
        echo "done.\n";
    }

    echo "Migration complete.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

?>
