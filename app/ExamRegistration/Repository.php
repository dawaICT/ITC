<?php
/**
 * Database operations for external assessment registration.
 */

namespace App\ExamRegistration;

use mysqli;
use RuntimeException;

class Repository
{
    public function __construct(private mysqli $db)
    {
        if (function_exists('\\student_exam_ensure_schema')) {
            \student_exam_ensure_schema($this->db);
        }
    }

    /**
     * Return courses a student can register for in the selected period.
     */
    public function getEnrolledCourses(string $sid, string $semester, string $year): array
    {
        if (!function_exists('\\getCoursesForExamRegistration')) {
            throw new RuntimeException('Exam registration helper is not loaded.');
        }

        $rows = [];
        foreach (\getCoursesForExamRegistration($this->db, $sid, (int)$semester, (int)$year) as $row) {
            $rows[] = (object)$row;
        }

        return $rows;
    }

    /**
     * Return selected course codes already registered for this assessment.
     *
     * @param string[] $courseCodes
     * @return string[]
     */
    public function findDuplicates(string $sid, array $courseCodes, string $semester, string $year): array
    {
        if (empty($courseCodes) || !function_exists('\\getCoursesForExamRegistration')) {
            return [];
        }

        $available = \getCoursesForExamRegistration($this->db, $sid, (int)$semester, (int)$year);
        $registered = [];
        foreach ($available as $row) {
            if (!empty($row['already_registered'])) {
                $registered[(string)$row['course_code']] = true;
            }
        }

        $duplicates = [];
        foreach ($courseCodes as $code) {
            if (isset($registered[$code])) {
                $duplicates[] = $code;
            }
        }

        return $duplicates;
    }

    /**
     * Register selected courses for their external assessment period.
     *
     * @param string[] $courseCodes
     */
    public function registerCourses(
        string $sid,
        array $courseCodes,
        string $semester,
        string $year,
        string $examType
    ): int {
        if (!function_exists('\\student_exam_register_courses')) {
            throw new RuntimeException('Exam registration helper is not loaded.');
        }

        $result = \student_exam_register_courses($this->db, $sid, $courseCodes, (int)$semester, (int)$year);
        if (empty($result['ok'])) {
            throw new RuntimeException((string)($result['message'] ?? 'Registration failed.'));
        }

        return (int)($result['inserted'] ?? 0);
    }
}
