<?php
if (isset($_GET['add_test_courses'])) {
    require_once __DIR__ . '/db/connect.php';

    $result = $db->query('SELECT id FROM semester_registration WHERE student_id = "test123"');
    $row = $result->fetch_assoc();
    $srId = $row['id'];

    $courses = $db->query('SELECT course_code FROM course_levels WHERE program_code = "BSCS" AND semester = 1 AND year = 1');
    while($c = $courses->fetch_assoc()) {
        $db->query("INSERT INTO course_registration (Sid, course_code, semester, Year, semester_registration_id, registration_date) VALUES ('test123', '{$c['course_code']}', 1, 1, $srId, NOW())");
        echo 'Added ' . $c['course_code'] . PHP_EOL;
    }
}
?>