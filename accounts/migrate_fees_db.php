<?php
/**
 * Migration Script - Fees and Fee Breakdown System
 * Rerun-safe database schema updater and seeder.
 */
header('Content-Type: text/plain');
define('IS_SCRIPT', true);
require_once __DIR__ . '/../db/connect.php';
// Override the database connection with admin/root privileges to run DDL operations
$db = new mysqli($db_host, 'root', '', $db_name, $db_port);
if ($db->connect_error) {
    throw new Exception('MySQL admin connection failed: ' . $db->connect_error);
}
$db->set_charset("utf8mb4");


// Enable error reporting for migration debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

echo "Starting Fees Module Database Migration...\n\n";

try {
    // 1. Update departments table
    echo "Updating 'departments' table...\n";
    $result = $db->query("SHOW COLUMNS FROM departments LIKE 'status'");
    if ($result->num_rows === 0) {
        $db->query("ALTER TABLE departments ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active'");
        echo "  - Added 'status' column to 'departments'.\n";
    } else {
        echo "  - Column 'status' already exists in 'departments'.\n";
    }
    $result->free();

    // 2. Update courses table
    echo "Updating 'courses' table...\n";
    $colsToAdd = [
        'department_id' => "INT(11) NULL",
        'duration_unit' => "VARCHAR(50) DEFAULT 'months'",
        'course_type' => "ENUM('short course', 'certificate', 'trade test', 'diploma', 'service') DEFAULT 'short course'"
    ];
    foreach ($colsToAdd as $col => $definition) {
        $result = $db->query("SHOW COLUMNS FROM courses LIKE '$col'");
        if ($result->num_rows === 0) {
            $db->query("ALTER TABLE courses ADD COLUMN $col $definition");
            echo "  - Added '$col' column to 'courses'.\n";
        } else {
            echo "  - Column '$col' already exists in 'courses'.\n";
        }
        $result->free();
    }

    // 3. Create training_modes table
    echo "Creating 'training_modes' table...\n";
    $db->query("CREATE TABLE IF NOT EXISTS training_modes (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        mode_name VARCHAR(50) UNIQUE NOT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  - 'training_modes' table is ready.\n";

    // 4. Create course_fees table
    echo "Creating 'course_fees' table...\n";
    $db->query("CREATE TABLE IF NOT EXISTS course_fees (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        course_id INT(11) NOT NULL,
        training_mode_id INT(11) NOT NULL,
        academic_year VARCHAR(20) NOT NULL,
        currency VARCHAR(10) DEFAULT 'ZMW',
        base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        effective_start_date DATE NOT NULL,
        effective_end_date DATE NULL,
        status ENUM('active', 'inactive', 'expired') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_course_mode_year (course_id, training_mode_id, academic_year, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  - 'course_fees' table is ready.\n";

    // 5. Create fee_items table
    echo "Creating 'fee_items' table...\n";
    $db->query("CREATE TABLE IF NOT EXISTS fee_items (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) UNIQUE NOT NULL,
        description TEXT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        mandatory_status ENUM('mandatory', 'optional') DEFAULT 'mandatory',
        collection_type ENUM('Institution Collected', 'External Payment', 'Informational Only') DEFAULT 'Institution Collected',
        academic_year VARCHAR(20) NOT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  - 'fee_items' table is ready.\n";

    // 6. Create course_fee_breakdown table
    echo "Creating 'course_fee_breakdown' table...\n";
    $db->query("CREATE TABLE IF NOT EXISTS course_fee_breakdown (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        course_id INT(11) NOT NULL,
        fee_item_id INT(11) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_course_fee_item (course_id, fee_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  - 'course_fee_breakdown' table is ready.\n";

    // 7. Create student_fee_accounts table
    echo "Creating 'student_fee_accounts' table...\n";
    $db->query("CREATE TABLE IF NOT EXISTS student_fee_accounts (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        course_id INT(11) NOT NULL,
        training_mode_id INT(11) NOT NULL,
        academic_year VARCHAR(20) NOT NULL,
        intake VARCHAR(50) NOT NULL,
        base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        additional_fee_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_payable DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        payment_status ENUM('Unpaid', 'Partially Paid', 'Paid', 'Overpaid') DEFAULT 'Unpaid',
        status ENUM('active', 'withdrawn', 'cancelled') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_course_mode_year (student_id, course_id, training_mode_id, intake, academic_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  - 'student_fee_accounts' table is ready.\n";

    // 8. Update student_payments table
    echo "Updating 'student_payments' table...\n";
    $spCols = [
        'student_fee_account_id' => "INT(11) NULL",
        'recorded_by' => "VARCHAR(50) NULL",
        'status' => "ENUM('approved', 'cancelled', 'reversed') DEFAULT 'approved'",
        'reversal_reason' => "TEXT NULL",
        'receipt_number' => "VARCHAR(50) NULL UNIQUE"
    ];
    foreach ($spCols as $col => $definition) {
        $result = $db->query("SHOW COLUMNS FROM student_payments LIKE '$col'");
        if ($result->num_rows === 0) {
            $db->query("ALTER TABLE student_payments ADD COLUMN $col $definition");
            echo "  - Added '$col' column to 'student_payments'.\n";
        } else {
            echo "  - Column '$col' already exists in 'student_payments'.\n";
        }
        $result->free();
    }

    // ── Seeding Default Values ──
    echo "\nSeeding default data...\n";

    // Seed training modes
    $modes = ['Full Time', 'In-House', 'Evening', 'Distance', 'At Campus'];
    $stmt = $db->prepare("INSERT INTO training_modes (mode_name, status) VALUES (?, 'active') ON DUPLICATE KEY UPDATE status='active'");
    foreach ($modes as $m) {
        $stmt->bind_param('s', $m);
        $stmt->execute();
        echo "  - Seeded training mode: $m\n";
    }
    $stmt->close();

    // Seed default fee items for academic year 2026
    $feeItems = [
        ['Tuition Fee', 'Base instructional fee', 0.00, 'mandatory', 'Institution Collected', '2026'],
        ['Registration Fee', 'Semester enrollment administrative fee', 150.00, 'mandatory', 'Institution Collected', '2026'],
        ['ID Fee', 'Student identity card replacement or issue fee', 50.00, 'mandatory', 'Institution Collected', '2026'],
        ['Medical Certificate', 'Health certificate verification fee', 100.00, 'optional', 'Institution Collected', '2026'],
        ['Online Booking for Provision', 'Platform fee for provisional license booking', 50.00, 'optional', 'Institution Collected', '2026'],
        ['RTSA Provisional Licence', 'External RTSA provisional driving license fee', 100.00, 'mandatory', 'External Payment', '2026'],
        ['RTSA Test Fee', 'External RTSA road test evaluation fee', 150.00, 'mandatory', 'External Payment', '2026'],
        ['RTSA Licence Fee', 'External RTSA physical license print fee', 200.00, 'mandatory', 'External Payment', '2026'],
        ['Examination Fee', 'End of semester examination assessment fee', 250.00, 'mandatory', 'Institution Collected', '2026'],
        ['Certificate Fee', 'Graduation diploma and certificate printing', 120.00, 'optional', 'Informational Only', '2026'],
        ['Practical Fee', 'Workshop materials and practical safety gear fee', 300.00, 'mandatory', 'Institution Collected', '2026'],
        ['Service Fee', 'General campus internet and facility upkeep fee', 100.00, 'mandatory', 'Institution Collected', '2026']
    ];
    $stmt = $db->prepare("INSERT INTO fee_items (name, description, amount, mandatory_status, collection_type, academic_year, status) 
                          VALUES (?, ?, ?, ?, ?, ?, 'active') 
                          ON DUPLICATE KEY UPDATE description=VALUES(description), amount=VALUES(amount), mandatory_status=VALUES(mandatory_status), collection_type=VALUES(collection_type)");
    foreach ($feeItems as $item) {
        $stmt->bind_param('ssdsss', $item[0], $item[1], $item[2], $item[3], $item[4], $item[5]);
        $stmt->execute();
        echo "  - Seeded fee item: {$item[0]}\n";
    }
    $stmt->close();

    // Map existing courses to departments if they are NULL
    // First, let's link the sample departments to courses or departments.
    // E.g., Welding (ITC02) should go to Mechanical Engineering or Automotive, etc.
    // For now we'll do:
    $db->query("UPDATE courses SET department_id = 1 WHERE department_id IS NULL LIMIT 5");
    $db->query("UPDATE courses SET department_id = 2 WHERE department_id IS NULL LIMIT 5");

    echo "\nDatabase migration and seeding completed successfully!\n";

} catch (Exception $e) {
    echo "\nMigration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
?>
