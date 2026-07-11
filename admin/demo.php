<?php
// Enable detailed error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', 1);

// Start session
session_start();

// Define root path
define('ROOT_PATH', dirname(__DIR__));

// Include database connection
require_once ROOT_PATH . '/includes/db_connect.php';

// Include header
require_once ROOT_PATH . '/admin/includes/header.php';
// Debug information
echo "<div class='debug-info' style='background: #f8f9fa; padding: 10px; margin-bottom: 20px; border: 1px solid #ddd;'>";
echo "<h4>Debug Information:</h4>";

// Check if database connection exists
if (!isset($db)) {
    die("<div class='alert alert-danger'>Database connection object (\$db) is not defined</div>");
}

// Check database connection
if ($db->connect_error) {
    die("<div class='alert alert-danger'>Database connection failed: " . $db->connect_error . "</div>");
}

// Verify database and tables
$tables_to_check = ['programs', 'fee_structures'];
$missing_tables = [];

foreach ($tables_to_check as $table) {
    $result = $db->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows == 0) {
        $missing_tables[] = $table;
    }
}

if (!empty($missing_tables)) {
    echo "<div class='alert alert-warning'>Missing tables: " . implode(', ', $missing_tables) . "</div>";
    
    // Create missing tables
    $create_tables = [
        "CREATE TABLE IF NOT EXISTS programs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program_code VARCHAR(10) NOT NULL,
            program_name VARCHAR(100) NOT NULL,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE IF NOT EXISTS fee_structures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program_code VARCHAR(10) NOT NULL,
            year_of_study INT NOT NULL,
            semester INT NOT NULL,
            fee_description VARCHAR(100) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    ];

    foreach ($create_tables as $query) {
        if (!$db->query($query)) {
            echo "<div class='alert alert-danger'>Error creating table: " . $db->error . "</div>";
        }
    }
}

// Check session status
echo "<p>Session Status: " . (session_status() === PHP_SESSION_ACTIVE ? "Active" : "Inactive") . "</p>";
echo "<p>Database Connection: " . ($db->ping() ? "Connected" : "Disconnected") . "</p>";
echo "</div>";

// Insert sample data if tables are empty
$check_programs = $db->query("SELECT COUNT(*) as count FROM programs");
$programs_count = $check_programs->fetch_assoc()['count'];

if ($programs_count == 0) {
    $sample_programs = [
        "('BSCE', 'Bachelor of Science in Computer Engineering')",
        "('BSEE', 'Bachelor of Science in Electrical Engineering')"
    ];
    
    $insert_programs = "INSERT INTO programs (program_code, program_name) VALUES " . implode(", ", $sample_programs);
    $db->query($insert_programs);
}

$check_fees = $db->query("SELECT COUNT(*) as count FROM fee_structures");
$fees_count = $check_fees->fetch_assoc()['count'];

if ($fees_count == 0) {
    $sample_fees = [
        "('BSCE', 1, 1, 'Tuition Fee', 52000.00)",
        "('BSCE', 1, 1, 'Library Fee', 2000.00)",
        "('BSEE', 1, 1, 'Tuition Fee', 48000.00)",
        "('BSEE', 1, 1, 'Library Fee', 2000.00)"
    ];
    
    $insert_fees = "INSERT INTO fee_structures (program_code, year_of_study, semester, fee_description, amount) VALUES " . implode(", ", $sample_fees);
    $db->query($insert_fees);
}

// Fetch and display data
$programs = $db->query("SELECT * FROM programs WHERE status = 'active'");
if ($programs === false) {
    echo "<div class='alert alert-danger'>Error fetching programs: " . $db->error . "</div>";
}

$fees = $db->query("SELECT f.*, p.program_name 
                   FROM fee_structures f 
                   JOIN programs p ON f.program_code = p.program_code 
                   WHERE f.status = 'active'");
if ($fees === false) {
    echo "<div class='alert alert-danger'>Error fetching fee structures: " . $db->error . "</div>";
}
?>

<div class="container-fluid px-4">
    <h1 class="mt-4">Demo Page</h1>
    
    <!-- Programs Section -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-graduation-cap me-1"></i>
            Available Programs
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Program Code</th>
                            <th>Program Name</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($programs && $programs->num_rows > 0): ?>
                            <?php while($program = $programs->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($program['program_code']); ?></td>
                                <td><?php echo htmlspecialchars($program['program_name']); ?></td>
                                <td><?php echo htmlspecialchars($program['status']); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center">No programs found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Fee Structures Section -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-money-bill-wave me-1"></i>
            Fee Structures
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Program</th>
                            <th>Year</th>
                            <th>Semester</th>
                            <th>Fee Description</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($fees && $fees->num_rows > 0): ?>
                            <?php while($fee = $fees->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($fee['program_name']); ?></td>
                                <td><?php echo htmlspecialchars($fee['year_of_study']); ?></td>
                                <td><?php echo htmlspecialchars($fee['semester']); ?></td>
                                <td><?php echo htmlspecialchars($fee['fee_description']); ?></td>
                                <td>ZMK <?php echo number_format($fee['amount'], 2); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center">No fee structures found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php 
// Include footer
require_once ROOT_PATH . '/admin/includes/footer.php'; 
?> 