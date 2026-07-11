<?php
/**
 * Backfill incomplete student account provisioning.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/backfill_student_provisioning.php
 *   C:\xampp\php\php.exe scripts/backfill_student_provisioning.php --apply
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$apply = in_array('--apply', $argv ?? [], true);
$argv = ['backfill_student_provisioning.php', '--type=student'];
if ($apply) {
    $argv[] = '--apply';
}

require __DIR__ . '/audit_account_creation.php';
