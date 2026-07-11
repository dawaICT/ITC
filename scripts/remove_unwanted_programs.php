<?php
/**
 * Utility script to remove target unwanted programs and their fee structures.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts\remove_unwanted_programs.php
 */

require_once __DIR__ . '/../db/connect.php';

if (php_sapi_name() !== 'cli') {
    echo "Run this script from the command line.\n";
    exit(1);
}

// Target program codes to remove
$target_programs = ['BA-BBA', 'BED', 'ENG-BE', 'BIT', 'CS-BSC', 'BSCS', 'BSIT'];

echo "=== DELETING TARGET PROGRAMS FROM DATABASE ===\n";

try {
    $db->begin_transaction();

    // 1. Update students referencing these programs (set to NULL or empty)
    $stmt = $db->prepare("UPDATE students SET program = NULL WHERE program = ?");
    foreach ($target_programs as $code) {
        $stmt->bind_param("s", $code);
        $stmt->execute();
    }
    $stmt->close();
    echo "✓ Cleaned up 'students' table associations.\n";

    // 2. Delete student program assignments referencing these programs
    $stmt = $db->prepare("DELETE FROM student_program WHERE program_code = ?");
    foreach ($target_programs as $code) {
        $stmt->bind_param("s", $code);
        $stmt->execute();
    }
    $stmt->close();
    echo "✓ Cleaned up 'student_program' table associations.\n";

    // 3. Delete fee structure entries (fee_structure table)
    if ($db->query("SHOW TABLES LIKE 'fee_structure'")->num_rows > 0) {
        $stmt = $db->prepare("DELETE FROM fee_structure WHERE program_code = ?");
        foreach ($target_programs as $code) {
            $stmt->bind_param("s", $code);
            $stmt->execute();
        }
        $stmt->close();
        echo "✓ Deleted associated rows from 'fee_structure'.\n";
    }

    // 4. Delete fee structure entries (fee_structures table if exists)
    if ($db->query("SHOW TABLES LIKE 'fee_structures'")->num_rows > 0) {
        $stmt = $db->prepare("DELETE FROM fee_structures WHERE program_code = ?");
        foreach ($target_programs as $code) {
            $stmt->bind_param("s", $code);
            $stmt->execute();
        }
        $stmt->close();
        echo "✓ Deleted associated rows from 'fee_structures'.\n";
    }

    // 5. Delete programs themselves from programs table
    $stmt = $db->prepare("DELETE FROM programs WHERE program_code = ?");
    foreach ($target_programs as $code) {
        $stmt->bind_param("s", $code);
        $stmt->execute();
    }
    $stmt->close();
    echo "✓ Deleted target programs from 'programs' table.\n";

    $db->commit();
    echo "=== DATABASE CLEANUP COMPLETED SUCCESSFULLY ===\n";

} catch (Throwable $e) {
    $db->rollback();
    echo "✗ Error during cleanup: " . $e->getMessage() . "\n";
    exit(1);
}
?>
