<?php
/**
 * 2026-07-06 — Create the four finance tables that db/finance_module.sql
 * failed to create (collation mismatch: FK targets are utf8mb4_unicode_ci,
 * the original DDL defaulted to utf8mb4_general_ci, so InnoDB rejected the
 * foreign keys and the migration died partway).
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS.
 * Run: php migrations/20260706_finance_missing_tables.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// DDL requires the migrator user (the app user is DML-only).
$host = getenv('WUC_MIGRATOR_HOST') ?: '127.0.0.1';
$user = getenv('WUC_MIGRATOR_USER') ?: 'root';
$pass = getenv('WUC_MIGRATOR_PASSWORD') ?: '';
$name = getenv('WUC_DB_NAME') ?: 'wucportal';
$db = new mysqli($host, $user, $pass, $name);
$db->set_charset('utf8mb4');

$ddl = [
'finance_student_discounts' => "
CREATE TABLE IF NOT EXISTS finance_student_discounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(50) NOT NULL,
  discount_id INT NOT NULL,
  effective_from DATE NULL,
  effective_to DATE NULL,
  approval_state ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_student_disc_student FOREIGN KEY (student_id) REFERENCES students(SID)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_student_disc_disc FOREIGN KEY (discount_id) REFERENCES finance_discounts(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  UNIQUE KEY uq_student_discount (student_id, discount_id, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'finance_student_installments' => "
CREATE TABLE IF NOT EXISTS finance_student_installments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(50) NOT NULL,
  plan_id INT NOT NULL,
  installment_no INT NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency_code CHAR(3) NOT NULL DEFAULT 'ZMW',
  status ENUM('pending','paid','overdue','waived') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_stu_inst_student FOREIGN KEY (student_id) REFERENCES students(SID)
    ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY uq_student_plan_installment (student_id, plan_id, installment_no),
  KEY idx_fsi_status_due (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'finance_sponsor_invoices' => "
CREATE TABLE IF NOT EXISTS finance_sponsor_invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sponsor_id INT NOT NULL,
  student_id VARCHAR(50) NOT NULL,
  invoice_number VARCHAR(32) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency_code CHAR(3) NOT NULL DEFAULT 'ZMW',
  due_date DATE NULL,
  status ENUM('Pending','Paid','Overdue','Cancelled') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_spon_inv_sponsor FOREIGN KEY (sponsor_id) REFERENCES finance_sponsors(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_spon_inv_student FOREIGN KEY (student_id) REFERENCES students(SID)
    ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY uq_fin_sponsor_invoice (invoice_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'finance_ar_reminders' => "
CREATE TABLE IF NOT EXISTS finance_ar_reminders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(50) NOT NULL,
  channel ENUM('sms','email') NOT NULL,
  message TEXT NOT NULL,
  sent_at TIMESTAMP NULL,
  status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  error_message TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arrem_student FOREIGN KEY (student_id) REFERENCES students(SID)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

// Note: fk_stu_inst_plan (plan_id -> finance_installment_plans.id) is intentionally
// omitted — the code inserts synthetic rows with plan_id = 0 for ad-hoc late fees
// (admin/ajax/finance_apply_late_fees.php), which a strict FK would reject.

foreach ($ddl as $table => $sql) {
    $exists = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    if ($exists && $exists->num_rows > 0) {
        echo "SKIP   $table (already exists)\n";
        continue;
    }
    $db->query($sql);
    echo "CREATE $table OK\n";
}
echo "Done.\n";
