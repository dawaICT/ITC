<?php
/**
 * Seed local e-learning courses for the ITC test lecturer/staff accounts.
 *
 * Usage:
 *   E:\xampp\php\php.exe scripts\seed_elearning_lecturer_accounts.php
 */

require_once __DIR__ . '/../db/connect.php';

if (php_sapi_name() !== 'cli') {
    echo "Run this script from the command line.\n";
    exit(1);
}

$academicYear = 2026;
$programCode = 'TEST-PROG';
$studentId = 'CSE26456789';

$accounts = [
    ['staff_id' => 'ITC900', 'label' => 'All Roles'],
    ['staff_id' => 'ITC901', 'label' => 'Systems Admin'],
    ['staff_id' => 'ITC902', 'label' => 'Admissions'],
    ['staff_id' => 'ITC903', 'label' => 'Finance'],
    ['staff_id' => 'ITC904', 'label' => 'Head of Section'],
    ['staff_id' => 'ITC905', 'label' => 'Registrar'],
    ['staff_id' => 'ITC906', 'label' => 'Dean'],
    ['staff_id' => 'ITC907', 'label' => 'Lecturer'],
    ['staff_id' => 'ITC908', 'label' => 'Library'],
];

function runSqlFile(mysqli $db, string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException("Missing SQL file: {$path}");
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Unable to read SQL file: {$path}");
    }

    if (!$db->multi_query($sql)) {
        throw new RuntimeException($db->error);
    }

    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());

    if ($db->errno) {
        throw new RuntimeException($db->error);
    }
}

function tableExists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '{$safe}'");
    if (!$result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

try {
    runSqlFile($db, __DIR__ . '/../db/elearning_schema.sql');

    $db->begin_transaction();

    foreach ($accounts as $index => $account) {
        $staffId = $account['staff_id'];
        $courseCode = 'EL' . substr($staffId, -3);
        $courseName = 'E-Learning Test Course - ' . $account['label'];
        $semester = ($index % 2) + 1;
        $yearOfStudy = 1;

        $course = $db->prepare(
            'INSERT INTO courses (course_code, course_name, credits, status)
             VALUES (?, ?, 3, "active")
             ON DUPLICATE KEY UPDATE course_name = VALUES(course_name), credits = VALUES(credits), status = "active"'
        );
        $course->bind_param('ss', $courseCode, $courseName);
        $course->execute();
        $course->close();

        $programCourse = $db->prepare(
            'INSERT INTO program_courses (program_code, course_code, course_name, semester, year_of_study)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE course_name = VALUES(course_name), semester = VALUES(semester), year_of_study = VALUES(year_of_study)'
        );
        $programCourse->bind_param('sssii', $programCode, $courseCode, $courseName, $semester, $yearOfStudy);
        $programCourse->execute();
        $programCourse->close();

        $lecturer = $db->prepare(
            'INSERT INTO course_lecturer (staff_id, course_code, program_code, year_of_study, semester, academic_year, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, "active", NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                program_code = VALUES(program_code),
                year_of_study = VALUES(year_of_study),
                semester = VALUES(semester),
                academic_year = VALUES(academic_year),
                status = "active",
                updated_at = NOW()'
        );
        $lecturer->bind_param('sssiii', $staffId, $courseCode, $programCode, $yearOfStudy, $semester, $academicYear);
        $lecturer->execute();
        $lecturer->close();

        $moduleTitle = 'Getting Started';
        $moduleDescription = 'Starter e-learning module for local lecturer account testing.';
        $moduleCheck = $db->prepare('SELECT id FROM el_course_modules WHERE course_code = ? AND title = ? LIMIT 1');
        $moduleCheck->bind_param('ss', $courseCode, $moduleTitle);
        $moduleCheck->execute();
        $moduleCheck->bind_result($moduleId);
        $moduleExists = $moduleCheck->fetch();
        $moduleCheck->close();

        if ($moduleExists) {
            $module = $db->prepare(
                'UPDATE el_course_modules
                 SET description = ?, release_at = NULL, close_at = NULL, position = 1, created_by = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $module->bind_param('ssi', $moduleDescription, $staffId, $moduleId);
        } else {
            $module = $db->prepare(
                'INSERT INTO el_course_modules (course_code, title, description, release_at, close_at, position, created_by)
                 VALUES (?, ?, ?, NULL, NULL, 1, ?)'
            );
            $module->bind_param('ssss', $courseCode, $moduleTitle, $moduleDescription, $staffId);
        }
        $module->execute();
        $module->close();

        if (tableExists($db, 'course_registration')) {
            $registrationCheck = $db->prepare('SELECT id FROM course_registration WHERE Sid = ? AND course_code = ? LIMIT 1');
            $registrationCheck->bind_param('ss', $studentId, $courseCode);
            $registrationCheck->execute();
            $registrationCheck->store_result();
            $registered = $registrationCheck->num_rows > 0;
            $registrationCheck->close();

            if (!$registered) {
                $registration = $db->prepare(
                    'INSERT INTO course_registration (Sid, course_code, semester, Year, created_at)
                     VALUES (?, ?, ?, ?, NOW())'
                );
                $registration->bind_param('ssii', $studentId, $courseCode, $semester, $academicYear);
                $registration->execute();
                $registration->close();
            }
        }
    }

    $db->commit();

    echo "Seeded lecturer e-learning courses and assignments.\n\n";
    foreach ($accounts as $account) {
        $courseCode = 'EL' . substr($account['staff_id'], -3);
        echo "{$account['staff_id']} => {$courseCode}\n";
    }
    echo "\nStudent {$studentId} is enrolled in the seeded e-learning test courses where course_registration is available.\n";
} catch (Throwable $e) {
    if ($db->errno === 0) {
        @$db->rollback();
    } else {
        @$db->rollback();
    }
    echo 'Error seeding e-learning lecturer accounts: ' . $e->getMessage() . "\n";
    exit(1);
}
