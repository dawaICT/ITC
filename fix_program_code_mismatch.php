<?php
/**
 * Fix Program Code Mismatch
 * 
 * Problem: Students are registered with program codes that don't exist in course_levels.
 * This script identifies the issues and provides options to fix them.
 * 
 * Usage:
 *   php fix_program_code_mismatch.php          - Show current issues (dry run)
 *   php fix_program_code_mismatch.php --fix    - Apply fixes
 */

require_once __DIR__ . '/db/connect.php';

$dryRun = !in_array('--fix', $argv ?? []);

echo "=== Program Code Mismatch Fix ===\n";
echo $dryRun ? "(DRY RUN - use --fix to apply changes)\n\n" : "(APPLYING FIXES)\n\n";

// 1. Get valid program codes from course_levels
$validCodes = [];
$result = $db->query("SELECT DISTINCT program_code FROM course_levels");
while ($row = $result->fetch_assoc()) {
    $validCodes[] = $row['program_code'];
}
echo "Valid program codes in course_levels: " . implode(', ', $validCodes) . "\n\n";

// 2. Find mismatched semester_registration entries
$mismatches = [];
$result = $db->query("
    SELECT sr.id, sr.student_id, sr.program_code, p.program_name
    FROM semester_registration sr
    LEFT JOIN programs p ON sr.program_code = p.program_code
    WHERE sr.program_code NOT IN (SELECT DISTINCT program_code FROM course_levels)
");

if ($result && $result->num_rows > 0) {
    echo "Found " . $result->num_rows . " mismatched registrations:\n";
    while ($row = $result->fetch_assoc()) {
        $mismatches[] = $row;
        echo "  - ID {$row['id']}: Student '{$row['student_id']}' has program_code '{$row['program_code']}' ({$row['program_name']})\n";
    }
} else {
    echo "No mismatches found - all registrations have valid program codes!\n";
    exit(0);
}

echo "\n";

// 3. Determine fixes
// Strategy: Map common mismatches to valid codes
$mappings = [
    'CS101' => 'BSCS',  // Bachelor of Computer Science -> BSc Computer Science (same program, different code)
    // Add more mappings as needed
];

echo "Applying mappings:\n";
foreach ($mappings as $old => $new) {
    echo "  '{$old}' -> '{$new}'\n";
}
echo "\n";

// 4. Apply fixes
if (!$dryRun) {
    $fixCount = 0;
    $deleteCount = 0;
    foreach ($mismatches as $m) {
        $oldCode = $m['program_code'];
        if (isset($mappings[$oldCode])) {
            $newCode = $mappings[$oldCode];
            
            // Check if updating would create a duplicate
            $check = $db->prepare("SELECT id FROM semester_registration WHERE student_id = ? AND program_code = ? AND semester = (SELECT semester FROM semester_registration WHERE id = ?) AND year_of_study = (SELECT year_of_study FROM semester_registration WHERE id = ?) AND id != ?");
            $check->bind_param("ssiii", $m['student_id'], $newCode, $m['id'], $m['id'], $m['id']);
            $check->execute();
            $dupResult = $check->get_result();
            
            if ($dupResult && $dupResult->num_rows > 0) {
                // A duplicate exists - delete this obsolete record instead
                $delStmt = $db->prepare("DELETE FROM semester_registration WHERE id = ?");
                $delStmt->bind_param("i", $m['id']);
                if ($delStmt->execute()) {
                    echo "Deleted duplicate: ID {$m['id']} (Student '{$m['student_id']}' with '{$oldCode}' - duplicate of existing '{$newCode}')\n";
                    $deleteCount++;
                } else {
                    echo "ERROR deleting ID {$m['id']}: " . $delStmt->error . "\n";
                }
                $delStmt->close();
            } else {
                // No duplicate - safe to update
                $stmt = $db->prepare("UPDATE semester_registration SET program_code = ? WHERE id = ?");
                $stmt->bind_param("si", $newCode, $m['id']);
                if ($stmt->execute()) {
                    echo "Fixed: Student '{$m['student_id']}' updated from '{$oldCode}' to '{$newCode}'\n";
                    $fixCount++;
                } else {
                    echo "ERROR fixing ID {$m['id']}: " . $stmt->error . "\n";
                }
                $stmt->close();
            }
            $check->close();
        } else {
            echo "SKIPPED: No mapping for '{$oldCode}' (ID {$m['id']})\n";
        }
    }
    echo "\nTotal updates: {$fixCount}, Total deletes: {$deleteCount}\n";
} else {
    echo "Would fix:\n";
    foreach ($mismatches as $m) {
        $oldCode = $m['program_code'];
        if (isset($mappings[$oldCode])) {
            echo "  Student '{$m['student_id']}': '{$oldCode}' -> '{$mappings[$oldCode]}'\n";
        } else {
            echo "  Student '{$m['student_id']}': '{$oldCode}' -> (no mapping defined)\n";
        }
    }
    echo "\nRun with --fix to apply these changes.\n";
}

echo "\n=== Done ===\n";
