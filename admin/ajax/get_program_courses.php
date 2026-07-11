<?php
/**
 * get_program_courses.php
 *
 * Returns the courses attached to a program for a given year of study and
 * semester, together with the number of students who currently hold an active
 * registration in each course. Used by admin/print_registers.php to populate
 * the course dropdown and show live registration counts.
 *
 * Method: GET
 * Params:
 *   program_code   (required) programs.program_code / program_courses.program_code
 *   year_of_study  (required) program_courses.year_of_study (1-?)
 *   semester       (required) program_courses.semester (1-2)
 *   register_type  (optional) 'semester' (default) or 'exam' — chooses which
 *                  registration table the student counts come from
 *   search         (optional) filters by course_code or course_name
 *   category       (optional) filters courses.category, 'all' = no filter
 *
 * Response: JSON array of
 *   { course_code, course_name, category, credits, year_of_study, semester, registered_students }
 */

require_once __DIR__ . "/../includes/admin.php";
require_once __DIR__ . "/../includes/register_types.php";
require_once dirname(__DIR__, 2) . "/includes/helpers/course_availability_helpers.php";
header('Content-Type: application/json');

// This endpoint only reads data, so a GET without a CSRF token is acceptable.
// Authentication is still enforced by includes/admin.php above.

try {
    $program_code = trim($_GET['program_code'] ?? '');
    $year         = $_GET['year_of_study'] ?? '';
    $semester     = $_GET['semester'] ?? '';
    $register_type = wuc_register_normalise_type($_GET['register_type'] ?? 'semester');
    $search       = trim($_GET['search'] ?? '');
    $category     = trim($_GET['category'] ?? 'all');

    // Validate required, numeric-ish parameters.
    if ($program_code === '' || $year === '' || $semester === '') {
        http_response_code(400);
        echo json_encode(['error' => 'program_code, year_of_study and semester are required']);
        exit;
    }
    if (!ctype_digit((string)$year) || !ctype_digit((string)$semester)) {
        http_response_code(400);
        echo json_encode(['error' => 'year_of_study and semester must be numeric']);
        exit;
    }
    $year     = (int)$year;
    $semester = (int)$semester;

    // The roster source (course_registration vs exam_registration) is decided by the
    // register type's config. The count subquery is scoped both to the course/period
    // AND to the program, because a shared course (e.g. a general-education unit) can
    // be registered by students from several programs. Program membership is matched
    // through student_program, with a fallback to the denormalised students.program.
    $type_config = wuc_register_type_config($register_type);
    $source      = $type_config['source'] ?? 'course'; // 'course' or 'exam'
    $countSql    = wuc_register_count_subquery($source);

    $pcCols = wuc_course_availability_columns($db, 'program_courses');
    $where = [
        'TRIM(UPPER(pc.program_code)) = TRIM(UPPER(?))',
        'pc.year = ?',
    ];
    $types  = "si";
    $params = [$program_code, $year];
    $periodFilter = wuc_course_availability_period_filter($pcCols, 'pc', $pcCols['semester'] ?? null, $semester);
    if ($periodFilter['sql'] !== '1=1') {
        $where[] = $periodFilter['sql'];
        $types .= $periodFilter['types'];
        $params = array_merge($params, $periodFilter['params']);
    }

    $sql = "SELECT pc.course_code,
                   c.course_name,
                   c.category,
                   c.credits,
                   pc.year,
                   pc.semester,
                   {$countSql} AS registered_students
              FROM program_courses pc
              LEFT JOIN courses c ON TRIM(UPPER(c.course_code)) = TRIM(UPPER(pc.course_code))
             WHERE " . implode(' AND ', $where);

    if ($search !== '') {
        $sql .= " AND (pc.course_code LIKE ? OR c.course_name LIKE ?)";
        $like = '%' . $search . '%';
        $types .= "ss";
        $params[] = $like;
        $params[] = $like;
    }

    if ($category !== '' && strtolower($category) !== 'all') {
        $sql .= " AND c.category = ?";
        $types .= "s";
        $params[] = $category;
    }

    $sql .= " ORDER BY pc.course_code";

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $courses = [];
    while ($row = $result->fetch_assoc()) {
        $courses[] = [
            'course_code'         => $row['course_code'],
            'course_name'         => $row['course_name'],
            'category'            => $row['category'],
            'credits'             => $row['credits'] !== null ? (int)$row['credits'] : null,
            'year_of_study'       => (int)$row['year'],
            'semester'            => (int)$row['semester'],
            'registered_students' => (int)$row['registered_students'],
        ];
    }
    $stmt->close();

    echo json_encode($courses);
} catch (Throwable $e) {
    error_log('get_program_courses error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load courses. Please try again.']);
}
