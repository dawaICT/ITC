<?php
// Suppress any PHP output before JSON response
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require_once "../includes/admin.php";

ob_end_clean();

header('Content-Type: application/json');

try {
    $statistics = [];

    // Total programs with assigned courses
    $programs_query = "SELECT COUNT(DISTINCT program_code) as total_programs FROM program_courses";
    $programs_result = $db->query($programs_query);
    $statistics['total_programs'] = $programs_result->fetch_assoc()['total_programs'] ?? 0;

    // Total courses assigned
    $courses_query = "SELECT COUNT(*) as total_courses FROM program_courses";
    $courses_result = $db->query($courses_query);
    $statistics['total_courses'] = $courses_result->fetch_assoc()['total_courses'] ?? 0;

    // Semester-based programs courses (study_mode = 'semester')
    $sem_query = "SELECT pc.semester, COUNT(*) as cnt 
                  FROM program_courses pc
                  JOIN programs p ON pc.program_code = p.program_code
                  WHERE p.study_mode = 'semester'
                  GROUP BY pc.semester
                  ORDER BY pc.semester";
    $sem_result = $db->query($sem_query);
    $semester_counts = [];
    if ($sem_result) {
        while ($row = $sem_result->fetch_assoc()) {
            $semester_counts[$row['semester']] = (int)$row['cnt'];
        }
    }
    $statistics['semester_1_courses'] = $semester_counts[1] ?? 0;
    $statistics['semester_2_courses'] = $semester_counts[2] ?? 0;

    // Term-based programs courses (study_mode = 'term')
    $term_query = "SELECT pc.semester, COUNT(*) as cnt 
                   FROM program_courses pc
                   JOIN programs p ON pc.program_code = p.program_code
                   WHERE p.study_mode = 'term'
                   GROUP BY pc.semester
                   ORDER BY pc.semester";
    $term_result = $db->query($term_query);
    $term_counts = [];
    if ($term_result) {
        while ($row = $term_result->fetch_assoc()) {
            $term_counts[$row['semester']] = (int)$row['cnt'];
        }
    }
    $statistics['term_1_courses'] = $term_counts[1] ?? 0;
    $statistics['term_2_courses'] = $term_counts[2] ?? 0;
    $statistics['term_3_courses'] = $term_counts[3] ?? 0;

    // Count programs by study mode
    $mode_query = "SELECT 
                     SUM(CASE WHEN p.study_mode = 'semester' THEN 1 ELSE 0 END) as semester_programs,
                     SUM(CASE WHEN p.study_mode = 'term' THEN 1 ELSE 0 END) as term_programs
                   FROM (SELECT DISTINCT pc.program_code FROM program_courses pc) pcs
                   JOIN programs p ON pcs.program_code = p.program_code";
    $mode_result = $db->query($mode_query);
    if ($mode_result && $mode_row = $mode_result->fetch_assoc()) {
        $statistics['semester_programs'] = (int)($mode_row['semester_programs'] ?? 0);
        $statistics['term_programs'] = (int)($mode_row['term_programs'] ?? 0);
    } else {
        $statistics['semester_programs'] = 0;
        $statistics['term_programs'] = 0;
    }

    // Fallback: if no programs join properly (study_mode column might not exist yet), use simple counts
    if ($statistics['semester_1_courses'] == 0 && $statistics['term_1_courses'] == 0 && $statistics['total_courses'] > 0) {
        $fallback_query = "SELECT semester, COUNT(*) as cnt FROM program_courses GROUP BY semester ORDER BY semester";
        $fallback_result = $db->query($fallback_query);
        if ($fallback_result) {
            while ($row = $fallback_result->fetch_assoc()) {
                $s = (int)$row['semester'];
                $c = (int)$row['cnt'];
                if ($s <= 2) {
                    $statistics['semester_' . $s . '_courses'] = $c;
                } else {
                    $statistics['term_' . $s . '_courses'] = $c;
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'statistics' => $statistics
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
