<?php
require_once 'includes/admin.php'; // Handles session and DB connection
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/action_confirmation.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_GET['sid'])) {
    wuc_render_action_confirmation('Delete student?', 'This permanently removes the student and their program/login records.', 'delete_student.php', ['sid' => trim((string)$_GET['sid'])]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid request.');
}

if (!empty($_POST['sid'])) {
    $sid = trim((string)$_POST['sid']);

    // 1. Prepare the statement to prevent SQL Injection
    // We use a transaction because we need to delete from student_program AND students
    $db->begin_transaction();

    try {
        // Delete from the mapping table first (Foreign Key constraint safety).
        $studentProgramColumns = [];
        if ($columnsResult = $db->query("SHOW COLUMNS FROM student_program")) {
            while ($column = $columnsResult->fetch_assoc()) {
                $studentProgramColumns[] = (string)$column['Field'];
            }
            $columnsResult->free();
        }
        $programSidColumn = in_array('Sid', $studentProgramColumns, true)
            ? 'Sid'
            : (in_array('student_id', $studentProgramColumns, true) ? 'student_id' : null);
        if ($programSidColumn !== null) {
            $stmt1 = $db->prepare("DELETE FROM student_program WHERE `{$programSidColumn}` = ?");
            $stmt1->bind_param("s", $sid);
            $stmt1->execute();
            $stmt1->close();
        }

        // Delete from the main students table
        $stmt2 = $db->prepare("DELETE FROM students WHERE SID = ?");
        $stmt2->bind_param("s", $sid);
        $stmt2->execute();

        if ($stmt2->affected_rows > 0) {
            $db->commit();
            $_SESSION['successDel'] = "Student record (ID: $sid) has been successfully deleted.";
        } else {
            // If no rows affected, maybe ID didn't exist or was already deleted
            // But if transaction started, we should probably commit if no error, 
            // though 'affected_rows == 0' might mean nothing to delete.
            // User logic threw exception, let's follow that.
            throw new Exception("Student ID not found or already deleted.");
        }
        $stmt2->close();

    } catch (Exception $e) {
        $db->rollback();
        error_log('Student deletion failed: ' . $e->getMessage());
        $_SESSION['error'] = "The student could not be deleted.";
    }

} else {
    $_SESSION['error'] = "No student ID provided.";
}

// Redirect back to the management page
header("Location: students_by_admin.php");
exit();
?>
