<?php
require "includes/admin.php";
error_reporting(0);

header('Content-Type: application/json');

if(!isset($_POST['Sid'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Student ID is required'
    ]);
    exit;
}

$Sid = trim($_POST['Sid']);
$academic_year = '1';  // Year of study (1, 2, 3, or 4)

// Get student information
$query = "SELECT s.SID, s.Fname, s.Lname, sp.mode, sp.program_code, p.program_name,
         (SELECT GROUP_CONCAT(CONCAT('Semester ', semester, ' - Year ', Year) SEPARATOR ', ') 
          FROM semester_registration 
          WHERE Sid = s.SID AND academic_year = ?) as current_registrations
         FROM students s 
         LEFT JOIN student_program sp ON s.SID = sp.Sid 
         LEFT JOIN programs p ON sp.program_code = p.program_code 
         WHERE s.SID = ?";

try {
    if($stmt = $db->prepare($query)) {
        $stmt->bind_param("ss", $academic_year, $Sid);
        $stmt->execute();
        $result = $stmt->get_result();

        if($result->num_rows > 0) {
            $student_info = $result->fetch_assoc();

            // Get registration history
            $reg_query = "SELECT academic_year, semester, Year 
                         FROM semester_registration 
                         WHERE Sid = ? 
                         ORDER BY academic_year DESC, semester ASC";

            $reg_stmt = $db->prepare($reg_query);
            $reg_stmt->bind_param("s", $Sid);
            $reg_stmt->execute();
            $registrations = $reg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            echo json_encode([
                'status' => 'success',
                'student' => [
                    'SID' => $student_info['SID'],
                    'Fname' => $student_info['Fname'],
                    'Lname' => $student_info['Lname'],
                    'program_code' => $student_info['program_code'],
                    'program_name' => $student_info['program_name'],
                    'mode' => $student_info['mode'],
                    'current_registrations' => $student_info['current_registrations']
                ],
                'registrations' => $registrations,
                'academic_year' => $academic_year
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Student not found'
            ]);
        }
    } else {
        throw new Exception("Failed to prepare query");
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?> 