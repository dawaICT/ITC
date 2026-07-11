<?php
require 'db/connect.php';

echo "Testing program selection query...\n";

$result = $db->query("SELECT program_code, program_name, term_based FROM programs ORDER BY program_name");
if ($result) {
    echo "Programs found:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['program_code'] . ": " . $row['program_name'] . " (term_based: " . ($row['term_based'] ? 'Yes' : 'No') . ")\n";
    }
} else {
    echo "Query failed: " . $db->error . "\n";
}
?>