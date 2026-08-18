<?php
/**
 * DPO Pay / PayGate online payments — foundation migration.
 *
 * 1. Creates payment_gateway_transactions (the table payment_helpers.php has
 *    referenced since the fees-side DPO flow was built, but which was never
 *    migrated — the DML-only app user cannot run the old runtime CREATE TABLE).
 *    Adds the new columns needed for the course-registration payment leg:
 *    payment_type, semester_registration_id, program_code, course_codes,
 *    result_code, result_desc, receipt_no.
 * 2. Adds amount_paid/balance to invoices so partial payments can be tracked
 *    (payment_apply_completed_payment refuses partials without them).
 * 3. Seeds portal_settings keys for the DPO gateway and the registration
 *    payment gate. Existing admin-set values are never overwritten.
 *
 * Idempotent: safe to re-run. Run as a privileged DB user:
 *   php migrations/20260703_dpo_paygate_payments.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';

/** @var mysqli $db */

function mig_column_exists(mysqli $db, string $table, string $column): bool
{
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($column);
    if ($res = $db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'")) {
        $ok = $res->num_rows > 0;
        $res->free();
        return $ok;
    }
    return false;
}

function mig_table_exists(mysqli $db, string $table): bool
{
    $t = $db->real_escape_string($table);
    if ($res = $db->query("SHOW TABLES LIKE '{$t}'")) {
        $ok = $res->num_rows > 0;
        $res->free();
        return $ok;
    }
    return false;
}

echo "== 1. payment_gateway_transactions ==\n";

if (!mig_table_exists($db, 'payment_gateway_transactions')) {
    $db->query("CREATE TABLE payment_gateway_transactions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(64) NOT NULL DEFAULT '',
        student_id VARCHAR(50) NOT NULL,
        payment_type VARCHAR(32) NOT NULL DEFAULT 'fee_payment',
        semester_registration_id INT DEFAULT NULL,
        program_code VARCHAR(20) DEFAULT NULL,
        course_codes TEXT DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(10) NOT NULL DEFAULT 'ZMW',
        narration VARCHAR(255) DEFAULT NULL,
        provider VARCHAR(32) NOT NULL,
        reference_number VARCHAR(100) NOT NULL,
        provider_transaction_id VARCHAR(100) DEFAULT NULL,
        provider_token VARCHAR(100) DEFAULT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        result_code VARCHAR(16) DEFAULT NULL,
        result_desc VARCHAR(255) DEFAULT NULL,
        receipt_no VARCHAR(50) DEFAULT NULL,
        proof_file VARCHAR(255) DEFAULT NULL,
        proof_mime VARCHAR(100) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        request_payload LONGTEXT DEFAULT NULL,
        response_payload LONGTEXT DEFAULT NULL,
        created_by VARCHAR(50) DEFAULT NULL,
        verified_by VARCHAR(50) DEFAULT NULL,
        verified_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_payment_gateway_ref (reference_number),
        UNIQUE KEY uniq_payment_gateway_token (provider_token),
        UNIQUE KEY uniq_pgt_provider_external_ref (provider, provider_transaction_id),
        KEY idx_payment_gateway_student (student_id),
        KEY idx_payment_gateway_invoice (invoice_number),
        KEY idx_payment_gateway_status (status),
        KEY idx_payment_gateway_provider (provider),
        KEY idx_pgt_type (payment_type),
        KEY idx_pgt_semreg (semester_registration_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($db->error) {
        fwrite(STDERR, "  ! create failed: {$db->error}\n");
        exit(1);
    }
    echo "  + created payment_gateway_transactions\n";
} else {
    // Table pre-exists (e.g. created manually): add any missing new columns.
    $adds = [
        'payment_type' => "ADD COLUMN payment_type VARCHAR(32) NOT NULL DEFAULT 'fee_payment' AFTER student_id",
        'semester_registration_id' => "ADD COLUMN semester_registration_id INT DEFAULT NULL AFTER payment_type",
        'program_code' => "ADD COLUMN program_code VARCHAR(20) DEFAULT NULL AFTER semester_registration_id",
        'course_codes' => "ADD COLUMN course_codes TEXT DEFAULT NULL AFTER program_code",
        'result_code' => "ADD COLUMN result_code VARCHAR(16) DEFAULT NULL AFTER status",
        'result_desc' => "ADD COLUMN result_desc VARCHAR(255) DEFAULT NULL AFTER result_code",
        'receipt_no' => "ADD COLUMN receipt_no VARCHAR(50) DEFAULT NULL AFTER result_desc",
    ];
    foreach ($adds as $col => $ddl) {
        if (!mig_column_exists($db, 'payment_gateway_transactions', $col)) {
            $db->query("ALTER TABLE payment_gateway_transactions {$ddl}");
            echo $db->error ? "  ! {$col}: {$db->error}\n" : "  + added column {$col}\n";
        } else {
            echo "  = column {$col} exists\n";
        }
    }
}

echo "== 2. invoices partial-payment columns ==\n";

if (!mig_table_exists($db, 'invoices')) {
    echo "  ! invoices table missing — skipped (fees module base migration required)\n";
} else {
    if (!mig_column_exists($db, 'invoices', 'amount_paid')) {
        $db->query("ALTER TABLE invoices ADD COLUMN amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER amount");
        echo $db->error ? "  ! amount_paid: {$db->error}\n" : "  + added invoices.amount_paid\n";
    } else {
        echo "  = invoices.amount_paid exists\n";
    }
    if (!mig_column_exists($db, 'invoices', 'balance')) {
        $db->query("ALTER TABLE invoices ADD COLUMN balance DECIMAL(10,2) DEFAULT NULL AFTER amount_paid");
        echo $db->error ? "  ! balance: {$db->error}\n" : "  + added invoices.balance\n";
        // Backfill: paid invoices carry no balance; everything else owes the
        // full invoice amount (there was no partial tracking before this).
        $db->query("UPDATE invoices
                       SET amount_paid = CASE WHEN status = 'Paid' THEN amount ELSE amount_paid END,
                           balance     = CASE WHEN status = 'Paid' THEN 0.00 ELSE amount END
                     WHERE balance IS NULL");
        echo $db->error ? "  ! backfill: {$db->error}\n" : "  + backfilled balances ({$db->affected_rows} rows)\n";
    } else {
        echo "  = invoices.balance exists\n";
    }
}

echo "== 3. portal_settings seeds ==\n";

if (!mig_table_exists($db, 'portal_settings')) {
    fwrite(STDERR, "  ! portal_settings table missing — run base migrations first.\n");
    exit(1);
}

$defaults = [
    // DPO Pay gateway (hosted checkout, API 3G v6)
    'dpo_enabled' => '0',
    'dpo_company_token' => '',
    'dpo_service_type' => '',
    'dpo_api_url' => 'https://secure.3gdirectpay.com/API/v6/',
    'dpo_payment_url' => 'https://secure.3gdirectpay.com/payv2.php',
    'dpo_currency' => 'ZMW',
    'dpo_ptl_hours' => '24',
    'dpo_debug_mode' => '0',
    'dpo_default_payment' => '',
    'dpo_default_payment_country' => '',
    'dpo_default_payment_mno' => '',
    // Course-registration payment gate policy
    'reg_payment_gate_enabled' => '1',
    'reg_payment_threshold_pct' => '50',
    'reg_pending_payment_expiry_days' => '7',
];

$stmt = $db->prepare(
    "INSERT INTO portal_settings (setting_key, setting_value)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE updated_at = updated_at"
);
$added = 0;
foreach ($defaults as $key => $value) {
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    if ($db->affected_rows > 0) {
        $added++;
        echo "  + seeded {$key}\n";
    } else {
        echo "  = exists {$key}\n";
    }
}
$stmt->close();

echo "Done. {$added} setting(s) seeded.\n";
