<?php
$page_title = 'Lecturer Dashboard';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/academic_risk_engine.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_period_helpers.php';
require_once dirname(__DIR__) . '/includes/helpers/lecturer_course_helpers.php';
require "includes/nav.php";

// Define root URL for links
$root_url = '/wucportal';

// Helper functions for flexible schema detection
$tableExists = function(mysqli $db, string $table): bool {
    if ($res = $db->query("SHOW TABLES LIKE '".$db->real_escape_string($table)."'")) {
        $exists = $res->num_rows > 0; $res->free(); return $exists;
    }
    return false;
};

$detectColumn = function(mysqli $db, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        if ($res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '".$db->real_escape_string($col)."'")) {
            if ($res->num_rows > 0) { $res->free(); return $col; }
            $res->free();
        }
    }
    return null;
};

// Initialize stats
$courseCount = 0;
$studentCount = 0;
$pendingAssessments = 0;
$postedCaCount = 0;
$assignedCourseCodes = [];
$aiLecturerRiskSummary = null;
$aiLecturerAlerts = [];

if (isset($_SESSION['staff_id'])) {
    // Detect flexible table/column names for course assignments and enrollments
    $lecturerCourseTable = null;
    if ($tableExists($db, 'course_lecturer')) { $lecturerCourseTable = 'course_lecturer'; }

    $studentCourseTable = null;
    if ($tableExists($db, 'student_course')) { $studentCourseTable = 'student_course'; }
    elseif ($tableExists($db, 'student_courses')) { $studentCourseTable = 'student_courses'; }

    // Common columns
    $lcStaffCol = $lecturerCourseTable ? $detectColumn($db, $lecturerCourseTable, ['staff_id','lecturer_id','staffId']) : null;
    $lcCourseCol = $lecturerCourseTable ? $detectColumn($db, $lecturerCourseTable, ['course_code','code']) : null;
    $scStudentCol = $studentCourseTable ? $detectColumn($db, $studentCourseTable, ['Sid','SID','student_id','studentID']) : null;
    $scCourseCol = $studentCourseTable ? $detectColumn($db, $studentCourseTable, ['course_code','code']) : null;
    $studentsIdCol = $detectColumn($db, 'students', ['SID','Sid','student_id','studentID']);

    // Count distinct courses assigned to this lecturer.
    if ($lecturerCourseTable && $lcStaffCol && $lcCourseCol) {
        $staffId = (string)$_SESSION['staff_id'];
        $statusFilter = '';
        if ($statusCol = $detectColumn($db, $lecturerCourseTable, ['status','state'])) {
            $statusFilter = " AND COALESCE(cl.`{$statusCol}`, 'active') <> 'inactive'";
        }
        // INNER JOIN courses so the "My Courses" stat counts only assignments that
        // resolve to a real catalogue course — matching the "My Assigned Courses"
        // table below (which also INNER JOINs courses). Without this the stat
        // over-counts orphan codes such as EL900/EL907 that exist in
        // course_lecturer but were never inserted into `courses`, making the stat
        // card disagree with the table it links to.
        $stmt = $db->prepare("SELECT DISTINCT cl.`{$lcCourseCol}` AS course_code
                               FROM `{$lecturerCourseTable}` cl
                               INNER JOIN courses c ON c.course_code = cl.`{$lcCourseCol}`
                               WHERE cl.`{$lcStaffCol}` = ?{$statusFilter}");
        if ($stmt) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $code = trim((string)($row['course_code'] ?? ''));
                if ($code !== '') { $assignedCourseCodes[] = $code; }
            }
            $stmt->close();
            $courseCount = count($assignedCourseCodes);
        }
    }

    // Count distinct students enrolled in this lecturer's courses via course_registration
    // (the canonical enrollment table; student_courses only has partial data).
    if ($lecturerCourseTable && $lcStaffCol) {
        $staffId = (string)$_SESSION['staff_id'];
        $stmt = $db->prepare("SELECT COUNT(DISTINCT cr.Sid) AS total
                               FROM course_registration cr
                               INNER JOIN `{$lecturerCourseTable}` lc ON cr.course_code = lc.`{$lcCourseCol}`
                               WHERE lc.`{$lcStaffCol}` = ?");
        if ($stmt) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_object();
            if ($row) { $studentCount = (int)$row->total; }
            $stmt->close();
        }
    }
    
    // Count pending assessments
    $caTable = null;
    if ($tableExists($db, 'continuous_assessment')) { $caTable = 'continuous_assessment'; }
    elseif ($tableExists($db, 'assessments')) { $caTable = 'assessments'; }
    if ($caTable) {
        $lecCol = $detectColumn($db, $caTable, ['lecturer_id','staff_id','instructor_id']);
        $statusCol = $detectColumn($db, $caTable, ['status','state']);
        if ($lecCol) {
            $sidEsc = $db->real_escape_string($_SESSION['staff_id']);
            $sqlCa = "SELECT COUNT(*) AS total FROM `{$caTable}` WHERE `{$lecCol}` = '{$sidEsc}'";
            if ($statusCol) { $sqlCa .= " AND `{$statusCol}` = 'Pending'"; }
            if($assessmentQuery = $db->query($sqlCa)) {
                if($assessmentQuery->num_rows > 0) { $pendingAssessments = (int)$assessmentQuery->fetch_object()->total; }
                $assessmentQuery->free();
            }
        }
    }

    if ($tableExists($db, 'semester_assessment')) {
        $sidEsc = $db->real_escape_string($_SESSION['staff_id']);
        $postedCol = $detectColumn($db, 'semester_assessment', ['posted_by','lecturer_id','staff_id']);
        if ($postedCol) {
            $sqlPostedCa = "SELECT COUNT(*) AS total FROM semester_assessment WHERE `{$postedCol}` = '{$sidEsc}'";
            if($postedCaQuery = $db->query($sqlPostedCa)) {
                if($postedCaQuery->num_rows > 0) { $postedCaCount = (int)$postedCaQuery->fetch_object()->total; }
                $postedCaQuery->free();
            }
        } elseif (!empty($assignedCourseCodes)) {
            $courseList = "'" . implode("','", array_map([$db, 'real_escape_string'], array_unique($assignedCourseCodes))) . "'";
            if($postedCaQuery = $db->query("SELECT COUNT(*) AS total FROM semester_assessment WHERE Course_Code IN ({$courseList})")) {
                if($postedCaQuery->num_rows > 0) { $postedCaCount = (int)$postedCaQuery->fetch_object()->total; }
                $postedCaQuery->free();
            }
        }
    }

    try {
        $aiLecturerRiskSummary = wuc_academic_risk_lecturer_summary($db, (string)$_SESSION['staff_id']);
        $aiLecturerAlerts = wuc_academic_risk_lecturer_alerts($db, (string)$_SESSION['staff_id']);
    } catch (Throwable $e) {
        error_log('lecturers/index.php AI teaching insight failed: ' . $e->getMessage());
        $aiLecturerRiskSummary = null;
        $aiLecturerAlerts = [];
    }

    // Deterministic class insights + missing-CA task alerts (rules, no LLM).
    try {
        require_once dirname(__DIR__) . '/includes/lecturer_insights_engine.php';
        $lecturerInsights = wuc_lecturer_insights($db, (string)$_SESSION['staff_id']);
        wuc_lecturer_generate_task_alerts($db, (string)$_SESSION['staff_id'], $lecturerInsights);
        $lecturerTaskAlerts = wuc_lecturer_open_task_alerts($db, (string)$_SESSION['staff_id']);
    } catch (Throwable $e) {
        error_log('lecturers/index.php class insights failed: ' . $e->getMessage());
        $lecturerInsights = ['courses' => [], 'totals' => []];
        $lecturerTaskAlerts = [];
    }
}

// Configure unified dashboard
$dashboard_title = 'Lecturer Dashboard';
$dashboard_subtitle = 'Welcome to your teaching portal';
$user_role = 'Lecturer';
$user_role_class = 'bg-lecturer';
$header_section_class = 'lecturer-section';
$stat_icon_class = 'bg-lecturer';
$profile_link = 'viewStaff.php?view=' . ($_SESSION['staff_id'] ?? '');
$edit_profile_link = 'editStaff.php?update=' . ($_SESSION['staff_id'] ?? '');

// Stats cards — keep unique counts only. High-risk is shown in the AI Teaching
// Insight panel below, so it is not repeated here as a fifth stat card.
$stat_cards = [
    [
        'icon' => 'fas fa-book',
        'value' => number_format($courseCount),
        'label' => 'My Courses',
        'bg_class' => 'bg-lecturer',
        'link' => 'myCourses.php',
        'link_text' => 'View'
    ],
    [
        'icon' => 'fas fa-user-graduate',
        'value' => number_format($studentCount),
        'label' => 'My Students',
        'bg_class' => 'bg-info',
        'link' => 'myStudent.php',
        'link_text' => 'View'
    ],
    [
        'icon' => 'fas fa-tasks',
        'value' => number_format($pendingAssessments),
        'label' => 'Pending Assessments',
        'bg_class' => 'bg-warning',
        'link' => 'upload_ca.php',
        'link_text' => 'Open'
    ],
    [
        'icon' => 'fas fa-clipboard-check',
        'value' => number_format($postedCaCount),
        'label' => 'Posted CA Records',
        'bg_class' => 'bg-success',
        'link' => 'viewCaRes.php',
        'link_text' => 'Open'
    ],
];

// Build Lecturer Timetable and Courses List
$todayDay = date('l');
$timetableHtml = '';
$timetableRows = [];
$staffIdEsc = $db->real_escape_string($_SESSION['staff_id'] ?? '');

$sqlTimetable = "SELECT cs.*, ts.slot_name, ts.start_time, ts.end_time, c.course_name 
                 FROM course_schedule cs 
                 LEFT JOIN time_slots ts ON ts.id = cs.time_slot_id 
                 LEFT JOIN courses c ON c.course_code = cs.course_code 
                 WHERE (cs.lecturer_id = (SELECT id FROM staff WHERE staff_id = '{$staffIdEsc}' LIMIT 1) 
                        OR cs.course_code IN (SELECT course_code FROM course_lecturer WHERE staff_id = '{$staffIdEsc}' AND status <> 'inactive'))
                   AND cs.day_of_week = '{$todayDay}'
                 ORDER BY ts.start_time ASC";
if ($resTimetable = $db->query($sqlTimetable)) {
    while ($row = $resTimetable->fetch_assoc()) {
        $timetableRows[] = $row;
    }
    $resTimetable->free();
}

$timetableHtml = '
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="mb-0 text-purple fw-bold"><i class="fas fa-calendar-day me-2"></i>Today\'s Timetable (' . $todayDay . ')</h5>
    </div>
    <div class="card-body p-0">';
    
if (empty($timetableRows)) {
    $timetableHtml .= '
        <div class="p-4 text-center text-muted">
            <i class="fas fa-calendar-check fa-2x mb-2 text-muted"></i>
            <p class="mb-0">No classes scheduled for today.</p>
        </div>';
} else {
    $timetableHtml .= '
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Time Slot</th>
                        <th>Course</th>
                        <th>Room / Lab</th>
                        <th>Type</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>';
    foreach ($timetableRows as $t) {
        $timeLabel = ($t['slot_name'] ?? 'Class') . ' (' . date('g:i A', strtotime($t['start_time'] ?? '08:00:00')) . ' - ' . date('g:i A', strtotime($t['end_time'] ?? '09:00:00')) . ')';
        $roomLabel = !empty($t['room']) ? htmlspecialchars($t['room']) : 'Unassigned';
        $typeLabel = ucfirst(htmlspecialchars($t['schedule_type'] ?? 'lecture'));
        $timetableHtml .= '
                    <tr>
                        <td><strong>' . htmlspecialchars($timeLabel) . '</strong></td>
                        <td><span class="badge bg-light text-dark border">' . htmlspecialchars($t['course_code']) . '</span> ' . htmlspecialchars($t['course_name'] ?? '') . '</td>
                        <td><i class="fas fa-map-marker-alt text-muted me-1"></i> ' . $roomLabel . '</td>
                        <td><span class="badge bg-purple">' . $typeLabel . '</span></td>
                        <td><span class="text-success"><i class="fas fa-circle small me-1"></i> Active</span></td>
                    </tr>';
    }
    $timetableHtml .= '
                </tbody>
            </table>
        </div>';
}

$timetableHtml .= '
    </div>
</div>';

$assignedCoursesDetails = [];
// GROUP BY course_code deduplicates multiple course_lecturer rows for the same
// course (different programs/intakes). course_registration is used for enrolled
// student counts because student_courses only holds partial enrollment data.
// period_mode is resolved via program_courses → programs so term-based courses
// show "Term N" instead of the incorrect "Semester N".
$staffIdForDetails = (string)($_SESSION['staff_id'] ?? '');
$hasProgramCourses = $tableExists($db, 'program_courses') && $tableExists($db, 'programs');
require_once dirname(__DIR__) . '/includes/helpers/academic_period_helpers.php';
$periodModeSql = wuc_program_period_mode_sql('p');
$periodModeExpr = $hasProgramCourses
    ? "(SELECT {$periodModeSql} FROM program_courses pc
            JOIN programs p ON pc.program_code = p.program_code
            WHERE pc.course_code = cl.course_code LIMIT 1)"
    : "'semester'";
$stmtDetails = $db->prepare(
    "SELECT cl.course_code, c.course_name,
            MAX(cl.semester) AS semester,
            MAX(cl.academic_year) AS academic_year,
            (SELECT COUNT(DISTINCT cr.Sid)
             FROM course_registration cr
             WHERE cr.course_code = cl.course_code) AS enrolled_students,
            {$periodModeExpr} AS period_mode
     FROM course_lecturer cl
     INNER JOIN courses c ON c.course_code = cl.course_code
     WHERE cl.staff_id = ? AND cl.status <> 'inactive'
     GROUP BY cl.course_code, c.course_name
     ORDER BY cl.course_code"
);
if ($stmtDetails) {
    $stmtDetails->bind_param('s', $staffIdForDetails);
    $stmtDetails->execute();
    $resDetails = $stmtDetails->get_result();
    while ($row = $resDetails->fetch_assoc()) {
        $assignedCoursesDetails[] = $row;
    }
    $stmtDetails->close();
}

$coursesHtml = '
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-book-open me-2"></i>My Assigned Courses</h5>
    </div>
    <div class="card-body p-0">';
    
if (empty($assignedCoursesDetails)) {
    $coursesHtml .= '
        <div class="p-4 text-center text-muted">
            <p class="mb-0">No courses assigned to you.</p>
        </div>';
} else {
    $coursesHtml .= '
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Course Code</th>
                        <th>Course Name</th>
                        <th>Enrolled Students</th>
                        <th>Term / Semester</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>';
    foreach ($assignedCoursesDetails as $c) {
        $coursesHtml .= '
                    <tr>
                        <td><strong>' . htmlspecialchars($c['course_code']) . '</strong></td>
                        <td>' . htmlspecialchars($c['course_name']) . '</td>
                        <td><span class="badge bg-info">' . (int)$c['enrolled_students'] . ' students</span></td>
                        <td>' . (($c['period_mode'] ?? 'semester') === 'term' ? 'Term' : 'Semester') . ' ' . htmlspecialchars($c['semester'] ?? '1') . '</td>
                        <td>
                            <a href="/wucportal/lecturers/myStudent.php?course_code=' . urlencode($c['course_code']) . '" class="btn btn-sm btn-purple me-1"><i class="fas fa-user-graduate"></i> Students</a>
                            <a href="/wucportal/lecturers/upload_ca.php?course_code=' . urlencode($c['course_code']) . '" class="btn btn-sm btn-outline-purple"><i class="fas fa-upload"></i> Upload CA</a>
                        </td>
                    </tr>';
    }
    $coursesHtml .= '
                </tbody>
            </table>
        </div>';
}

$coursesHtml .= '
    </div>
</div>';

$extra_dashboard_content = '';
if (function_exists('wuc_lecturer_setup_notice')) {
    $extra_dashboard_content .= wuc_lecturer_setup_notice($db, (string)($_SESSION['staff_id'] ?? ''));
}
if (!empty($lecturerInsights['courses']) && function_exists('wuc_lecturer_insights_render_panel')) {
    $extra_dashboard_content .= wuc_lecturer_insights_render_panel($lecturerInsights, $lecturerTaskAlerts ?? []);
}
// eLearning engagement panel (Sprint 10): per-course progress + inactive learners.
try {
    require_once dirname(__DIR__) . '/includes/elearning_insights_engine.php';
    $lecturerEngagement = wuc_el_lecturer_engagement($db, (string)($_SESSION['staff_id'] ?? ''));
    $extra_dashboard_content .= wuc_el_render_lecturer_engagement($lecturerEngagement);
} catch (Throwable $e) {
    error_log('lecturers/index.php elearning engagement failed: ' . $e->getMessage());
}
if ($aiLecturerRiskSummary !== null) {
    $extra_dashboard_content .= wuc_academic_risk_render_lecturer_panel($aiLecturerRiskSummary);
    // Only show the alerts card when there are open alerts — an empty "0 open"
    // card is noise next to the insight panel above.
    if (!empty($aiLecturerAlerts)) {
        $extra_dashboard_content .= wuc_academic_risk_render_alerts_panel($aiLecturerAlerts, 'AI Learner Alerts');
    }
}
$extra_dashboard_content .= $timetableHtml . $coursesHtml;

// No static welcome announcement — it duplicated the page subtitle and added
// no actionable information. Real notices come from setup/insights panels below.
$show_announcements = false;
$announcements = [];

// Quick Actions removed: every link already exists as a stat-card action, a
// sidebar nav item, or a row action in "My Assigned Courses" / Today's Timetable.
$quick_modules = [];

// Include the unified dashboard template
require_once dirname(__DIR__) . '/includes/dashboard_template.php';
require_once __DIR__ . '/includes/footer.php';
?>
