<?php
require_once __DIR__ . '/db/connect.php';

echo "=== STUDENTS TABLE ===" . PHP_EOL;
$r = $db->query('DESCRIBE students');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | Key=' . $row['Key'] . ' | Default=' . $row['Default'] . ' | ' . $row['Extra'] . PHP_EOL;
}

echo PHP_EOL . "=== STAFF TABLE ===" . PHP_EOL;
$r = $db->query('DESCRIBE staff');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | Key=' . $row['Key'] . ' | Default=' . $row['Default'] . ' | ' . $row['Extra'] . PHP_EOL;
}

echo PHP_EOL . "=== USER_CREDENTIALS TABLE ===" . PHP_EOL;
$r = $db->query("SHOW TABLES LIKE 'user_credentials'");
if ($r->num_rows > 0) {
    $r = $db->query('DESCRIBE user_credentials');
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . ' | ' . $row['Type'] . ' | Key=' . $row['Key'] . PHP_EOL;
    }
} else {
    echo "TABLE DOES NOT EXIST" . PHP_EOL;
}

echo PHP_EOL . "=== PROGRAMS TABLE ===" . PHP_EOL;
$r = $db->query('DESCRIBE programs');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | Key=' . $row['Key'] . ' | Default=' . $row['Default'] . PHP_EOL;
}

echo PHP_EOL . "=== STUDENT_LOGIN TABLE ===" . PHP_EOL;
$r = $db->query('DESCRIBE student_login');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | Key=' . $row['Key'] . PHP_EOL;
}

echo PHP_EOL . "=== STUDENT_PROGRAM TABLE ===" . PHP_EOL;
$r = $db->query('DESCRIBE student_program');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | Key=' . $row['Key'] . PHP_EOL;
}

echo PHP_EOL . "=== POSITIONS TABLE ===" . PHP_EOL;
$r = $db->query('DESCRIBE positions');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | Key=' . $row['Key'] . PHP_EOL;
}

echo PHP_EOL . "=== STAFF_POSITIONS TABLE ===" . PHP_EOL;
$r = $db->query('DESCRIBE staff_positions');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | Key=' . $row['Key'] . PHP_EOL;
}

echo PHP_EOL . "=== ACCESS_RIGHT TABLE ===" . PHP_EOL;
$r2 = $db->query("SHOW TABLES LIKE 'access_right'");
if ($r2->num_rows > 0) {
    $r = $db->query('DESCRIBE access_right');
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . ' | ' . $row['Type'] . ' | Key=' . $row['Key'] . PHP_EOL;
    }
} else {
    echo "TABLE DOES NOT EXIST" . PHP_EOL;
}

echo PHP_EOL . "=== SAMPLE STUDENT IDs (first 15) ===" . PHP_EOL;
$r = $db->query('SELECT SID FROM students ORDER BY id LIMIT 15');
while ($row = $r->fetch_assoc()) {
    echo $row['SID'] . PHP_EOL;
}

echo PHP_EOL . "=== SAMPLE STAFF IDs (first 15) ===" . PHP_EOL;
$r = $db->query('SELECT staff_id, Fname, Lname, role FROM staff ORDER BY id LIMIT 15');
while ($row = $r->fetch_assoc()) {
    echo $row['staff_id'] . ' | ' . $row['Fname'] . ' ' . $row['Lname'] . ' | role=' . $row['role'] . PHP_EOL;
}

echo PHP_EOL . "=== COUNTS ===" . PHP_EOL;
$r = $db->query('SELECT COUNT(*) as c FROM students');
echo 'Students: ' . $r->fetch_assoc()['c'] . PHP_EOL;
$r = $db->query('SELECT COUNT(*) as c FROM staff');
echo 'Staff: ' . $r->fetch_assoc()['c'] . PHP_EOL;

echo PHP_EOL . "=== ALL TABLES IN DB ===" . PHP_EOL;
$r = $db->query('SHOW TABLES');
while ($row = $r->fetch_row()) {
    echo $row[0] . PHP_EOL;
}

echo PHP_EOL . "=== PROGRAMME_CODES SEARCH ===" . PHP_EOL;
$r = $db->query("SHOW TABLES LIKE '%programme%'");
echo 'Tables matching programme: ' . $r->num_rows . PHP_EOL;
while ($row = $r->fetch_row()) echo '  ' . $row[0] . PHP_EOL;
$r = $db->query("SHOW TABLES LIKE '%program_code%'");
echo 'Tables matching program_code: ' . $r->num_rows . PHP_EOL;
while ($row = $r->fetch_row()) echo '  ' . $row[0] . PHP_EOL;

echo PHP_EOL . "=== SAMPLE PROGRAMS ===" . PHP_EOL;
$r = $db->query('SELECT program_code, program_name FROM programs LIMIT 10');
while ($row = $r->fetch_assoc()) {
    echo $row['program_code'] . ' | ' . $row['program_name'] . PHP_EOL;
}

echo PHP_EOL . "=== POSITIONS DATA ===" . PHP_EOL;
$r = $db->query('SELECT PosID, PosName FROM positions');
while ($row = $r->fetch_assoc()) {
    echo $row['PosID'] . ' | ' . $row['PosName'] . PHP_EOL;
}

echo PHP_EOL . "DONE" . PHP_EOL;
