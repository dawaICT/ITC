<?php
/**
 * Repair coerced student_fee_accounts.status values and enforce the enum's
 * intended state set even when MariaDB is running without strict SQL mode.
 *
 * Usage:
 *   php migrations/20260717_fee_account_status_integrity.php --dry-run
 *   php migrations/20260717_fee_account_status_integrity.php --apply
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../db/connect.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$apply = in_array('--apply', $argv ?? [], true);
if (!$dryRun && !$apply) {
    fwrite(STDERR, "Usage: php migrations/20260717_fee_account_status_integrity.php [--dry-run|--apply]\n");
    exit(1);
}

$table = $db->query("SHOW TABLES LIKE 'student_fee_accounts'");
if (!$table || $table->num_rows === 0) {
    fwrite(STDERR, "student_fee_accounts is missing.\n");
    exit(2);
}
$table->free();

$invalidResult = $db->query(
    "SELECT COUNT(*) AS total
       FROM student_fee_accounts
      WHERE status IS NULL OR status NOT IN ('active', 'withdrawn', 'cancelled')"
);
$invalidRows = (int)($invalidResult->fetch_assoc()['total'] ?? 0);
$invalidResult->free();

$constraintStmt = $db->prepare(
    "SELECT 1
       FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'student_fee_accounts'
        AND CONSTRAINT_NAME = 'chk_sfa_status_valid'
        AND CONSTRAINT_TYPE = 'CHECK'
      LIMIT 1"
);
$constraintStmt->execute();
$constraintExists = $constraintStmt->get_result()->num_rows > 0;
$constraintStmt->close();

echo '[state] invalid_status_rows=' . $invalidRows
    . '; check_constraint=' . ($constraintExists ? 'present' : 'missing') . PHP_EOL;

if ($dryRun) {
    if ($invalidRows > 0) {
        echo "[dry-run] UPDATE student_fee_accounts SET status = 'cancelled' WHERE status IS NULL OR status NOT IN ('active','withdrawn','cancelled')\n";
    }
    if (!$constraintExists) {
        echo "[dry-run] ALTER TABLE student_fee_accounts ADD CONSTRAINT chk_sfa_status_valid CHECK (status IN ('active','withdrawn','cancelled'))\n";
    }
    exit(0);
}

try {
    if ($invalidRows > 0) {
        $db->query(
            "UPDATE student_fee_accounts
                SET status = 'cancelled', updated_at = NOW()
              WHERE status IS NULL OR status NOT IN ('active', 'withdrawn', 'cancelled')"
        );
        echo '[applied] repaired_invalid_status_rows=' . $db->affected_rows . PHP_EOL;
    }

    if (!$constraintExists) {
        $db->query(
            "ALTER TABLE student_fee_accounts
             ADD CONSTRAINT chk_sfa_status_valid
             CHECK (status IN ('active', 'withdrawn', 'cancelled'))"
        );
        echo "[applied] chk_sfa_status_valid\n";
    }

    $remaining = $db->query(
        "SELECT COUNT(*) AS total
           FROM student_fee_accounts
          WHERE status IS NULL OR status NOT IN ('active', 'withdrawn', 'cancelled')"
    );
    $remainingRows = (int)($remaining->fetch_assoc()['total'] ?? 0);
    $remaining->free();
    if ($remainingRows !== 0) {
        throw new RuntimeException('Invalid fee-account statuses remain after repair.');
    }

    echo "[verified] invalid_status_rows=0; check_constraint=present\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[failed] ' . $e->getMessage() . PHP_EOL);
    exit(3);
}

