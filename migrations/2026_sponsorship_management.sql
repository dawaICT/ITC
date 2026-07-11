-- ============================================================================
-- Unified Sponsorship & Scholarship Management
-- ----------------------------------------------------------------------------
-- ONE configurable framework (TEVETA, CDF, Government, Company, NGO, Church,
-- Employer, Staff Development, Self, Other …). Programmes stay normal academic
-- programmes; funding eligibility is data-driven, never hardcoded.
--
-- Extends the EXISTING finance_sponsors / finance_student_sponsors tables
-- (both empty) rather than creating a parallel system. Adds two new config
-- tables (sponsor_types, programme_sponsorship_eligibility) and approval/finance
-- columns on the student sponsorship link.
--
-- Idempotent: safe to re-run (CREATE/ADD ... IF NOT EXISTS — MariaDB 10.4+).
-- Apply as a DDL-capable user (root or wucportal_migrator), NOT at runtime:
--   mysql -h 127.0.0.1 --protocol=TCP -u root wucportal < migrations/2026_sponsorship_management.sql
-- ============================================================================

-- 1. Configurable sponsor TYPES (categories). Admins add new ones with no code.
CREATE TABLE IF NOT EXISTS sponsor_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    requires_approval TINYINT(1) NOT NULL DEFAULT 1,
    is_self_funded TINYINT(1) NOT NULL DEFAULT 0,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sponsor_type_code (code),
    INDEX idx_sponsor_type_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Per-programme eligibility: which sponsor types a programme accepts, with
--    caps and requirements. No row => programme not eligible for that type.
CREATE TABLE IF NOT EXISTS programme_sponsorship_eligibility (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(50) NOT NULL,
    sponsor_type_id INT UNSIGNED NOT NULL,
    max_sponsored_students INT UNSIGNED NULL,
    sponsorship_requirements TEXT NULL,
    academic_requirements TEXT NULL,
    intake_restrictions VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prog_sponsor (program_code, sponsor_type_id),
    INDEX idx_pse_prog (program_code),
    INDEX idx_pse_type (sponsor_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Extend finance_sponsors (the sponsor ORGANISATION registry) with a type
--    link and a funding allocation pool (to cap total approved spend).
ALTER TABLE finance_sponsors
    ADD COLUMN IF NOT EXISTS code VARCHAR(50) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS sponsor_type_id INT UNSIGNED NULL AFTER name,
    ADD COLUMN IF NOT EXISTS total_allocation DECIMAL(14,2) NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS allocation_year VARCHAR(10) NULL AFTER total_allocation,
    ADD INDEX IF NOT EXISTS idx_finance_sponsors_type (sponsor_type_id);

-- 4. Extend finance_student_sponsors (the per-student sponsorship PROFILE) with
--    the approval workflow + finance fields. coverage_percent, start_date,
--    end_date, status(active/expired/cancelled) already exist.
ALTER TABLE finance_student_sponsors
    ADD COLUMN IF NOT EXISTS sponsor_type_id INT UNSIGNED NULL AFTER sponsor_id,
    ADD COLUMN IF NOT EXISTS program_code VARCHAR(50) NULL AFTER sponsor_type_id,
    ADD COLUMN IF NOT EXISTS academic_year VARCHAR(10) NULL AFTER program_code,
    ADD COLUMN IF NOT EXISTS reference_number VARCHAR(80) NULL AFTER academic_year,
    ADD COLUMN IF NOT EXISTS amount_approved DECIMAL(14,2) NULL AFTER coverage_percent,
    ADD COLUMN IF NOT EXISTS amount_released DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER amount_approved,
    ADD COLUMN IF NOT EXISTS conditions TEXT NULL AFTER end_date,
    ADD COLUMN IF NOT EXISTS approval_status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending' AFTER status,
    ADD COLUMN IF NOT EXISTS approved_by VARCHAR(80) NULL AFTER approval_status,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500) NULL AFTER approved_at,
    ADD COLUMN IF NOT EXISTS created_by VARCHAR(80) NULL,
    ADD INDEX IF NOT EXISTS idx_fss_student (student_id),
    ADD INDEX IF NOT EXISTS idx_fss_type (sponsor_type_id),
    ADD INDEX IF NOT EXISTS idx_fss_approval (approval_status),
    ADD INDEX IF NOT EXISTS idx_fss_program (program_code);

-- 5. Programme TEVETA accreditation status (reporting + eligibility context).
ALTER TABLE programs
    ADD COLUMN IF NOT EXISTS is_teveta_accredited TINYINT(1) NOT NULL DEFAULT 0 AFTER examination_type;

-- 5b. Relax legacy NOT NULL constraints so a sponsorship can reference a sponsor
--     TYPE only (no specific sponsor organisation yet) and so fixed-amount
--     sponsorships with no percentage are valid. (MODIFY is idempotent.)
ALTER TABLE finance_student_sponsors
    MODIFY COLUMN sponsor_id INT(11) NULL,
    MODIFY COLUMN coverage_percent DECIMAL(5,2) NULL;

-- 6. Seed the standard, configurable sponsor types (built-ins; admins can add
--    more or deactivate these — but is_system protects them from hard delete).
INSERT IGNORE INTO sponsor_types (code, name, description, requires_approval, is_self_funded, is_system, sort_order) VALUES
    ('self',        'Self Sponsored',                'Student funds their own tuition in full.',                 0, 1, 1, 10),
    ('cdf',         'CDF Skills Development Bursary', 'Constituency Development Fund skills bursary.',            1, 0, 1, 20),
    ('teveta',      'TEVETA Scholarship',            'TEVETA scholarship / bursary funding.',                    1, 0, 1, 30),
    ('government',  'Government Sponsorship',        'Central or local government sponsorship.',                 1, 0, 1, 40),
    ('company',     'Company Sponsorship',           'Private company / corporate sponsorship.',                 1, 0, 1, 50),
    ('ngo',         'NGO Sponsorship',               'Non-governmental organisation sponsorship.',               1, 0, 1, 60),
    ('church',      'Church Sponsorship',            'Church or faith-based organisation sponsorship.',          1, 0, 1, 70),
    ('employer',    'Employer Sponsorship',          'Sponsorship by the student''s employer.',                  1, 0, 1, 80),
    ('staff_dev',   'Staff Development Sponsorship',  'Institutional staff development funding.',                 1, 0, 1, 90),
    ('other',       'Other',                         'Other sponsor type (specify in sponsor name).',            1, 0, 1, 100);
