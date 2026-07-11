<?php
/**
 * Database Migration - Program Academic Structures (with DDL/Root permissions)
 * Adds academic structure fields to the programs table and seeds defaults.
 */

echo "Starting database migration for programs table...\n";

// 1. Establish DDL connection as root
try {
    $db = new mysqli('127.0.0.1', 'root', '', 'wucportal', 3306);
    if ($db->connect_error) {
        throw new Exception($db->connect_error);
    }
    $db->set_charset("utf8mb4");
} catch (Exception $e) {
    echo "Root connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Add columns to programs table
$columns_to_add = [
    'academic_structure' => "VARCHAR(50) DEFAULT NULL AFTER period_mode",
    'duration_value' => "INT(11) DEFAULT NULL AFTER academic_structure",
    'duration_unit' => "VARCHAR(20) DEFAULT NULL AFTER duration_value",
    'uses_terms' => "TINYINT(1) DEFAULT 0 AFTER duration_unit",
    'uses_semesters' => "TINYINT(1) DEFAULT 0 AFTER uses_terms",
    'is_short_course' => "TINYINT(1) DEFAULT 0 AFTER uses_semesters",
    'is_transport_exception' => "TINYINT(1) DEFAULT 0 AFTER is_short_course",
    'examination_type' => "ENUM('external', 'internal') DEFAULT 'external' AFTER is_transport_exception"
];

foreach ($columns_to_add as $colName => $definition) {
    // Check if column already exists
    $check = $db->query("SHOW COLUMNS FROM programs LIKE '$colName'");
    if ($check && $check->num_rows > 0) {
        echo "Column '$colName' already exists. Skipping.\n";
    } else {
        echo "Adding column '$colName'...\n";
        $alter = $db->query("ALTER TABLE programs ADD COLUMN `$colName` $definition");
        if ($alter) {
            echo "Column '$colName' added successfully.\n";
        } else {
            echo "Error adding column '$colName': " . $db->error . "\n";
            exit(1);
        }
    }
}

// 3. Map existing programs to their appropriate academic structure
echo "Migrating existing program configurations...\n";

// Update Certificate programs (term-based)
$stmt1 = $db->query("UPDATE programs SET 
    academic_structure = 'certificate_term',
    uses_terms = 1,
    uses_semesters = 0,
    is_short_course = 0,
    is_transport_exception = 0,
    examination_type = 'external'
    WHERE LOWER(program_type) = 'certificate' AND program_code NOT IN ('DTL', 'TRANS-014')");

if ($stmt1) {
    echo "Updated Certificate programs.\n";
}

// Update Diploma programs (term-based, except Transport and Logistics)
$stmt2 = $db->query("UPDATE programs SET 
    academic_structure = 'diploma_term',
    uses_terms = 1,
    uses_semesters = 0,
    is_short_course = 0,
    is_transport_exception = 0,
    examination_type = 'external'
    WHERE LOWER(program_type) = 'diploma' AND program_code NOT IN ('DTL', 'TRANS-014')");

if ($stmt2) {
    echo "Updated Diploma programs.\n";
}

// Update Transport and Logistics programs (semester-based exception)
$stmt3 = $db->query("UPDATE programs SET 
    academic_structure = 'semester_exception',
    uses_terms = 0,
    uses_semesters = 1,
    is_short_course = 0,
    is_transport_exception = 1,
    examination_type = 'external',
    period_mode = 'semester'
    WHERE program_code IN ('DTL', 'TRANS-014')");

if ($stmt3) {
    echo "Updated Transport and Logistics exception programs (DTL/TRANS-014).\n";
}

echo "Database migration completed successfully!\n";
?>
