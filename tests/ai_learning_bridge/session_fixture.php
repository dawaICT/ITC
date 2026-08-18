<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
$kind = $argv[1] ?? '';
$sessionId = 'aibridge' . preg_replace('/[^a-z0-9]/', '', strtolower($kind)) . bin2hex(random_bytes(4));
session_id($sessionId);
session_start();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['logged_in'] = true;
$_SESSION['last_activity'] = time();
$_SESSION['must_change_password'] = false;

if ($kind === 'student') {
    $_SESSION['Sid'] = 'CSE26456789';
    $_SESSION['student_id'] = 'CSE26456789';
    $_SESSION['user_id'] = 'CSE26456789';
    $_SESSION['user_id_db'] = 25;
    $_SESSION['user_name'] = 'AI Bridge Test Student';
    $_SESSION['user_role'] = 'student';
    $_SESSION['role'] = 'student';
} elseif ($kind === 'lecturer') {
    $_SESSION['user_id'] = 'ITC907';
    $_SESSION['staff_id'] = 'ITC907';
    $_SESSION['user_id_db'] = 18;
    $_SESSION['user_name'] = 'AI Bridge Test Lecturer';
    $_SESSION['user_role'] = 'staff';
    $_SESSION['role'] = 'lecturer';
    $_SESSION['all_roles'] = ['lecturer'];
    $_SESSION['all_roles_raw'] = ['Lecturer'];
} elseif ($kind === 'other_student') {
    $_SESSION['Sid'] = 'CVM26567121';
    $_SESSION['student_id'] = 'CVM26567121';
    $_SESSION['user_id'] = 'CVM26567121';
    $_SESSION['user_id_db'] = 31;
    $_SESSION['user_name'] = 'AI Bridge Other Student';
    $_SESSION['user_role'] = 'student';
    $_SESSION['role'] = 'student';
} elseif ($kind === 'other_lecturer') {
    $_SESSION['user_id'] = 'ITC911';
    $_SESSION['staff_id'] = 'ITC911';
    $_SESSION['user_id_db'] = 82;
    $_SESSION['user_name'] = 'AI Bridge Other Lecturer';
    $_SESSION['user_role'] = 'staff';
    $_SESSION['role'] = 'lecturer';
    $_SESSION['all_roles'] = ['lecturer'];
    $_SESSION['all_roles_raw'] = ['Lecturer'];
} elseif ($kind === 'hos') {
    $_SESSION['user_id'] = 'ITC904';
    $_SESSION['staff_id'] = 'ITC904';
    $_SESSION['user_id_db'] = 15;
    $_SESSION['user_name'] = 'AI Bridge Test Head';
    $_SESSION['user_role'] = 'staff';
    $_SESSION['role'] = 'head_of_department';
    $_SESSION['all_roles'] = ['head_of_department'];
    $_SESSION['all_roles_raw'] = ['Head of Section'];
    $_SESSION['hos_section_id'] = 'ENGICT';
    $_SESSION['hos_section_name'] = 'Engineering/ICT Section';
    $_SESSION['hos_section_type'] = 'academic';
} elseif ($kind === 'admin') {
    $_SESSION['user_id'] = 'ITC900';
    $_SESSION['staff_id'] = 'ITC900';
    $_SESSION['user_id_db'] = 11;
    $_SESSION['user_name'] = 'AI Bridge Test Administrator';
    $_SESSION['user_role'] = 'staff';
    $_SESSION['role'] = 'systems_admin';
    $_SESSION['all_roles'] = ['systems_admin','head_of_department','lecturer'];
    $_SESSION['all_roles_raw'] = ['Systems Admin','Head of Section','Lecturer'];
} elseif ($kind === 'outside_hos') {
    $_SESSION['user_id'] = 'ITC910';
    $_SESSION['staff_id'] = 'ITC910';
    $_SESSION['user_id_db'] = 21;
    $_SESSION['user_name'] = 'AI Bridge Transport Head';
    $_SESSION['user_role'] = 'staff';
    $_SESSION['role'] = 'head_of_department';
    $_SESSION['all_roles'] = ['head_of_department'];
    $_SESSION['all_roles_raw'] = ['Head of Section'];
    $_SESSION['hos_section_id'] = 'TRANSPORT';
    $_SESSION['hos_section_name'] = 'Transport Section';
    $_SESSION['hos_section_type'] = 'transport';
} else {
    fwrite(STDERR, "Usage: php session_fixture.php [student|other_student|lecturer|other_lecturer|hos|outside_hos|admin]\n");
    exit(2);
}
session_write_close();
echo $sessionId;
