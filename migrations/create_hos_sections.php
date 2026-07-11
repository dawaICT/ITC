<?php

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$db->begin_transaction();

try {
    $db->query("
        CREATE TABLE IF NOT EXISTS sections (
            section_id VARCHAR(30) NOT NULL PRIMARY KEY,
            section_name VARCHAR(120) NOT NULL,
            section_type VARCHAR(40) NOT NULL DEFAULT 'academic',
            department_id INT(11) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sections_name (section_name),
            KEY idx_sections_department (department_id),
            CONSTRAINT fk_sections_department
                FOREIGN KEY (department_id) REFERENCES departments(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS staff_section_assignments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            staff_id VARCHAR(50) NOT NULL,
            section_id VARCHAR(30) NOT NULL,
            role_key VARCHAR(60) NOT NULL DEFAULT 'head_of_department',
            is_primary TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            assigned_by VARCHAR(50) NULL,
            assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_section_role (staff_id, section_id, role_key),
            KEY idx_staff_section_staff (staff_id),
            KEY idx_staff_section_section (section_id),
            CONSTRAINT fk_staff_section_staff
                FOREIGN KEY (staff_id) REFERENCES staff(staff_id)
                ON DELETE CASCADE,
            CONSTRAINT fk_staff_section_section
                FOREIGN KEY (section_id) REFERENCES sections(section_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $db->prepare("
        INSERT INTO sections (section_id, section_name, section_type, department_id, status)
        VALUES (?, ?, ?, ?, 'active')
        ON DUPLICATE KEY UPDATE
            section_name = VALUES(section_name),
            section_type = VALUES(section_type),
            department_id = VALUES(department_id),
            status = 'active'
    ");
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $seedSections = [
        ['TRANSPORT', 'Transport Section', 'transport', null],
        ['ENGICT', 'Engineering/ICT Section', 'academic', null],
    ];

    foreach ($seedSections as $section) {
        [$sectionId, $sectionName, $sectionType, $departmentId] = $section;
        $stmt->bind_param('ssss', $sectionId, $sectionName, $sectionType, $departmentId);
        $stmt->execute();
    }
    $stmt->close();

    $db->commit();
    echo "HOS section schema is ready.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "HOS section migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
