<?php
/**
 * Verification script for sessions.php enhancements
 * Run this to check if everything is set up correctly
 */

require_once __DIR__ . '/db/connect.php';

$checks = [];
$warnings = [];
$errors = [];

echo "\n";
echo "╔════════════════════════════════════════════════════╗\n";
echo "║   Sessions.php Enhancement Verification Script    ║\n";
echo "╚════════════════════════════════════════════════════╝\n\n";

// 1. Check database tables and columns
echo "1. Checking database structure...\n";

// Check lms_sessions table
$result = $db->query("SHOW TABLES LIKE 'lms_sessions'");
if ($result->num_rows > 0) {
    $checks[] = "✓ lms_sessions table exists";
    
    // Check for new columns
    $result = $db->query("SHOW COLUMNS FROM lms_sessions LIKE 'created_at'");
    if ($result->num_rows > 0) {
        $checks[] = "✓ lms_sessions.created_at column exists";
    } else {
        $errors[] = "✗ Missing lms_sessions.created_at column";
    }
    
    $result = $db->query("SHOW COLUMNS FROM lms_sessions LIKE 'updated_at'");
    if ($result->num_rows > 0) {
        $checks[] = "✓ lms_sessions.updated_at column exists";
    } else {
        $errors[] = "✗ Missing lms_sessions.updated_at column";
    }
} else {
    $errors[] = "✗ lms_sessions table missing!";
}

// Check lecturer_courses table
$result = $db->query("SHOW TABLES LIKE 'lecturer_courses'");
if ($result->num_rows > 0) {
    $checks[] = "✓ lecturer_courses table exists";
    
    // Check how many assignments exist
    $result = $db->query("SELECT COUNT(*) as count FROM lecturer_courses");
    $row = $result->fetch_assoc();
    $count = (int)$row['count'];
    
    if ($count > 0) {
        $checks[] = "✓ Found $count lecturer-course assignments";
    } else {
        $warnings[] = "⚠ No lecturer-course assignments found";
    }
} else {
    $errors[] = "✗ lecturer_courses table missing!";
}

// Check courses table
$result = $db->query("SHOW TABLES LIKE 'courses'");
if ($result->num_rows > 0) {
    $checks[] = "✓ courses table exists";
    
    $result = $db->query("SHOW COLUMNS FROM courses LIKE 'status'");
    if ($result->num_rows > 0) {
        $checks[] = "✓ courses.status column exists";
        
        // Check active courses
        $result = $db->query("SELECT COUNT(*) as count FROM courses WHERE status = 'active'");
        $row = $result->fetch_assoc();
        $count = (int)$row['count'];
        
        if ($count > 0) {
            $checks[] = "✓ Found $count active courses";
        } else {
            $warnings[] = "⚠ No active courses found";
        }
    } else {
        $warnings[] = "⚠ courses.status column missing (will default all to active)";
    }
} else {
    $errors[] = "✗ courses table missing!";
}

// 2. Check file system
echo "\n2. Checking file system...\n";

$uploadDir = __DIR__ . '/uploads/live_sessions/';
if (is_dir($uploadDir)) {
    $checks[] = "✓ Upload directory exists: $uploadDir";
    
    if (is_writable($uploadDir)) {
        $checks[] = "✓ Upload directory is writable";
    } else {
        $errors[] = "✗ Upload directory is NOT writable!";
    }
    
    if (file_exists($uploadDir . '.htaccess')) {
        $checks[] = "✓ .htaccess protection file exists";
    } else {
        $warnings[] = "⚠ .htaccess missing (will be created on first upload)";
    }
} else {
    $warnings[] = "⚠ Upload directory doesn't exist (will be created on first upload)";
}

// Check sessions.php exists
$sessionsFile = __DIR__ . '/admin/elearning/sessions.php';
if (file_exists($sessionsFile)) {
    $checks[] = "✓ sessions.php file exists";
    
    // Check file size to ensure it's the new version
    $fileSize = filesize($sessionsFile);
    if ($fileSize > 20000) { // New version should be larger
        $checks[] = "✓ sessions.php appears to be updated (size: " . number_format($fileSize) . " bytes)";
    } else {
        $warnings[] = "⚠ sessions.php may be old version (small file size)";
    }
} else {
    $errors[] = "✗ sessions.php file missing!";
}

// 3. Check PHP configuration
echo "\n3. Checking PHP configuration...\n";

$uploadMax = ini_get('upload_max_filesize');
$postMax = ini_get('post_max_size');
$maxExecTime = ini_get('max_execution_time');

echo "   upload_max_filesize: $uploadMax\n";
echo "   post_max_size: $postMax\n";
echo "   max_execution_time: $maxExecTime seconds\n";

$uploadMaxBytes = return_bytes($uploadMax);
$postMaxBytes = return_bytes($postMax);

if ($uploadMaxBytes >= 500 * 1024 * 1024) {
    $checks[] = "✓ upload_max_filesize supports 500MB uploads";
} else {
    $warnings[] = "⚠ upload_max_filesize is only $uploadMax (recommend 500M or higher)";
}

if ($postMaxBytes >= 500 * 1024 * 1024) {
    $checks[] = "✓ post_max_size supports 500MB uploads";
} else {
    $warnings[] = "⚠ post_max_size is only $postMax (recommend 550M or higher)";
}

if ($maxExecTime >= 300) {
    $checks[] = "✓ max_execution_time sufficient for large uploads";
} else {
    $warnings[] = "⚠ max_execution_time is only $maxExecTime (recommend 600 or higher)";
}

// 4. Check for sample data
echo "\n4. Checking for test data...\n";

// Check lecturers
$result = $db->query("SELECT COUNT(*) as count FROM staff WHERE role = 'lecturer'");
if ($result) {
    $row = $result->fetch_assoc();
    $count = (int)$row['count'];
    if ($count > 0) {
        $checks[] = "✓ Found $count lecturers in system";
    } else {
        $warnings[] = "⚠ No lecturers found in staff table";
    }
}

// Check sessions
$result = $db->query("SELECT COUNT(*) as count FROM lms_sessions");
if ($result) {
    $row = $result->fetch_assoc();
    $count = (int)$row['count'];
    if ($count > 0) {
        $checks[] = "✓ Found $count existing sessions";
    } else {
        $warnings[] = "⚠ No sessions created yet";
    }
}

// Summary
echo "\n";
echo "╔════════════════════════════════════════════════════╗\n";
echo "║                    SUMMARY                         ║\n";
echo "╚════════════════════════════════════════════════════╝\n\n";

if (!empty($checks)) {
    echo "✅ Passed Checks (" . count($checks) . "):\n";
    foreach ($checks as $check) {
        echo "   $check\n";
    }
}

if (!empty($warnings)) {
    echo "\n⚠️  Warnings (" . count($warnings) . "):\n";
    foreach ($warnings as $warning) {
        echo "   $warning\n";
    }
}

if (!empty($errors)) {
    echo "\n❌ Errors (" . count($errors) . "):\n";
    foreach ($errors as $error) {
        echo "   $error\n";
    }
    echo "\n⚠️  Please fix these errors before using sessions.php!\n";
    echo "   Run: php apply_sessions_improvements.php\n";
} else {
    echo "\n✅ All critical checks passed!\n";
    
    if (!empty($warnings)) {
        echo "\n📋 Recommended actions:\n";
        
        if (strpos(implode(' ', $warnings), 'No lecturer-course assignments') !== false) {
            echo "   1. Assign lecturers to courses:\n";
            echo "      php assign_lecturers_to_courses.php --auto-assign-test\n";
        }
        
        if (strpos(implode(' ', $warnings), 'upload_max_filesize') !== false || 
            strpos(implode(' ', $warnings), 'post_max_size') !== false) {
            echo "   2. Update php.ini:\n";
            echo "      upload_max_filesize = 500M\n";
            echo "      post_max_size = 550M\n";
            echo "      max_execution_time = 600\n";
            echo "      Then restart Apache\n";
        }
    }
    
    echo "\n🎉 System is ready to use!\n";
    echo "   Navigate to: http://localhost/wucportal/admin/elearning/sessions.php\n";
}

echo "\n";

// Helper function
function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    switch($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}
