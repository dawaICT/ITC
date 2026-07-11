-- ============================================================================
-- ITC Training System — Eligibility (§7), Fees (§8) and Certificate (§15)
-- Spec: ITC_Course_Fees_System_Algorithm_and_Logic.md
-- Continuation of 2026_transport_payment_gate.sql (BR001 booking gate).
--
-- Idempotent: safe to re-run. Apply as root (XAMPP MariaDB 10.4+).
--   mysql -u root wucportal < migrations/2026_transport_fees_eligibility_certs.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. transport_programs: entry-requirement columns (§7 / BR003–BR007)
--    NOTE: `license_class` (already present) is the program's OUTPUT class.
--          `required_licence_class` below is the ENTRY prerequisite (comma list).
-- ----------------------------------------------------------------------------
ALTER TABLE `transport_programs`
  ADD COLUMN IF NOT EXISTS `minimum_age` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `license_class`,
  ADD COLUMN IF NOT EXISTS `required_licence_class` VARCHAR(120) NULL DEFAULT NULL AFTER `minimum_age`,
  ADD COLUMN IF NOT EXISTS `requires_nrc` TINYINT(1) NOT NULL DEFAULT 0 AFTER `required_licence_class`,
  ADD COLUMN IF NOT EXISTS `requires_grade_12` TINYINT(1) NOT NULL DEFAULT 0 AFTER `requires_nrc`,
  ADD COLUMN IF NOT EXISTS `requires_driver_licence` TINYINT(1) NOT NULL DEFAULT 0 AFTER `requires_grade_12`,
  ADD COLUMN IF NOT EXISTS `requires_medical_certificate` TINYINT(1) NOT NULL DEFAULT 0 AFTER `requires_driver_licence`;

-- ----------------------------------------------------------------------------
-- 2. transport_course_fees: versioned base fee per program/year/study mode (§19.4)
--    is_available = 0  ->  the study mode is "N/A" for this course (BR002).
--    Rows are versioned by fee_year and never deleted (BR016 / BR017).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transport_course_fees` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `program_id` INT(11) NOT NULL,
  `fee_year` SMALLINT(6) NOT NULL,
  `training_mode` VARCHAR(30) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'ZMW',
  `is_available` TINYINT(1) NOT NULL DEFAULT 1,
  `effective_from` DATE NULL DEFAULT NULL,
  `effective_to` DATE NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_course_fee_program_year_mode` (`program_id`, `fee_year`, `training_mode`),
  KEY `idx_course_fee_program` (`program_id`),
  KEY `idx_course_fee_year` (`fee_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. transport_additional_fees: mandatory/optional add-ons per program (§19.5)
--    fee_category: standard | rtsa | equipment | option
--    option rows (is_mandatory = 0) are applicant-selectable, e.g. own vs ITC motorbike.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transport_additional_fees` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `program_id` INT(11) NOT NULL,
  `fee_name` VARCHAR(120) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'ZMW',
  `is_mandatory` TINYINT(1) NOT NULL DEFAULT 1,
  `fee_category` ENUM('standard','rtsa','equipment','option') NOT NULL DEFAULT 'standard',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_additional_fee_program` (`program_id`),
  KEY `idx_additional_fee_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. transport_certificates: issued-certificate record (§15 / BR015)
--    One certificate per enrolment; certificate_number is globally unique.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transport_certificates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `enrollment_id` INT(11) NOT NULL,
  `certificate_number` VARCHAR(40) NOT NULL,
  `completion_date` DATE NULL DEFAULT NULL,
  `approved_by` VARCHAR(80) NULL DEFAULT NULL,
  `issue_date` DATE NULL DEFAULT NULL,
  `status` ENUM('ready','issued','revoked') NOT NULL DEFAULT 'issued',
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_certificate_enrollment` (`enrollment_id`),
  UNIQUE KEY `uq_certificate_number` (`certificate_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Backfill so existing programs keep working.
--    5a. One full-time base-fee row (current year) per active program, from default_fee.
--        INSERT IGNORE relies on the unique (program_id, fee_year, training_mode) key.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `transport_course_fees`
  (`program_id`, `fee_year`, `training_mode`, `amount`, `currency`, `is_available`)
SELECT `id`, YEAR(CURDATE()), 'full-time', `default_fee`, 'ZMW', 1
FROM `transport_programs`
WHERE `status` = 'active';

-- 5b. Placeholder RTSA additional fees for RTSA-regulated programs.
--     Amounts default to 0.00 — admins MUST set the real RTSA fees in the UI.
--     NOT EXISTS keeps this idempotent (no natural unique key on additional fees).
INSERT INTO `transport_additional_fees`
  (`program_id`, `fee_name`, `amount`, `currency`, `is_mandatory`, `fee_category`, `is_active`)
SELECT p.`id`, x.`fee_name`, 0.00, 'ZMW', 1, 'rtsa', 1
FROM `transport_programs` p
JOIN (
        SELECT 'RTSA Provisional Licence Fee' AS fee_name
  UNION SELECT 'RTSA Test Fee'
  UNION SELECT 'RTSA Licence Fee'
) x
WHERE p.`rtsa_regulated` = 1
  AND NOT EXISTS (
    SELECT 1 FROM `transport_additional_fees` a
    WHERE a.`program_id` = p.`id`
      AND a.`fee_name` = x.`fee_name`
      AND a.`fee_category` = 'rtsa'
  );
