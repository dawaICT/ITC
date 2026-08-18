<?php
/**
 * Seed test student accounts for every program portal type:
 * - Short Courses Portal (TEST-SC01)
 * - Diploma Portal (TEST-DIP01)
 * - Certificate Portal (TEST-CERT01)
 * - Trade Test Portal (TEST-TT01)
 * - Degree Portal (TEST-DEG01)
 * - Postgraduate Portal (TEST-PG01)
 *
 * Password for all test accounts: Student@12345
 */

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/portal_access.php';
require_once __DIR__ . '/../includes/short_course_student.php';
require_once __DIR__ . '/../includes/student_program_portal.php';

$defaultPassword = 'Student@12345';
$hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);

echo "========================================================\n";
echo "SEEDING MULTI-PROGRAM TEST ACCOUNTS\n";
echo "========================================================\n";

// 1. Ensure Programs Exist
$programs = [
    [
        'program_code' => 'BSCS',
        'program_name' => 'Bachelor of Science in Computer Science',
        'program_type' => 'Degree',
        'qualification_level' => 'Degree',
        'academic_structure' => 'semester',
        'structure_type' => 'SEMESTER_BASED',
        'period_mode' => 'semester',
        'study_mode' => 'Full Time',
        'program_duration' => 4,
        'duration_value' => 4,
        'duration_unit' => 'years',
        'uses_semesters' => 1,
        'uses_terms' => 0,
        'is_short_course' => 0,
    ],
    [
        'program_code' => 'MSC-IT',
        'program_name' => 'Master of Science in Information Technology',
        'program_type' => 'Postgraduate',
        'qualification_level' => 'Masters',
        'academic_structure' => 'semester',
        'structure_type' => 'SEMESTER_BASED',
        'period_mode' => 'semester',
        'study_mode' => 'Full Time',
        'program_duration' => 2,
        'duration_value' => 2,
        'duration_unit' => 'years',
        'uses_semesters' => 1,
        'uses_terms' => 0,
        'is_short_course' => 0,
    ],
    [
        'program_code' => 'CSE',
        'program_name' => 'Diploma in Computer Systems Engineering',
        'program_type' => 'Diploma',
        'qualification_level' => 'Diploma',
        'academic_structure' => 'diploma_term',
        'structure_type' => 'TERM_BASED',
        'period_mode' => 'term',
        'study_mode' => 'Full Time',
        'program_duration' => 3,
        'duration_value' => 3,
        'duration_unit' => 'years',
        'uses_semesters' => 0,
        'uses_terms' => 1,
        'is_short_course' => 0,
    ],
    [
        'program_code' => 'AUTO-002',
        'program_name' => 'Certificate in Vehicle Maintenance and Repair',
        'program_type' => 'Certificate',
        'qualification_level' => 'Certificate',
        'academic_structure' => 'certificate_term',
        'structure_type' => 'TERM_BASED',
        'period_mode' => 'term',
        'study_mode' => 'Full Time',
        'program_duration' => 2,
        'duration_value' => 2,
        'duration_unit' => 'years',
        'uses_semesters' => 0,
        'uses_terms' => 1,
        'is_short_course' => 0,
    ],
    [
        'program_code' => 'FAB-TT1',
        'program_name' => 'Trade Test Level 1 Metal Fabrication',
        'program_type' => 'Certificate',
        'qualification_level' => 'Trade Test',
        'academic_structure' => 'trade_test_level',
        'structure_type' => 'TRADE_TEST_LEVEL',
        'period_mode' => 'term',
        'study_mode' => 'Full Time',
        'program_duration' => 1,
        'duration_value' => 1,
        'duration_unit' => 'years',
        'uses_semesters' => 0,
        'uses_terms' => 1,
        'is_short_course' => 0,
    ],
    [
        'program_code' => 'TRANS-001',
        'program_name' => 'Class A Motor Bike Riding',
        'program_type' => 'Short Course',
        'qualification_level' => 'Short Course',
        'academic_structure' => 'short_course',
        'structure_type' => 'SHORT_COURSE',
        'period_mode' => 'short_course',
        'study_mode' => 'Full Time',
        'program_duration' => 0.1,
        'duration_value' => 10,
        'duration_unit' => 'days',
        'uses_semesters' => 0,
        'uses_terms' => 0,
        'is_short_course' => 1,
    ],
];

foreach ($programs as $prog) {
    $stmt = $db->prepare(
        "INSERT INTO programs (
            program_code, program_name, program_type, qualification_level,
            academic_structure, structure_type, period_mode, study_mode,
            program_duration, duration_value, duration_unit, uses_semesters,
            uses_terms, is_short_course, is_active
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
            program_name = VALUES(program_name),
            program_type = VALUES(program_type),
            qualification_level = VALUES(qualification_level),
            academic_structure = VALUES(academic_structure),
            structure_type = VALUES(structure_type),
            period_mode = VALUES(period_mode),
            is_short_course = VALUES(is_short_course),
            is_active = 1"
    );
    if ($stmt) {
        $stmt->bind_param(
            'ssssssssddsiii',
            $prog['program_code'],
            $prog['program_name'],
            $prog['program_type'],
            $prog['qualification_level'],
            $prog['academic_structure'],
            $prog['structure_type'],
            $prog['period_mode'],
            $prog['study_mode'],
            $prog['program_duration'],
            $prog['duration_value'],
            $prog['duration_unit'],
            $prog['uses_semesters'],
            $prog['uses_terms'],
            $prog['is_short_course']
        );
        $stmt->execute();
        $stmt->close();
    }
}

// 2. Ensure Short Course in short_courses
$scStmt = $db->prepare(
    "INSERT INTO short_courses (
        course_code, course_name, duration_value, duration_unit,
        standard_duration_days, is_duration_fixed, fee, max_capacity, delivery_mode, status
     ) VALUES ('TRANS-001', 'Class A Motor Bike Riding', 10, 'days', 10, 1, 1500.00, 30, 'full-time', 'active')
     ON DUPLICATE KEY UPDATE
        course_name = VALUES(course_name),
        duration_value = VALUES(duration_value),
        duration_unit = VALUES(duration_unit),
        fee = VALUES(fee),
        status = 'active'"
);
if ($scStmt) {
    $scStmt->execute();
    $scStmt->close();
}

$scId = 5;
$chkScId = $db->query("SELECT id FROM short_courses WHERE course_code = 'TRANS-001' LIMIT 1");
if ($chkScId && ($scRow = $chkScId->fetch_assoc())) {
    $scId = (int)$scRow['id'];
}

// 3. Define Test Students
$testStudents = [
    [
        'sid' => 'TEST-SC01',
        'fname' => 'Sam',
        'lname' => 'Shortcourse',
        'email' => 'test-sc01@wuc.edu.zm',
        'mobile' => '0971000001',
        'program_code' => 'TRANS-001',
        'is_sc_only' => true,
        'portal_desc' => 'Short Course Portal',
    ],
    [
        'sid' => 'TEST-DIP01',
        'fname' => 'David',
        'lname' => 'Diploma',
        'email' => 'test-dip01@wuc.edu.zm',
        'mobile' => '0971000002',
        'program_code' => 'ICT-002',
        'is_sc_only' => false,
        'portal_desc' => 'Diploma Portal',
    ],
    [
        'sid' => 'TEST-CERT01',
        'fname' => 'Charles',
        'lname' => 'Certificate',
        'email' => 'test-cert01@wuc.edu.zm',
        'mobile' => '0971000003',
        'program_code' => 'ICT-001',
        'is_sc_only' => false,
        'portal_desc' => 'Certificate Portal',
    ],
    [
        'sid' => 'TEST-TT01',
        'fname' => 'Thomas',
        'lname' => 'Tradetest',
        'email' => 'test-tt01@wuc.edu.zm',
        'mobile' => '0971000004',
        'program_code' => 'FAB-TT1',
        'is_sc_only' => false,
        'portal_desc' => 'Trade Test Portal',
    ],
    [
        'sid' => 'TEST-DEG01',
        'fname' => 'Daniel',
        'lname' => 'Degree',
        'email' => 'test-deg01@wuc.edu.zm',
        'mobile' => '0971000005',
        'program_code' => 'BSCS',
        'is_sc_only' => false,
        'portal_desc' => 'Degree Portal',
    ],
    [
        'sid' => 'TEST-PG01',
        'fname' => 'Patricia',
        'lname' => 'Postgrad',
        'email' => 'test-pg01@wuc.edu.zm',
        'mobile' => '0971000006',
        'program_code' => 'MSC-IT',
        'is_sc_only' => false,
        'portal_desc' => 'Postgraduate Portal',
    ],
];

foreach ($testStudents as $ts) {
    $sid = $ts['sid'];
    $prog = $ts['program_code'];

    // Insert into students table
    $st = $db->prepare(
        "INSERT INTO students (SID, Fname, Lname, email, mobile, program, status, academic_year, intake, mode, year)
         VALUES (?, ?, ?, ?, ?, ?, 'active', 2026, 'January', 'Full Time', 'Year 1')
         ON DUPLICATE KEY UPDATE
            Fname = VALUES(Fname),
            Lname = VALUES(Lname),
            email = VALUES(email),
            mobile = VALUES(mobile),
            program = VALUES(program),
            status = 'active'"
    );
    if ($st) {
        $st->bind_param('ssssss', $sid, $ts['fname'], $ts['lname'], $ts['email'], $ts['mobile'], $prog);
        $st->execute();
        $st->close();
    }

    // Insert into student_login table
    $stLog = $db->prepare(
        "INSERT INTO student_login (Sid, Password)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE Password = VALUES(Password)"
    );
    if ($stLog) {
        $stLog->bind_param('ss', $sid, $hashedPassword);
        $stLog->execute();
        $stLog->close();
    }

    // Insert into users table
    $stUser = $db->prepare(
        "INSERT INTO users (username, password, student_id, primary_role, status)
         VALUES (?, ?, ?, 'student', 'active')
         ON DUPLICATE KEY UPDATE
            password = VALUES(password),
            student_id = VALUES(student_id),
            primary_role = 'student',
            status = 'active'"
    );
    if ($stUser) {
        $stUser->bind_param('sss', $sid, $hashedPassword, $sid);
        $stUser->execute();
        $stUser->close();
    }

    // Get user_id
    $userId = 0;
    $chkUid = $db->prepare("SELECT user_id FROM users WHERE student_id = ? LIMIT 1");
    if ($chkUid) {
        $chkUid->bind_param('s', $sid);
        $chkUid->execute();
        $chkUid->bind_result($userId);
        $chkUid->fetch();
        $chkUid->close();
    }

    // Grant academic portal access
    if ($userId > 0) {
        wuc_grant_user_portal_access($db, (int)$userId, ['academic', 'elearning'], 'seeder');
    }

    // Insert into student_program (all students except short-only if we keep separation, but even with short course program)
    if (!$ts['is_sc_only']) {
        $stProg = $db->prepare(
            "INSERT INTO student_program (Sid, program_code, intake, mode, startYear, endYear, status, academic_year)
             VALUES (?, ?, 'January', 'Full Time', 2026, 2029, 'active', 2026)
             ON DUPLICATE KEY UPDATE
                program_code = VALUES(program_code),
                status = 'active'"
        );
        if ($stProg) {
            $stProg->bind_param('ss', $sid, $prog);
            $stProg->execute();
            $stProg->close();
        }
        // Also seed course registrations from program_courses
        $coursesQuery = $db->prepare("SELECT course_code FROM program_courses WHERE program_code = ? LIMIT 6");
        if ($coursesQuery) {
            $coursesQuery->bind_param('s', $prog);
            $coursesQuery->execute();
            $cRes = $coursesQuery->get_result();
            while ($cRow = $cRes->fetch_assoc()) {
                $cCode = (string)$cRow['course_code'];
                $db->query("INSERT INTO course_registration (Sid, course_code, semester, Year, academic_year, is_active)
                            VALUES ('$sid', '$cCode', '1', '1', '2026', 1)
                            ON DUPLICATE KEY UPDATE is_active = 1");

                // Seed sample published CA marks
                $db->query("INSERT INTO semester_assessment (Sid, Course_Code, A1, A2, T1, Total_CA, semester, Year, program_type, posted_by, status, published_by, published_at)
                            VALUES ('$sid', '$cCode', 82.00, 88.00, 79.00, 83.00, '1', '2026', 'term', 'WUC907', 'Published', 'admin', NOW())
                            ON DUPLICATE KEY UPDATE A1 = 82.00, A2 = 88.00, T1 = 79.00, Total_CA = 83.00, status = 'Published'");

                if (function_exists('ca_sync_normalized_component')) {
                    $regId = ca_sync_normalized_component($db, $sid, $cCode, '1', '2026', 'A1', 82.00, 'WUC907');
                    ca_sync_normalized_component($db, $sid, $cCode, '1', '2026', 'A2', 88.00, 'WUC907');
                    ca_sync_normalized_component($db, $sid, $cCode, '1', '2026', 'T1', 79.00, 'WUC907');
                    if ($regId && function_exists('ca_sync_normalized_result')) {
                        ca_sync_normalized_result($db, (int)$regId, 83.00);
                    }
                }
            }
            $coursesQuery->close();
        }
    } else {
        // Enrol short course in short_course_enrollments
        $stScEnrol = $db->prepare(
            "INSERT INTO short_course_enrollments (short_course_id, student_id, status, enrollment_date, certificate_issued)
             VALUES (?, ?, 'enrolled', NOW(), 0)
             ON DUPLICATE KEY UPDATE status = 'enrolled'"
        );
        if ($stScEnrol) {
            $stScEnrol->bind_param('is', $scId, $sid);
            $stScEnrol->execute();
            $stScEnrol->close();
        }

        // Seed short course assessment mark
        $db->query("INSERT INTO short_course_assessment (short_course_id, course_code, student_id, A1, A2, T1, Total_CA, posted_by)
                    VALUES ($scId, '$prog', '$sid', 85.00, 90.00, 88.00, 87.67, 'WUC907')
                    ON DUPLICATE KEY UPDATE A1 = 85.00, A2 = 90.00, T1 = 88.00, Total_CA = 87.67");
    }

    // Verify resolved profile
    $profile = wuc_student_program_portal_profile($db, $sid);
    echo sprintf(
        "%-14s | %-18s | %-16s | %s\n",
        $sid,
        $ts['portal_desc'],
        $profile['dashboard_label'],
        $profile['route']
    );
}

echo "========================================================\n";
echo "SUCCESS: ALL MULTI-PROGRAM TEST ACCOUNTS SEEDED\n";
echo "========================================================\n";
