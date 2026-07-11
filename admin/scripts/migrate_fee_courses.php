<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$root = dirname(__DIR__, 1); // admin/
$base = dirname($root); // project root

require_once $base . '/db/connect.php'; // $db mysqli

function columnExists(mysqli $db, string $table, string $column): bool {
    $schema = $db->real_escape_string($db->query('SELECT DATABASE() as db')->fetch_assoc()['db']);
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?");
    $stmt->bind_param('sss', $schema, $table, $column);
    $stmt->execute();
    $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    return $cnt > 0;
}

function indexExists(mysqli $db, string $table, string $indexName): bool {
    $stmt = $db->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
    $stmt->bind_param('s', $indexName);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function fkExists(mysqli $db, string $table, string $constraintName): bool {
    $stmt = $db->prepare("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ? AND TABLE_NAME = ?");
    $stmt->bind_param('ss', $constraintName, $table);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

$db->begin_transaction();
try {
    // Ensure base tables exist
    $db->query("CREATE TABLE IF NOT EXISTS programs (
        program_code VARCHAR(20) NOT NULL PRIMARY KEY,
        program_name VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS program_courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_code VARCHAR(20) NOT NULL,
        course_code VARCHAR(20) NOT NULL,
        course_name VARCHAR(255) NOT NULL,
        semester INT NOT NULL,
        UNIQUE KEY unique_course_per_program (program_code, course_code),
        CONSTRAINT fk_program_courses_program FOREIGN KEY (program_code)
            REFERENCES programs(program_code) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // fee_structure may not exist; create minimal table if needed
    $db->query("CREATE TABLE IF NOT EXISTS fee_structure (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_code VARCHAR(20) NOT NULL,
        year_of_study INT(1) NOT NULL,
        semester INT NOT NULL,
        fee_description VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fee_structure_ibfk_1 FOREIGN KEY (program_code) REFERENCES programs(program_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure columns
    if (!columnExists($db, 'fee_structure', 'course_code')) {
        $db->query("ALTER TABLE fee_structure ADD COLUMN course_code VARCHAR(20) NULL AFTER program_code");
    }
    if (!columnExists($db, 'fee_structure', 'status')) {
        $db->query("ALTER TABLE fee_structure ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER amount");
    }

    // Unique index: drop old if present and create new covering course_code
    if (indexExists($db, 'fee_structure', 'unique_fee')) {
        $db->query("ALTER TABLE fee_structure DROP INDEX unique_fee");
    }
    $db->query("ALTER TABLE fee_structure ADD UNIQUE KEY unique_fee (program_code, course_code, year_of_study, semester, fee_description)");

    // Add composite FK to program_courses (optional; ignore if fails because of data)
    if (!fkExists($db, 'fee_structure', 'fee_structure_ibfk_course')) {
        // Ensure composite index exists on fee_structure for FK
        if (!indexExists($db, 'fee_structure', 'idx_fee_prog_course')) {
            $db->query("ALTER TABLE fee_structure ADD INDEX idx_fee_prog_course (program_code, course_code)");
        }
        // Try to add FK; if it fails due to existing orphan rows, skip but continue
        try {
            $db->query("ALTER TABLE fee_structure ADD CONSTRAINT fee_structure_ibfk_course FOREIGN KEY (program_code, course_code) REFERENCES program_courses (program_code, course_code) ON UPDATE CASCADE");
        } catch (Throwable $e) {
            // ignore
        }
    }

    $db->commit();
    echo "Migration (course linkage) completed successfully.\n";
} catch (Throwable $e) {
    $db->rollback();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
} 