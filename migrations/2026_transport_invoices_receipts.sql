-- ============================================================================
-- ITC Training System — Invoices & Receipts (§4.5 / §10 / BR011)
-- Spec: ITC_Course_Fees_System_Algorithm_and_Logic.md
-- Continues 2026_transport_fees_eligibility_certs.sql.
--
-- An invoice is generated from the assessed fee at enrolment; a receipt may only
-- be generated AFTER an accounts officer verifies payment (BR011).
--
-- Idempotent. Apply as root:
--   mysql -u root wucportal < migrations/2026_transport_invoices_receipts.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `transport_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `enrollment_id` INT(11) NOT NULL,
  `invoice_number` VARCHAR(40) NOT NULL,
  `fee_year` SMALLINT(6) NOT NULL,
  `training_mode` VARCHAR(30) NULL DEFAULT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'ZMW',
  `status` ENUM('issued','paid','cancelled') NOT NULL DEFAULT 'issued',
  `created_by` VARCHAR(80) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_enrollment` (`enrollment_id`),
  UNIQUE KEY `uq_invoice_number` (`invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transport_invoice_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` INT(11) NOT NULL,
  `item_name` VARCHAR(150) NOT NULL,
  `category` VARCHAR(30) NOT NULL DEFAULT 'standard',
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_items_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transport_receipts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` INT(11) NULL DEFAULT NULL,
  `enrollment_id` INT(11) NOT NULL,
  `receipt_number` VARCHAR(40) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `issued_by` VARCHAR(80) NULL DEFAULT NULL,
  `issued_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt_enrollment` (`enrollment_id`),
  UNIQUE KEY `uq_receipt_number` (`receipt_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
