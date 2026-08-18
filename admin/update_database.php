<?php
require_once __DIR__ . '/../includes/production_guards.php';
wuc_require_cli_only();
include "../includes/config.php";

try {
    // Add new columns to programs table
    $db->query("ALTER TABLE programs ADD COLUMN IF NOT EXISTS program_type ENUM('semester', 'intake', 'short') NOT NULL DEFAULT 'semester'");
    $db->query("ALTER TABLE programs ADD COLUMN IF NOT EXISTS program_duration DECIMAL(4,2) DEFAULT NULL COMMENT 'Duration in years'");

    // Create program_courses table
    $db->query("CREATE TABLE IF NOT EXISTS program_courses (
        id INT PRIMARY KEY AUTO_INCREMENT,
        program_id INT NOT NULL,
        course_name VARCHAR(255) NOT NULL,
        course_code VARCHAR(50) NOT NULL,
        term_number INT,
        FOREIGN KEY (program_id) REFERENCES programs(id)
    )");

    echo "Database updated successfully!";
} catch (Exception $e) {
    echo "Error updating database: " . $e->getMessage();
}
?> 
