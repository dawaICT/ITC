<?php
// Simple migration runner for create_fee_structure.sql
error_reporting(E_ALL);
ini_set('display_errors', 1);

$root = dirname(__DIR__, 1); // admin/
$base = dirname($root); // project root

require_once $base . '/db/connect.php'; // provides $db (mysqli)

$sqlFile = $base . '/create_fee_structure.sql';
if (!file_exists($sqlFile)) {
    echo "ERROR: SQL file not found: $sqlFile\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);
$queries = array_filter(array_map('trim', explode(';', $sql)));

$db->begin_transaction();
try {
    foreach ($queries as $q) {
        if ($q === '' || stripos($q, '--') === 0) {
            continue;
        }
        if (!$db->query($q)) {
            throw new Exception('MySQL error: ' . $db->error . "\nQuery: " . $q);
        }
    }
    $db->commit();
    echo "Migration completed successfully.\n";
} catch (Throwable $e) {
    $db->rollback();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
} 