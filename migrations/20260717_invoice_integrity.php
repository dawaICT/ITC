<?php
/**
 * Normalize invoice identity/period fields and enforce one invoice per student
 * academic period. Also reconciles unambiguous historical normalized payments.
 *
 * Usage:
 *   php migrations/20260717_invoice_integrity.php --dry-run
 *   php migrations/20260717_invoice_integrity.php --apply
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
    fwrite(STDERR, "Usage: php migrations/20260717_invoice_integrity.php [--dry-run|--apply]\n");
    exit(1);
}

$table = $db->query("SHOW TABLES LIKE 'invoices'");
if (!$table || $table->num_rows === 0) {
    fwrite(STDERR, "invoices is missing.\n");
    exit(2);
}
$table->free();

$columnResult = $db->query("SHOW COLUMNS FROM invoices LIKE 'SID'");
$sidColumn = $columnResult->fetch_assoc() ?: [];
$columnResult->free();
$sidType = strtolower((string)($sidColumn['Type'] ?? ''));

$yearColumnResult = $db->query("SHOW COLUMNS FROM invoices LIKE 'year_of_study'");
$hasYearOfStudy = $yearColumnResult->num_rows > 0;
$yearColumnResult->free();

$indexResult = $db->query("SHOW INDEX FROM invoices WHERE Key_name = 'uniq_invoice_student_period'");
$hasPeriodIndex = $indexResult && $indexResult->num_rows > 0;
if ($indexResult) {
    $indexResult->free();
}

$constraintExists = static function (mysqli $db, string $name): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME = 'invoices'
            AND CONSTRAINT_NAME = ?
            AND CONSTRAINT_TYPE = 'CHECK'
          LIMIT 1"
    );
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
};

$identityIssues = (int)($db->query(
    "SELECT COUNT(*) AS total FROM invoices WHERE CAST(SID AS CHAR) <> student_id"
)->fetch_assoc()['total'] ?? 0);
$contextIssues = (int)($db->query(
    "SELECT COUNT(*) AS total FROM invoices
      WHERE academic_year NOT REGEXP '^[0-9]{4}$'
         OR semester NOT IN ('1','2','3','4')
         OR program_code = ''
         OR status NOT IN ('Pending','Paid','Overdue')"
)->fetch_assoc()['total'] ?? 0);
$duplicates = (int)($db->query(
    "SELECT COUNT(*) AS total FROM (
        SELECT student_id, academic_year, semester
          FROM invoices
         GROUP BY student_id, academic_year, semester
        HAVING COUNT(*) > 1
    ) duplicate_periods"
)->fetch_assoc()['total'] ?? 0);
$reconciliationCandidates = (int)($db->query(
    "SELECT COUNT(*) AS total
       FROM invoices i
       JOIN (
            SELECT student_id, academic_year, CAST(semester AS CHAR) AS semester, SUM(amount) AS paid
              FROM payments
             WHERE LOWER(status) IN ('completed','posted','confirmed','paid','success')
             GROUP BY student_id, academic_year, semester
       ) p ON p.student_id = i.student_id
          AND p.academic_year = i.academic_year
          AND p.semester = i.semester
      WHERE i.amount_paid = 0 AND p.paid > 0 AND p.paid <= i.amount"
)->fetch_assoc()['total'] ?? 0);

echo '[state] sid_type=' . $sidType
    . '; year_of_study=' . ($hasYearOfStudy ? 'present' : 'missing')
    . '; identity_issues=' . $identityIssues
    . '; context_issues=' . $contextIssues
    . '; duplicate_periods=' . $duplicates
    . '; reconciliation_candidates=' . $reconciliationCandidates
    . '; period_index=' . ($hasPeriodIndex ? 'present' : 'missing') . PHP_EOL;

if ($duplicates > 0) {
    fwrite(STDERR, "Refusing migration: duplicate student/year/semester invoice groups require reconciliation.\n");
    exit(3);
}

$constraints = [
    'chk_invoice_identity_match' => 'CHECK (SID = student_id)',
    'chk_invoice_period_valid' => "CHECK (academic_year REGEXP '^[0-9]{4}$' AND semester IN ('1','2','3','4'))",
    'chk_invoice_year_study_valid' => 'CHECK (year_of_study BETWEEN 1 AND 10)',
    'chk_invoice_amounts_valid' => 'CHECK (amount >= 0 AND amount_paid >= 0 AND amount_paid <= amount AND balance >= 0 AND ABS((amount_paid + balance) - amount) <= 0.01)',
    'chk_invoice_status_valid' => "CHECK (status IN ('Pending','Paid','Overdue'))",
];

if ($dryRun) {
    if (!str_starts_with($sidType, 'varchar(50)')) {
        echo "[dry-run] ALTER TABLE invoices MODIFY SID VARCHAR(50) NOT NULL\n";
    }
    if (!$hasYearOfStudy) {
        echo "[dry-run] ALTER TABLE invoices ADD year_of_study TINYINT UNSIGNED NULL AFTER academic_year\n";
    }
    echo "[dry-run] normalize invoice identity, program, academic year, semester, year of study, and status\n";
    echo "[dry-run] reconcile {$reconciliationCandidates} unambiguous normalized-payment allocation(s)\n";
    echo "[dry-run] cancel zero-value pending invoice mirror rows in student_payments\n";
    if (!$hasPeriodIndex) {
        echo "[dry-run] ALTER TABLE invoices ADD UNIQUE KEY uniq_invoice_student_period (student_id, academic_year, semester)\n";
    }
    foreach ($constraints as $name => $definition) {
        if (!$constraintExists($db, $name)) {
            echo "[dry-run] ALTER TABLE invoices ADD CONSTRAINT {$name} {$definition}\n";
        }
    }
    exit(0);
}

try {
    if (!str_starts_with($sidType, 'varchar(50)')) {
        $db->query('ALTER TABLE invoices MODIFY SID VARCHAR(50) NOT NULL');
        echo "[applied] invoices.SID VARCHAR(50)\n";
    }
    if (!$hasYearOfStudy) {
        $db->query('ALTER TABLE invoices ADD year_of_study TINYINT UNSIGNED NULL AFTER academic_year');
        echo "[applied] invoices.year_of_study\n";
    }

    $db->query(
        "UPDATE invoices i
            SET i.SID = i.student_id,
                i.academic_year = COALESCE(
                    NULLIF(CASE WHEN i.academic_year REGEXP '^[0-9]{4}$' THEN i.academic_year ELSE '' END, ''),
                    (SELECT sr.academic_year FROM semester_registration sr WHERE sr.student_id = i.student_id ORDER BY sr.id DESC LIMIT 1),
                    (SELECT s.academic_year FROM students s WHERE s.SID = i.student_id LIMIT 1),
                    YEAR(i.date_generated)
                ),
                i.semester = COALESCE(
                    NULLIF(CASE WHEN i.semester IN ('1','2','3','4') THEN i.semester ELSE '' END, ''),
                    (SELECT CAST(sr.semester AS CHAR) FROM semester_registration sr WHERE sr.student_id = i.student_id ORDER BY sr.id DESC LIMIT 1),
                    '1'
                ),
                i.program_code = COALESCE(
                    NULLIF(i.program_code, ''),
                    (SELECT sp.program_code FROM student_program sp WHERE sp.Sid = i.student_id AND COALESCE(sp.status,'active') <> 'inactive' ORDER BY sp.id DESC LIMIT 1),
                    (SELECT s.program FROM students s WHERE s.SID = i.student_id LIMIT 1),
                    'UNASSIGNED'
                ),
                i.year_of_study = COALESCE(
                    NULLIF(i.year_of_study, 0),
                    (SELECT sr.year_of_study FROM semester_registration sr WHERE sr.student_id = i.student_id ORDER BY sr.id DESC LIMIT 1),
                    (SELECT s.year FROM students s WHERE s.SID = i.student_id LIMIT 1),
                    1
                ),
                i.status = CASE WHEN i.status IN ('Pending','Paid','Overdue') THEN i.status ELSE 'Pending' END,
                i.payment_status = CASE
                    WHEN LOWER(COALESCE(i.payment_status,'')) IN ('paid','completed','success') THEN 'paid'
                    ELSE 'pending'
                END,
                i.balance = GREATEST(i.amount - i.amount_paid, 0),
                i.updated_at = NOW()
          WHERE i.SID <> i.student_id
             OR i.academic_year NOT REGEXP '^[0-9]{4}$'
             OR i.semester NOT IN ('1','2','3','4')
             OR i.program_code = ''
             OR i.year_of_study IS NULL OR i.year_of_study NOT BETWEEN 1 AND 10
             OR i.status NOT IN ('Pending','Paid','Overdue')
             OR LOWER(COALESCE(i.payment_status,'')) NOT IN ('pending','paid','completed','success')
             OR i.balance IS NULL OR ABS(i.balance - GREATEST(i.amount - i.amount_paid, 0)) > 0.01"
    );
    echo '[applied] normalized_invoice_rows=' . $db->affected_rows . PHP_EOL;

    $db->query(
        "UPDATE invoices i
          JOIN (
                SELECT student_id, academic_year, CAST(semester AS CHAR) AS semester, SUM(amount) AS paid
                  FROM payments
                 WHERE LOWER(status) IN ('completed','posted','confirmed','paid','success')
                 GROUP BY student_id, academic_year, semester
          ) p ON p.student_id = i.student_id
             AND p.academic_year = i.academic_year
             AND p.semester = i.semester
           SET i.amount_paid = LEAST(i.amount, p.paid),
               i.balance = GREATEST(i.amount - LEAST(i.amount, p.paid), 0),
               i.status = CASE WHEN p.paid + 0.01 >= i.amount THEN 'Paid' ELSE 'Pending' END,
               i.payment_status = CASE WHEN p.paid + 0.01 >= i.amount THEN 'paid' ELSE 'pending' END,
               i.updated_at = NOW()
         WHERE i.amount_paid = 0 AND p.paid > 0 AND p.paid <= i.amount"
    );
    echo '[applied] reconciled_invoice_rows=' . $db->affected_rows . PHP_EOL;

    $db->query(
        "UPDATE student_payments sp
          JOIN invoices i ON i.invoice_number = sp.reference_number
           SET sp.payment_status = 'failed',
               sp.status = 'cancelled',
               sp.reversal_reason = 'Retired zero-value invoice mirror; invoices is the charge ledger.',
               sp.updated_at = NOW()
         WHERE (LOWER(COALESCE(sp.channel,'')) = 'invoice' OR sp.channel IS NULL)
           AND sp.amount_paid = 0
           AND sp.payment_status = 'pending'"
    );
    echo '[applied] retired_invoice_mirror_rows=' . $db->affected_rows . PHP_EOL;

    if (!$hasPeriodIndex) {
        $db->query('ALTER TABLE invoices ADD UNIQUE KEY uniq_invoice_student_period (student_id, academic_year, semester)');
        echo "[applied] uniq_invoice_student_period\n";
    }
    foreach ($constraints as $name => $definition) {
        if (!$constraintExists($db, $name)) {
            $db->query("ALTER TABLE invoices ADD CONSTRAINT {$name} {$definition}");
            echo "[applied] {$name}\n";
        }
    }

    $remaining = $db->query(
        "SELECT COUNT(*) AS total FROM invoices
          WHERE SID <> student_id
             OR academic_year NOT REGEXP '^[0-9]{4}$'
             OR semester NOT IN ('1','2','3','4')
             OR year_of_study NOT BETWEEN 1 AND 10
             OR program_code = ''
             OR status NOT IN ('Pending','Paid','Overdue')
             OR amount < 0 OR amount_paid < 0 OR amount_paid > amount
             OR balance < 0 OR ABS((amount_paid + balance) - amount) > 0.01"
    );
    $remainingIssues = (int)($remaining->fetch_assoc()['total'] ?? 0);
    $remaining->free();
    if ($remainingIssues !== 0) {
        throw new RuntimeException("{$remainingIssues} invalid invoice row(s) remain.");
    }
    echo "[verified] invoice_integrity_issues=0\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[failed] ' . $e->getMessage() . PHP_EOL);
    exit(4);
}
