<?php
// Write program assignment report to file to avoid terminal issues
$reportFile = __DIR__ . '/debug_program_report.txt';
$db = new mysqli('127.0.0.1','root','','wucportal');
if ($db->connect_error) {
    file_put_contents($reportFile, "DB connection failed: " . $db->connect_error);
    exit;
}
$out = "=== Program Assignment Report ===\n\n";

// Students with missing program in student_program table
$res = $db->query("SELECT s.SID, CONCAT(s.Fname,' ',s.Lname) as name, COALESCE(sp.program_code, 'NULL') as program FROM students s LEFT JOIN student_program sp ON s.SID = sp.Sid WHERE sp.Sid IS NULL LIMIT 50");
$out .= "Students without student_program (sample up to 50):\n";
while ($row = $res->fetch_assoc()) {
    $out .= " - {$row['SID']} => {$row['name']} (program: {$row['program']})\n";
}

// Semester registrations without student_program
$res = $db->query("SELECT sr.student_id, sr.program_code FROM semester_registration sr LEFT JOIN student_program sp ON sr.student_id = sp.Sid WHERE sp.Sid IS NULL LIMIT 50");
$out .= "\nSemester registrations without student_program (sample up to 50):\n";
while ($row = $res->fetch_assoc()) {
    $out .= " - {$row['student_id']} => reg_program: {$row['program_code']}\n";
}

// Student_program entries where program_code is NULL or empty
$res = $db->query("SELECT Sid, program_code FROM student_program WHERE program_code IS NULL OR program_code = '' LIMIT 50");
$out .= "\nstudent_program entries with missing program_code (sample up to 50):\n";
while ($row = $res->fetch_assoc()) {
    $out .= " - {$row['Sid']} => program_code: '{$row['program_code']}'\n";
}

file_put_contents($reportFile, $out);
$db->close();
echo "Report written to $reportFile\n";
?>