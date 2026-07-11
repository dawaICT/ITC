<?php
// Install LMS schema (lms_*) for eLearning admin module
// Uses the nested schema file that defines lms_* tables

require_once __DIR__ . '/connect.php';

$candidates = [
    // Preferred: nested schema with lms_* tables
    __DIR__ . '/../wucportal/db/elearning_schema.sql',
    // Fallback: top-level (may define el_* tables; not ideal for admin/elearning)
    __DIR__ . '/elearning_schema.sql',
];

$sqlFile = null;
foreach ($candidates as $path) {
    if (is_file($path)) { $sqlFile = $path; break; }
}

if (!$sqlFile) {
    fwrite(STDERR, "Schema file not found. Checked:\n - " . implode("\n - ", $candidates) . "\n");
    exit(1);
}

$sql = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "Failed to read schema file: {$sqlFile}\n");
    exit(1);
}

// Execute schema using multi_query to support multiple statements
if (!$db->multi_query($sql)) {
    fwrite(STDERR, "Error executing schema: " . $db->error . "\n");
    exit(1);
}
// Flush all results
do { /* no-op */ } while ($db->more_results() && $db->next_result());

echo "LMS schema installed from: {$sqlFile}\n";


