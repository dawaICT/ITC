<?php
require 'db/connect.php';

echo "Checking period_mode column addition...\n\n";

// Check if column exists
$result = $db->query("SHOW COLUMNS FROM programs LIKE 'period_mode'");
if ($result->num_rows > 0) {
    echo "✓ period_mode column exists\n";
} else {
    echo "✗ period_mode column NOT found\n";
}

// Count programs
$count_result = $db->query("SELECT COUNT(*) as cnt FROM programs");
$count_row = $count_result->fetch_assoc();
echo "Total programs in database: " . $count_row['cnt'] . "\n\n";

// Show sample programs
$sample = $db->query("SELECT program_code, program_name, period_mode, study_mode FROM programs LIMIT 5");
if ($sample->num_rows > 0) {
    echo "Sample programs:\n";
    while ($row = $sample->fetch_assoc()) {
        echo "  - " . $row['program_code'] . " (" . $row['program_name'] . "): period_mode=" . ($row['period_mode'] ?? 'NULL') . ", study_mode=" . ($row['study_mode'] ?? 'NULL') . "\n";
    }
} else {
    echo "No programs found in database.\n";
}

echo "\nDone!\n";
$db->close();
?>
