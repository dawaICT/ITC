<?php
/**
 * Timetable Tables Migration Script
 * Run this to create/update timetable-related database tables
 */
require_once __DIR__ . '/db/connect.php';

echo "=== Timetable Database Migration ===\n\n";

$migrations = [
    // Classrooms table
    "classrooms" => "CREATE TABLE IF NOT EXISTS classrooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_code VARCHAR(20) UNIQUE NOT NULL,
        room_name VARCHAR(100) NOT NULL,
        building VARCHAR(50) DEFAULT 'Main',
        floor_level INT DEFAULT 0,
        capacity INT DEFAULT 30,
        room_type ENUM('lecture_hall', 'laboratory', 'seminar', 'computer_lab', 'tutorial') DEFAULT 'lecture_hall',
        has_projector TINYINT(1) DEFAULT 1,
        has_whiteboard TINYINT(1) DEFAULT 1,
        has_computers TINYINT(1) DEFAULT 0,
        is_accessible TINYINT(1) DEFAULT 1,
        status ENUM('available', 'maintenance', 'closed') DEFAULT 'available',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Time slots table
    "time_slots" => "CREATE TABLE IF NOT EXISTS time_slots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slot_name VARCHAR(50) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Course schedule table
    "course_schedule" => "CREATE TABLE IF NOT EXISTS course_schedule (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_code VARCHAR(50) NOT NULL,
        lecturer_id INT DEFAULT NULL,
        classroom_id INT DEFAULT NULL,
        time_slot_id INT DEFAULT NULL,
        day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
        academic_year INT NOT NULL,
        semester INT NOT NULL DEFAULT 1,
        schedule_type ENUM('lecture', 'tutorial', 'lab', 'seminar', 'workshop') DEFAULT 'lecture',
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        recurrence ENUM('weekly', 'biweekly', 'once') DEFAULT 'weekly',
        is_active TINYINT(1) DEFAULT 1,
        notes TEXT,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_course_schedule (course_code, academic_year, semester),
        INDEX idx_day_slot (day_of_week, time_slot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Exam schedule table
    "exam_schedule" => "CREATE TABLE IF NOT EXISTS exam_schedule (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_code VARCHAR(50) NOT NULL,
        exam_type ENUM('midterm', 'final', 'quiz', 'practical', 'supplementary') DEFAULT 'final',
        exam_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        classroom_id INT DEFAULT NULL,
        academic_year INT NOT NULL,
        semester INT NOT NULL DEFAULT 1,
        invigilator_id INT DEFAULT NULL,
        max_students INT DEFAULT NULL,
        special_instructions TEXT,
        status ENUM('scheduled', 'completed', 'cancelled', 'postponed') DEFAULT 'scheduled',
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_exam_date (exam_date),
        INDEX idx_exam_course (course_code, academic_year, semester)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

// Execute migrations
foreach ($migrations as $table => $sql) {
    echo "Creating table '$table'... ";
    if ($db->query($sql)) {
        echo "✓ OK\n";
    } else {
        echo "✗ ERROR: " . $db->error . "\n";
    }
}

// Add foreign keys if not exist
echo "\nAdding foreign keys...\n";

// Check and add FK for course_schedule.classroom_id
$fkCheck = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_schedule' 
    AND CONSTRAINT_NAME = 'fk_schedule_classroom'");
if ($fkCheck && $fkCheck->num_rows === 0) {
    $db->query("ALTER TABLE course_schedule ADD CONSTRAINT fk_schedule_classroom 
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE SET NULL");
    echo "  Added FK: fk_schedule_classroom\n";
}

$fkCheck = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_schedule' 
    AND CONSTRAINT_NAME = 'fk_schedule_timeslot'");
if ($fkCheck && $fkCheck->num_rows === 0) {
    $db->query("ALTER TABLE course_schedule ADD CONSTRAINT fk_schedule_timeslot 
        FOREIGN KEY (time_slot_id) REFERENCES time_slots(id) ON DELETE SET NULL");
    echo "  Added FK: fk_schedule_timeslot\n";
}

// Insert default time slots
echo "\nInserting default time slots...\n";
$timeSlots = [
    ['Period 1', '08:00:00', '09:00:00'],
    ['Period 2', '09:00:00', '10:00:00'],
    ['Period 3', '10:00:00', '11:00:00'],
    ['Period 4', '11:00:00', '12:00:00'],
    ['Lunch Break', '12:00:00', '13:00:00'],
    ['Period 5', '13:00:00', '14:00:00'],
    ['Period 6', '14:00:00', '15:00:00'],
    ['Period 7', '15:00:00', '16:00:00'],
    ['Period 8', '16:00:00', '17:00:00'],
    ['Evening 1', '17:00:00', '18:00:00'],
    ['Evening 2', '18:00:00', '19:00:00']
];

$stmt = $db->prepare("INSERT IGNORE INTO time_slots (slot_name, start_time, end_time) VALUES (?, ?, ?)");
foreach ($timeSlots as $slot) {
    $stmt->bind_param("sss", $slot[0], $slot[1], $slot[2]);
    $stmt->execute();
}
$stmt->close();
echo "  ✓ Time slots inserted\n";

// Insert sample classrooms
echo "\nInserting sample classrooms...\n";
$classrooms = [
    ['LH-101', 'Lecture Hall 101', 'Main Building', 1, 150, 'lecture_hall', 0],
    ['LH-102', 'Lecture Hall 102', 'Main Building', 1, 100, 'lecture_hall', 0],
    ['LH-201', 'Lecture Hall 201', 'Main Building', 2, 80, 'lecture_hall', 0],
    ['SR-101', 'Seminar Room 1', 'Main Building', 1, 30, 'seminar', 0],
    ['SR-102', 'Seminar Room 2', 'Main Building', 1, 30, 'seminar', 0],
    ['CL-101', 'Computer Lab 1', 'IT Block', 1, 40, 'computer_lab', 1],
    ['CL-102', 'Computer Lab 2', 'IT Block', 1, 40, 'computer_lab', 1],
    ['SL-101', 'Science Lab 1', 'Science Block', 1, 25, 'laboratory', 0],
    ['SL-102', 'Science Lab 2', 'Science Block', 1, 25, 'laboratory', 0],
    ['TR-101', 'Tutorial Room 1', 'Main Building', 2, 20, 'tutorial', 0]
];

$stmt = $db->prepare("INSERT IGNORE INTO classrooms (room_code, room_name, building, floor_level, capacity, room_type, has_computers) VALUES (?, ?, ?, ?, ?, ?, ?)");
foreach ($classrooms as $room) {
    $stmt->bind_param("sssiisi", $room[0], $room[1], $room[2], $room[3], $room[4], $room[5], $room[6]);
    $stmt->execute();
}
$stmt->close();
echo "  ✓ Classrooms inserted\n";

// Insert sample schedule data for existing courses
echo "\nInserting sample schedule data...\n";
$coursesResult = $db->query("SELECT course_code FROM courses WHERE status = 'active' LIMIT 10");
if ($coursesResult && $coursesResult->num_rows > 0) {
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    $scheduleTypes = ['lecture', 'tutorial', 'lab'];
    $currentYear = (int)date('Y');
    
    $stmt = $db->prepare("INSERT IGNORE INTO course_schedule 
        (course_code, day_of_week, time_slot_id, classroom_id, academic_year, semester, schedule_type) 
        VALUES (?, ?, ?, ?, ?, 1, ?)");
    
    $slotId = 1;
    $classroomId = 1;
    while ($course = $coursesResult->fetch_assoc()) {
        $day = $days[array_rand($days)];
        $type = $scheduleTypes[array_rand($scheduleTypes)];
        $stmt->bind_param("ssiiss", $course['course_code'], $day, $slotId, $classroomId, $currentYear, $type);
        $stmt->execute();
        
        $slotId = ($slotId % 11) + 1;
        $classroomId = ($classroomId % 10) + 1;
    }
    $stmt->close();
    echo "  ✓ Sample schedules created\n";
}

echo "\n=== Migration Complete ===\n";

// Summary
echo "\nTable Summary:\n";
$tables = ['classrooms', 'time_slots', 'course_schedule', 'exam_schedule'];
foreach ($tables as $table) {
    $count = $db->query("SELECT COUNT(*) as cnt FROM $table")->fetch_assoc()['cnt'];
    echo "  $table: $count records\n";
}
