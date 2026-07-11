<?php
require 'db/connect.php';

echo "Checking for term-based programs...\n\n";

// Check for existing term-based programs
$check = $db->query("SELECT program_code, program_name, period_mode FROM programs WHERE period_mode = 'term' ORDER BY program_name ASC");

if ($check->num_rows > 0) {
    echo "✓ Found " . $check->num_rows . " term-based program(s):\n";
    while ($row = $check->fetch_assoc()) {
        echo "  - " . $row['program_code'] . " (" . $row['program_name'] . ")\n";
    }
} else {
    echo "✗ No term-based programs found. Adding test term-based program...\n\n";
    
    // Add a test term-based program
    $program_code = 'TRM-ADM';
    $program_name = 'Administration (Term-Based)';
    $program_type = 'diploma';
    $study_mode = 'fulltime';
    $period_mode = 'term';
    $duration_months = 36;
    
    $insert = $db->prepare("
        INSERT INTO programs (program_code, program_name, program_type, study_mode, period_mode, duration_months)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    if ($insert) {
        $insert->bind_param("sssssi", $program_code, $program_name, $program_type, $study_mode, $period_mode, $duration_months);
        
        if ($insert->execute()) {
            echo "✓ Successfully added term-based program: $program_code ($program_name)\n";
            echo "  - Period Mode: $period_mode (Term 1, 2, 3)\n";
            echo "  - Study Mode: $study_mode\n";
            echo "  - Duration: $duration_months months\n";
        } else {
            echo "✗ Error adding program: " . $insert->error . "\n";
        }
        $insert->close();
    } else {
        echo "✗ Error preparing statement: " . $db->error . "\n";
    }
}

echo "\nAll programs in database:\n";
$all = $db->query("SELECT program_code, program_name, period_mode, study_mode FROM programs ORDER BY period_mode, program_name");
echo str_pad("Code", 12) . " | " . str_pad("Name", 30) . " | " . str_pad("Period Mode", 12) . " | Study Mode\n";
echo str_repeat("-", 70) . "\n";
while ($row = $all->fetch_assoc()) {
    echo str_pad($row['program_code'], 12) . " | " . 
         str_pad($row['program_name'], 30) . " | " . 
         str_pad($row['period_mode'], 12) . " | " . 
         $row['study_mode'] . "\n";
}

$db->close();
?>
