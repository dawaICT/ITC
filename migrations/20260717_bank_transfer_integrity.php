<?php
/**
 * Prevent the same external bank reference from being credited twice.
 *
 * Usage:
 *   php migrations/20260717_bank_transfer_integrity.php --dry-run
 *   php migrations/20260717_bank_transfer_integrity.php --apply
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
    fwrite(STDERR, "Usage: php migrations/20260717_bank_transfer_integrity.php [--dry-run|--apply]\n");
    exit(1);
}

$table = $db->query("SHOW TABLES LIKE 'payment_gateway_transactions'");
if (!$table || $table->num_rows === 0) {
    fwrite(STDERR, "payment_gateway_transactions is missing. Run the payment foundation migration first.\n");
    exit(1);
}
$table->free();

$index = $db->query("SHOW INDEX FROM payment_gateway_transactions WHERE Key_name = 'uniq_pgt_provider_external_ref'");
if ($index && $index->num_rows > 0) {
    $index->free();
    echo "[ok] uniq_pgt_provider_external_ref already exists.\n";
    exit(0);
}
if ($index) {
    $index->free();
}

$duplicates = $db->query(
    "SELECT COUNT(*) AS duplicate_groups
       FROM (
            SELECT provider, provider_transaction_id
              FROM payment_gateway_transactions
             WHERE provider_transaction_id IS NOT NULL
               AND provider_transaction_id <> ''
             GROUP BY provider, provider_transaction_id
            HAVING COUNT(*) > 1
       ) duplicate_refs"
);
$duplicateGroups = $duplicates ? (int)($duplicates->fetch_assoc()['duplicate_groups'] ?? 0) : 0;
if ($duplicates) {
    $duplicates->free();
}

if ($duplicateGroups > 0) {
    fwrite(STDERR, "Refusing to add the unique index: {$duplicateGroups} duplicate provider-reference group(s) require reconciliation.\n");
    exit(2);
}

$ddl = 'ALTER TABLE payment_gateway_transactions ADD UNIQUE KEY uniq_pgt_provider_external_ref (provider, provider_transaction_id)';
if ($dryRun) {
    echo "[dry-run] {$ddl}\n";
    exit(0);
}

$db->query($ddl);
echo "[applied] uniq_pgt_provider_external_ref\n";
