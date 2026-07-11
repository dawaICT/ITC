<?php
require_once 'includes/Database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Drop existing tables in reverse order
    $conn->exec("DROP TABLE IF EXISTS invoices");
    $conn->exec("DROP TABLE IF EXISTS registration_courses");
    $conn->exec("DROP TABLE IF EXISTS registrations");
    $conn->exec("DROP TABLE IF EXISTS courses");
    $conn->exec("DROP TABLE IF EXISTS students");

    // Create students table
    $conn->exec("CREATE TABLE IF NOT EXISTS students (
        student_id VARCHAR(10) PRIMARY KEY,
        first_name VARCHAR(50) NOT NULL,
        last_name VARCHAR(50) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        phone VARCHAR(20) NOT NULL,
        min_year INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Create courses table
    $conn->exec("CREATE TABLE IF NOT EXISTS courses (
        course_id INT AUTO_INCREMENT PRIMARY KEY,
        course_code VARCHAR(10) NOT NULL UNIQUE,
        course_name VARCHAR(100) NOT NULL,
        credit_hours INT NOT NULL,
        min_year INT NOT NULL,
        semester INT NOT NULL,
        is_required TINYINT(1) DEFAULT 1,
        is_laboratory TINYINT(1) DEFAULT 0,
        is_advanced TINYINT(1) DEFAULT 0,
        max_capacity INT DEFAULT 50,
        status ENUM('active', 'inactive') DEFAULT 'active',
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Create registrations table
    $conn->exec("CREATE TABLE IF NOT EXISTS registrations (
        registration_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(10) NOT NULL,
        semester INT NOT NULL,
        total_credits INT NOT NULL,
        total_fees DECIMAL(10,2) NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(student_id)
    )");

    // Create registration_courses table
    $conn->exec("CREATE TABLE IF NOT EXISTS registration_courses (
        registration_id INT NOT NULL,
        course_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (registration_id, course_id),
        FOREIGN KEY (registration_id) REFERENCES registrations(registration_id),
        FOREIGN KEY (course_id) REFERENCES courses(course_id)
    )");

    // Create invoices table
    $conn->exec("CREATE TABLE IF NOT EXISTS invoices (
        invoice_id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(20) NOT NULL UNIQUE,
        student_id VARCHAR(10) NOT NULL,
        academic_year INT NOT NULL,
        semester INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        base_tuition DECIMAL(10,2) NOT NULL,
        registration_fee DECIMAL(10,2) NOT NULL,
        additional_fees DECIMAL(10,2) NOT NULL,
        due_date DATE NOT NULL,
        status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(student_id)
    )");

    // Insert sample courses
    $courses = [
        ['CS101', 'Introduction to Computer Science', 3, 1, 1, 1, 0, 0],
        ['MATH101', 'Calculus I', 4, 1, 1, 1, 0, 0],
        ['PHY101', 'Physics I', 4, 1, 1, 1, 1, 0],
        ['ENG101', 'English Composition', 3, 1, 1, 1, 0, 0],
        ['CHEM101', 'Chemistry I', 4, 1, 1, 1, 1, 0]
    ];

    $stmt = $conn->prepare("INSERT INTO courses 
        (course_code, course_name, credit_hours, min_year, semester, is_required, is_laboratory, is_advanced) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($courses as $course) {
        $stmt->execute($course);
    }

    // Insert sample student
    $stmt = $conn->prepare("INSERT INTO students 
        (student_id, first_name, last_name, email, phone, min_year) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        '23001', 'John', 'Doe', 'john.doe@example.com', '1234567890', 1
    ]);

    echo "Database setup completed successfully!";

} catch (PDOException $e) {
    die("Database setup failed: " . $e->getMessage());
} 