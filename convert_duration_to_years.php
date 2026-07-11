<?php
/**
 * Migration script to convert program_duration from months to years
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once "db/connect.php";

echo "<h1>Convert Program Duration: Months → Years</h1>";
echo "<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    h1, h2, h3 { color: #333; }
    .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .success { color: #28a745; font-weight: bold; }
    .error { color: #dc3545; font-weight: bold; }
    .info { color: #17a2b8; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
    th { background: #f8f9fa; font-weight: 600; }
    tr:nth-child(even) { background: #f8f9fa; }
</style>";

$db->begin_transaction();

try {
    echo "<div class='section'>";
    echo "<h2>Step 1: Check Current Data</h2>";
    
    // Get current programs with duration
    $result = $db->query("SELECT program_code, program_name, program_duration FROM programs WHERE program_duration IS NOT NULL ORDER BY program_code");
    
    if ($result && $result->num_rows > 0) {
        echo "<p class='info'>Found {$result->num_rows} programs with duration values</p>";
        echo "<h3>Before Conversion (in months):</h3>";
        echo "<table>";
        echo "<tr><th>Code</th><th>Name</th><th>Duration (Months)</th><th>Will Convert To (Years)</th></tr>";
        
        $programs = [];
        while ($row = $result->fetch_assoc()) {
            $programs[] = $row;
            $months = floatval($row['program_duration']);
            $years = round($months / 12, 1);
            
            echo "<tr>";
            echo "<td>{$row['program_code']}</td>";
            echo "<td>" . htmlspecialchars($row['program_name']) . "</td>";
            echo "<td>{$months} months</td>";
            echo "<td class='info'>{$years} years</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>No programs have duration values set</p>";
        $programs = [];
    }
    echo "</div>";
    
    echo "<div class='section'>";
    echo "<h2>Step 2: Convert Duration Values</h2>";
    
    if (!empty($programs)) {
        $updated = 0;
        
        foreach ($programs as $program) {
            $months = floatval($program['program_duration']);
            $years = round($months / 12, 1);
            
            $stmt = $db->prepare("UPDATE programs SET program_duration = ? WHERE program_code = ?");
            $stmt->bind_param("ds", $years, $program['program_code']);
            
            if ($stmt->execute()) {
                $updated++;
            } else {
                throw new Exception("Failed to update {$program['program_code']}: " . $stmt->error);
            }
            $stmt->close();
        }
        
        echo "<p class='success'>✓ Successfully converted $updated program(s) from months to years</p>";
    } else {
        echo "<p class='info'>→ No conversions needed</p>";
    }
    echo "</div>";
    
    echo "<div class='section'>";
    echo "<h2>Step 3: Update Column Definition</h2>";
    
    // Update column to have better precision for years
    $alter = $db->query("ALTER TABLE programs MODIFY COLUMN program_duration DECIMAL(4,1) DEFAULT NULL COMMENT 'Duration in years'");
    
    if ($alter) {
        echo "<p class='success'>✓ Updated program_duration column definition</p>";
        echo "<p class='info'>→ Column now: DECIMAL(4,1) with comment 'Duration in years'</p>";
    } else {
        throw new Exception("Failed to alter column: " . $db->error);
    }
    echo "</div>";
    
    echo "<div class='section'>";
    echo "<h2>Step 4: Verify Results</h2>";
    
    $verify = $db->query("SELECT program_code, program_name, program_duration FROM programs WHERE program_duration IS NOT NULL ORDER BY program_code");
    
    if ($verify && $verify->num_rows > 0) {
        echo "<h3>After Conversion (in years):</h3>";
        echo "<table>";
        echo "<tr><th>Code</th><th>Name</th><th>Duration (Years)</th></tr>";
        
        while ($row = $verify->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['program_code']}</td>";
            echo "<td>" . htmlspecialchars($row['program_name']) . "</td>";
            echo "<td class='success'>{$row['program_duration']} years</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    echo "</div>";
    
    // Commit transaction
    $db->commit();
    
    echo "<div class='section'>";
    echo "<h2 class='success'>✓ Conversion Complete!</h2>";
    echo "<p>All program durations have been successfully converted from months to years.</p>";
    echo "<p class='info'><strong>Next Steps:</strong></p>";
    echo "<ul>";
    echo "<li>Test the <a href='admin/programs.php'>programs.php</a> page</li>";
    echo "<li>Add/edit programs to verify the new year-based duration works correctly</li>";
    echo "<li>Common values: 1 year (certificates), 2 years (diplomas), 3-4 years (degrees)</li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    $db->rollback();
    
    echo "<div class='section'>";
    echo "<h2 class='error'>✗ Conversion Failed</h2>";
    echo "<p class='error'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p class='info'>All changes have been rolled back.</p>";
    echo "</div>";
}

$db->close();
?>
