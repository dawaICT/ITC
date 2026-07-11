<?php
$isCli = PHP_SAPI === 'cli';
if ($isCli) {
    require_once __DIR__ . "/../../db/connect.php";
} else {
    require_once __DIR__ . "/../includes/admin.php";
}

header('Content-Type: text/html; charset=utf-8');

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
    echo '<p><a href="/wucportal/transport.php">Go to Transport Management</a></p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h3>Transport installer</h3><p>Install failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}
