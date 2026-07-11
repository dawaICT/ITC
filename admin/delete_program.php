<?php
include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/auth_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid request.');
}

if (!isset($_POST['program_code']) || trim((string)$_POST['program_code']) === '') {
    echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Invalid program ID!'
        }).then(function() {
            window.location.href = 'programs.php';
        });
    </script>";
    exit;
}

$program_code = trim((string)$_POST['program_code']);

// Start transaction
$db->begin_transaction();

try {
    // First check if program exists and is active
    $check_query = "SELECT * FROM programs WHERE program_code = ? AND status != 'deleted'";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bind_param("s", $program_code);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("Program not found or already deleted!");
    }

    // Check if there are any enrolled students
    $student_check = "SELECT COUNT(*) as count FROM student_program WHERE program_code = ?";
    $student_stmt = $db->prepare($student_check);
    $student_stmt->bind_param("s", $program_code);
    $student_stmt->execute();
    $student_count = $student_stmt->get_result()->fetch_object()->count;

    if ($student_count > 0) {
        throw new Exception("Cannot delete program: There are students enrolled in this program!");
    }

    // Delete program courses first
    $delete_courses = "DELETE FROM program_courses WHERE program_code = ?";
    $course_stmt = $db->prepare($delete_courses);
    $course_stmt->bind_param("s", $program_code);

    if (!$course_stmt->execute()) {
        throw new Exception("Error deleting program courses: " . $db->error);
    }

    // Update program status to deleted
    $update_query = "UPDATE programs SET status = 'deleted' WHERE program_code = ?";
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bind_param("s", $program_code);

    if (!$update_stmt->execute()) {
        throw new Exception("Error deleting program: " . $db->error);
    }

    // If everything is successful, commit the transaction
    $db->commit();

    echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: 'Program deleted successfully!',
            showConfirmButton: false,
            timer: 1500
        }).then(function() {
            window.location.href = 'programs.php';
        });
    </script>";

} catch (Exception $e) {
    // If there's an error, rollback the transaction
    $db->rollback();

    echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: '" . addslashes($e->getMessage()) . "'
        }).then(function() {
            window.location.href = 'programs.php';
        });
    </script>";
}
?> 
