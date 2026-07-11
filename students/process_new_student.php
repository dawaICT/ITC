<?php
require_once __DIR__ . '/../includes/manual_entry_guards.php';
wuc_gate_debug_endpoint();
header('Content-Type: application/json');
require_once 'includes/StudentRegistrationSystem.php';

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid input data');
    }

    // Validate required fields
    $requiredFields = ['first_name', 'last_name', 'email', 'phone', 'academic_year', 'semester', 'courses'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Initialize database connection
    $db = new Database();
    $conn = $db->getConnection();

    // Start transaction
    $conn->beginTransaction();

    try {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT student_id FROM students WHERE email = ?");
        $stmt->execute([$input['email']]);
        if ($stmt->fetch()) {
            throw new Exception("Email already registered");
        }

        // Insert new student
        $stmt = $conn->prepare("
            INSERT INTO students 
            (first_name, last_name, email, phone, academic_year, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $input['first_name'],
            $input['last_name'],
            $input['email'],
            $input['phone'],
            $input['academic_year']
        ]);

        $studentId = $conn->lastInsertId();

        // Initialize registration system
        $registrationSystem = new StudentRegistrationSystem();

        // Process registration
        $result = $registrationSystem->processRegistration(
            $studentId,
            $input['courses'],
            $input['semester']
        );

        $conn->commit();

        // Return success response with student and registration details
        echo json_encode([
            'success' => true,
            'data' => [
                'student_id' => $studentId,
                'registration' => $result
            ],
            'message' => 'Student registration successful'
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} 