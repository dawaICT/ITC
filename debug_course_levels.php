<?php
require 'db/connect.php';

echo "=== COURSE_LEVELS TABLE STRUCTURE ===\n";
$result = $db->query('DESCRIBE course_levels');
while($row = $result->fetch_assoc()) {
    echo "{$row['Field']} | {$row['Type']} | Null:{$row['Null']} | Key:{$row['Key']} | Default:{$row['Default']} | Extra:{$row['Extra']}\n";
}

echo "\n=== SAMPLE DATA (First 10 records) ===\n";
$result = $db->query('SELECT * FROM course_levels LIMIT 10');
$count = 0;
while($row = $result->fetch_assoc()) {
    $count++;
    echo "\nRecord $count:\n";
    foreach($row as $field => $value) {
        echo "  $field: $value\n";
    }
}

echo "\n=== CHECKING FOR COLUMN NAME ISSUES ===\n";
$result = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'course_levels' AND TABLE_SCHEMA = DATABASE()");
echo "All column names:\n";
while($row = $result->fetch_assoc()) {
    echo "  - {$row['COLUMN_NAME']}\n";
}

echo "\n=== CHECKING courseReg.php QUERY ===\n";
echo "courseReg.php expects column: 'year_level'\n";
$result = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'course_levels' AND COLUMN_NAME LIKE '%year%'");
echo "Columns containing 'year':\n";
while($row = $result->fetch_assoc()) {
    echo "  - {$row['COLUMN_NAME']}\n";
}

echo "\n=== RECORD COUNT BY PROGRAM ===\n";
$result = $db->query('SELECT program_code, COUNT(*) as count FROM course_levels GROUP BY program_code');
while($row = $result->fetch_assoc()) {
    echo "  {$row['program_code']}: {$row['count']} courses\n";
}

echo "\n=== CHECKING FOR NULL/EMPTY VALUES ===\n";
$result = $db->query("SELECT 
    SUM(CASE WHEN course_code IS NULL OR course_code = '' THEN 1 ELSE 0 END) as empty_course_code,
    SUM(CASE WHEN program_code IS NULL OR program_code = '' THEN 1 ELSE 0 END) as empty_program_code,
    SUM(CASE WHEN semester IS NULL THEN 1 ELSE 0 END) as null_semester
FROM course_levels");
$row = $result->fetch_assoc();
echo "Empty course_code: {$row['empty_course_code']}\n";
echo "Empty program_code: {$row['empty_program_code']}\n";
echo "NULL semester: {$row['null_semester']}\n";

echo "\n=== CHECKING FOR DUPLICATES ===\n";
$result = $db->query("SELECT course_code, program_code, semester, year, COUNT(*) as count 
    FROM course_levels 
    GROUP BY course_code, program_code, semester, year 
    HAVING count > 1");
$duplicates = $result->num_rows;
echo "Duplicate entries: $duplicates\n";
if($duplicates > 0) {
    while($row = $result->fetch_assoc()) {
        echo "  {$row['course_code']} | {$row['program_code']} | Sem:{$row['semester']} | Year:{$row['year']} | Count:{$row['count']}\n";
    }
}
?>
