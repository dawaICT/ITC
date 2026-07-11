<?php
require_once 'db/connect.php';

echo "=== Lecturer-Related Database Analysis ===\n\n";

// Check tables
$tables = ['course_lecturer', 'lecturer_courses', 'staff', 'staff_positions', 'courses'];

foreach($tables as $table) {
    $result = $db->query("SHOW TABLES LIKE '$table'");
    if($result && $result->num_rows > 0) {
        echo "✓ Table '$table' EXISTS\n";
        
        // Get structure
        $desc = $db->query("DESCRIBE $table");
        echo "  Columns: ";
        $cols = [];
        while($c = $desc->fetch_assoc()) {
            $cols[] = $c['Field'] . ' (' . $c['Type'] . ')';
        }
        echo implode(", ", $cols) . "\n";
        
        // Get count
        $count = $db->query("SELECT COUNT(*) as cnt FROM $table");
        $cnt = $count->fetch_assoc()['cnt'];
        echo "  Records: $cnt\n";
        
        // Sample data
        if($cnt > 0 && $cnt < 50) {
            echo "  Sample data:\n";
            $sample = $db->query("SELECT * FROM $table LIMIT 5");
            while($row = $sample->fetch_assoc()) {
                echo "    " . json_encode($row) . "\n";
            }
        }
        echo "\n";
    } else {
        echo "✗ Table '$table' DOES NOT EXIST\n\n";
    }
}

// Check lecturer-course assignments
echo "=== Lecturer-Course Assignments ===\n";
$queries = [
    "course_lecturer table" => "SELECT cl.*, s.Fname, s.Lname, c.course_name FROM course_lecturer cl LEFT JOIN staff s ON cl.staff_id = s.staff_id LEFT JOIN courses c ON cl.course_code = c.course_code LIMIT 10",
    "lecturer_courses table" => "SELECT lc.*, s.Fname, s.Lname, c.course_name FROM lecturer_courses lc LEFT JOIN staff s ON lc.lecturer_id = s.staff_id LEFT JOIN courses c ON lc.course_code = c.course_code LIMIT 10"
];

foreach($queries as $desc => $sql) {
    echo "\nChecking $desc:\n";
    $result = $db->query($sql);
    if($result) {
        if($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                echo "  " . json_encode($row) . "\n";
            }
        } else {
            echo "  No records found\n";
        }
    } else {
        echo "  Error: " . $db->error . "\n";
    }
}

// Check lecturers in staff table
echo "\n=== Lecturers in Staff Table ===\n";
$lec_check = $db->query("SELECT s.staff_id, s.Fname, s.Lname, s.email, sp.PosID FROM staff s LEFT JOIN staff_positions sp ON s.staff_id = sp.staff_id LEFT JOIN positions p ON sp.PosID = p.PosID WHERE p.PosName = 'Lecturer' OR s.staff_id LIKE 'LEC%' LIMIT 10");
if($lec_check && $lec_check->num_rows > 0) {
    while($row = $lec_check->fetch_assoc()) {
        echo "  " . json_encode($row) . "\n";
    }
} else {
    echo "  No lecturers found\n";
}

echo "\n=== Position Codes ===\n";
$pos = $db->query("SELECT DISTINCT PosID FROM staff_positions LIMIT 20");
if($pos) {
    while($row = $pos->fetch_assoc()) {
        echo "  Position: " . $row['PosID'] . "\n";
    }
}
