<?php
/**
 * app/ExamRegistration/Validator.php
 *
 * Pure validation logic — no DB, no HTTP, no globals.
 * Returns structured error arrays so the controller can decide what to do.
 */

namespace App\ExamRegistration;

class Validator
{
    /** Allowed semester values */
    private const VALID_SEMESTERS = ['1', '2', '3'];

    /** Allowed year-of-study values */
    private const VALID_YEARS = ['1', '2', '3', '4', '5'];

    /** Allowed exam type values */
    private const VALID_EXAM_TYPES = ['External Assessment', 'End of Semester Exam', 'End of Term Exam', 'Short Course Test', 'First Attempt', 'Resit'];

    /** Student ID: exactly 9 digits */
    private const SID_PATTERN = '/^\d{9}$/';

    /**
     * Validate the search form (Sid + semester + Year).
     *
     * @param  array $data  Raw POST data
     * @return array        ['errors' => string[], 'data' => array|null]
     */
    public static function search(array $data): array
    {
        $errors = [];

        $sid      = trim($data['Sid']      ?? '');
        $semester = trim($data['semester'] ?? '');
        $year     = trim($data['Year']     ?? '');

        if ($sid === '') {
            $errors[] = 'Student ID is required.';
        } elseif (!preg_match(self::SID_PATTERN, $sid)) {
            $errors[] = 'Student ID must be exactly 9 digits.';
        }

        if ($semester === '') {
            $errors[] = 'Semester is required.';
        } elseif (!in_array($semester, self::VALID_SEMESTERS, true)) {
            $errors[] = 'Invalid semester selected.';
        }

        if ($year === '') {
            $errors[] = 'Year of study is required.';
        } elseif (!in_array($year, self::VALID_YEARS, true)) {
            $errors[] = 'Invalid year of study selected.';
        }

        if (!empty($errors)) {
            return ['errors' => $errors, 'data' => null];
        }

        return [
            'errors' => [],
            'data'   => [
                'Sid'      => $sid,
                'semester' => $semester,
                'Year'     => $year,
            ],
        ];
    }

    /**
     * Validate the exam registration form.
     *
     * @param  array $data  Raw POST data
     * @return array        ['errors' => string[], 'data' => array|null]
     */
    public static function register(array $data): array
    {
        $errors = [];

        $sid        = trim($data['Sid']      ?? '');
        $semester   = trim($data['semester'] ?? '');
        $year       = trim($data['Year']     ?? '');
        $examType   = trim($data['examType'] ?? 'External Assessment');
        $rawCourses = $data['course_code']   ?? [];

        if ($sid === '') {
            $errors[] = 'Student ID is required.';
        } elseif (!preg_match(self::SID_PATTERN, $sid)) {
            $errors[] = 'Student ID is invalid.';
        }

        if ($semester === '' || !in_array($semester, self::VALID_SEMESTERS, true)) {
            $errors[] = 'Invalid semester value.';
        }

        if ($year === '' || !in_array($year, self::VALID_YEARS, true)) {
            $errors[] = 'Invalid year of study value.';
        }

        if ($examType === '' || !in_array($examType, self::VALID_EXAM_TYPES, true)) {
            $errors[] = 'Invalid exam type selected.';
        }

        if (!is_array($rawCourses) || empty($rawCourses)) {
            $errors[] = 'At least one course must be selected.';
        } else {
            // Sanitise each course code: strip anything that is not alphanumeric or hyphen
            $rawCourses = array_values(array_filter(array_map(
                fn(string $c) => preg_replace('/[^A-Za-z0-9\-]/', '', trim($c)),
                array_map('strval', $rawCourses)
            )));

            if (empty($rawCourses)) {
                $errors[] = 'Selected course codes are invalid.';
            }
        }

        if (!empty($errors)) {
            return ['errors' => $errors, 'data' => null];
        }

        return [
            'errors' => [],
            'data'   => [
                'Sid'         => $sid,
                'semester'    => $semester,
                'Year'        => $year,
                'examType'    => $examType,
                'course_code' => $rawCourses,       // cleaned array, one element per course
            ],
        ];
    }
}
