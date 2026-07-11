<?php
// EligibilityService: derives failed courses, repeat flags, and validations for returning students
// Uses mysqli $db connection from db/connect.php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/assessment_weighting_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/grading_helpers.php'; // wuc_result_exam_written
require_once dirname(__DIR__, 2) . '/includes/audit.php';
require_once dirname(__DIR__, 2) . '/includes/helpers/course_availability_helpers.php';

class EligibilityService
{
    // Policy knobs (adjust as needed)
    // Failing if total grade strictly less than this threshold
    private const FAILING_THRESHOLD_SCORE = 45; // E in current scale
    private const REPEAT_SEMESTER_MIN_FAILS = 3;

    // Default max credits per year of study (fallback when program-specific rules absent)
    private const DEFAULT_MAX_CREDITS_BY_YEAR = [
        1 => 24,
        2 => 24,
        3 => 24,
        4 => 24,
    ];

    /**
     * Compute latest attempts per course for a student and flag failures.
     * Returns array keyed by course_code with:
     *   [
     *     'course_code' => string,
     *     'year' => int,
     *     'semester' => int,
     *     'total_score' => float,
     *     'grade_letter' => string,
     *     'is_failed' => bool
     *   ]
     */
    public static function getLatestCourseAttempts(mysqli $db, string $sid): array
    {
        // A "graded attempt" only exists once results are PUBLISHED and the
        // student actually sat the final exam. Un-examined or unpublished rows
        // (e.g. CA-only) are not completed results, so they must not surface as
        // a grade or a failure to the student, nor gate prerequisites.
        $sidEsc = $db->real_escape_string($sid);
        $sql = "
            SELECT e.Course_Code AS course_code,
                   e.semester AS sem,
                   e.Year AS yr,
                   IFNULL(sa.Total_CA, 0) AS total_ca,
                   IFNULL(e.Exam_marks, e.Total_marks) AS total_exam
            FROM exams e
            LEFT JOIN semester_assessment sa
              ON sa.Sid = e.Sid
             AND sa.Course_Code = e.Course_Code
             AND sa.Year = e.Year
             AND sa.semester = e.semester
            WHERE e.Sid = '{$sidEsc}'
              AND e.status = 'Published'
        ";

        $attempts = [];
        if ($res = $db->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                if (!wuc_result_exam_written($row['total_exam'])) {
                    continue; // not yet examined → no final grade
                }
                $code = (string)$row['course_code'];
                $yr = (int)($row['yr'] ?? 0);
                $sem = (int)($row['sem'] ?? 0);
                $total = assessment_weighting_total($db, $sid, $row['total_ca'] ?? 0, $row['total_exam'] ?? 0);

                $current = $attempts[$code] ?? null;
                if ($current === null || self::isLater($yr, $sem, (int)$current['year'], (int)$current['semester'])) {
                    $attempts[$code] = [
                        'course_code' => $code,
                        'year' => $yr,
                        'semester' => $sem,
                        'total_score' => $total,
                        'grade_letter' => self::scoreToLetter($total),
                        'is_failed' => $total < self::FAILING_THRESHOLD_SCORE,
                    ];
                }
            }
            $res->free();
        }
        return $attempts;
    }

    /** Determine if (yearA, semA) is later than (yearB, semB) */
    private static function isLater(int $yearA, int $semA, int $yearB, int $semB): bool
    {
        if ($yearA !== $yearB) { return $yearA > $yearB; }
        return $semA > $semB;
    }

    /** Map numeric total score to letter based on existing UI scale */
    public static function scoreToLetter(float $total): string
    {
        if ($total >= 90) return 'A+';
        if ($total >= 80) return 'A';
        if ($total >= 75) return 'B+';
        if ($total >= 70) return 'B';
        if ($total >= 65) return 'B-';
        if ($total >= 60) return 'C+';
        if ($total >= 50) return 'C';
        if ($total >= 45) return 'D';
        return 'E'; // Fail
    }

    /**
     * Return list of courses where the latest attempt is a failure.
     * Each entry: ['course_code' => ..., 'year' => ..., 'semester' => ..., 'grade_letter' => ..., 'total_score' => ...]
     */
    public static function getFailedCourses(mysqli $db, string $sid): array
    {
        $latest = self::getLatestCourseAttempts($db, $sid);
        $failed = [];
        foreach ($latest as $code => $att) {
            if (!empty($att['is_failed'])) {
                $failed[] = $att;
            }
        }
        // Sort by course code for display stability
        usort($failed, function ($a, $b) {
            return strcmp((string)$a['course_code'], (string)$b['course_code']);
        });
        return $failed;
    }

    /** Basic flags based on failure count */
    public static function computeFailureFlags(int $failedCount): array
    {
        $repeat = $failedCount >= self::REPEAT_SEMESTER_MIN_FAILS;
        $autoAppend = ($failedCount >= 1 && $failedCount <= 2);
        return [
            'repeat_semester' => $repeat,
            'auto_append_failed' => $autoAppend && !$repeat,
        ];
    }

    /** Return subset of $failedCourses that are offered for the student's program/year/semester */
    public static function filterFailedCoursesOfferedThisTerm(
        mysqli $db,
        string $programCode,
        int $year,
        int $semester,
        array $failedCourses,
        bool $matchYear = true
    ): array {
        if (empty($failedCourses)) { return []; }
        $failedCodes = array_values(array_unique(array_map(fn($r) => (string)$r['course_code'], $failedCourses)));
        $in = implode(",", array_map(fn($c) => "'" . $db->real_escape_string($c) . "'", $failedCodes));
        $prog = $db->real_escape_string($programCode);
        $yr = (int)$year;
        $sem = (int)$semester;

        // Discover the curriculum table shape because installs differ
        // (program_courses uses `year` in the live schema, not `year_of_study`).
        $sourceTable = null;
        $sourceCols = [];
        if ($chk = $db->query("SHOW TABLES LIKE 'course_levels'")) {
            if ($chk->num_rows > 0) { $sourceTable = 'course_levels'; }
            $chk->free();
        }
        if ($sourceTable === null) {
            if ($chk = $db->query("SHOW TABLES LIKE 'program_courses'")) {
                if ($chk->num_rows > 0) { $sourceTable = 'program_courses'; }
                $chk->free();
            }
        }
        if ($sourceTable === null) {
            // No curriculum table available — be conservative and treat all failed
            // courses as offered. The caller will then auto-append them; downstream
            // validation will reject any that don't exist.
            return $failedCourses;
        }

        if ($meta = $db->query("SHOW COLUMNS FROM `{$sourceTable}`")) {
            while ($col = $meta->fetch_assoc()) {
                $sourceCols[strtolower((string)$col['Field'])] = (string)$col['Field'];
            }
            $meta->free();
        }
        $programColumn = $sourceCols['program_code'] ?? null;
        $courseColumn = $sourceCols['course_code'] ?? null;
        $semesterColumn = $sourceCols['semester'] ?? ($sourceCols['semester_term'] ?? null);
        $yearColumn = $sourceCols['year'] ?? ($sourceCols['year_of_study'] ?? ($sourceCols['year_level'] ?? null));
        if (!$programColumn || !$courseColumn) {
            return [];
        }

        $sql = "
            SELECT DISTINCT t.`{$courseColumn}` AS course_code
            FROM `{$sourceTable}` t
            WHERE t.`{$programColumn}` = '{$prog}'
              AND t.`{$courseColumn}` IN ({$in})
        ";
        $periodFilter = wuc_course_availability_period_filter($sourceCols, 't', $semesterColumn, $sem);
        if ($periodFilter['sql'] !== '1=1') {
            $sql .= ' AND ' . str_replace('?', (string)$sem, $periodFilter['sql']);
        }
        if ($yearColumn !== null && $matchYear) {
            $sql .= " AND t.`{$yearColumn}` = {$yr}";
        }
        $offered = [];
        if ($res = $db->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $offered[] = (string)$row['course_code'];
            }
            $res->free();
        }

        return array_values(array_filter($failedCourses, fn($r) => in_array($r['course_code'], $offered, true)));
    }

    /** Sum credits for a set of course codes using courses.credits if available, else fallback 3 per course */
    public static function sumCredits(mysqli $db, array $courseCodes): int
    {
        if (empty($courseCodes)) return 0;
        $in = implode(",", array_map(fn($c) => "'" . $db->real_escape_string((string)$c) . "'", $courseCodes));
        $credits = 0;
        $hasCreditsCol = false;
        if ($res = $db->query("SHOW COLUMNS FROM courses LIKE 'credits'")) {
            $hasCreditsCol = $res->num_rows > 0; $res->free();
        }
        if ($hasCreditsCol) {
            $sql = "SELECT COALESCE(SUM(credits), 0) AS total FROM courses WHERE course_code IN ({$in})";
            if ($res = $db->query($sql)) {
                $credits = (int)($res->fetch_assoc()['total'] ?? 0);
                $res->free();
            }
        } else {
            // Fallback: assume 3 credits per course
            $credits = count($courseCodes) * 3;
        }
        return $credits;
    }

    /** Return max credits for a given year; can extend to program rules */
    public static function maxCreditsForYear(int $year): int
    {
        return self::DEFAULT_MAX_CREDITS_BY_YEAR[$year] ?? 21;
    }

    /**
     * Check prerequisites: requires table course_prerequisites(course_code, prereq_code, min_grade)
     * Returns [isValid => bool, errors => array]
     */
    public static function validatePrerequisites(mysqli $db, string $sid, array $selectedCourseCodes): array
    {
        if (empty($selectedCourseCodes)) { return ['isValid' => true, 'errors' => []]; }
        $in = implode(",", array_map(fn($c) => "'" . $db->real_escape_string((string)$c) . "'", $selectedCourseCodes));
        $errors = [];

        $hasPrereqTable = false;
        if ($tableCheck = $db->query("SHOW TABLES LIKE 'course_prerequisites'")) {
            $hasPrereqTable = $tableCheck->num_rows > 0;
            $tableCheck->free();
        }
        if (!$hasPrereqTable) {
            return ['isValid' => true, 'errors' => []];
        }

        // Fetch prereqs
        $prereqs = [];
        if ($res = $db->query("SELECT cp.course_code, cp.prereq_code, cp.min_grade FROM course_prerequisites cp WHERE cp.course_code IN ({$in})")) {
            while ($row = $res->fetch_assoc()) {
                $prereqs[] = $row;
            }
            $res->free();
        }
        if (empty($prereqs)) { return ['isValid' => true, 'errors' => []]; }

        // Build latest attempts once
        $latest = self::getLatestCourseAttempts($db, $sid);

        foreach ($prereqs as $p) {
            $course = (string)$p['course_code'];
            $pre = (string)$p['prereq_code'];
            $minGrade = trim((string)($p['min_grade'] ?? ''));
            $att = $latest[$pre] ?? null;
            if ($att === null) {
                $errors[] = "Missing prerequisite $pre for $course";
                continue;
            }
            $letter = (string)$att['grade_letter'];
            if ($minGrade !== '') {
                if (!self::meetsMinGrade($letter, $minGrade)) {
                    $errors[] = "$course requires $pre with grade >= $minGrade (you have $letter)";
                }
            } else {
                if ($att['is_failed']) {
                    $errors[] = "$course requires passing $pre (you have $letter)";
                }
            }
        }
        return ['isValid' => empty($errors), 'errors' => $errors];
    }

    /** Compare grade letters roughly by threshold order */
    private static function meetsMinGrade(string $achieved, string $min): bool
    {
        $rank = [ 'E' => 0, 'D' => 1, 'C' => 2, 'C+' => 3, 'B-' => 4, 'B' => 5, 'B+' => 6, 'A' => 7, 'A+' => 8 ];
        $a = $rank[$achieved] ?? -1; $m = $rank[$min] ?? PHP_INT_MAX;
        return $a >= $m;
    }

    /** Ensure audit log table and insert an entry */
    public static function audit(mysqli $db, string $sid, string $action, array $details): void
    {
        try {
            audit_log($db, $sid, $action, $details);
        } catch (Throwable $e) {
            // Swallow audit failures to avoid breaking main flows
            error_log('Audit log failure: ' . $e->getMessage());
        }
    }
}
