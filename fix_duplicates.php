<?php
/**
 * Remove duplicate entries from course_levels table
 */
require_once 'db/connect.php';

echo "=== REMOVING DUPLICATE COURSE_LEVELS ENTRIES ===\n\n";

// First, check table structure
$struct = $db->query("DESCRIBE course_levels");
echo "Table structure:\n";
while ($row = $struct->fetch_assoc()) {
    echo "  - {$row['Field']} ({$row['Type']})\n";
}
echo "\n";

// Find duplicates
$findDupes = "SELECT program_code, year, semester, course_code, COUNT(*) as cnt 
              FROM course_levels 
              GROUP BY program_code, year, semester, course_code 
              HAVING COUNT(*) > 1";

$result = mysqli_query($db, $findDupes);
if ($result && mysqli_num_rows($result) > 0) {
    echo "Found duplicates:\n";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "  - {$row['program_code']} Year {$row['year']} Sem {$row['semester']} - {$row['course_code']} ({$row['cnt']} entries)\n";
    }
    
    // Remove duplicates - keep the one with lowest course_level_id
    $removeDupes = "DELETE t1 FROM course_levels t1 
                    INNER JOIN course_levels t2 
                    WHERE t1.course_level_id > t2.course_level_id 
                    AND t1.program_code = t2.program_code 
                    AND t1.year = t2.year 
                    AND t1.semester = t2.semester 
                    AND t1.course_code = t2.course_code";
    
    if (mysqli_query($db, $removeDupes)) {
        $affected = mysqli_affected_rows($db);
        echo "\n[OK] Removed $affected duplicate entries\n";
    } else {
        echo "\n[ERROR] Failed to remove duplicates: " . mysqli_error($db) . "\n";
    }
} else {
    echo "No duplicates found.\n";
}

// Verify BSCS curriculum
echo "\n=== BSCS CURRICULUM AFTER CLEANUP ===\n";
$verify = "SELECT cl.program_code, cl.year, cl.semester, cl.course_code, c.course_name
           FROM course_levels cl
           JOIN courses c ON cl.course_code = c.course_code
           WHERE cl.program_code = 'BSCS'
           ORDER BY cl.year, cl.semester, cl.course_code";

$result = mysqli_query($db, $verify);
if ($result) {
    $currentKey = '';
    while ($row = mysqli_fetch_assoc($result)) {
        $key = "Year {$row['year']} Semester {$row['semester']}";
        if ($key != $currentKey) {
            echo "\n$key:\n";
            $currentKey = $key;
        }
        echo "  - {$row['course_code']}: {$row['course_name']}\n";
    }
}

$db->close();
echo "\n=== DONE ===\n";
