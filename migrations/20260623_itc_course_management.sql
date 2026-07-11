-- ITC Course Management module
-- Adds the academic/training structure from ITC_Courses_Duration_Intake_Level_Logic.md:
--   * course categories (§3)
--   * course classification levels (§4, ranks §10.2)
--   * intake types (§7)
--   * structured duration + classification columns on short_courses (the catalogue home)
--   * the intake -> course_intake -> training_batch workflow (§10.5-10.7)
--   * an application lifecycle on short_course_enrollments (§17)
-- Idempotent: every statement is guarded so the file is safe to re-run.

-- ─── 1) Course categories (§3) ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS course_categories (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    category_code VARCHAR(20)  NOT NULL UNIQUE,
    category_name VARCHAR(120) NOT NULL,
    description   VARCHAR(255) NULL,
    sort_order    INT          NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO course_categories (category_code, category_name, sort_order) VALUES
    ('AUTO',  'Automotive Engineering',                    10),
    ('TRANS', 'Transport and Logistics',                   20),
    ('ELEC',  'Electrical and Electronics Engineering',    30),
    ('MECH',  'Mechanical Engineering',                    40),
    ('ICT',   'Information and Communication Technology',   50),
    ('AGRI',  'Agricultural Courses',                      60),
    ('SAFE',  'Safety Courses',                            70),
    ('MINE',  'Manufacturing / Mining Industry',           80),
    ('SERV',  'Other Services',                            90)
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name), sort_order = VALUES(sort_order);

-- ─── 2) Classification levels (§4, ranks §10.2) ───────────────────────────
CREATE TABLE IF NOT EXISTS course_classification_levels (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    level_code  VARCHAR(20)  NOT NULL UNIQUE,
    level_name  VARCHAR(80)  NOT NULL,
    level_rank  INT          NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO course_classification_levels (level_code, level_name, level_rank, description) VALUES
    ('SERVICE',    'Service',               1, 'Non-training service such as vehicle repair, hiring or assessment'),
    ('ASSESSMENT', 'Assessment',            1, 'Testing or competence assessment'),
    ('SHORT',      'Short Course',          2, '1 to 20 days practical training'),
    ('REFRESHER',  'Refresher Course',      2, 'Short course for people who already have knowledge/licence'),
    ('BASIC',      'Basic Skills Course',   3, 'Beginner practical training'),
    ('ADVANCED',   'Advanced Skills Course',4, 'Higher practical skills training'),
    ('TRADE_III',  'Trade Test Level III',  4, 'Entry trade test level, commonly 3 months'),
    ('TRADE_II',   'Trade Test Level II',   5, 'Intermediate trade test level, commonly 6 months'),
    ('TRADE_I',    'Trade Test Level I',    6, 'Advanced trade test level, commonly 12 months'),
    ('CERTIFICATE','Certificate',           7, 'Formal certificate programme'),
    ('CRAFT_CERT', 'Craft Certificate',     8, 'Formal craft certificate programme'),
    ('TECH_CERT',  'Technician Certificate',9, 'Technician certificate programme'),
    ('DIPLOMA',    'Diploma',              10, 'Diploma or professional diploma programme')
ON DUPLICATE KEY UPDATE level_name = VALUES(level_name), level_rank = VALUES(level_rank), description = VALUES(description);

-- ─── 3) Intake types (§7) ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS intake_types (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    type_code   VARCHAR(20)  NOT NULL UNIQUE,
    type_name   VARCHAR(80)  NOT NULL,
    description VARCHAR(255) NULL,
    min_days    INT NULL,
    max_days    INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO intake_types (type_code, type_name, description, min_days, max_days) VALUES
    ('ON_DEMAND',      'On Demand',      'Services and assessments',                 NULL, NULL),
    ('ROLLING',        'Rolling Intake', 'Short courses that can start often',        1,    5),
    ('WEEKLY',         'Weekly Intake',  '6 to 10 day high-demand courses',           6,    10),
    ('MONTHLY',        'Monthly Intake', '11 to 20 day short courses',                11,   20),
    ('TERM_BASED',     'Term Based',     '3 to 6 month trade test courses',           90,   180),
    ('SEMESTER_BASED', 'Semester Based', '9 to 12 month programmes',                  270,  365),
    ('ANNUAL',         'Annual',         '2 year formal programmes',                  730,  NULL)
ON DUPLICATE KEY UPDATE type_name = VALUES(type_name), description = VALUES(description),
    min_days = VALUES(min_days), max_days = VALUES(max_days);

-- ─── 4) Extend short_courses with structured duration + classification ─────
-- Widen the duration unit so formal 2-year programmes (diplomas / craft certs) fit.
ALTER TABLE short_courses
    MODIFY COLUMN duration_unit ENUM('days','weeks','months','years') NOT NULL DEFAULT 'weeks';

ALTER TABLE short_courses
    ADD COLUMN IF NOT EXISTS category_id            INT NULL        AFTER description,
    ADD COLUMN IF NOT EXISTS level_id               INT NULL        AFTER category_id,
    ADD COLUMN IF NOT EXISTS intake_type_id         INT NULL        AFTER level_id,
    ADD COLUMN IF NOT EXISTS standard_duration_days INT NULL        AFTER duration_unit,
    ADD COLUMN IF NOT EXISTS is_duration_fixed      TINYINT(1) NOT NULL DEFAULT 1 AFTER standard_duration_days,
    ADD COLUMN IF NOT EXISTS minimum_age            INT NULL        AFTER is_duration_fixed;

ALTER TABLE short_courses
    ADD INDEX IF NOT EXISTS idx_sc_category    (category_id),
    ADD INDEX IF NOT EXISTS idx_sc_level       (level_id),
    ADD INDEX IF NOT EXISTS idx_sc_intake_type (intake_type_id);

-- ─── 5) Intakes (§10.5; statuses §9) ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS intakes (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    intake_name            VARCHAR(150) NOT NULL,
    intake_year            INT NOT NULL,
    intake_type_id         INT NULL,
    application_open_date  DATE NULL,
    application_close_date DATE NULL,
    training_start_date    DATE NULL,
    training_end_date      DATE NULL,
    status ENUM('Draft','Open for Applications','Closed for Applications','Screening Applicants',
                'Payment Collection','Ready for Training','Training in Progress','Completed','Cancelled')
           NOT NULL DEFAULT 'Draft',
    created_by VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_intakes_status (status),
    INDEX idx_intakes_type   (intake_type_id),
    CONSTRAINT fk_intakes_type FOREIGN KEY (intake_type_id)
        REFERENCES intake_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 6) Course offered within an intake (§10.6) ───────────────────────────
CREATE TABLE IF NOT EXISTS course_intakes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    short_course_id INT NOT NULL,
    intake_id       INT NOT NULL,
    capacity        INT NOT NULL DEFAULT 30,
    available_slots INT NOT NULL DEFAULT 30,
    status ENUM('open','closed','full','cancelled') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_intake (short_course_id, intake_id),
    INDEX idx_ci_intake (intake_id),
    CONSTRAINT fk_ci_course FOREIGN KEY (short_course_id)
        REFERENCES short_courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_ci_intake FOREIGN KEY (intake_id)
        REFERENCES intakes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 7) Training batches (§10.7) ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS training_batches (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    course_intake_id  INT NOT NULL,
    batch_name        VARCHAR(120) NOT NULL,
    start_date        DATE NULL,
    end_date          DATE NULL,
    trainer_id        VARCHAR(50) NULL,
    location          VARCHAR(150) NULL,
    capacity          INT NOT NULL DEFAULT 30,
    current_enrolment INT NOT NULL DEFAULT 0,
    status ENUM('open','in_progress','completed','cancelled') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tb_ci (course_intake_id),
    CONSTRAINT fk_tb_ci FOREIGN KEY (course_intake_id)
        REFERENCES course_intakes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 8) Application lifecycle on enrollments (§17) ────────────────────────
ALTER TABLE short_course_enrollments
    ADD COLUMN IF NOT EXISTS intake_id          INT NULL AFTER short_course_id,
    ADD COLUMN IF NOT EXISTS course_intake_id   INT NULL AFTER intake_id,
    ADD COLUMN IF NOT EXISTS training_batch_id  INT NULL AFTER course_intake_id,
    ADD COLUMN IF NOT EXISTS invoice_id         INT NULL AFTER training_batch_id,
    ADD COLUMN IF NOT EXISTS application_status
        ENUM('pending_requirements','awaiting_payment','confirmed','rejected','cancelled') NULL AFTER status,
    ADD COLUMN IF NOT EXISTS applied_at         TIMESTAMP NULL DEFAULT NULL AFTER application_status;

ALTER TABLE short_course_enrollments
    ADD INDEX IF NOT EXISTS idx_sce_intake        (intake_id),
    ADD INDEX IF NOT EXISTS idx_sce_course_intake (course_intake_id),
    ADD INDEX IF NOT EXISTS idx_sce_batch         (training_batch_id);
