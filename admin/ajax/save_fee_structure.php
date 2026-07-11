<?php
require_once "../includes/admin.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
header('Content-Type: application/json');

try {
    // Validate required fields
    $required_fields = ['program_code', 'year_of_study', 'semester', 'fee_description', 'amount', 'status'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            throw new Exception("$field is required");
        }
    }

    // Validate amount is numeric and positive
    if (!is_numeric($_POST['amount']) || $_POST['amount'] <= 0) {
        throw new Exception("Amount must be a positive number");
    }

    // Validate year of study
    if (!in_array((int)$_POST['year_of_study'], [1, 2, 3, 4], true)) {
        throw new Exception("Year of study must be between 1 and 4");
    }

    // Validate semester
    if (!in_array((int)$_POST['semester'], [1, 2], true)) {
        throw new Exception("Semester must be either 1 or 2");
    }

    // Validate status
    if (!in_array($_POST['status'], ['active', 'inactive'], true)) {
        throw new Exception("Invalid status");
    }

    // Check if program exists
    $stmt = $db->prepare("SELECT program_code FROM programs WHERE program_code = ?");
    $stmt->bind_param('s', $_POST['program_code']);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_object()) {
        throw new Exception("Invalid program code");
    }

    // Begin transaction
    $tx_started = $db->begin_transaction();

    if (!empty($_POST['id'])) {
        // Update existing fee structure
        $stmt = $db->prepare("
            UPDATE fee_structure 
            SET program_code = ?,
                year_of_study = ?,
                semester = ?,
                fee_description = ?,
                amount = ?,
                status = ?,
                updated_by = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            'siissdii',
            $_POST['program_code'],
            $_POST['year_of_study'],
            $_POST['semester'],
            $_POST['fee_description'],
            $_POST['amount'],
            $_POST['status'],
            $_SESSION['user_id'],
            $_POST['id']
        );
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception("Fee structure not found or no changes made");
        }

        $message = "Fee structure updated successfully";
    } else {
        // Check for duplicate fee structure
        $stmt = $db->prepare("
            SELECT id FROM fee_structure 
            WHERE program_code = ? 
            AND year_of_study = ? 
            AND semester = ? 
            AND fee_description = ?
        ");
        $stmt->bind_param(
            'siis',
            $_POST['program_code'],
            $_POST['year_of_study'],
            $_POST['semester'],
            $_POST['fee_description']
        );
        $stmt->execute();
        if ($stmt->get_result()->fetch_object()) {
            throw new Exception("A fee structure already exists for this program, course, year, semester and description");
        }

        // Insert new fee structure
        $stmt = $db->prepare("
            INSERT INTO fee_structure (
                program_code, 
                year_of_study, 
                semester, 
                fee_description, 
                amount, 
                status,
                created_by,
                updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'siissdii',
            $_POST['program_code'],
            $_POST['year_of_study'],
            $_POST['semester'],
            $_POST['fee_description'],
            $_POST['amount'],
            $_POST['status'],
            $_SESSION['user_id'],
            $_SESSION['user_id']
        );
        $stmt->execute();

        $message = "Fee structure added successfully";
    }

    // Commit transaction
    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);

} catch (Exception $e) {
    // Rollback transaction if started
    if (isset($tx_started) && $tx_started) {
        $db->rollback();
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 