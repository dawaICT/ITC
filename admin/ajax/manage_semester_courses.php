<?php
// Suppress any PHP output before JSON response
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start(); // Start output buffering to catch any stray output

require_once "../includes/admin.php";

// Discard any output from admin.php (session warnings, etc.)
ob_end_clean();

require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
require_once dirname(__DIR__, 2) . '/includes/helpers/academic_structure_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/helpers/course_availability_helpers.php';
wuc_ajax_require_csrf();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'add_course':
            addSemesterCourse($db);
            break;

        case 'update_course':
            updateSemesterCourse($db);
            break;

        case 'delete_course':
            deleteSemesterCourse($db);
            break;

        case 'bulk_delete':
            bulkDeleteSemesterCourses($db);
            break;

        case 'bulk_edit':
            bulkEditSemesterCourses($db);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
    }
} catch (Exception $e) {
    error_log('manage_semester_courses error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}

function addSemesterCourse($db) {
    $program_code = trim($_POST['program_code'] ?? '');
    $course_code = trim($_POST['course_code'] ?? '');
    $year = max(1, (int)($_POST['year'] ?? 1));
    $semester = (int)($_POST['semester'] ?? 0);
    $periodSpecific = !empty($_POST['period_specific']);

    if (empty($program_code) || empty($course_code)) {
        echo json_encode(['success' => false, 'message' => 'Program and course are required']);
        return;
    }

    if ($periodSpecific) {
        if (!$semester) {
            echo json_encode(['success' => false, 'message' => 'Delivery period is required when limiting to one period']);
            return;
        }
        $alignment = wuc_validate_curriculum_period($db, $program_code, $year, $semester);
        if (!$alignment['ok']) {
            echo json_encode(['success' => false, 'message' => $alignment['reason']]);
            return;
        }
    }

    $result = wuc_insert_program_course_assignment(
        $db,
        $program_code,
        $course_code,
        $year,
        $periodSpecific ? $semester : null,
        $periodSpecific
    );

    if ($result['ok']) {
        $msg = $result['skipped'] ? 'This course is already assigned to this program for that year.' : 'Course added successfully';
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message'] ?: 'Failed to add course']);
    }
}

function updateSemesterCourse($db) {
    $id = (int)($_POST['id'] ?? 0);
    $semester = (int)($_POST['semester'] ?? 0);
    $credits = (int)($_POST['credits'] ?? 3);

    // Validate required fields
    if (!$id || !$semester || !$credits) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        return;
    }

    // Validate the new period against the owning programme's structure.
    $rowProgram = '';
    if ($progStmt = $db->prepare("SELECT program_code FROM program_courses WHERE id = ? LIMIT 1")) {
        $progStmt->bind_param('i', $id);
        $progStmt->execute();
        $progStmt->bind_result($rowProgram);
        $progStmt->fetch();
        $progStmt->close();
    }
    $alignment = wuc_validate_curriculum_period($db, (string)$rowProgram, null, $semester);
    if (!$alignment['ok']) {
        echo json_encode(['success' => false, 'message' => $alignment['reason']]);
        return;
    }

    // Update semester course
    $update_sql = "UPDATE program_courses SET semester = ?, credits = ? WHERE id = ?";
    $update_stmt = $db->prepare($update_sql);
    $update_stmt->bind_param("iii", $semester, $credits, $id);

    if ($update_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Course updated successfully']);
    } else {
        error_log('manage_semester_courses update failed: ' . $db->error); echo json_encode(['success' => false, 'message' => 'Failed to update course.']);
    }
}

function deleteSemesterCourse($db) {
    $id = (int)($_POST['id'] ?? 0);

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Course ID is required']);
        return;
    }

    // Delete semester course
    $delete_sql = "DELETE FROM program_courses WHERE id = ?";
    $delete_stmt = $db->prepare($delete_sql);
    $delete_stmt->bind_param("i", $id);

    if ($delete_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Course removed successfully']);
    } else {
        error_log('manage_semester_courses remove failed: ' . $db->error);
        echo json_encode(['success' => false, 'message' => 'Failed to remove course.']);
    }
}

function bulkDeleteSemesterCourses($db) {
    $courseIds = trim($_POST['course_ids'] ?? '');

    if (empty($courseIds)) {
        echo json_encode(['success' => false, 'message' => 'No course IDs provided']);
        return;
    }

    $courseIdArray = explode(',', $courseIds);
    $courseIdArray = array_map('intval', $courseIdArray);
    // Filter out any zero/invalid IDs
    $courseIdArray = array_filter($courseIdArray, function($id) { return $id > 0; });

    if (empty($courseIdArray)) {
        echo json_encode(['success' => false, 'message' => 'No valid course IDs provided']);
        return;
    }

    // Create placeholders and types string for MySQLi bind_param
    $placeholders = str_repeat('?,', count($courseIdArray) - 1) . '?';
    $types = str_repeat('i', count($courseIdArray));

    // Delete multiple courses
    $delete_sql = "DELETE FROM program_courses WHERE id IN ($placeholders)";
    $delete_stmt = $db->prepare($delete_sql);
    $delete_stmt->bind_param($types, ...array_values($courseIdArray));

    if ($delete_stmt->execute()) {
        $deletedCount = $delete_stmt->affected_rows;
        echo json_encode([
            'success' => true,
            'message' => "Successfully removed $deletedCount course(s)"
        ]);
    } else {
        error_log('manage_semester_courses bulk remove failed: ' . $db->error);
        echo json_encode(['success' => false, 'message' => 'Failed to remove courses.']);
    }
}

function bulkEditSemesterCourses($db) {
    $courseIds = trim($_POST['course_ids'] ?? '');
    $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : null;
    $credits = isset($_POST['credits']) ? (int)$_POST['credits'] : null;

    if (empty($courseIds)) {
        echo json_encode(['success' => false, 'message' => 'No course IDs provided']);
        return;
    }

    if (!$semester && !$credits) {
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        return;
    }

    $courseIdArray = explode(',', $courseIds);
    $courseIdArray = array_map('intval', $courseIdArray);
    $courseIdArray = array_filter($courseIdArray, function($id) { return $id > 0; });

    if (empty($courseIdArray)) {
        echo json_encode(['success' => false, 'message' => 'No valid course IDs provided']);
        return;
    }

    // Create placeholders for the IN clause
    $placeholders = str_repeat('?,', count($courseIdArray) - 1) . '?';

    // When changing the period, every affected programme must accept it.
    if ($semester) {
        $idTypes = str_repeat('i', count($courseIdArray));
        if ($progStmt = $db->prepare("SELECT DISTINCT program_code FROM program_courses WHERE id IN ($placeholders)")) {
            $progStmt->bind_param($idTypes, ...array_values($courseIdArray));
            $progStmt->execute();
            $progRes = $progStmt->get_result();
            while ($progRow = $progRes->fetch_assoc()) {
                $alignment = wuc_validate_curriculum_period($db, (string)$progRow['program_code'], null, $semester);
                if (!$alignment['ok']) {
                    $progStmt->close();
                    echo json_encode(['success' => false, 'message' => $alignment['reason']]);
                    return;
                }
            }
            $progStmt->close();
        }
    }

    $updateFields = [];
    $updateValues = [];
    $types = '';

    if ($semester) {
        $updateFields[] = "semester = ?";
        $updateValues[] = $semester;
        $types .= 'i';
    }

    if ($credits) {
        $updateFields[] = "credits = ?";
        $updateValues[] = $credits;
        $types .= 'i';
    }

    // Add types for IN clause IDs
    $types .= str_repeat('i', count($courseIdArray));

    // Combine all values for the prepared statement
    $allValues = array_merge($updateValues, array_values($courseIdArray));

    // Update multiple courses
    $update_sql = "UPDATE program_courses SET " . implode(', ', $updateFields) . " WHERE id IN ($placeholders)";
    $update_stmt = $db->prepare($update_sql);
    $update_stmt->bind_param($types, ...$allValues);

    if ($update_stmt->execute()) {
        $updatedCount = $update_stmt->affected_rows;
        $changes = [];
        if ($semester) $changes[] = "semester to $semester";
        if ($credits) $changes[] = "credits to $credits";
        $changesText = implode(' and ', $changes);

        echo json_encode([
            'success' => true,
            'message' => "Successfully updated $updatedCount course(s) - changed $changesText"
        ]);
    } else {
        error_log('manage_semester_courses batch update failed: ' . $db->error);
        echo json_encode(['success' => false, 'message' => 'Failed to update courses.']);
    }
}
?>
