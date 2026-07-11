<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "Starting database schema updates...\n";

// 1. Update semester_registration table
$tables = ['semester_registration', 'student_payments'];

foreach ($tables as $table) {
    echo "Processing table: $table\n";
    
    // Check if academic_year exists
    $res = $db->query("SHOW COLUMNS FROM `$table` LIKE 'academic_year'");
    if ($res->num_rows == 0) {
        echo "Adding 'academic_year' column to $table...\n";
        $db->query("ALTER TABLE `$table` ADD COLUMN `academic_year` VARCHAR(20) AFTER `program_code` ");
    } else {
        echo "'academic_year' already exists in $table.\n";
    }

    // Check if year_of_study exists
    $res = $db->query("SHOW COLUMNS FROM `$table` LIKE 'year_of_study'");
    if ($res->num_rows == 0) {
        echo "Adding 'year_of_study' column to $table...\n";
        // Place it appropriately
        $after = ($table == 'semester_registration') ? 'semester' : 'academic_year';
        $db->query("ALTER TABLE `$table` ADD COLUMN `year_of_study` VARCHAR(10) AFTER `$after` ");
    } else {
        echo "'year_of_study' already exists in $table.\n";
    }
}

// 2. Special case for semester_registration constraints
// If there was a unique constraint on student_id, program_code, semester - it might need update
echo "Updating constraints for semester_registration...\n";
// Dropping old unique index if it exists (usually named 'unique_reg' or similar in this codebase's patterns)
// Let's check existing indexes
$res = $db->query("SHOW INDEX FROM semester_registration");
$indexes = [];
while($row = $res->fetch_assoc()) {
    $indexes[] = $row['Key_name'];
}

if (in_array('unique_period', $indexes)) {
    echo "Dropping old 'unique_period' index...\n";
    $db->query("ALTER TABLE semester_registration DROP INDEX unique_period");
}

// Create new composite unique index
echo "Creating new unique constraint including academic_year...\n";
$db->query("ALTER TABLE semester_registration ADD UNIQUE INDEX `unique_period_v2` (student_id, program_code, academic_year, semester)");

echo "Database updates completed successfully.\n";
$db->close();
?>
