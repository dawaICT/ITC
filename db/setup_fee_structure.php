<?php
// When run from CLI, allow admin include to operate in script mode
if (php_sapi_name() === 'cli' && !defined('IS_SCRIPT')) {
    define('IS_SCRIPT', true);
}
require_once dirname(__FILE__) . "/../admin/includes/admin.php";

try {
    // Clear any existing results
    while ($db->more_results()) {
        $db->next_result();
        if ($result = $db->store_result()) {
            $result->free();
        }
    }

    // Read and execute the SQL file
    $sql = file_get_contents(dirname(__FILE__) . '/fee_structure.sql');

    // Execute each query separately
    $queries = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($queries as $query) {
        if (!empty($query)) {
            if (!$db->query($query)) {
                throw new Exception("Error executing query: " . $db->error . "\nQuery: " . $query);
            }
        }
    }

    echo "Fee structure table created successfully!\n";

    // Insert some sample data if the fee_structures table is empty
    if($result = $db->query("SELECT COUNT(*) as count FROM fee_structures")) {
        $row = $result->fetch_object();
        if($row->count == 0) {
            // Get existing programs
            $programs = [];
            if($result = $db->query("SELECT program_code FROM programs")) {
                while($row = $result->fetch_object()) {
                    $programs[] = $row->program_code;
                }
                $result->free();
            }

            if (empty($programs)) {
                throw new Exception("No programs found in the database. Please set up programs first.");
            }

            // Start transaction for sample data
            $db->begin_transaction();

            try {
                // Insert sample data for each program into fee_structures
                $stmt = $db->prepare("INSERT INTO fee_structures (program_code, academic_year, semester, year_of_study, fee_description, amount, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                if (!$stmt) {
                    throw new Exception("Failed to prepare statement: " . $db->error);
                }

                // Current academic year
                $current_year = date('Y');
                $academic_year = $current_year . '/' . ($current_year + 1);

                foreach($programs as $program) {
                    // Semester 1 sample
                    $semester = 1;
                    $year_of_study = 1;
                    $fee_description = 'Tuition - Semester 1';
                    $amount = 15000.00; // Default amount
                    if (!$stmt->bind_param('ssiisd', $program, $academic_year, $semester, $year_of_study, $fee_description, $amount)) {
                        // Fallback to manual query if bind_param fails due to types
                        $sql = sprintf("INSERT INTO fee_structures (program_code, academic_year, semester, year_of_study, fee_description, amount, status) VALUES ('%s','%s',%d,%d,'%s',%F,'active')",
                            $db->real_escape_string($program), $db->real_escape_string($academic_year), $semester, $year_of_study, $db->real_escape_string($fee_description), $amount
                        );
                        if (!$db->query($sql)) { throw new Exception('Failed to insert sample fee (fallback): ' . $db->error); }
                    } else {
                        if (!$stmt->execute()) { throw new Exception("Failed to insert sample fee: " . $stmt->error); }
                    }

                    // Semester 2 sample
                    $semester = 2;
                    $year_of_study = 1;
                    $fee_description = 'Tuition - Semester 2';
                    $amount = 15000.00;

                    if (!$stmt->bind_param('ssiisd', $program, $academic_year, $semester, $year_of_study, $fee_description, $amount)) {
                        $sql = sprintf("INSERT INTO fee_structures (program_code, academic_year, semester, year_of_study, fee_description, amount, status) VALUES ('%s','%s',%d,%d,'%s',%F,'active')",
                            $db->real_escape_string($program), $db->real_escape_string($academic_year), $semester, $year_of_study, $db->real_escape_string($fee_description), $amount
                        );
                        if (!$db->query($sql)) { throw new Exception('Failed to insert sample fee (fallback): ' . $db->error); }
                    } else {
                        if (!$stmt->execute()) { throw new Exception("Failed to insert sample fee: " . $stmt->error); }
                    }
                }

                if (isset($stmt) && is_object($stmt)) {
                    $stmt->close();
                }

                $db->commit();
                echo "Sample fee structures added successfully!\n";

            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }
        }
    }

    echo "Setup completed successfully!";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if (isset($db) && $db->error) {
        echo "Database Error: " . $db->error . "\n";
    }
}
?> 