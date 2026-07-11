<?php
require __DIR__ . '/../includes/security.php';
$pass = 0; $fail = 0;
function check($got, $want, $label){ global $pass,$fail; if($got===$want){$pass++; echo "  [OK]   $label => $got\n";} else {$fail++; echo "  [FAIL] $label => got '$got' want '$want'\n";} }

// Subdirectory page issuing a bare-relative redirect (the lecturer bug)
$_SERVER['SCRIPT_NAME'] = '/wucportal/lecturers/viewCourse.php';
check(wuc_normalize_local_url('viewCourse.php?code=GEN104'), '/wucportal/lecturers/viewCourse.php?code=GEN104', 'subdir relative keeps subdir');
check(wuc_normalize_local_url('upload_ca.php'), '/wucportal/lecturers/upload_ca.php', 'subdir relative sibling');

// Root-level page issuing a bare-relative redirect (must still resolve to root)
$_SERVER['SCRIPT_NAME'] = '/wucportal/studentLogin.php';
check(wuc_normalize_local_url('student_login.php'), '/wucportal/student_login.php', 'root relative stays at root');

// Absolute app paths unaffected, regardless of current dir
$_SERVER['SCRIPT_NAME'] = '/wucportal/lecturers/viewCourse.php';
check(wuc_normalize_local_url('/wucportal/staff_login.php'), '/wucportal/staff_login.php', 'absolute path unchanged');

// Security: traversal and external are rejected to fallback
check(wuc_normalize_local_url('../secret.php', '/wucportal/index.php'), '/wucportal/index.php', 'parent traversal -> fallback');
check(wuc_normalize_local_url('https://evil.test/x', '/wucportal/index.php'), '/wucportal/index.php', 'external URL -> fallback');
check(wuc_normalize_local_url('//evil.test', '/wucportal/index.php'), '/wucportal/index.php', 'protocol-relative -> fallback');
check(wuc_normalize_local_url('/etc/passwd', '/wucportal/index.php'), '/wucportal/index.php', 'outside app base -> fallback');

// Deeper subdir (e.g. students/elearning) keeps full path
$_SERVER['SCRIPT_NAME'] = '/wucportal/students/elearning/index.php';
check(wuc_normalize_local_url('course.php?id=1'), '/wucportal/students/elearning/course.php?id=1', 'deep subdir relative');

echo "\nResult: $pass passed, $fail failed\n";
