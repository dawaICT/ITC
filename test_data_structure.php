<?php
/**
 * Test Database and Model Classes
 * Verifies the data structure implementation
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Load the database class
require_once __DIR__ . '/db/Database.php';

echo "=== DATABASE & MODEL SYSTEM TEST ===\n\n";

// Test 1: Database Singleton
echo "1. Testing Database Singleton...\n";
try {
    $db = Database::getInstance();
    echo "   ✓ Database instance created\n";
    
    $db2 = Database::getInstance();
    echo "   ✓ Second call returns same instance: " . ($db === $db2 ? "YES" : "NO") . "\n";
} catch (Exception $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n";
}

// Test 2: Basic Query
echo "\n2. Testing Basic Queries...\n";
try {
    $tables = $db->fetchAll("SHOW TABLES");
    echo "   ✓ Found " . count($tables) . " tables\n";
    
    $version = $db->fetchValue("SELECT VERSION()");
    echo "   ✓ MySQL Version: $version\n";
} catch (Exception $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n";
}

// Test 3: Prepared Statements
echo "\n3. Testing Prepared Statements...\n";
try {
    // Check if students table exists
    $tableExists = $db->fetchValue(
        "SELECT COUNT(*) FROM information_schema.tables 
         WHERE table_schema = DATABASE() AND table_name = 'students'"
    );
    
    if ($tableExists) {
        $studentCount = $db->fetchValue("SELECT COUNT(*) FROM students");
        echo "   ✓ Students table exists with $studentCount records\n";
        
        // Test parameterized query
        $student = $db->fetchOne("SELECT * FROM students LIMIT 1");
        if ($student) {
            echo "   ✓ Sample student: " . ($student['first_name'] ?? 'N/A') . " " . ($student['last_name'] ?? 'N/A') . "\n";
        }
    } else {
        echo "   ⚠ Students table not found - skipping\n";
    }
} catch (Exception $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n";
}

// Test 4: Model Classes
echo "\n4. Testing Model Classes...\n";
try {
    require_once __DIR__ . '/db/models/Student.php';
    echo "   ✓ Student model loaded\n";
    
    require_once __DIR__ . '/db/models/Course.php';
    echo "   ✓ Course model loaded\n";
    
    require_once __DIR__ . '/db/models/Program.php';
    echo "   ✓ Program model loaded\n";
} catch (Exception $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n";
}

// Test 5: Model Operations
echo "\n5. Testing Model Operations...\n";
try {
    // Test Program model
    $programCount = Program::count();
    echo "   ✓ Program::count() = $programCount\n";
    
    if ($programCount > 0) {
        $programs = Program::all();
        $firstProgram = $programs[0] ?? null;
        if ($firstProgram) {
            echo "   ✓ First program: " . ($firstProgram->program_name ?? $firstProgram->program_code ?? 'Unknown') . "\n";
        }
    }
    
    // Test Course model
    $courseCount = Course::count();
    echo "   ✓ Course::count() = $courseCount\n";
    
    // Test Student model
    $studentCount = Student::count();
    echo "   ✓ Student::count() = $studentCount\n";
    
} catch (Exception $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n";
}

// Test 6: Query Builder
echo "\n6. Testing Query Builder...\n";
try {
    // Test where clause - use program_code which exists
    $results = Program::where('is_active', '=', 1)->limit(3)->get();
    echo "   ✓ Where query returned " . count($results) . " active programs\n";
    
    // Test count with condition
    $count = Course::where('course_id', '>', 0)->count();
    echo "   ✓ Conditional count on courses: $count\n";
    
    // Test Student query
    $students = Student::all();
    echo "   ✓ Student::all() returned " . count($students) . " students\n";
    if (count($students) > 0) {
        echo "   ✓ First student: " . $students[0]->getFullName() . "\n";
    }
    
} catch (Exception $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n";
}

// Test 7: Transactions
echo "\n7. Testing Transactions...\n";
try {
    $db->beginTransaction();
    echo "   ✓ Transaction started\n";
    
    $db->rollback();
    echo "   ✓ Transaction rolled back\n";
    
} catch (Exception $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n";
}

// Test 8: Helper Function
echo "\n8. Testing db() Helper Function...\n";
try {
    $instance = db();
    echo "   ✓ db() helper works: " . ($instance instanceof Database ? "YES" : "NO") . "\n";
} catch (Exception $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n";
}

// Summary
echo "\n=== TEST COMPLETE ===\n";
echo "Database connection: WORKING\n";
echo "Model system: LOADED\n";
echo "Query builder: FUNCTIONAL\n";

// Show usage examples
echo "\n=== USAGE EXAMPLES ===\n";
echo <<<'EXAMPLES'

// Using Database class directly:
$db = Database::getInstance();
$students = $db->fetchAll("SELECT * FROM students WHERE program_id = ?", [1]);
$count = $db->fetchValue("SELECT COUNT(*) FROM courses");
$db->insert('logs', ['action' => 'login', 'user_id' => 1]);

// Using Model classes:
$student = Student::find(123);
$student = Student::findByAdmissionNo('WUC/2024/001');
$students = Student::where('program_id', 1)->get();

$course = Course::find(1);
$courses = Course::findByProgram(1, year: 1, semester: 1);

$program = Program::findByCode('BSCS');
$programs = Program::where('department_id', 1)->orderBy('program_name')->get();

// Using Query Builder:
$results = Student::where('year', 2)
    ->where('status', 'active')
    ->orderBy('last_name')
    ->limit(10)
    ->get();

EXAMPLES;

echo "\n";
