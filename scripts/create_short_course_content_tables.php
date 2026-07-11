<?php
/**
 * Content support for short courses: modules (lessons) and their materials
 * (uploaded files or external links). Mirrors the academic e-learning idea but
 * scoped to the standalone short_courses subsystem.
 *
 * Idempotent. Run from project root:
 *   E:\xampp\php\php.exe scripts\create_short_course_content_tables.php
 */

require_once __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db->query(
        "CREATE TABLE IF NOT EXISTS short_course_modules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            short_course_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            content MEDIUMTEXT NULL,
            position INT NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(50) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_scm_course (short_course_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "ensured short_course_modules\n";

    // Backfill the rich-text lesson body column on installs created before it
    // existed (admin/short_course_content.php + students/short_courses.php need
    // short_course_modules.content). Idempotent.
    $hasContent = $db->query("SHOW COLUMNS FROM short_course_modules LIKE 'content'");
    if (!$hasContent || $hasContent->num_rows === 0) {
        $db->query("ALTER TABLE short_course_modules ADD COLUMN content MEDIUMTEXT NULL AFTER description");
        echo "added short_course_modules.content\n";
    } else {
        echo "short_course_modules.content already present\n";
    }

    $db->query(
        "CREATE TABLE IF NOT EXISTS short_course_materials (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            module_id BIGINT UNSIGNED NOT NULL,
            short_course_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            material_type VARCHAR(20) NOT NULL DEFAULT 'file',
            url VARCHAR(500) NULL,
            created_by VARCHAR(50) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_scmat_module (module_id),
            KEY idx_scmat_course (short_course_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "ensured short_course_materials\n";

    // Upload directory for short-course material files.
    $dir = __DIR__ . '/../uploads/short_courses';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
        echo "created uploads/short_courses\n";
    } else {
        echo "uploads/short_courses already exists\n";
    }

    echo "DONE\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
