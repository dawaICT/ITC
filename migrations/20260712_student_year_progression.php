<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db->query(
    "CREATE TABLE IF NOT EXISTS student_progression_decisions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        student_id VARCHAR(50) NOT NULL,
        decision_type ENUM('progress','repeat','defer','complete') NOT NULL DEFAULT 'progress',
        decision_status ENUM('approved','declined','cancelled') NOT NULL DEFAULT 'approved',
        from_program_code VARCHAR(20) NOT NULL,
        to_program_code VARCHAR(20) NOT NULL,
        from_year_of_study TINYINT UNSIGNED NOT NULL,
        to_year_of_study TINYINT UNSIGNED NOT NULL,
        from_academic_year VARCHAR(10) NULL,
        to_academic_year VARCHAR(10) NOT NULL,
        decision_basis ENUM('external_results','examination_board','internal_ca_review','administrative') NOT NULL,
        decision_reference VARCHAR(120) NOT NULL,
        decision_notes TEXT NULL,
        ca_evidence_json LONGTEXT NULL,
        decided_by VARCHAR(50) NOT NULL,
        decided_by_role VARCHAR(60) NOT NULL,
        section_id VARCHAR(30) NULL,
        decided_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_progression_student (student_id, decided_at),
        KEY idx_progression_program_year (to_program_code, to_academic_year, to_year_of_study),
        KEY idx_progression_actor (decided_by, decided_at),
        CONSTRAINT fk_progression_student FOREIGN KEY (student_id)
            REFERENCES students (SID) ON UPDATE CASCADE ON DELETE RESTRICT,
        CONSTRAINT fk_progression_from_program FOREIGN KEY (from_program_code)
            REFERENCES programs (program_code) ON UPDATE CASCADE ON DELETE RESTRICT,
        CONSTRAINT fk_progression_to_program FOREIGN KEY (to_program_code)
            REFERENCES programs (program_code) ON UPDATE CASCADE ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "Student progression decision schema is ready.\n";

