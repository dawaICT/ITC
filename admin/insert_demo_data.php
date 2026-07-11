<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Start session
session_start();

// Define root path
define('ROOT_PATH', dirname(__DIR__));

// Include database connection
require_once ROOT_PATH . '/includes/db_connect.php';

// Verify database connection
if (!isset($db) || !$db) {
    die("Database connection failed. Please check your database configuration.");
}

echo "<h2>Inserting Demo Data</h2>";

// Sample programs data
$programs = [
    ['BSCE', 'Bachelor of Science in Computer Engineering'],
    ['BSEE', 'Bachelor of Science in Electrical Engineering'],
    ['BSME', 'Bachelor of Science in Mechanical Engineering']
];

// Sample fee structures data
$fee_structures = [
    // BSCE Fees
    ['BSCE', 1, 1, 'Tuition Fee', 52000.00],
    ['BSCE', 1, 1, 'Library Fee', 2000.00],
    ['BSCE', 1, 1, 'Laboratory Fee', 3500.00],
    ['BSCE', 1, 2, 'Tuition Fee', 52000.00],
    ['BSCE', 1, 2, 'Library Fee', 2000.00],
    ['BSCE', 2, 1, 'Tuition Fee', 55000.00],
    ['BSCE', 2, 1, 'Library Fee', 2000.00]
];

// Start transaction
$db->begin_transaction();

try {
    // Insert programs
    echo "<h3>Inserting Programs</h3>";
    $stmt = $db->prepare("INSERT INTO programs (program_code, program_name) VALUES (?, ?)");
    
    foreach ($programs as $program) {
        $stmt->bind_param("ss", $program[0], $program[1]);
        if ($stmt->execute()) {
            echo "<p>Inserted program: {$program[0]} - {$program[1]}</p>";
        } else {
            throw new Exception("Error inserting program {$program[0]}: " . $stmt->error);
        }
    }
    
    // Insert fee structures
    echo "<h3>Inserting Fee Structures</h3>";
    $stmt = $db->prepare("INSERT INTO fee_structures (program_code, year_of_study, semester, fee_description, amount) VALUES (?, ?, ?, ?, ?)");
    
    foreach ($fee_structures as $fee) {
        $stmt->bind_param("siisd", $fee[0], $fee[1], $fee[2], $fee[3], $fee[4]);
        if ($stmt->execute()) {
            echo "<p>Inserted fee: {$fee[0]} - Year {$fee[1]} Semester {$fee[2]} - {$fee[3]} (ZMK " . number_format($fee[4], 2) . ")</p>";
        } else {
            throw new Exception("Error inserting fee structure: " . $stmt->error);
        }
    }
    
    // Commit transaction
    $db->commit();
    echo "<div class='alert alert-success'>Demo data inserted successfully!</div>";
    
} catch (Exception $e) {
    // Rollback transaction on error
    $db->rollback();
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
}

// Display current data
echo "<h3>Current Programs</h3>";
$result = $db->query("SELECT * FROM programs ORDER BY program_code");
if ($result) {
    echo "<table class='table table-hover align-middle'>";
    echo "<thead class='table-light'><tr><th>Program Code</th><th>Program Name</th><th>Status</th></tr></thead>";
    echo "<tbody>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['program_code']}</td>";
        echo "<td>{$row['program_name']}</td>";
        echo "<td>{$row['status']}</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
}

echo "<h3>Current Fee Structures</h3>";
$result = $db->query("SELECT f.*, p.program_name 
                     FROM fee_structures f 
                     JOIN programs p ON f.program_code = p.program_code 
                     ORDER BY f.program_code, f.year_of_study, f.semester");
if ($result) {
    echo "<table class='table table-hover align-middle'>";
    echo "<thead class='table-light'><tr><th>Program</th><th>Year</th><th>Semester</th><th>Description</th><th>Amount</th></tr></thead>";
    echo "<tbody>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['program_name']}</td>";
        echo "<td>{$row['year_of_study']}</td>";
        echo "<td>{$row['semester']}</td>";
        echo "<td>{$row['fee_description']}</td>";
        echo "<td>ZMK " . number_format($row['amount'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
}

// Close connection
$db->close();
?> 