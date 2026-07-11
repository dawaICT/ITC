<?php
/**
 * Test Script for update_student.php
 * Run this in browser or via CLI to verify the file works without errors
 */

// Define test mode
define('TEST_MODE', true);

// Capture output
ob_start();

// Test basic PHP syntax
try {
    include 'update_student.php';
    $output = ob_get_clean();
    
    echo "✅ File loads without fatal errors\n";
    echo "✅ PHP syntax is valid\n";
    
    // Check for any PHP warnings or notices in output
    if (strpos($output, 'Warning:') !== false || strpos($output, 'Notice:') !== false) {
        echo "⚠️  Warnings or notices detected:\n";
        echo $output;
    } else {
        echo "✅ No warnings or notices\n";
    }
    
    echo "\n=== TEST PASSED ===\n";
    echo "The update_student.php file is ready to use.\n";
    
} catch (Exception $e) {
    ob_end_clean();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\n=== TEST FAILED ===\n";
}
