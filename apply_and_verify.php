<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) {
    file_put_contents('final_report.txt', "Connection failed: " . $db->connect_error);
    exit;
}

$output = "Starting database schema updates...\n";

// 1. Update semester_registration table
$tables = ['semester_registration', 'student_payments'];

foreach ($tables as $table) {
    $output .= "Processing table: $table\n";
    
    // Check if academic_year exists
    $res = $db->query("SHOW COLUMNS FROM `$table` LIKE 'academic_year'");
    if ($res->num_rows == 0) {
        $output .= "Adding 'academic_year' column to $table...\n";
        $db->query("ALTER TABLE `$table` ADD COLUMN `academic_year` VARCHAR(20) AFTER `program_code` ");
    } else {
        $output .= "'academic_year' already exists in $table.\n";
    }

    // Check if year_of_study exists
    $res = $db->query("SHOW COLUMNS FROM `$table` LIKE 'year_of_study'");
    if ($res->num_rows == 0) {
        $output .= "Adding 'year_of_study' column to $table...\n";
        // Place it appropriately
        $after = ($table == 'semester_registration') ? 'semester' : 'academic_year';
        $db->query("ALTER TABLE `$table` ADD COLUMN `year_of_study` VARCHAR(10) AFTER `$after` ");
    } else {
        $output .= "'year_of_study' already exists in $table.\n";
    }
}

// 2. Special case for semester_registration constraints
$output .= "Updating constraints for semester_registration...\n";
$res = $db->query("SHOW INDEX FROM semester_registration");
$indexes = [];
while($row = $res->fetch_assoc()) {
    $indexes[] = $row['Key_name'];
}

if (in_array('unique_period', $indexes)) {
    $output .= "Dropping old 'unique_period' index...\n";
    $db->query("ALTER TABLE semester_registration DROP INDEX unique_period");
}

// Create new composite unique index if not exists
if (!in_array('unique_period_v2', $indexes)) {
    $output .= "Creating new unique constraint including academic_year...\n";
    $db->query("ALTER TABLE semester_registration ADD UNIQUE INDEX `unique_period_v2` (student_id, program_code, academic_year, semester)");
} else {
    $output .= "'unique_period_v2' already exists.\n";
}

$output .= "\n--- Verification ---\n";
foreach ($tables as $table) {
    $output .= "--- Table: $table ---\n";
    $res = $db->query("SHOW COLUMNS FROM `$table` ");
    while($row = $res->fetch_assoc()) {
        $output .= $row['Field'] . " (" . $row['Type'] . ")\n";
    }
    $output .= "\n";
}

$output .= "--- Indexes for semester_registration ---\n";
$res = $db->query("SHOW INDEX FROM semester_registration");
while($row = $res->fetch_assoc()) {
    $output .= $row['Key_name'] . " -> " . $row['Column_name'] . " (Unique: " . ($row['Non_unique'] == 0 ? 'Yes' : 'No') . ")\n";
}

// Run any additional SQL migration files placed in the repo root
$output .= "\n--- Running additional SQL migrations if present ---\n";
$sqlFiles = [
    'fix_add_deptName.sql',
    'create_elearning_structure.sql'
    // 'db/library_schema.sql' // already run
];

// run student_id type fix before seeding (idempotent)
$sqlFiles[] = 'fix_elearning_studentid_type.sql';
// also run seed data for elearning (idempotent)
$sqlFiles[] = 'seed_elearning_sample.sql';
// seed library sample data (run once)
// $sqlFiles[] = 'seed_library_sample.sql';

foreach ($sqlFiles as $sqlFile) {
    if (file_exists($sqlFile)) {
        $output .= "Found $sqlFile, executing...\n";
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            $output .= "Failed to read $sqlFile\n";
            continue;
        }

        if ($db->multi_query($sql)) {
            do {
                if ($result = $db->store_result()) {
                    while ($row = $result->fetch_row()) { }
                    $result->free();
                }
            } while ($db->more_results() && $db->next_result());
            $output .= "$sqlFile executed successfully.\n";
        } else {
            $output .= "Error executing $sqlFile: " . $db->error . "\n";
        }
    } else {
        $output .= "$sqlFile not present; skipping.\n";
    }
}

$output .= "\nDatabase updates completed successfully.\n";
file_put_contents('final_report.txt', $output);
$db->close();
?>
