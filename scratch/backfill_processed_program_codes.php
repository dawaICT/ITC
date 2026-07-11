<?php
declare(strict_types=1);
/**
 * One-time backfill: set processed_applicants.program_code from programs catalogue.
 * Run: php scratch/backfill_processed_program_codes.php
 */
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/applicant_program_helpers.php';

$updated = 0;
$res = $db->query("SELECT id, program, program_code FROM processed_applicants WHERE program_code IS NULL OR program_code = ''");
while ($row = $res->fetch_assoc()) {
    $resolved = wuc_resolve_applicant_program($db, (string)($row['program'] ?? ''));
    if (!$resolved['valid']) {
        echo "Skip id={$row['id']} — orphan programme '{$row['program']}'\n";
        continue;
    }
    $stmt = $db->prepare('UPDATE processed_applicants SET program_code = ?, program = ? WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('ssi', $resolved['code'], $resolved['code'], $row['id']);
        if ($stmt->execute()) {
            $updated++;
            echo "Updated id={$row['id']} -> {$resolved['code']}\n";
        }
        $stmt->close();
    }
}

echo "Done. Updated {$updated} row(s).\n";
