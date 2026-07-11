<?php
/**
 * Phase 6 (Multi-Portal Redesign) — exam/test payment eligibility settings.
 *
 * The CA gate already had enforce_ca_payment seeded in portal_settings. This
 * adds the parallel exam/test controls so the new is_student_allowed_exam()
 * gate (enforced in result_save_exam_mark) is visible and configurable by admins:
 *
 *   enforce_exam_payment   = '1'    (on)
 *   min_exam_paid_percent  = '100'  (full payment required for exam/test marks)
 *   min_ca_paid_percent    = '50'   (made explicit; was already the code default)
 *
 * Idempotent and non-destructive: existing rows are left untouched (an admin's
 * chosen value is never overwritten).
 *
 * Run:  php migrations/20260630_seed_exam_payment_settings.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';

/** @var mysqli $db */

$check = $db->query("SHOW TABLES LIKE 'portal_settings'");
if (!$check || $check->num_rows === 0) {
    fwrite(STDERR, "portal_settings table missing — run base migrations first.\n");
    exit(1);
}

$defaults = [
    'enforce_exam_payment'  => '1',
    'min_exam_paid_percent' => '100',
    'min_ca_paid_percent'   => '50',
];

// INSERT only when absent; the no-op ON DUPLICATE keeps any admin-set value.
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
        echo "  + seeded {$key} = {$value}\n";
    } else {
        echo "  = exists {$key} (left unchanged)\n";
    }
}
$stmt->close();

echo "Done. {$added} setting(s) seeded.\n";
