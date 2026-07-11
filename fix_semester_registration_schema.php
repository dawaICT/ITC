<?php
require_once __DIR__ . '/db/connect.php';

echo "Updating semester_registration table...\n";

// Add academic_year if missing
$check = $db->query("SHOW COLUMNS FROM semester_registration LIKE 'academic_year'");
if ($check->num_rows == 0) {
    echo "Adding academic_year column...\n";
    $db->query("ALTER TABLE semester_registration ADD COLUMN academic_year VARCHAR(20) AFTER program_code");
}

// Update unique key to include academic_year
// First drop old unique key if it exists
$res = $db->query("SHOW INDEX FROM semester_registration WHERE Key_name = 'semester_reg_unique'");
if ($res->num_rows > 0) {
    echo "Dropping old unique key...\n";
    $db->query("ALTER TABLE semester_registration DROP INDEX semester_reg_unique");
}

echo "Adding new unique key (student_id, program_code, academic_year, semester, year_of_study)...\n";
// We need to handle variations in column names (student_id vs Sid, Year vs year_of_study)
$cols = [];
$res = $db->query("SHOW COLUMNS FROM semester_registration");
while($row = $res->fetch_assoc()) $cols[] = $row['Field'];

$sidCol = in_array('student_id', $cols) ? 'student_id' : 'Sid';
$yosCol = in_array('year_of_study', $cols) ? 'year_of_study' : 'Year';

$sql = "ALTER TABLE semester_registration ADD UNIQUE KEY semester_reg_unique ($sidCol, program_code, academic_year, semester, $yosCol)";
if(!$db->query($sql)) {
    echo "Error adding unique key: " . $db->error . "\n";
} else {
    echo "Unique key updated successfully.\n";
}

echo "Updating student_payments table...\n";
$check = $db->query("SHOW COLUMNS FROM student_payments LIKE 'academic_year'");
if ($check->num_rows == 0) {
    echo "Adding academic_year column to student_payments...\n";
    $db->query("ALTER TABLE student_payments ADD COLUMN academic_year VARCHAR(20) AFTER Year");
}

echo "Done.\n";
?>