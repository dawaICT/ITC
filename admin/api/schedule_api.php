<?php
/**
 * Schedule Management API
 * Handles CRUD operations for course schedules, exam schedules, classrooms, and time slots
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../db/connect.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in (admin check)
if (!isset($_SESSION['staff_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

try {
    switch ($action) {
        // ==================== COURSE SCHEDULE ====================
        case 'add_schedule':
            $courseCode = trim($_POST['course_code'] ?? '');
            $dayOfWeek = trim($_POST['day_of_week'] ?? '');
            $timeSlotId = (int)($_POST['time_slot_id'] ?? 0);
            $classroomId = !empty($_POST['classroom_id']) ? (int)$_POST['classroom_id'] : null;
            $lecturerId = !empty($_POST['lecturer_id']) ? $_POST['lecturer_id'] : null;
            $scheduleType = $_POST['schedule_type'] ?? 'lecture';
            $academicYear = (int)($_POST['academic_year'] ?? 1);  // Year of study (1, 2, 3, or 4)
            $semester = (int)($_POST['semester'] ?? 1);
            $notes = trim($_POST['notes'] ?? '');
            
            if (empty($courseCode) || empty($dayOfWeek) || $timeSlotId <= 0) {
                $response = ['success' => false, 'message' => 'Please fill in all required fields'];
                break;
            }
            
            // Check for scheduling conflicts
            $conflictSql = "SELECT cs.*, c.course_name FROM course_schedule cs
                           LEFT JOIN courses c ON c.course_code = cs.course_code
                           WHERE cs.day_of_week = ? AND cs.time_slot_id = ? 
                           AND cs.academic_year = ? AND cs.semester = ? AND cs.is_active = 1";
            
            if ($classroomId) {
                $conflictSql .= " AND cs.classroom_id = ?";
                $stmt = $db->prepare($conflictSql);
                $stmt->bind_param("siiii", $dayOfWeek, $timeSlotId, $academicYear, $semester, $classroomId);
            } else {
                $stmt = $db->prepare($conflictSql);
                $stmt->bind_param("siii", $dayOfWeek, $timeSlotId, $academicYear, $semester);
            }
            $stmt->execute();
            $conflicts = $stmt->get_result();
            
            if ($conflicts->num_rows > 0) {
                $conflict = $conflicts->fetch_assoc();
                $response = ['success' => false, 'message' => "Scheduling conflict: Room is already booked for {$conflict['course_code']} - {$conflict['course_name']}"];
                $stmt->close();
                break;
            }
            $stmt->close();
            
            // Insert the schedule
            $sql = "INSERT INTO course_schedule (course_code, day_of_week, time_slot_id, classroom_id, lecturer_id, schedule_type, academic_year, semester, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $createdBy = $_SESSION['staff_id'];
            $stmt->bind_param("ssiissisis", $courseCode, $dayOfWeek, $timeSlotId, $classroomId, $lecturerId, $scheduleType, $academicYear, $semester, $notes, $createdBy);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Schedule added successfully', 'id' => $stmt->insert_id];
            } else {
                $response = ['success' => false, 'message' => 'Failed to add schedule: ' . $db->error];
            }
            $stmt->close();
            break;
            
        case 'update_schedule':
            $id = (int)($_POST['id'] ?? 0);
            $courseCode = trim($_POST['course_code'] ?? '');
            $dayOfWeek = trim($_POST['day_of_week'] ?? '');
            $timeSlotId = (int)($_POST['time_slot_id'] ?? 0);
            $classroomId = !empty($_POST['classroom_id']) ? (int)$_POST['classroom_id'] : null;
            $lecturerId = !empty($_POST['lecturer_id']) ? $_POST['lecturer_id'] : null;
            $scheduleType = $_POST['schedule_type'] ?? 'lecture';
            $notes = trim($_POST['notes'] ?? '');
            
            if ($id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid schedule ID'];
                break;
            }
            
            $sql = "UPDATE course_schedule SET course_code = ?, day_of_week = ?, time_slot_id = ?, classroom_id = ?, lecturer_id = ?, schedule_type = ?, notes = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ssiisssi", $courseCode, $dayOfWeek, $timeSlotId, $classroomId, $lecturerId, $scheduleType, $notes, $id);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Schedule updated successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to update schedule'];
            }
            $stmt->close();
            break;
            
        case 'delete_schedule':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid schedule ID'];
                break;
            }
            
            $stmt = $db->prepare("DELETE FROM course_schedule WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Schedule deleted successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to delete schedule'];
            }
            $stmt->close();
            break;
            
        case 'get_schedules':
            $year = (int)($_GET['year'] ?? date('Y'));
            $semester = (int)($_GET['semester'] ?? 1);
            
            $sql = "SELECT cs.*, c.course_name, cl.room_code, cl.room_name, ts.slot_name,
                    TIME_FORMAT(ts.start_time, '%H:%i') as start_time, TIME_FORMAT(ts.end_time, '%H:%i') as end_time,
                    CONCAT(s.Fname, ' ', s.Lname) as lecturer_name
                    FROM course_schedule cs
                    LEFT JOIN courses c ON c.course_code = cs.course_code
                    LEFT JOIN classrooms cl ON cl.id = cs.classroom_id
                    LEFT JOIN time_slots ts ON ts.id = cs.time_slot_id
                    LEFT JOIN staff s ON s.staff_id = cs.lecturer_id
                    WHERE cs.academic_year = ? AND cs.semester = ?
                    ORDER BY FIELD(cs.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), ts.start_time";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ii", $year, $semester);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $schedules = [];
            while ($row = $result->fetch_assoc()) {
                $schedules[] = $row;
            }
            $stmt->close();
            
            $response = ['success' => true, 'data' => $schedules];
            break;
            
        // ==================== EXAM SCHEDULE ====================
        case 'add_exam':
            $courseCode = trim($_POST['course_code'] ?? '');
            $examType = $_POST['exam_type'] ?? 'final';
            $examDate = $_POST['exam_date'] ?? '';
            $startTime = $_POST['start_time'] ?? '';
            $endTime = $_POST['end_time'] ?? '';
            $classroomId = !empty($_POST['classroom_id']) ? (int)$_POST['classroom_id'] : null;
            $academicYear = (int)($_POST['academic_year'] ?? 1);  // Year of study (1, 2, 3, or 4)
            $semester = (int)($_POST['semester'] ?? 1);
            $maxStudents = !empty($_POST['max_students']) ? (int)$_POST['max_students'] : null;
            $instructions = trim($_POST['special_instructions'] ?? '');
            
            if (empty($courseCode) || empty($examDate) || empty($startTime) || empty($endTime)) {
                $response = ['success' => false, 'message' => 'Please fill in all required fields'];
                break;
            }
            
            $sql = "INSERT INTO exam_schedule (course_code, exam_type, exam_date, start_time, end_time, classroom_id, academic_year, semester, max_students, special_instructions, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $createdBy = $_SESSION['staff_id'];
            $stmt->bind_param("sssssiiiiss", $courseCode, $examType, $examDate, $startTime, $endTime, $classroomId, $academicYear, $semester, $maxStudents, $instructions, $createdBy);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Exam scheduled successfully', 'id' => $stmt->insert_id];
            } else {
                $response = ['success' => false, 'message' => 'Failed to schedule exam: ' . $db->error];
            }
            $stmt->close();
            break;
            
        case 'delete_exam':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid exam ID'];
                break;
            }
            
            $stmt = $db->prepare("DELETE FROM exam_schedule WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Exam deleted successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to delete exam'];
            }
            $stmt->close();
            break;
            
        case 'get_exams':
            $year = (int)($_GET['year'] ?? date('Y'));
            $semester = (int)($_GET['semester'] ?? 1);
            
            $sql = "SELECT es.*, c.course_name, cl.room_code, cl.room_name
                    FROM exam_schedule es
                    LEFT JOIN courses c ON c.course_code = es.course_code
                    LEFT JOIN classrooms cl ON cl.id = es.classroom_id
                    WHERE es.academic_year = ? AND es.semester = ?
                    ORDER BY es.exam_date, es.start_time";
            
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ii", $year, $semester);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $exams = [];
            while ($row = $result->fetch_assoc()) {
                $exams[] = $row;
            }
            $stmt->close();
            
            $response = ['success' => true, 'data' => $exams];
            break;
            
        // ==================== CLASSROOM ====================
        case 'add_classroom':
            $roomCode = trim($_POST['room_code'] ?? '');
            $roomName = trim($_POST['room_name'] ?? '');
            $building = trim($_POST['building'] ?? 'Main Building');
            $floorLevel = (int)($_POST['floor_level'] ?? 0);
            $capacity = (int)($_POST['capacity'] ?? 30);
            $roomType = $_POST['room_type'] ?? 'lecture_hall';
            $hasProjector = isset($_POST['has_projector']) ? 1 : 0;
            $hasWhiteboard = isset($_POST['has_whiteboard']) ? 1 : 0;
            $hasComputers = isset($_POST['has_computers']) ? 1 : 0;
            $isAccessible = isset($_POST['is_accessible']) ? 1 : 0;
            
            if (empty($roomCode) || empty($roomName)) {
                $response = ['success' => false, 'message' => 'Room code and name are required'];
                break;
            }
            
            $sql = "INSERT INTO classrooms (room_code, room_name, building, floor_level, capacity, room_type, has_projector, has_whiteboard, has_computers, is_accessible) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("sssiiiiiii", $roomCode, $roomName, $building, $floorLevel, $capacity, $roomType, $hasProjector, $hasWhiteboard, $hasComputers, $isAccessible);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Classroom added successfully', 'id' => $stmt->insert_id];
            } else {
                if ($db->errno === 1062) {
                    $response = ['success' => false, 'message' => 'A room with this code already exists'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to add classroom: ' . $db->error];
                }
            }
            $stmt->close();
            break;
            
        case 'delete_classroom':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid classroom ID'];
                break;
            }
            
            // Check if classroom is in use
            $check = $db->prepare("SELECT COUNT(*) as cnt FROM course_schedule WHERE classroom_id = ?");
            $check->bind_param("i", $id);
            $check->execute();
            $result = $check->get_result()->fetch_assoc();
            $check->close();
            
            if ($result['cnt'] > 0) {
                $response = ['success' => false, 'message' => 'Cannot delete: Classroom is assigned to ' . $result['cnt'] . ' schedule(s)'];
                break;
            }
            
            $stmt = $db->prepare("DELETE FROM classrooms WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Classroom deleted successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to delete classroom'];
            }
            $stmt->close();
            break;
            
        case 'get_classrooms':
            $result = $db->query("SELECT * FROM classrooms ORDER BY room_code");
            $classrooms = [];
            while ($row = $result->fetch_assoc()) {
                $classrooms[] = $row;
            }
            $response = ['success' => true, 'data' => $classrooms];
            break;
            
        // ==================== TIME SLOTS ====================
        case 'add_timeslot':
            $slotName = trim($_POST['slot_name'] ?? '');
            $startTime = $_POST['start_time'] ?? '';
            $endTime = $_POST['end_time'] ?? '';
            
            if (empty($slotName) || empty($startTime) || empty($endTime)) {
                $response = ['success' => false, 'message' => 'All fields are required'];
                break;
            }
            
            $sql = "INSERT INTO time_slots (slot_name, start_time, end_time) VALUES (?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("sss", $slotName, $startTime, $endTime);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Time slot added successfully', 'id' => $stmt->insert_id];
            } else {
                $response = ['success' => false, 'message' => 'Failed to add time slot'];
            }
            $stmt->close();
            break;
            
        case 'delete_timeslot':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid time slot ID'];
                break;
            }
            
            // Check if time slot is in use
            $check = $db->prepare("SELECT COUNT(*) as cnt FROM course_schedule WHERE time_slot_id = ?");
            $check->bind_param("i", $id);
            $check->execute();
            $result = $check->get_result()->fetch_assoc();
            $check->close();
            
            if ($result['cnt'] > 0) {
                $response = ['success' => false, 'message' => 'Cannot delete: Time slot is used in ' . $result['cnt'] . ' schedule(s)'];
                break;
            }
            
            $stmt = $db->prepare("DELETE FROM time_slots WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Time slot deleted successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to delete time slot'];
            }
            $stmt->close();
            break;
            
        case 'get_timeslots':
            $result = $db->query("SELECT * FROM time_slots ORDER BY start_time");
            $slots = [];
            while ($row = $result->fetch_assoc()) {
                $slots[] = $row;
            }
            $response = ['success' => true, 'data' => $slots];
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Unknown action: ' . $action];
    }
} catch (Exception $e) {
    $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    error_log("Schedule API Error: " . $e->getMessage());
}

echo json_encode($response);
