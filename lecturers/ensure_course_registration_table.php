<?php
/**
 * Migration Script: Ensure course_registration table structure
 * 
 * This script creates or updates the course_registration table to support
 * the CA Upload Module's fee validation and student registration checks.
 * 
 * Run this once via CLI: php ensure_course_registration_table.php
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    die("Database connection failed.\n");
}

echo "Starting course_registration table migration...\n\n";

// Helper functions
$tableExists = function(mysqli $db, string $table): bool {
    $res = $db->query("SHOW TABLES LIKE '".$db->real_escape_string($table)."'");
    if ($res) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
};

$columnExists = function(mysqli $db, string $table, string $column): bool {
    $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '".$db->real_escape_string($column)."'");
    if ($res) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
};

// Create table if it doesn't exist
if (!$tableExists($db, 'course_registration')) {
    echo "Creating course_registration table...\n";
    $sql = "CREATE TABLE `course_registration` (
        `registration_id` INT AUTO_INCREMENT PRIMARY KEY,
        `Sid` VARCHAR(50) NOT NULL COMMENT 'Student ID',
        `course_code` VARCHAR(50) NOT NULL COMMENT 'Course code',
        `semester` VARCHAR(20) NOT NULL COMMENT 'Semester/Term (1, 2, 3)',
        `Year` VARCHAR(10) NOT NULL COMMENT 'Academic year (1, 2, 3, 4)',
        `program_type` VARCHAR(20) DEFAULT 'semester' COMMENT 'semester or term',
        `study_mode` ENUM('Full-time', 'Part-time', 'Distance') DEFAULT 'Full-time',
        `tuition_total` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Total tuition for this course',
        `amount_paid` DECIMAL(10, 2) DEFAULT 0.00 COMMENT 'Amount paid by student',
        `is_active` TINYINT(1) DEFAULT 1 COMMENT '1=active, 0=inactive/deferred',
        `registration_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_student (`Sid`),
        INDEX idx_course (`course_code`),
        INDEX idx_student_course (`Sid`, `course_code`, `semester`, `Year`),
        UNIQUE KEY unique_registration (`Sid`, `course_code`, `semester`, `Year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Student course registrations with fee tracking'";
    
    if ($db->query($sql)) {
        echo "✓ Table created successfully.\n";
    } else {
        echo "✗ Error creating table: " . $db->error . "\n";
        exit(1);
    }
} else {
    echo "Table course_registration already exists. Checking columns...\n";
    
    // Add missing columns to existing table
    $columnsToAdd = [
        'program_type' => "ALTER TABLE `course_registration` ADD COLUMN `program_type` VARCHAR(20) DEFAULT 'semester' COMMENT 'semester or term'",
        'study_mode' => "ALTER TABLE `course_registration` ADD COLUMN `study_mode` ENUM('Full-time', 'Part-time', 'Distance') DEFAULT 'Full-time'",
        'tuition_total' => "ALTER TABLE `course_registration` ADD COLUMN `tuition_total` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Total tuition for this course'",
        'amount_paid' => "ALTER TABLE `course_registration` ADD COLUMN `amount_paid` DECIMAL(10, 2) DEFAULT 0.00 COMMENT 'Amount paid by student'",
        'is_active' => "ALTER TABLE `course_registration` ADD COLUMN `is_active` TINYINT(1) DEFAULT 1 COMMENT '1=active, 0=inactive/deferred'",
        'registration_date' => "ALTER TABLE `course_registration` ADD COLUMN `registration_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE `course_registration` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"
    ];
    
    foreach ($columnsToAdd as $col => $sql) {
        if (!$columnExists($db, 'course_registration', $col)) {
            echo "  Adding column: $col... ";
            if ($db->query($sql)) {
                echo "✓\n";
            } else {
                echo "✗ Error: " . $db->error . "\n";
            }
        } else {
            echo "  Column $col already exists ✓\n";
        }
    }
    
    // Ensure indexes exist
    echo "\nChecking indexes...\n";
    
    $checkIndex = function(mysqli $db, string $table, string $indexName): bool {
        $res = $db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '".$db->real_escape_string($indexName)."'");
        if ($res) {
            $exists = $res->num_rows > 0;
            $res->free();
            return $exists;
        }
        return false;
    };
    
    if (!$checkIndex($db, 'course_registration', 'idx_student')) {
        echo "  Adding index idx_student... ";
        if ($db->query("ALTER TABLE `course_registration` ADD INDEX idx_student (`Sid`)")) {
            echo "✓\n";
        } else {
            echo "✗ Error: " . $db->error . "\n";
        }
    } else {
        echo "  Index idx_student exists ✓\n";
    }
    
    if (!$checkIndex($db, 'course_registration', 'idx_course')) {
        echo "  Adding index idx_course... ";
        if ($db->query("ALTER TABLE `course_registration` ADD INDEX idx_course (`course_code`)")) {
            echo "✓\n";
        } else {
            echo "✗ Error: " . $db->error . "\n";
        }
    } else {
        echo "  Index idx_course exists ✓\n";
    }
    
    if (!$checkIndex($db, 'course_registration', 'idx_student_course')) {
        echo "  Adding composite index idx_student_course... ";
        if ($db->query("ALTER TABLE `course_registration` ADD INDEX idx_student_course (`Sid`, `course_code`, `semester`, `Year`)")) {
            echo "✓\n";
        } else {
            echo "✗ Error: " . $db->error . "\n";
        }
    } else {
        echo "  Index idx_student_course exists ✓\n";
    }
}

// Verify final structure
echo "\n" . str_repeat("=", 60) . "\n";
echo "Final table structure:\n";
echo str_repeat("=", 60) . "\n";

$result = $db->query("DESCRIBE course_registration");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        printf("%-20s %-20s %s\n", $row['Field'], $row['Type'], $row['Null'] === 'NO' ? 'NOT NULL' : 'NULL');
    }
    $result->free();
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Migration completed successfully!\n";
echo str_repeat("=", 60) . "\n";

echo "\nNext steps:\n";
echo "1. Update the is_student_allowed_ca() function in finance_guard.php\n";
echo "2. Ensure student registration data includes tuition_total and amount_paid\n";
echo "3. Test CA upload with students at various payment levels\n";

$db->close();
?>
