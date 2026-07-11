<?php
require_once "../../db/connect.php";

header('Content-Type: text/html; charset=utf-8');

function run_multi_query(mysqli $db, string $sql): array {
    $results = [];
    if ($db->multi_query($sql)) {
        do {
            if ($res = $db->store_result()) {
                $results[] = $res->fetch_all(MYSQLI_ASSOC);
                $res->free();
            }
        } while ($db->more_results() && $db->next_result());
    }
    return $results;
}

$schemaPath = realpath(__DIR__ . '/../../db/finance_module.sql');
if (!$schemaPath || !file_exists($schemaPath)) {
    http_response_code(500);
    echo '<h3>Finance installer</h3><p>Schema file not found: db/finance_module.sql</p>';
    exit;
}

$sql = file_get_contents($schemaPath);
try {
    run_multi_query($db, $sql);
    echo '<h3>Finance installer</h3><p>Finance tables installed successfully.</p>';
    echo '<p><a href="../finance.php">Go to Finance Dashboard</a></p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h3>Finance installer</h3><p>Install failed: ' . htmlspecialchars($e->getMessage()) . '</p>';
}


