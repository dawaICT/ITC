<?php
/**
 * 2026-07-06 — Create the Airtel Money `transactions` table.
 *
 * students/process_airtel_payment.php, students/airtel_callback.php and
 * admin/airtel_config.php all referenced this table but it never existed —
 * initiating an Airtel payment or receiving a callback fatalled.
 *
 * Column names follow the actual code (camelCase: transactionId/studentID/
 * referenceID), not AIRTEL_MONEY_SETUP.md whose snake_case draft never matched
 * the implementation.
 *
 * Run: php migrations/20260706_airtel_transactions_table.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('WUC_MIGRATOR_HOST') ?: '127.0.0.1';
$user = getenv('WUC_MIGRATOR_USER') ?: 'root';
$pass = getenv('WUC_MIGRATOR_PASSWORD') ?: '';
$name = getenv('WUC_DB_NAME') ?: 'wucportal';
$db = new mysqli($host, $user, $pass, $name);
$db->set_charset('utf8mb4');

$db->query("
CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transactionId VARCHAR(100) NULL,
  studentID VARCHAR(50) NOT NULL,
  referenceID VARCHAR(100) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  narration VARCHAR(255) NULL,
  transactionType VARCHAR(50) NOT NULL DEFAULT 'Airtel Money',
  channel VARCHAR(50) NOT NULL DEFAULT 'Airtel Money',
  payment_date DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  callback_response LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tx_reference (referenceID),
  KEY idx_tx_student (studentID),
  KEY idx_tx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "transactions table ready.\n";
