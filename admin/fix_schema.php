<?php
// Unified, idempotent migration to normalize DB schema
// Usage (CLI only): php admin/fix_schema.php

require_once __DIR__ . '/../includes/production_guards.php';
wuc_require_cli_only();

define('IS_SCRIPT', true);
require_once __DIR__ . '/../db/connect.php';

header('Content-Type: text/plain');

function execQuery(mysqli $db, string $sql, string $label) {
    if (!$db->query($sql)) {
        throw new Exception($label . ' failed: ' . $db->error);
    }
}

function columnExists(mysqli $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return ((int)($row['cnt'] ?? 0) > 0);
}

function tableExists(mysqli $db, string $table): bool {
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return ((int)($row['cnt'] ?? 0) > 0);
}

$db->begin_transaction();
try {
    // 1) Ensure programs
    execQuery($db, "CREATE TABLE IF NOT EXISTS programs (
        program_code VARCHAR(20) NOT NULL PRIMARY KEY,
        program_name VARCHAR(255) NOT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create programs');

    // 2) Ensure students (SID, Fname, Lname, sex, email, phone/mobile)
    execQuery($db, "CREATE TABLE IF NOT EXISTS students (
        studentID INT AUTO_INCREMENT PRIMARY KEY,
        SID VARCHAR(50) NOT NULL UNIQUE,
        Fname VARCHAR(100),
        Lname VARCHAR(100),
        sex VARCHAR(10),
        email VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create students');

    if (!columnExists($db, 'students', 'DOB') && !columnExists($db, 'students', 'dob')) {
        execQuery($db, "ALTER TABLE students ADD COLUMN DOB DATE NULL AFTER sex", 'Add students.DOB');
    }
    if (!columnExists($db, 'students', 'phone') && !columnExists($db, 'students', 'mobile')) {
        execQuery($db, "ALTER TABLE students ADD COLUMN phone VARCHAR(20) NULL AFTER email", 'Add students.phone');
    }

    // 3) Ensure program_courses
    execQuery($db, "CREATE TABLE IF NOT EXISTS program_courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_code VARCHAR(20) NOT NULL,
        course_code VARCHAR(20) NOT NULL,
        course_name VARCHAR(255) NOT NULL,
        semester INT NOT NULL,
        credits INT NULL,
        UNIQUE KEY uniq_prog_course (program_code, course_code),
        CONSTRAINT fk_pc_program FOREIGN KEY (program_code) REFERENCES programs(program_code)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create program_courses');

    // 4) Ensure courses table minimal
    execQuery($db, "CREATE TABLE IF NOT EXISTS courses (
        course_code VARCHAR(20) NOT NULL PRIMARY KEY,
        course_name VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create courses');
    if (!columnExists($db, 'courses', 'credits')) {
        execQuery($db, "ALTER TABLE courses ADD COLUMN credits INT NULL AFTER course_name", 'Add courses.credits');
    }
    if (!columnExists($db, 'courses', 'course_fee')) {
        execQuery($db, "ALTER TABLE courses ADD COLUMN course_fee DECIMAL(10,2) NULL AFTER credits", 'Add courses.course_fee');
    }

    // 5) Ensure student_program with optional semester
    execQuery($db, "CREATE TABLE IF NOT EXISTS student_program (
        id INT AUTO_INCREMENT PRIMARY KEY,
        Sid VARCHAR(50) NOT NULL,
        program_code VARCHAR(20) NOT NULL,
        intake VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_sp_student FOREIGN KEY (Sid) REFERENCES students(SID) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_sp_program FOREIGN KEY (program_code) REFERENCES programs(program_code) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create student_program');
    if (!columnExists($db, 'student_program', 'semester')) {
        execQuery($db, "ALTER TABLE student_program ADD COLUMN semester INT NULL AFTER intake", 'Add student_program.semester');
    }

    // 6) Ensure student_courses table (optional; used by invoices breakdown)
    execQuery($db, "CREATE TABLE IF NOT EXISTS student_courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        course_code VARCHAR(20) NOT NULL,
        academic_year VARCHAR(9) NOT NULL,
        semester INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_student_year_sem (student_id, academic_year, semester)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create student_courses');

    // 7) Ensure invoices unified detailed schema
    execQuery($db, "CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(30) NOT NULL UNIQUE,
        student_id VARCHAR(50) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        academic_year VARCHAR(9) NOT NULL,
        semester INT NOT NULL,
        status ENUM('Pending','Paid','Overdue') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create invoices');

    // 8) fee_structure pivot for fallback
    execQuery($db, "CREATE TABLE IF NOT EXISTS fee_structure (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_code VARCHAR(20) NOT NULL,
        year_of_study INT NULL,
        semester INT NOT NULL,
        fee_description VARCHAR(255) NOT NULL DEFAULT 'Tuition',
        amount DECIMAL(10,2) NOT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_fee_prog_sem (program_code, semester)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create fee_structure');

    // Ensure 'invoice' column exists in student_payments
    if (tableExists($db, 'student_payments') && !columnExists($db, 'student_payments', 'invoice')) {
        execQuery($db, "ALTER TABLE student_payments ADD COLUMN invoice VARCHAR(50) DEFAULT NULL", 'Add student_payments.invoice');
    }

    $db->commit();
    echo "Schema normalized successfully.\n";
    exit(0);
} catch (Throwable $e) {
    $db->rollback();
    $db->rollback();
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

?>
<?php
// Schema Fix Script for WUC Portal
// This script will create missing tables and add required columns

error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "<h1>Database Schema Fix</h1>";
echo "<p>Fixing database schema issues...</p>";

// Include database connection
require_once "includes/admin.php";

if (!isset($db) || $db->connect_error) {
    die("Database connection failed: " . ($db->connect_error ?? "Unknown error"));
}

echo "<h2>Step 1: Creating missing tables</h2>";

// Create departments table if it doesn't exist
$check_departments = "SHOW TABLES LIKE 'departments'";
$result = $db->query($check_departments);

if (!$result || $result->num_rows == 0) {
    echo "<p>Creating departments table...</p>";
    
    $create_departments = "CREATE TABLE departments (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        department_name VARCHAR(100) NOT NULL,
        department_code VARCHAR(20),
        faculty_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    if ($db->query($create_departments)) {
        echo "<p style='color: green;'>✓ Departments table created successfully</p>";
        
        // Add sample department
        $insert_sample = "INSERT INTO departments (department_name, department_code) 
                        VALUES ('Computer Science', 'CS')";
        if ($db->query($insert_sample)) {
            echo "<p style='color: green;'>✓ Sample department added</p>";
        } else {
            echo "<p style='color: orange;'>⚠ Could not add sample department: " . $db->error . "</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Failed to create departments table: " . $db->error . "</p>";
    }
} else {
    echo "<p style='color: green;'>✓ Departments table already exists</p>";
}

// Create programs table if it doesn't exist
$check_programs = "SHOW TABLES LIKE 'programs'";
$result = $db->query($check_programs);

if (!$result || $result->num_rows == 0) {
    echo "<p>Creating programs table...</p>";
    
    $create_programs = "CREATE TABLE programs (
        program_code VARCHAR(20) NOT NULL PRIMARY KEY,
        program_name VARCHAR(100) NOT NULL,
        program_type VARCHAR(50) NOT NULL,
        program_duration DECIMAL(4,2),
        program_description TEXT,
        department_id INT,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (department_id) REFERENCES departments(id)
    )";
    
    if ($db->query($create_programs)) {
        echo "<p style='color: green;'>✓ Programs table created successfully</p>";
        
        // Add sample program
        $insert_sample = "INSERT INTO programs (program_code, program_name, program_type, program_duration, program_description) 
                       VALUES ('MATH-BSC', 'Bachelor of Science in Mathematics', 'Undergraduate', 4, 'Advanced mathematics program')";
        if ($db->query($insert_sample)) {
            echo "<p style='color: green;'>✓ Sample program added</p>";
        } else {
            echo "<p style='color: orange;'>⚠ Could not add sample program: " . $db->error . "</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Failed to create programs table: " . $db->error . "</p>";
    }
} else {
    echo "<p style='color: green;'>✓ Programs table already exists</p>";
}

echo "<h2>Step 2: Checking and updating existing tables</h2>";

// Check if departments table has required columns
$dept_struct_query = "DESCRIBE departments";
$dept_struct_result = $db->query($dept_struct_query);
$has_department_name = false;

if ($dept_struct_result) {
    while ($col = $dept_struct_result->fetch_assoc()) {
        if ($col['Field'] == 'department_name') {
            $has_department_name = true;
            break;
        }
    }
    
    if (!$has_department_name) {
        echo "<p>Adding department_name column to departments table...</p>";
        
        // Add department_name column safely
        $add_column = "ALTER TABLE departments ADD COLUMN department_name VARCHAR(100) NULL AFTER id";
        if ($db->query($add_column)) {
            echo "<p style='color: green;'>✓ department_name column added</p>";
            
            // Backfill from existing name-like column if available
            $backfill = "UPDATE departments SET department_name = CONCAT('Department ', id) WHERE department_name IS NULL";
            if ($db->query($backfill)) {
                echo "<p style='color: green;'>✓ department_name backfilled</p>";
            }
            
            // Make it NOT NULL
            $enforce_not_null = "ALTER TABLE departments MODIFY COLUMN department_name VARCHAR(100) NOT NULL";
            if ($db->query($enforce_not_null)) {
                echo "<p style='color: green;'>✓ department_name made NOT NULL</p>";
            }
        } else {
            echo "<p style='color: red;'>✗ Failed to add department_name: " . $db->error . "</p>";
        }
    } else {
        echo "<p style='color: green;'>✓ department_name column already exists</p>";
    }
}

// Check if programs table has required columns
$prog_struct_query = "DESCRIBE programs";
$prog_struct_result = $db->query($prog_struct_query);

if ($prog_struct_result) {
    $required_columns = [
        'program_name' => "ADD COLUMN program_name VARCHAR(100) NOT NULL AFTER program_code",
        'program_type' => "ADD COLUMN program_type VARCHAR(50) NOT NULL AFTER program_name",
        'department_id' => "ADD COLUMN department_id INT NULL AFTER program_description"
    ];
    
    $existing_columns = [];
    while ($col = $prog_struct_result->fetch_assoc()) {
        $existing_columns[$col['Field']] = true;
    }
    
    foreach ($required_columns as $column => $alter_sql) {
        if (!isset($existing_columns[$column])) {
            echo "<p>Adding $column column to programs table...</p>";
            
            $add_column = "ALTER TABLE programs $alter_sql";
            if ($db->query($add_column)) {
                echo "<p style='color: green;'>✓ $column column added</p>";
                
                if ($column === 'department_id') {
                    // Create index for better performance
                    $db->query("CREATE INDEX idx_programs_department_id ON programs (department_id)");
                    
                    // Try to add foreign key
                    $db->query("ALTER TABLE programs 
                                ADD CONSTRAINT fk_programs_department 
                                FOREIGN KEY (department_id) 
                                REFERENCES departments(id) 
                                ON UPDATE CASCADE 
                                ON DELETE RESTRICT");
                }
            } else {
                echo "<p style='color: red;'>✗ Failed to add $column: " . $db->error . "</p>";
            }
        } else {
            echo "<p style='color: green;'>✓ $column column already exists</p>";
        }
    }
}

echo "<h2>Step 3: Verifying schema</h2>";

// Verify tables exist
$tables_to_check = ['departments', 'programs', 'student_program'];
foreach ($tables_to_check as $table) {
    $check_query = "SHOW TABLES LIKE '$table'";
    $result = $db->query($check_query);
    
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✓ $table table exists</p>";
    } else {
        echo "<p style='color: red;'>✗ $table table missing</p>";
    }
}

// Check data counts
$dept_count = $db->query("SELECT COUNT(*) as count FROM departments")->fetch_assoc()['count'];
$prog_count = $db->query("SELECT COUNT(*) as count FROM programs")->fetch_assoc()['count'];
$student_prog_count = $db->query("SELECT COUNT(*) as count FROM student_program")->fetch_assoc()['count'];

echo "<h3>Data Summary:</h3>";
echo "<ul>";
echo "<li>Departments: $dept_count</li>";
echo "<li>Programs: $prog_count</li>";
echo "<li>Student Programs: $student_prog_count</li>";
echo "</ul>";

echo "<h2>Step 4: Schema fix complete!</h2>";
echo "<p style='color: green; font-weight: bold;'>✓ Database schema has been successfully updated.</p>";
echo "<p><a href='programs.php' class='btn btn-primary'>Go to Programs Management</a></p>";

$db->close();
?>
