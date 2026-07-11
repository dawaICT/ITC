<?php
/**
 * AJAX Handlers for Student Records Management
 * Handles all AJAX requests for students.php
 */
require_once dirname(__DIR__, 2) . '/includes/short_course_db.php';

function admissionsStudentTableExists(mysqli $db, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) {
        return $cache[$table] = false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $cache[$table] = $exists;
}

/**
 * Get Students with Pagination and Filters
 */
function handleGetStudents($db, $input) {
    $page = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $limit = isset($input['limit']) ? max(1, min(200, (int)$input['limit'])) : 50;
    $offset = ($page - 1) * $limit;
    
    $search = isset($input['search']) ? trim($input['search']) : '';
    $program = isset($input['program']) ? trim($input['program']) : '';
    $intake = isset($input['intake']) ? trim($input['intake']) : '';
    $mode = isset($input['mode']) ? trim($input['mode']) : '';
    $hasTransportPrograms = admissionsStudentTableExists($db, 'transport_programs');
    $transportProgramJoin = $hasTransportPrograms
        ? "LEFT JOIN transport_programs tp ON tp.program_code COLLATE utf8mb4_unicode_ci = sp.program_code COLLATE utf8mb4_unicode_ci"
        : "";
    $programDisplaySql = $hasTransportPrograms
        ? "COALESCE(p.program_name, CONCAT(tp.program_name, ' (Transport)'), sp.program_code, 'Unassigned')"
        : "COALESCE(p.program_name, sp.program_code, 'Unassigned')";
    
    // Build WHERE clause
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(s.SID LIKE ? OR CONCAT(s.Fname, ' ', s.Lname) LIKE ? OR s.email LIKE ? OR s.nrc_pass LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= 'ssss';
    }
    
    if (!empty($program)) {
        $where[] = "{$programDisplaySql} = ?";
        $params[] = $program;
        $types .= 's';
    }
    
    if (!empty($intake)) {
        $where[] = "sp.intake = ?";
        $params[] = $intake;
        $types .= 's';
    }
    
    if (!empty($mode)) {
        $where[] = "sp.mode = ?";
        $params[] = $mode;
        $types .= 's';
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Count total
    $countSql = "
        SELECT COUNT(DISTINCT s.SID) as total
        FROM students s
        LEFT JOIN student_program sp ON s.SID COLLATE utf8mb4_unicode_ci = sp.Sid COLLATE utf8mb4_unicode_ci
        LEFT JOIN programs p ON sp.program_code = p.program_code
        {$transportProgramJoin}
        {$whereClause}
    ";
    
    $countStmt = $db->prepare($countSql);
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();
    
    // Get data
    $dataSql = "
        SELECT 
            s.SID as sid,
            CONCAT(s.Fname, ' ', s.Lname) as name,
            s.sex as gender,
            s.email,
            s.mobile,
            s.profile_image,
            {$programDisplaySql} as program,
            sp.intake,
            sp.mode,
            COALESCE(s.is_transfer, 0) AS is_transfer,
            s.transfer_from AS previous_institution,
            COALESCE(s.transfer_credits, 0) AS credits_transferred
        FROM students s
        LEFT JOIN student_program sp ON s.SID COLLATE utf8mb4_unicode_ci = sp.Sid COLLATE utf8mb4_unicode_ci
        LEFT JOIN programs p ON sp.program_code = p.program_code
        {$transportProgramJoin}
        {$whereClause}
        GROUP BY s.SID
        ORDER BY s.SID DESC
        LIMIT ? OFFSET ?
    ";
    
    $dataStmt = $db->prepare($dataSql);
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    if (!empty($params)) {
        $dataStmt->bind_param($types, ...$params);
    }
    $dataStmt->execute();
    $result = $dataStmt->get_result();
    
    $students = [];
    while ($row = $result->fetch_assoc()) {
        // Handle profile image path
        $imagePath = '/wucportal/admissions/uploads/profile_images/' . ($row['profile_image'] ?? '');
        $absPath = $_SERVER['DOCUMENT_ROOT'] . $imagePath;
        
        if (empty($row['profile_image']) || !file_exists($absPath)) {
            $row['profile_image'] = '/wucportal/admissions/images/avatar.png';
        } else {
            $row['profile_image'] = $imagePath;
        }
        
        // Convert is_transfer to boolean
        $row['is_transfer'] = (bool)$row['is_transfer'];
        
        $students[] = $row;
    }
    $dataStmt->close();
    
    return [
        'success' => true,
        'data' => $students,
        'total' => (int)$total,
        'pages' => (int)ceil($total / $limit),
        'page' => $page,
        'limit' => $limit
    ];
}

/**
 * Get Statistics
 */
function handleGetStats($db) {
    // Total students
    $totalResult = $db->query("SELECT COUNT(*) as count FROM students");
    $total = $totalResult->fetch_assoc()['count'];
    
    // Active students (those with program enrollment)
    $activeResult = $db->query("
        SELECT COUNT(DISTINCT s.SID) as count 
        FROM students s
        INNER JOIN student_program sp ON s.SID COLLATE utf8mb4_unicode_ci = sp.Sid COLLATE utf8mb4_unicode_ci
    ");
    $active = $activeResult->fetch_assoc()['count'];
    
    // Transfer students
    $transferResult = $db->query("SELECT COUNT(*) as count FROM students WHERE is_transfer = 1");
    $transfer = $transferResult ? (int)$transferResult->fetch_assoc()['count'] : 0;
    
    // Total academic programs. Short courses live in short_courses /
    // short_course_enrollments and are not counted as programs here.
    $programsResult = $db->query("SELECT COUNT(*) as count FROM programs");
    $programs = $programsResult->fetch_assoc()['count'];
    
    return [
        'success' => true,
        'data' => [
            'total' => (int)$total,
            'active' => (int)$active,
            'transfer' => (int)$transfer,
            'programs' => (int)$programs
        ]
    ];
}

/**
 * Get Student Details
 */
function handleGetStudentDetails($db, $input) {
    if (empty($input['sid'])) {
        return ['success' => false, 'message' => 'Student ID is required'];
    }
    
    $sid = $input['sid'];
    $hasTransportPrograms = admissionsStudentTableExists($db, 'transport_programs');
    $transportProgramJoin = $hasTransportPrograms
        ? "LEFT JOIN transport_programs tp ON tp.program_code COLLATE utf8mb4_unicode_ci = sp.program_code COLLATE utf8mb4_unicode_ci"
        : "";
    $programDisplaySql = $hasTransportPrograms
        ? "COALESCE(p.program_name, CONCAT(tp.program_name, ' (Transport)'), sp.program_code, 'Unassigned')"
        : "COALESCE(p.program_name, sp.program_code, 'Unassigned')";
    
    $sql = "
        SELECT 
            s.*,
            {$programDisplaySql} AS program_name,
            sp.intake,
            sp.mode,
            COALESCE(s.is_transfer, 0) AS is_transfer,
            s.transfer_from AS previous_institution,
            COALESCE(s.transfer_credits, 0) AS credits_transferred,
            sp.startYear AS start_year,
            sp.endYear AS end_year
        FROM students s
        LEFT JOIN student_program sp ON s.SID COLLATE utf8mb4_unicode_ci = sp.Sid COLLATE utf8mb4_unicode_ci
        LEFT JOIN programs p ON sp.program_code = p.program_code
        {$transportProgramJoin}
        WHERE s.SID = ?
        LIMIT 1
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Handle profile image path
        $imagePath = '/wucportal/admissions/uploads/profile_images/' . ($row['profile_image'] ?? '');
        $absPath = $_SERVER['DOCUMENT_ROOT'] . $imagePath;
        
        if (empty($row['profile_image']) || !file_exists($absPath)) {
            $row['profile_image'] = '/wucportal/admissions/images/avatar.png';
        } else {
            $row['profile_image'] = $imagePath;
        }
        
        $stmt->close();
        return [
            'success' => true,
            'data' => $row
        ];
    }
    
    $stmt->close();
    return ['success' => false, 'message' => 'Student not found'];
}

/**
 * Delete Student
 */
function handleDeleteStudent($db, $input) {
    if (empty($input['sid'])) {
        return ['success' => false, 'message' => 'Student ID is required'];
    }
    
    $sid = $input['sid'];
    
    // Start transaction
    $db->begin_transaction();
    
    try {
        // Delete from student_program
        $stmt1 = $db->prepare("DELETE FROM student_program WHERE Sid = ?");
        $stmt1->bind_param('s', $sid);
        $stmt1->execute();
        $stmt1->close();
        
        // Delete from students
        $stmt2 = $db->prepare("DELETE FROM students WHERE SID = ?");
        $stmt2->bind_param('s', $sid);
        $stmt2->execute();
        
        if ($stmt2->affected_rows === 0) {
            throw new Exception('Student not found or already deleted');
        }
        
        $stmt2->close();
        $db->commit();
        
        return [
            'success' => true,
            'message' => 'Student deleted successfully'
        ];
    } catch (Exception $e) {
        $db->rollback();
        return [
            'success' => false,
            'message' => 'Failed to delete student: ' . $e->getMessage()
        ];
    }
}

/**
 * Bulk Export
 */
function handleBulkExport($db, $input) {
    $format = $input['format'] ?? 'csv';
    $studentIds = $input['student_ids'] ?? [];
    
    if (empty($studentIds)) {
        return ['success' => false, 'message' => 'No students selected'];
    }
    $hasTransportPrograms = admissionsStudentTableExists($db, 'transport_programs');
    $transportProgramJoin = $hasTransportPrograms
        ? "LEFT JOIN transport_programs tp ON tp.program_code COLLATE utf8mb4_unicode_ci = sp.program_code COLLATE utf8mb4_unicode_ci"
        : "";
    $programDisplaySql = $hasTransportPrograms
        ? "COALESCE(p.program_name, CONCAT(tp.program_name, ' (Transport)'), sp.program_code, '')"
        : "COALESCE(p.program_name, sp.program_code, '')";
    
    // Get student data
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $sql = "
        SELECT 
            s.SID,
            s.Fname,
            s.Lname,
            s.sex,
            s.email,
            s.mobile,
            {$programDisplaySql} AS program_name,
            sp.intake,
            sp.mode
        FROM students s
        LEFT JOIN student_program sp ON s.SID COLLATE utf8mb4_unicode_ci = sp.Sid COLLATE utf8mb4_unicode_ci
        LEFT JOIN programs p ON sp.program_code = p.program_code
        {$transportProgramJoin}
        WHERE s.SID IN ($placeholders)
    ";
    
    $stmt = $db->prepare($sql);
    $types = str_repeat('s', count($studentIds));
    $stmt->bind_param($types, ...$studentIds);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($format === 'csv') {
        $filename = 'students_export_' . date('Y-m-d_His') . '.csv';
        $filepath = $_SERVER['DOCUMENT_ROOT'] . '/wucportal/exports/' . $filename;
        
        // Create exports directory if it doesn't exist
        $exportDir = dirname($filepath);
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }
        
        $fp = fopen($filepath, 'w');
        
        // Headers
        fputcsv($fp, ['Student ID', 'First Name', 'Last Name', 'Gender', 'Email', 'Mobile', 'Program', 'Intake', 'Mode']);
        
        // Data
        while ($row = $result->fetch_assoc()) {
            fputcsv($fp, [
                $row['SID'],
                $row['Fname'],
                $row['Lname'],
                $row['sex'],
                $row['email'] ?? '',
                $row['mobile'] ?? '',
                $row['program_name'] ?? '',
                $row['intake'] ?? '',
                $row['mode'] ?? ''
            ]);
        }
        
        fclose($fp);
        $stmt->close();
        
        return [
            'success' => true,
            'download_url' => '/wucportal/exports/' . $filename
        ];
    }
    
    $stmt->close();
    return ['success' => false, 'message' => 'Unsupported export format'];
}

/**
 * Admit Student
 */
function handleAdmitStudent($db, $input) {
    // Validate required fields
    $required = ['student_id', 'program_code', 'intake', 'mode', 'startYear', 'endYear'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            return ['success' => false, 'message' => "Missing required field: {$field}"];
        }
    }
    
    $studentId = $input['student_id'];
    $programCode = $input['program_code'];
    $intake = $input['intake'];
    $mode = $input['mode'];
    $startYear = (int)$input['startYear'];
    $endYear = (int)$input['endYear'];
    $isTransfer = !empty($input['is_transfer']) && $input['is_transfer'] !== '0';
    
    // Check if student exists
    $checkStmt = $db->prepare("SELECT SID FROM students WHERE SID = ?");
    $checkStmt->bind_param('s', $studentId);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows === 0) {
        $checkStmt->close();
        return ['success' => false, 'message' => 'Student not found'];
    }
    $checkStmt->close();
    
    // Check if already admitted
    $admitCheckStmt = $db->prepare("SELECT SID FROM student_program WHERE SID = ?");
    $admitCheckStmt->bind_param('s', $studentId);
    $admitCheckStmt->execute();
    if ($admitCheckStmt->get_result()->num_rows > 0) {
        $admitCheckStmt->close();
        return ['success' => false, 'message' => 'Student is already admitted to a program'];
    }
    $admitCheckStmt->close();

    $programCheckSql = "SELECT 1 FROM programs WHERE program_code = ?";
    $programCheckTypes = 's';
    $programCheckParams = [$programCode];
    if (admissionsStudentTableExists($db, 'transport_programs')) {
        $programCheckSql .= "
            UNION
            SELECT 1 FROM transport_programs
            WHERE program_code = ?
              AND status = 'active'
              AND COALESCE(duration_days, 0) BETWEEN 1 AND " . SC_MAX_SHORT_COURSE_DAYS . "
        ";
        $programCheckTypes .= 's';
        $programCheckParams[] = $programCode;
    }
    $programCheckSql .= " LIMIT 1";
    $programCheckStmt = $db->prepare($programCheckSql);
    if (!$programCheckStmt) {
        return ['success' => false, 'message' => 'Unable to validate the selected program'];
    }
    $programCheckStmt->bind_param($programCheckTypes, ...$programCheckParams);
    $programCheckStmt->execute();
    $programExists = $programCheckStmt->get_result()->num_rows > 0;
    $programCheckStmt->close();
    if (!$programExists) {
        return ['success' => false, 'message' => 'Select a valid academic programme or a short course of six months or less'];
    }
    
    $previousInstitution = $isTransfer ? ($input['previous_institution'] ?? '') : null;
    $creditsTransferred = $isTransfer ? (int)($input['credits_transferred'] ?? 0) : 0;

    // Record transfer details (orthogonal to enrolment) before enrolling.
    if ($isTransfer) {
        if ($transferStmt = $db->prepare("UPDATE students SET is_transfer = 1, transfer_from = ?, transfer_credits = ? WHERE SID = ?")) {
            $transferStmt->bind_param('sis', $previousInstitution, $creditsTransferred, $studentId);
            $transferStmt->execute();
            $transferStmt->close();
        }
    }

    // Single canonical admission path. It derives the period (term / semester /
    // rolling short-course), the intake label, the term start/end dates, the
    // academic_year and term number from the program's period_mode — and also
    // guarantees the login + course assignment + invoice. This replaces a
    // hand-written INSERT that ignored period_mode (so term programs were stored
    // as if semester) and left modal-admitted students without a login. Keeps the
    // modal admit identical to admitStudent.php and the online-applicant flow.
    require_once dirname(__DIR__, 2) . '/includes/applicant_admission.php';
    return admissionsEnrollExistingStudent($db, $studentId, $programCode, $intake, $mode, $startYear);
}
