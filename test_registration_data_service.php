<?php
// Test RegistrationDataService
require_once __DIR__ . '/students/includes/guard.php';
require_once __DIR__ . '/students/includes/RegistrationDataService.php';

echo "=== Testing RegistrationDataService ===\n";

try {
    $service = new RegistrationDataService($db);
    echo "Service initialized: SUCCESS\n";
    echo "Class type: " . get_class($service) . "\n";
    
    // Test with a sample student ID
    $testSid = '2020';
    echo "\nTesting with student ID: {$testSid}\n";
    
    // Get latest semester registration
    $latestReg = $service->getLatestSemesterRegistration($testSid);
    if ($latestReg) {
        echo "Latest Semester Registration: ID=" . $latestReg['id'] . 
             ", Year=" . $latestReg['year_of_study'] . 
             ", Semester=" . $latestReg['semester'] . 
             ", Program=" . $latestReg['program_code'] . "\n";
        
        // Get registered courses
        $courses = $service->getRegisteredCourses(
            $testSid, 
            (int)$latestReg['year_of_study'], 
            (int)$latestReg['semester'],
            (int)$latestReg['id']
        );
        echo "Registered courses: " . count($courses) . "\n";
        
        // Get available courses
        $available = $service->getAvailableCourses(
            $latestReg['program_code'],
            (int)$latestReg['year_of_study'],
            (int)$latestReg['semester']
        );
        echo "Available courses: " . count($available) . "\n";
    } else {
        echo "No semester registration found for student {$testSid}\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
