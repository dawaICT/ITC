<?php
// Installer for eLearning tables and permissions
require_once __DIR__ . '/connect.php';

function run_sql_file(mysqli $db, string $path): array {
    $sql = @file_get_contents($path);
    if ($sql === false) return [false, ["Failed to read $path"]];
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/',$sql)));
    $errors = []; $ok = true;
    foreach ($statements as $statement) {
        if ($statement === '' || strpos($statement, '--') === 0) continue;
        if (!$db->query($statement)) { $ok = false; $errors[] = $db->error; }
    }
    return [$ok, $errors];
}

[$ok1, $err1] = run_sql_file($db, __DIR__ . '/elearning_schema.sql');
[$ok2, $err2] = run_sql_file($db, dirname(__DIR__) . '/admin/sql/elearning_permissions.sql');

if ($ok1 && $ok2) {
    echo "eLearning setup completed successfully\n";
} else {
    echo "Errors during setup:\n";
    foreach (array_merge($err1, $err2) as $e) echo "- $e\n";
}

@mysqli_close($db);


