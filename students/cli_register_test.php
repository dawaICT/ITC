<?php
// CLI test runner for process_registration.php
chdir(__DIR__);
// Start session
if (session_status() === PHP_SESSION_NONE) session_start();

// Provide test POST data
$_POST = [
    'student_id' => 'test123',
    'academic_year' => '1',
    'year_of_study' => '1',
    'semester' => '1',
    'courses' => 'CSC101,CSC102',
    'is_transfer' => '0',
    'pay' => '0',
    'csrf_token' => ''
];

// Emulate POST request environment
$_SERVER['REQUEST_METHOD'] = 'POST';

// Capture output
ob_start();
require __DIR__ . '/process_registration.php';
$body = ob_get_clean();

file_put_contents(__DIR__ . '/cli_register_output.json', $body);
echo "Wrote output to cli_register_output.json\n";
