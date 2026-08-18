<?php
/**
 * Structural / display contracts for student continuousAssessment UI.
 * Does not reimplement the page — inspects shipped files and optional live render path.
 *
 * Usage: C:\xampp\php\php.exe tests/student_ca_style/run.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
chdir($root);

$failures = 0;
$assert = static function (bool $ok, string $label, string $detail = '') use (&$failures): void {
    if ($ok) {
        echo "PASS: $label" . ($detail !== '' ? " | $detail" : '') . "\n";
        return;
    }
    $failures++;
    echo "FAIL: $label" . ($detail !== '' ? " | $detail" : '') . "\n";
};

echo 'RUN_TS=' . date('c') . "\n";

$pagePath = $root . '/students/continuousAssessment.php';
$cssPath = $root . '/students/css/continuous-assessment.css';
$tablePath = $root . '/students/includes/ca_annual_results_table.php';
$helpersPath = $root . '/students/includes/continuous_assessment_helpers.php';

$assert(is_file($pagePath), 'file.page');
$assert(is_file($cssPath), 'file.css');
$assert(is_file($tablePath), 'file.table_include');
$assert(is_file($helpersPath), 'file.helpers');

$page = (string)file_get_contents($pagePath);
$css = (string)file_get_contents($cssPath);
$table = (string)file_get_contents($tablePath);
$helpers = (string)file_get_contents($helpersPath);

// Page links the stylesheet
$assert(
    str_contains($page, 'continuous-assessment.css')
        || str_contains($page, '/students/css/continuous-assessment.css'),
    'page.links_ca_stylesheet'
);

// Stats have visible labels (not icon-only)
$assert(str_contains($page, 'ca-stat-label'), 'page.stat_labels_present');
$assert(str_contains($page, 'Courses') && str_contains($page, 'Published') && str_contains($page, 'Awaiting'), 'page.stat_label_text');

// Legend explains empty cells
$assert(str_contains($page, 'ca-legend'), 'page.legend_markup');
$assert(
    str_contains($page, 'How to read')
        && (str_contains($page, 'Not published') || str_contains($page, 'not published')),
    'page.legend_explains_empty'
);

// CSS: no body-level scroll lock for ca-page
$assert(
    !preg_match('/body\.ca-page\s*\{[^}]*overflow\s*:\s*hidden/s', $css),
    'css.no_body_overflow_hidden'
);
$assert(
    !preg_match('/body\.ca-page\s+\.ca-main\.content-wrapper\s*\{[^}]*height\s*:\s*100vh/s', $css),
    'css.no_main_100vh_lock'
);
$assert(
    (bool)preg_match('/overflow-y\s*:\s*auto/', $css),
    'css.page_scroll_enabled'
);
$assert(
    (bool)preg_match('/\.ca-table-wrap\s*\{[^}]*overflow-x\s*:\s*auto/s', $css),
    'css.table_horizontal_scroll'
);

// Table headers readable
$assert(str_contains($table, 'Final CA') || str_contains($table, 'ca-final-heading'), 'table.final_ca_header');
$assert(str_contains($table, 'Course name') || str_contains($table, 'ca-name-column'), 'table.course_name_header');

// Score helpers use consistent empty placeholder
$assert(
    str_contains($helpers, 'student_ca_render_component_score_html')
        && (str_contains($helpers, 'Not published') || str_contains($helpers, '—') || str_contains($helpers, 'missing')),
    'helpers.empty_score_treatment'
);

// Live helper still works (display path)
require_once $helpersPath;
$assert(function_exists('student_ca_render_component_score_html'), 'helpers.fn_component');
$emptyHtml = student_ca_render_component_score_html(null);
$scoreHtml = student_ca_render_component_score_html(12.5);
$assert(
    str_contains($emptyHtml, 'missing') || str_contains($emptyHtml, '—') || str_contains($emptyHtml, '-'),
    'helpers.empty_html',
    $emptyHtml
);
$assert(
    str_contains($scoreHtml, '12') || str_contains($scoreHtml, '12.5'),
    'helpers.score_html',
    $scoreHtml
);
$assert(
    $emptyHtml !== $scoreHtml,
    'helpers.published_vs_empty_distinct'
);

// Optional: if DB available, ensure page data path still non-fatal for a SID
try {
    require_once $root . '/db/connect.php';
    if (isset($db) && $db instanceof mysqli) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        require_once $root . '/students/includes/period_mode_helper.php';
        require_once $root . '/students/includes/RegistrationDataService.php';
        require_once $root . '/students/includes/AcademicSessionService.php';
        require_once $root . '/includes/short_course_student.php';

        $r = $db->query(
            "SELECT s.SID FROM students s
             INNER JOIN student_login sl ON s.SID = sl.Sid
             INNER JOIN student_program sp ON s.SID = sp.Sid
             LIMIT 1"
        );
        $sid = ($r && ($row = $r->fetch_assoc())) ? (string)$row['SID'] : '';
        if ($sid !== '') {
            $student = student_ca_fetch_student_profile($db, $sid);
            $assert(is_array($student), 'live.profile', $sid);
            $years = student_ca_academic_year_options($db, $sid, date('Y'));
            $assert(is_array($years), 'live.year_options', implode(',', $years));
            $yos = student_ca_resolve_year_of_study($db, $sid, (string)($years[0] ?? date('Y')));
            $periods = student_ca_period_columns($db, (string)($student['program_code'] ?? ''), getStudentProgramPeriodMode($db, $sid));
            $assert(!empty($periods['periods']), 'live.periods', implode(',', $periods['periods'] ?? []));
            $reg = new RegistrationDataService($db);
            $courses = $reg->getRegisteredCourses($sid, (int)$yos, 0, null, (string)($years[0] ?? date('Y')), 'year');
            $map = student_ca_fetch_period_components_map($db, $sid, (string)($years[0] ?? date('Y')), $yos, $periods['periods']);
            $records = student_ca_build_annual_records($courses, $map, $periods['periods'], $yos);
            $assert(is_array($records), 'live.annual_records', 'rows=' . count($records));
        } else {
            $assert(true, 'live.profile', 'no_sid_skip');
        }
    }
} catch (Throwable $e) {
    $assert(false, 'live.path', $e->getMessage());
}

echo "\n=== SUMMARY ===\n";
if ($failures > 0) {
    echo "RESULT=FAIL failures=$failures\n";
    exit(1);
}
echo "RESULT=OK\n";
exit(0);
