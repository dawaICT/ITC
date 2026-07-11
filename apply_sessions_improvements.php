<?php
/**
 * Apply database improvements for sessions.php
 * Run this once to set up the required tables and columns
 */

require_once __DIR__ . '/db/connect.php';

echo "===================================================\n";
echo "Applying Sessions.php Database Improvements\n";
echo "===================================================\n\n";

$errors = [];
$success = [];

// 1. Add timestamp columns to lms_sessions
echo "1. Adding timestamp columns to lms_sessions...\n";
try {
    $db->query("ALTER TABLE lms_sessions 
                ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    $success[] = "✓ Added created_at column";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        $success[] = "✓ created_at column already exists";
    } else {
        $errors[] = "✗ Failed to add created_at: " . $e->getMessage();
    }
}

try {
    $db->query("ALTER TABLE lms_sessions 
                ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    $success[] = "✓ Added updated_at column";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        $success[] = "✓ updated_at column already exists";
    } else {
        $errors[] = "✗ Failed to add updated_at: " . $e->getMessage();
    }
}

// 2. Create lecturer_courses table
echo "\n2. Creating lecturer_courses table...\n";
try {
    $db->query("CREATE TABLE IF NOT EXISTS lecturer_courses (
        lecturer_id VARCHAR(50) NOT NULL,
        course_code VARCHAR(20) NOT NULL,
        assigned_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (lecturer_id, course_code),
        KEY idx_lecturer (lecturer_id),
        KEY idx_course (course_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $success[] = "✓ lecturer_courses table created/verified";
} catch (Exception $e) {
    $errors[] = "✗ Failed to create lecturer_courses: " . $e->getMessage();
}

// 3. Add status column to courses if missing
echo "\n3. Adding status column to courses...\n";
try {
    $db->query("ALTER TABLE courses 
                ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active'");
    $success[] = "✓ Added status column to courses";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        $success[] = "✓ status column already exists";
    } else {
        $errors[] = "✗ Failed to add status: " . $e->getMessage();
    }
}

// 4. Add indexes for performance
echo "\n4. Adding database indexes...\n";
$indexes = [
    "ALTER TABLE courses ADD INDEX IF NOT EXISTS idx_status (status)",
    "ALTER TABLE lms_sessions ADD INDEX IF NOT EXISTS idx_created_by (created_by)",
    "ALTER TABLE lms_sessions ADD INDEX IF NOT EXISTS idx_start_time (start_time)",
    "ALTER TABLE lms_sessions ADD INDEX IF NOT EXISTS idx_provider (provider)",
    "ALTER TABLE lms_sessions ADD INDEX IF NOT EXISTS idx_status (status)"
];

foreach ($indexes as $indexQuery) {
    try {
        $db->query($indexQuery);
        preg_match('/ADD INDEX.*? (\w+)/', $indexQuery, $matches);
        $indexName = $matches[1] ?? 'unknown';
        $success[] = "✓ Added index: $indexName";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key') !== false) {
            preg_match('/ADD INDEX.*? (\w+)/', $indexQuery, $matches);
            $indexName = $matches[1] ?? 'unknown';
            $success[] = "✓ Index already exists: $indexName";
        } else {
            $errors[] = "✗ Failed to add index: " . $e->getMessage();
        }
    }
}

// 5. Check if we need to populate lecturer_courses
echo "\n5. Checking lecturer_courses assignments...\n";
$result = $db->query("SELECT COUNT(*) as count FROM lecturer_courses");
$row = $result->fetch_assoc();
$count = (int)$row['count'];

if ($count === 0) {
    echo "   WARNING: No lecturer-course assignments found!\n";
    echo "   You need to populate the lecturer_courses table manually.\n";
    echo "   Example SQL:\n";
    echo "   INSERT INTO lecturer_courses (lecturer_id, course_code) VALUES\n";
    echo "   ('LEC001', 'CS101'),\n";
    echo "   ('LEC002', 'MATH101');\n\n";
} else {
    $success[] = "✓ Found $count lecturer-course assignments";
}

// Summary
echo "\n===================================================\n";
echo "SUMMARY\n";
echo "===================================================\n\n";

if (!empty($success)) {
    echo "Successful operations:\n";
    foreach ($success as $msg) {
        echo "  $msg\n";
    }
}

if (!empty($errors)) {
    echo "\n⚠ Errors encountered:\n";
    foreach ($errors as $msg) {
        echo "  $msg\n";
    }
    echo "\nPlease fix these errors before using the updated sessions.php\n";
} else {
    echo "\n✅ All database improvements applied successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Populate lecturer_courses table with actual assignments\n";
    echo "2. Test the new sessions.php interface\n";
    echo "3. Verify video upload functionality\n";
}

echo "\n===================================================\n";
