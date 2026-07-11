<?php
// Production-safe error handling
ini_set('display_errors', '0');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Include database connection
require_once 'includes/db_connect.php';

// Enable SSL if needed (uncomment and configure paths)
// $db->ssl_set('/path/to/client-key.pem', '/path/to/client-cert.pem', '/path/to/ca-cert.pem', NULL, NULL);

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "Database connection successful\n";

// Check total number of students
$count_result = $db->query("SELECT COUNT(*) as total FROM students");
if ($count_result === false) {
    die("Count query failed: " . $db->error);
}

$count = $count_result->fetch_assoc();
echo "Total students: " . $count['total'] . "\n";
$count_result->free();

// Analyze SID patterns
$patterns = $db->query("SELECT 
    LEFT(SID, 2) as year,
    SUBSTRING(SID, 3, 2) as month,
    SUBSTRING(SID, 5, 2) as day,
    LENGTH(SID) as id_length,
    COUNT(*) as count
FROM students 
GROUP BY year, month, day, id_length
ORDER BY year DESC, month DESC, day DESC
LIMIT 10");

if ($patterns === false) {
    die("Pattern query failed: " . $db->error);
}

echo "\nSID patterns analysis:\n";
while ($row = $patterns->fetch_assoc()) {
    echo "Pattern: Year: 20{$row['year']}, Month: {$row['month']}, Day: {$row['day']}, " .
         "Length: {$row['id_length']}, Count: {$row['count']}\n";
}
$patterns->free();

// Get sample of actual SIDs (robust if created_at column doesn't exist)
$hasCreatedAt = false;
if ($res = $db->query("SHOW COLUMNS FROM students LIKE 'created_at'")) {
    $hasCreatedAt = $res->num_rows > 0;
    $res->free();
}

if ($hasCreatedAt) {
    $samples = $db->query("SELECT SID, created_at FROM students ORDER BY created_at DESC LIMIT 5");
} else {
    $samples = $db->query("SELECT SID FROM students ORDER BY SID DESC LIMIT 5");
}
if ($samples !== false) {
    echo "\nRecent SID examples:\n";
    while ($row = $samples->fetch_assoc()) {
        $created = $row['created_at'] ?? 'N/A';
        echo "SID: {$row['SID']} (Created: {$created})\n";
    }
    $samples->free();
}

// Validate student ID format
$student_id = '230919254';
if (strlen($student_id) !== 9 || !ctype_digit($student_id)) {
    die("\nInvalid student ID format: Must be 9 numeric characters");
}

echo "\nAnalyzing student ID: $student_id\n";
echo "Length: " . strlen($student_id) . "\n";
echo "Year: 20" . substr($student_id, 0, 2) . " (verify year)\n";
echo "Month: " . substr($student_id, 2, 2) . "\n";
echo "Day/Sequence: " . substr($student_id, 4, 2) . "\n";
echo "Number: " . substr($student_id, 6) . "\n";

// Secure search with prepared statements
$searches = [
    "23%" => "All 2023 students",
    "2309%" => "All September 2023 students",
    "230919%" => "All September 19, 2023 students"
];

foreach ($searches as $pattern => $description) {
    echo "\nSearching $description:\n";

    $sql = $hasCreatedAt
        ? "SELECT SID, Fname, Lname, created_at FROM students WHERE SID LIKE ? ORDER BY SID"
        : "SELECT SID, Fname, Lname FROM students WHERE SID LIKE ? ORDER BY SID";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $db->error);
    }

    $stmt->bind_param("s", $pattern);
    if (!$stmt->execute()) {
        die("Execute failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    echo "Found " . $result->num_rows . " matches:\n";

    while ($row = $result->fetch_assoc()) {
        $created = $row['created_at'] ?? 'N/A';
        echo "{$row['SID']} - {$row['Fname']} {$row['Lname']} ({$created})\n";
    }

    $stmt->close();
    $result->free();
}

// Date search only if created_at exists
if ($hasCreatedAt) {
    $date_search = $db->query("SELECT SID, Fname, Lname, created_at FROM students WHERE DATE(created_at) = CURDATE() ORDER BY created_at DESC");
    if ($date_search !== false) {
        echo "\nStudents created today:\n";
        while ($row = $date_search->fetch_assoc()) {
            echo "{$row['SID']} - {$row['Fname']} {$row['Lname']} ({$row['created_at']})\n";
        }
        $date_search->free();
    }
}

// Check if programs table exists
$result = $db->query("SHOW TABLES LIKE 'programs'");
if ($result->num_rows > 0) {
    echo "\nPrograms table exists.\n";

    // Get table structure
    $result = $db->query("DESCRIBE programs");
    echo "Columns in programs:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
    // done

    // Get sample data
    $result = $db->query("SELECT * FROM programs LIMIT 5");
    echo "\nSample Programs Data (first 5):\n";
    while ($row = $result->fetch_assoc()) {
        echo json_encode($row) . "\n";
    }
    // done
} else {
    echo "Programs table does not exist.";

    // Create programs table
    echo "\nCreating programs table...\n";
    $sql = "CREATE TABLE programs (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        program_code VARCHAR(20) NOT NULL UNIQUE,
        program_name VARCHAR(255) NOT NULL,
        program_type ENUM('degree', 'diploma', 'certificate') NOT NULL,
        study_mode ENUM('fulltime', 'parttime', 'distance') NOT NULL,
        period_mode ENUM('semester', 'term') NOT NULL DEFAULT 'semester',
        duration_months INT(11) NOT NULL,
        status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";

    if ($db->query($sql) === TRUE) {
        echo "Programs table created successfully.";
    } else {
        echo "Error creating table: " . $db->error;
    }
}

// Add indexes for better performance (uncomment to create)
// $db->query("CREATE INDEX idx_created_at ON students(created_at)");
// $db->query("CREATE INDEX idx_sid_prefix ON students(SID(6))");

$db->close();