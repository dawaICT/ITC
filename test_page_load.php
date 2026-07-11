<?php
echo "Testing direct access to students.php...\n";
session_start();
$_SESSION['staff_id'] = 'WUC015';
$_SESSION['user_role'] = 'admin';

// Test if the file can be included without fatal errors
try {
    ob_start();
    include 'admissions/students.php';
    $output = ob_get_clean();
    echo "✅ Page included successfully\n";
    echo "Output length: " . strlen($output) . " characters\n";
    if (strpos($output, '<!DOCTYPE html>') !== false) {
        echo "✅ HTML output detected\n";
    } else {
        echo "⚠️  No HTML DOCTYPE found in output\n";
    }
} catch (Exception $e) {
    echo "❌ Error including page: " . $e->getMessage() . "\n";
}
?>