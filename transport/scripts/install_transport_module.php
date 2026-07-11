<?php
require_once __DIR__ . '/../includes/transport.php';

header('Content-Type: text/html; charset=utf-8');

if (!isSystemsAdmin() && !currentStaffHasPermission('transport_manage')) {
    http_response_code(403);
    echo '<h3>Transport installer</h3><p>You need transport management permission to install or update module tables.</p>';
    exit;
}

function transport_run_multi_query(mysqli $db, string $sql): void
{
    if (!$db->multi_query($sql)) {
        throw new RuntimeException($db->error);
    }

    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
        if ($db->more_results() && !$db->next_result()) {
            throw new RuntimeException($db->error);
        }
    } while ($db->more_results());
}

$schemaPath = realpath(__DIR__ . '/../../migrations/20260602_transport_management_system.sql');
if (!$schemaPath || !file_exists($schemaPath)) {
    http_response_code(500);
    echo '<h3>Transport installer</h3><p>Schema file not found: migrations/20260602_transport_management_system.sql</p>';
    exit;
}

try {
    transport_run_multi_query($db, file_get_contents($schemaPath));
    echo '<h3>Transport installer</h3><p>Transport management tables installed successfully.</p>';
    echo '<p><a href="../transport_management.php">Go to Transport Management</a></p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h3>Transport installer</h3><p>Install failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}
