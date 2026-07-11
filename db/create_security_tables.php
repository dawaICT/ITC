<?php
/**
 * Migration: create the password-security tables.
 *
 * admin/resetPassword.php and admin/createAccount.php depend on get_security_policy(),
 * password_meets_policy() and record_password_history() (implemented in
 * includes/security.php) plus two tables that were never created:
 *   - security_policies : configurable password policy (read by get_security_policy)
 *   - password_history  : prior password hashes (reuse check + record_password_history)
 *
 * Schemas follow debug_data_structure.php:47-48 (the documented expected structure),
 * with an extra history_count column on security_policies because resetPassword.php
 * reads $policy['history_count']. password_history.staff_id is VARCHAR(50) to match
 * staff.staff_id.
 *
 * Re-runnable: CREATE TABLE IF NOT EXISTS + a guarded default policy seed.
 *
 * Run:  E:\xampp\php\php.exe db\create_security_tables.php
 */

require_once __DIR__ . '/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ok = true;

$statements = [];
$statements['security_policies'] = <<<SQL
CREATE TABLE IF NOT EXISTS security_policies (
  id INT NOT NULL AUTO_INCREMENT,
  min_length INT NOT NULL DEFAULT 8,
  require_uppercase TINYINT(1) NOT NULL DEFAULT 1,
  require_lowercase TINYINT(1) NOT NULL DEFAULT 1,
  require_digit TINYINT(1) NOT NULL DEFAULT 1,
  require_special TINYINT(1) NOT NULL DEFAULT 0,
  history_count INT NOT NULL DEFAULT 5,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$statements['password_history'] = <<<SQL
CREATE TABLE IF NOT EXISTS password_history (
  id BIGINT NOT NULL AUTO_INCREMENT,
  staff_id VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_staff_created (staff_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

foreach ($statements as $table => $sql) {
    try {
        $db->query($sql);
        $existsRes = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
        $exists = $existsRes && $existsRes->num_rows > 0;
        echo ($exists ? '[OK]   ' : '[FAIL] ') . $table . "\n";
        if (!$exists) { $ok = false; }
    } catch (Throwable $e) {
        echo '[FAIL] ' . $table . ' -> ' . $e->getMessage() . "\n";
        $ok = false;
    }
}

// Seed a single default policy row if none exists.
if ($ok) {
    try {
        $cnt = (int)$db->query("SELECT COUNT(*) AS c FROM security_policies")->fetch_assoc()['c'];
        if ($cnt === 0) {
            $db->query("INSERT INTO security_policies (min_length, require_uppercase, require_lowercase, require_digit, require_special, history_count) VALUES (8,1,1,1,0,5)");
            echo "[SEED] default security policy row\n";
        } else {
            echo "[SKIP] security policy already has {$cnt} row(s)\n";
        }
    } catch (Throwable $e) {
        echo '[FAIL] seed -> ' . $e->getMessage() . "\n";
        $ok = false;
    }
}

echo $ok ? "\nMigration complete.\n" : "\nMigration finished with errors.\n";
exit($ok ? 0 : 1);
