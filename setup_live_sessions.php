<?php
/**
 * Live Session Video System - Setup Script
 * Run this once to verify and set up the system
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "=== ITC Portal - Live Session Video System Setup ===\n\n";

// Step 1: Check database connection
echo "1. Checking database connection...\n";
require_once __DIR__ . '/db/connect.php';

if (!$db || $db->connect_error) {
    die("❌ Database connection failed: " . ($db->connect_error ?? 'Unknown error') . "\n");
}
echo "✅ Database connected successfully\n\n";

// Step 2: Check if tables exist
echo "2. Checking database tables...\n";
$tables_to_check = [
    'lms_sessions',
    'students',
    'student_courses',
    'student_payments',
    'invoices'
];

$missing_tables = [];
foreach ($tables_to_check as $table) {
    $result = $db->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows === 0) {
        $missing_tables[] = $table;
        echo "❌ Table '$table' not found\n";
    } else {
        echo "✅ Table '$table' exists\n";
    }
}

if (!empty($missing_tables)) {
    echo "\n⚠️  Warning: Some required tables are missing. Please create them first.\n\n";
}

// Step 3: Check if new columns exist
echo "\n3. Checking for new columns in lms_sessions...\n";
$result = $db->query("DESCRIBE lms_sessions");
$existing_columns = [];
while ($row = $result->fetch_assoc()) {
    $existing_columns[] = $row['Field'];
}

$required_columns = [
    'session_type',
    'video_file_path',
    'video_file_size',
    'access_requires_payment',
    'min_payment_percentage',
    'status'
];

$missing_columns = array_diff($required_columns, $existing_columns);

if (!empty($missing_columns)) {
    echo "❌ Missing columns: " . implode(', ', $missing_columns) . "\n";
    echo "   Please run: db/update_sessions_for_internal_video.sql\n";
} else {
    echo "✅ All required columns exist\n";
}

// Step 4: Check upload directory
echo "\n4. Checking upload directories...\n";
$upload_dirs = [
    __DIR__ . '/uploads',
    __DIR__ . '/uploads/live_sessions'
];

foreach ($upload_dirs as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "✅ Created directory: $dir\n";
        } else {
            echo "❌ Failed to create directory: $dir\n";
        }
    } else {
        echo "✅ Directory exists: $dir\n";
    }
    
    // Check if writable
    if (is_writable($dir)) {
        echo "   ✓ Writable\n";
    } else {
        echo "   ❌ Not writable - please fix permissions\n";
    }
}

// Step 5: Check PHP configuration
echo "\n5. Checking PHP configuration...\n";
$upload_max = ini_get('upload_max_filesize');
$post_max = ini_get('post_max_size');
$memory_limit = ini_get('memory_limit');
$max_execution = ini_get('max_execution_time');

echo "   upload_max_filesize: $upload_max\n";
echo "   post_max_size: $post_max\n";
echo "   memory_limit: $memory_limit\n";
echo "   max_execution_time: $max_execution seconds\n";

// Convert to bytes for comparison
function parseSize($size) {
    $unit = strtoupper(substr($size, -1));
    $value = (int)$size;
    switch($unit) {
        case 'G': return $value * 1024 * 1024 * 1024;
        case 'M': return $value * 1024 * 1024;
        case 'K': return $value * 1024;
        default: return $value;
    }
}

$upload_bytes = parseSize($upload_max);
$recommended_bytes = 500 * 1024 * 1024; // 500MB

if ($upload_bytes < $recommended_bytes) {
    echo "   ⚠️  Warning: upload_max_filesize is less than recommended 500M\n";
    echo "      Edit php.ini and increase the limit\n";
} else {
    echo "   ✅ Upload size limit is adequate\n";
}

// Step 6: Check required files
echo "\n6. Checking required files...\n";
$required_files = [
    'includes/payment_verification.php',
    'admin/elearning/sessions.php',
    'students/elearning/live_sessions.php',
    'students/elearning/view_session.php',
    'db/update_sessions_for_internal_video.sql'
];

foreach ($required_files as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        echo "✅ $file\n";
    } else {
        echo "❌ Missing: $file\n";
    }
}

// Step 7: Test payment verification function
echo "\n7. Testing payment verification function...\n";
if (file_exists(__DIR__ . '/includes/payment_verification.php')) {
    require_once __DIR__ . '/includes/payment_verification.php';
    
    if (function_exists('getStudentPaymentPercentage')) {
        echo "✅ Payment verification function loaded\n";
    } else {
        echo "❌ Payment verification function not found\n";
    }
} else {
    echo "❌ Payment verification file not found\n";
}

// Summary
echo "\n" . str_repeat("=", 50) . "\n";
echo "SETUP SUMMARY\n";
echo str_repeat("=", 50) . "\n";

if (empty($missing_tables) && empty($missing_columns)) {
    echo "✅ System is ready to use!\n";
    echo "\nNext steps:\n";
    echo "1. Navigate to: /admin/elearning/sessions.php\n";
    echo "2. Create an internal video session\n";
    echo "3. Upload a video file\n";
    echo "4. Test student access at: /students/elearning/live_sessions.php\n";
} else {
    echo "⚠️  Setup incomplete. Please:\n";
    if (!empty($missing_columns)) {
        echo "   - Run the SQL migration: db/update_sessions_for_internal_video.sql\n";
    }
    if (!empty($missing_tables)) {
        echo "   - Create missing database tables\n";
    }
    echo "   - Then run this script again to verify\n";
}

echo "\n";
