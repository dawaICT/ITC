<?php
/**
 * Data cleanup script for course registration consistency
 * Links orphaned student_courses to semester_registration records
 * or creates semester_registration records where missing
 */

require_once 'students/includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

echo "Starting course registration data cleanup...\n";

$conn->beginTransaction();

try {
    // Step 1: Find student_courses records without corresponding semester_registration
    $orphanedStmt = $conn->query("
        SELECT sc.student_id, sc.academic_year, sc.semester, sc.course_code
        FROM student_courses sc
        LEFT JOIN semester_registration sr ON sc.student_id = sr.student_id
            AND sc.academic_year = sr.academic_year
            AND sc.semester = sr.semester
        WHERE sr.id IS NULL
        ORDER BY sc.student_id, sc.academic_year, sc.semester
    ");

    $orphanedCourses = $orphanedStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($orphanedCourses) . " orphaned student_courses records\n";

    // Group by student/academic_year/semester
    $grouped = [];
    foreach ($orphanedCourses as $course) {
        $key = $course['student_id'] . '|' . $course['academic_year'] . '|' . $course['semester'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'student_id' => $course['student_id'],
                'academic_year' => $course['academic_year'],
                'semester' => $course['semester'],
                'year_of_study' => 1, // Default, will be updated based on semester progression
                'courses' => []
            ];
        }
        $grouped[$key]['courses'][] = $course['course_code'];
    }

    echo "Grouped into " . count($grouped) . " semester registration groups\n";

    // Step 2: Create semester_registration records for orphaned groups
    $createdSemRegs = 0;
    foreach ($grouped as $group) {
        // Get program code for the student
        $progStmt = $conn->prepare("SELECT program_code FROM student_program WHERE Sid = ? ORDER BY id DESC LIMIT 1");
        $progStmt->execute([$group['student_id']]);
        $program = $progStmt->fetch(PDO::FETCH_ASSOC);

        if (!$program) {
            echo "Warning: No program found for student {$group['student_id']}, skipping\n";
            continue;
        }

        // Check if semester_registration already exists (double-check)
        $checkStmt = $conn->prepare("
            SELECT id FROM semester_registration
            WHERE student_id = ? AND academic_year = ? AND semester = ?
        ");
        $checkStmt->execute([$group['student_id'], $group['academic_year'], $group['semester']]);
        if ($checkStmt->fetch()) {
            continue; // Already exists
        }

        // Create semester_registration
        $insertStmt = $conn->prepare("
            INSERT INTO semester_registration
            (student_id, program_code, academic_year, semester, year_of_study, student_type, registration_date, created_at)
            VALUES (?, ?, ?, ?, ?, 'Regular', NOW(), NOW())
        ");
        $insertStmt->execute([
            $group['student_id'],
            $program['program_code'],
            $group['academic_year'],
            $group['semester'],
            $group['year_of_study']
        ]);

        $semRegId = $conn->lastInsertId();
        $createdSemRegs++;

        // Step 3: Move courses from student_courses to course_registration
        foreach ($group['courses'] as $courseCode) {
            // Check if already exists in course_registration
            $checkCourseStmt = $conn->prepare("
                SELECT id FROM course_registration
                WHERE semester_registration_id = ? AND course_code = ?
            ");
            $checkCourseStmt->execute([$semRegId, $courseCode]);
            if ($checkCourseStmt->fetch()) {
                continue; // Already exists
            }

            // Insert into course_registration
            $insertCourseStmt = $conn->prepare("
                INSERT INTO course_registration
                (semester_registration_id, student_id, course_code, semester, Year, registration_date)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $insertCourseStmt->execute([
                $semRegId,
                $group['student_id'],
                $courseCode,
                $group['semester'],
                $group['year_of_study']
            ]);
        }

        // Remove from student_courses (now properly linked)
        $deleteStmt = $conn->prepare("
            DELETE FROM student_courses
            WHERE student_id = ? AND academic_year = ? AND semester = ?
        ");
        $deleteStmt->execute([$group['student_id'], $group['academic_year'], $group['semester']]);
    }

    $conn->commit();

    echo "Cleanup completed:\n";
    echo "- Created $createdSemRegs semester_registration records\n";
    echo "- Migrated " . count($orphanedCourses) . " courses to proper structure\n";

} catch (Exception $e) {
    $conn->rollBack();
    echo "Error during cleanup: " . $e->getMessage() . "\n";
}
?>