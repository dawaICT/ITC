-- BR001 booking gate for the transport training module.
-- "A student must only be booked for training after payment has been verified."
-- Adds a verifiable payment workflow to enrolments and a proof-of-payment ledger.
-- Apply as a DDL-capable user (wucportal_migrator or root); the app user is DML-only.
--   mysql -h 127.0.0.1 --protocol=TCP -u root wucportal < migrations/2026_transport_payment_gate.sql

-- 1. Enrolment-level payment + booking state -------------------------------
ALTER TABLE transport_enrollments
  ADD COLUMN IF NOT EXISTS payment_status
      ENUM('awaiting_payment','payment_submitted','partial','verified','rejected')
      NOT NULL DEFAULT 'awaiting_payment' AFTER amount_paid,
  ADD COLUMN IF NOT EXISTS booking_status
      ENUM('pending_payment','booked','cancelled')
      NOT NULL DEFAULT 'pending_payment' AFTER payment_status,
  ADD COLUMN IF NOT EXISTS payment_verified_by VARCHAR(80) DEFAULT NULL AFTER booking_status,
  ADD COLUMN IF NOT EXISTS payment_verified_at DATETIME DEFAULT NULL AFTER payment_verified_by;

-- 2. Proof-of-payment ledger (one row per submitted payment) ----------------
CREATE TABLE IF NOT EXISTS transport_payments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  enrollment_id INT(11) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_method VARCHAR(50) DEFAULT NULL,
  bank_name VARCHAR(120) DEFAULT NULL,
  reference_number VARCHAR(80) DEFAULT NULL,
  proof_path VARCHAR(255) DEFAULT NULL,
  status ENUM('submitted','verified','rejected') NOT NULL DEFAULT 'submitted',
  rejection_reason VARCHAR(255) DEFAULT NULL,
  submitted_by VARCHAR(80) DEFAULT NULL,
  verified_by VARCHAR(80) DEFAULT NULL,
  verified_at DATETIME DEFAULT NULL,
  notes VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tp_enrollment (enrollment_id),
  KEY idx_tp_reference (reference_number),
  KEY idx_tp_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Backfill existing rows so already-active/paid trainees are not blocked --
--    Runs once: only touches rows still holding the freshly-added default.
UPDATE transport_enrollments
SET payment_status = CASE
        WHEN fee_amount > 0 AND amount_paid >= fee_amount THEN 'verified'
        WHEN amount_paid > 0 THEN 'partial'
        ELSE 'awaiting_payment' END,
    booking_status = CASE
        WHEN status IN ('active','completed') OR (fee_amount > 0 AND amount_paid >= fee_amount) THEN 'booked'
        ELSE 'pending_payment' END
WHERE booking_status = 'pending_payment';
