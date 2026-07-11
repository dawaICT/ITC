<?php
/**
 * CLI-ONLY maintenance script: grant the E-Library permission set to the
 * Lecturer and Admin positions.
 *
 * SECURITY: restricted to the command line so it cannot be triggered over HTTP.
 * Run it from the server console:
 *
 *     php admin/scripts/grant_library_permissions.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$assignments = [
    'ADM009' => [
        ['library_manage', 'Full library administration'],
        ['library_catalog', 'Add and maintain catalog records'],
        ['library_circulation', 'Checkout, returns, reservations'],
        ['library_fines', 'Assess and settle fines'],
        ['library_digital', 'Manage digital resources']
    ],
    'LEC001' => [
        ['library_digital', 'Manage and access digital resources']
    ]
];

$db->begin_transaction();
try {
    $stmt = $db->prepare("INSERT INTO role_permissions (PosID, permission_name, permission_description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permission_description=VALUES(permission_description)");
    foreach ($assignments as $posId => $perms) {
        foreach ($perms as $p) {
            [$name, $desc] = $p;
            $stmt->bind_param('sss', $posId, $name, $desc);
            $stmt->execute();
        }
    }
    $db->commit();
    fwrite(STDOUT, "E-Library permissions granted to Lecturers and Admin.\n");
} catch (Exception $e) {
    $db->rollback();
    fwrite(STDERR, 'Failed to grant permissions: ' . $e->getMessage() . "\n");
    exit(1);
}
