<?php
error_reporting(0);

require_once __DIR__ . '/guard.php';
require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/audit.php';
require_once dirname(__DIR__, 2) . '/includes/short_course_student.php';
require_once dirname(__DIR__, 2) . '/includes/student_program_portal.php';
require_once dirname(__DIR__, 2) . '/includes/city_guilds_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/portal_context.php';
require_once dirname(__DIR__, 2) . '/includes/portal_switch.php';
require_once __DIR__ . '/period_mode_helper.php';

$navStudentId = (string)($_SESSION['Sid'] ?? '');
$navRequestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
$navPath = (string)(parse_url($navRequestUri, PHP_URL_PATH) ?: '');
$navScript = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$studentPortalContext = wuc_current_portal_context(wuc_portal_context_from_request($navRequestUri));
$studentInElearningPortal = $studentPortalContext === 'student_elearning';
$studentInAcademicPortal = !$studentInElearningPortal;
$navProgramPortal = (!$studentInElearningPortal && $navStudentId !== '' && isset($db) && $db instanceof mysqli)
    ? wuc_student_program_portal_profile($db, $navStudentId)
    : null;
$studentDashboardHref = $studentInElearningPortal
    ? '/wucportal/students/elearning/index.php'
    : (string)($navProgramPortal['route'] ?? '/wucportal/students/index.php');
$studentDashboardLabel = $studentInElearningPortal
    ? 'Learning Dashboard'
    : (string)($navProgramPortal['dashboard_label'] ?? 'Dashboard');
$navAcademicSectionTitle = match ((string)($navProgramPortal['type'] ?? 'academic')) {
    'certificate' => 'Certificate academics',
    'diploma' => 'Diploma academics',
    'trade_test' => 'Trade test',
    'short_course' => 'Short courses',
    'degree' => 'Degree academics',
    'postgraduate' => 'Postgraduate academics',
    default => !empty($navProgramPortal['label']) ? str_replace(' Portal', ' academics', (string)$navProgramPortal['label']) : 'Academics',
};
$navIsCertificatePortal = (string)($navProgramPortal['type'] ?? '') === 'certificate';

$navPeriodLabel = 'Semester';
$navIsShortCourse = false;
$navHasShortCourses = false;
$navHasExamTranscript = false;
$navHasCaReport = false;
$navHasExternalRegistration = false;
$navHasCityGuilds = false;
$navReportLabel = 'Exam Transcript';
$navHasElearning = $studentInElearningPortal;

if ($navStudentId !== '' && isset($db) && $db instanceof mysqli) {
    if (function_exists('getPeriodLabel')) {
        $navPeriodLabel = getPeriodLabel($db, $navStudentId);
    }

    if (!function_exists('student_nav_table_exists')) {
        function student_nav_table_exists(mysqli $db, string $table): bool
        {
            $safeTable = $db->real_escape_string($table);
            $result = @$db->query("SHOW TABLES LIKE '{$safeTable}'");
            if (!$result) {
                return false;
            }
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }
    }

    if (!function_exists('student_nav_has_rows_for_sid')) {
        function student_nav_has_rows_for_sid(mysqli $db, string $table, string $sidColumn, string $sid): bool
        {
            if ($sid === '' || !student_nav_table_exists($db, $table)) {
                return false;
            }
            $sql = "SELECT 1 FROM `{$table}` WHERE `{$sidColumn}` COLLATE utf8mb4_general_ci = ? LIMIT 1";
            $stmt = @$db->prepare($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $stmt->store_result();
            $found = $stmt->num_rows > 0;
            $stmt->close();
            return $found;
        }
    }

    if (!isset($_SESSION['nav_flags']) || !is_array($_SESSION['nav_flags']) || (int)($_SESSION['nav_flags_at'] ?? 0) < time() - 600) {
        $portalMode = function_exists('sc_student_portal_mode')
            ? sc_student_portal_mode($db, $navStudentId)
            : ((function_exists('isShortCourseStudent') && isShortCourseStudent($db, $navStudentId)) ? 'short_course' : 'long_program');
        $hasScEnrol = function_exists('sc_student_enrolments') && sc_student_enrolments($db, $navStudentId) !== [];
        $_SESSION['nav_flags'] = [
            // Primary portal: short-course-only students never share long-term nav.
            'isShortCourse' => $portalMode === 'short_course',
            'isLongProgram' => $portalMode === 'long_program',
            // Secondary short-course link only for long-programme students who also enrol SC.
            'hasShortCourses' => $portalMode === 'long_program' && $hasScEnrol,
            'hasExamTranscript' => student_nav_has_rows_for_sid($db, 'exams', 'Sid', $navStudentId),
            'hasCaReport' => student_nav_has_rows_for_sid($db, 'semester_assessment', 'Sid', $navStudentId),
            'hasExternalReg' => student_nav_has_rows_for_sid($db, 'exam_registration', 'Sid', $navStudentId),
            'hasCityGuilds' => function_exists('cg_student_has_access') && cg_student_has_access($db, $navStudentId),
        ];
        $_SESSION['nav_flags_at'] = time();
    }

    $navFlags = $_SESSION['nav_flags'] ?? [];
    $navIsShortCourse = !empty($navFlags['isShortCourse']);
    $navIsLongProgram = !empty($navFlags['isLongProgram']);
    $navHasShortCourses = !empty($navFlags['hasShortCourses']);
    $navHasExamTranscript = !empty($navFlags['hasExamTranscript']);
    $navHasCaReport = !empty($navFlags['hasCaReport']);
    $navHasExternalRegistration = !empty($navFlags['hasExternalReg']);
    $navHasCityGuilds = !empty($navFlags['hasCityGuilds']);

    // Enrolments can be added or removed by staff while a student is logged in.
    // Refresh this data-backed flag on every request so the Short Courses link
    // does not remain visible for up to ten minutes after an enrolment removal.
    if (function_exists('sc_student_enrolments')) {
        $navHasShortCourses = !$navIsShortCourse && sc_student_enrolments($db, $navStudentId) !== [];
        $_SESSION['nav_flags']['hasShortCourses'] = $navHasShortCourses;
    }

    // Session-scoped short-course portal view (dual-enrolled students who
    // switched via portal_view.php): force short-course nav mode regardless of
    // the cached flags, and send the Dashboard link to the short-course home.
    $navPortalViewShort = (($_SESSION['student_portal_view'] ?? '') === 'short_course')
        && function_exists('sc_student_has_long_program')
        && sc_student_has_long_program($db, $navStudentId)
        && function_exists('sc_student_enrolments')
        && sc_student_enrolments($db, $navStudentId) !== [];
    if ($navPortalViewShort) {
        $navIsShortCourse = true;
        $navHasShortCourses = false;
        $navAcademicSectionTitle = 'Short courses';
        $studentDashboardHref = '/wucportal/students/short_course_portal.php';
        $studentDashboardLabel = 'Short Course Dashboard';
    }

    if (function_exists('cg_student_has_access')) {
        $navHasCityGuilds = cg_student_has_access($db, $navStudentId);
        $_SESSION['nav_flags']['hasCityGuilds'] = $navHasCityGuilds;
    }

    if (function_exists('wuc_user_has_portal_access')) {
        $navUserIdDb = (int)($_SESSION['user_id_db'] ?? 0);
        $navHasElearning = $navHasElearning || ($navUserIdDb > 0 && wuc_user_has_portal_access($db, $navUserIdDb, 'elearning'));
    }
}

if (!$navHasExamTranscript && ($navHasCaReport || $navHasExternalRegistration)) {
    $navReportLabel = 'CA Report';
}

if (function_exists('audit_log_page_view') && isset($db) && $db instanceof mysqli) {
    audit_log_page_view($db);
}

if ($navStudentId !== '' && (!isset($_SESSION['student_name']) || !isset($_SESSION['student_profile_image'])) && isset($db) && $db instanceof mysqli) {
    $stmtNav = $db->prepare("SELECT Fname, Lname, profile_image FROM students WHERE SID = ?");
    if ($stmtNav) {
        $stmtNav->bind_param('s', $navStudentId);
        $stmtNav->execute();
        $resNav = $stmtNav->get_result();
        if ($rowNav = $resNav->fetch_assoc()) {
            $_SESSION['student_name'] = trim((string)$rowNav['Fname'] . ' ' . (string)$rowNav['Lname']);
            $_SESSION['student_profile_image'] = (string)($rowNav['profile_image'] ?? '');
        }
        $stmtNav->close();
    }
}

function student_nav_active(string $target, string $navScript, string $navPath): string
{
    $dashboardPages = [
        'index.php',
        'short_course_portal.php',
        'diploma_portal.php',
        'certificate_portal.php',
        'trade_test_portal.php',
        'degree_portal.php',
        'postgraduate_portal.php',
        'program_portal.php',
    ];
    $targetBasename = basename($target);
    if (in_array($targetBasename, $dashboardPages, true) && in_array($navScript, $dashboardPages, true)) {
        return 'active';
    }
    if (strpos($target, '/') !== false) {
        return substr(str_replace('\\', '/', $navPath), -strlen($target)) === $target ? 'active' : '';
    }
    return $navScript === $target ? 'active' : '';
}
?>

<link href="/wucportal/assets/vendor/bootstrap/5.3.2/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="/wucportal/css/ui-portal.css">
<link rel="stylesheet" href="/wucportal/css/admin-style.css">
<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
<link rel="stylesheet" href="/wucportal/css/project-reusable.css">
<link rel="stylesheet" href="/wucportal/assets/css/main.css">
<link rel="stylesheet" href="/wucportal/assets/css/dashboard.css">
<!-- forms.css and tables.css are @imported by main.css above; not re-linked. -->
<!-- students-sidebar.css @imports unified-sidebar.css; do not also load css/sidebar.css (legacy light theme). -->
<link rel="stylesheet" href="/wucportal/students/css/students-sidebar.css?v=20260712-dashboard-responsive-v1">
<link rel="stylesheet" href="/wucportal/css/typography-override.css">
<link rel="stylesheet" href="/wucportal/css/consistent-styles.css">
<link rel="stylesheet" href="/wucportal/assets/vendor/fontawesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/wucportal/css/wuc-premium.css?v=20260613">
<!-- Keep the student design-system layer last so every student route uses the
     same timetable-derived shell, controls, cards, tables, and responsive rules. -->
<link rel="stylesheet" href="/wucportal/students/css/student-unified.css?v=20260720-shell-v2">
<script src="/wucportal/js/wuc-premium.js?v=20260613" defer></script>
<script src="/wucportal/js/wuc-print-fit.js?v=20260613" defer></script>
<!-- Collapsible sidebar categories (shared controller; same behaviour as staff modules) -->
<script src="/wucportal/js/sidebar-collapsible.js?v=20260708" defer></script>
<link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css?v=20260613">

<script>
(function(){
try {
  if (document.body) {
    document.body.classList.add('student-portal', 'has-unified-sidebar', 'portal-context-<?php echo htmlspecialchars($studentPortalContext, ENT_QUOTES, 'UTF-8'); ?>');
  }
  var head = document.head || document.getElementsByTagName('head')[0];
  if (head && !head.querySelector('meta[name="viewport"]')) {
    var viewport = document.createElement('meta');
    viewport.name = 'viewport';
    viewport.content = 'width=device-width, initial-scale=1';
    head.appendChild(viewport);
  }
  if (head && !head.querySelector('base')) {
    var b = document.createElement('base');
    b.href = window.location.origin + '/wucportal/students/';
    head.insertBefore(b, head.firstChild);
  }
} catch (e) {}
})();
</script>

<button type="button" class="sidebar-toggle" aria-label="Open sidebar" aria-controls="studentSidebar" aria-expanded="false">
    <i class="fas fa-bars"></i>
</button>
<div class="sidebar-backdrop" data-student-sidebar-backdrop></div>

<nav class="sidebar" id="studentSidebar">
    <div class="sidebar-header">
        <div class="logo-container">
            <img src="/wucportal/images/favicon.png" alt="ITC Logo" class="logo">
            <span class="logo-text">ITC</span>
        </div>
    </div>

    <div class="sidebar-content">
        <div class="nav-section">
            <div class="nav-section-title">Main</div>
            <a href="<?php echo htmlspecialchars($studentDashboardHref, ENT_QUOTES, 'UTF-8'); ?>" class="nav-item <?php echo student_nav_active($studentInElearningPortal ? '/students/elearning/index.php' : basename($studentDashboardHref), $navScript, $navPath); ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span><?php echo htmlspecialchars($studentDashboardLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <a href="/wucportal/notifications.php" class="nav-item <?php echo student_nav_active('/notifications.php', $navScript, $navPath); ?>">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <?php
                $navStudentUnreadAlerts = 0;
                if ($navStudentId !== '' && isset($db) && $db instanceof mysqli) {
                    if (!function_exists('wuc_portal_alerts_unread_count')) {
                        require_once dirname(__DIR__, 2) . '/includes/portal_alerts.php';
                    }
                    if (!function_exists('wuc_portal_alerts_sync_sources')) {
                        require_once dirname(__DIR__, 2) . '/includes/notification_integrations.php';
                    }
                    // Throttle the per-request notification sync (several reads/writes)
                    // to at most once per 45s per session; the unread count below still
                    // runs every request so the badge stays current to the last sync.
                    if (time() - (int)($_SESSION['wuc_alerts_last_sync_student'] ?? 0) >= 45) {
                        wuc_portal_alerts_sync_sources($db, $navStudentId, 'student');
                        wuc_portal_alerts_sync_student_documents($db, $navStudentId);
                        $_SESSION['wuc_alerts_last_sync_student'] = time();
                    }
                    $navStudentUnreadAlerts = wuc_portal_alerts_unread_count($db, $navStudentId, 'student');
                }
                if ($navStudentUnreadAlerts > 0): ?>
                    <span class="badge bg-danger ms-auto"><?php echo htmlspecialchars((string)$navStudentUnreadAlerts, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </a>
        </div>

        <?php if ($studentInAcademicPortal): ?>
        <div class="nav-section">
            <div class="nav-section-title"><?php echo htmlspecialchars($navAcademicSectionTitle, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if ($navIsShortCourse): ?>
            <?php // Short-course portal — do not expose long-term term registration / annual CA. ?>
            <a href="/wucportal/students/short_courses.php" class="nav-item <?php echo student_nav_active('short_courses.php', $navScript, $navPath); ?>"><i class="fas fa-certificate"></i><span>My Short Courses</span></a>
            <a href="/wucportal/students/registration.php" class="nav-item <?php echo student_nav_active('registration.php', $navScript, $navPath); ?>"><i class="fas fa-id-card"></i><span>Enrolment Status</span></a>
            <a href="/wucportal/students/continuousAssessment.php" class="nav-item <?php echo student_nav_active('continuousAssessment.php', $navScript, $navPath); ?>"><i class="fas fa-chart-line"></i><span>Short Course CA</span></a>
            <?php else: ?>
            <?php // Long-term academic portal. ?>
            <a href="/wucportal/students/registration.php" class="nav-item <?php echo student_nav_active('registration.php', $navScript, $navPath); ?>"><i class="fas fa-user-graduate"></i><span><?php echo htmlspecialchars($navPeriodLabel, ENT_QUOTES, 'UTF-8'); ?> Registration</span></a>
            <a href="/wucportal/students/courseReg.php" class="nav-item <?php echo student_nav_active('courseReg.php', $navScript, $navPath); ?>"><i class="fas fa-clipboard-list"></i><span>Course Enrolment</span></a>
            <a href="/wucportal/students/ai_course_advisor.php" class="nav-item <?php echo student_nav_active('ai_course_advisor.php', $navScript, $navPath); ?>"><i class="fas fa-wand-magic-sparkles"></i><span>AI Course Advisor</span></a>
            <a href="/wucportal/students/learning_assistant.php" class="nav-item <?php echo student_nav_active('learning_assistant.php', $navScript, $navPath); ?>"><i class="fas fa-graduation-cap"></i><span>My Learning Assistant</span></a>
            <a href="/wucportal/students/myCourses.php" class="nav-item <?php echo student_nav_active('myCourses.php', $navScript, $navPath); ?>"><i class="fas fa-book-open"></i><span>My Courses</span></a>
            <a href="/wucportal/students/continuousAssessment.php" class="nav-item <?php echo student_nav_active('continuousAssessment.php', $navScript, $navPath); ?>"><i class="fas fa-chart-line"></i><span>Continuous Assessment</span></a>
            <a href="/wucportal/students/examTranscript.php" class="nav-item <?php echo student_nav_active('examTranscript.php', $navScript, $navPath); ?>"><i class="fas fa-file-alt"></i><span><?php echo htmlspecialchars($navReportLabel, ENT_QUOTES, 'UTF-8'); ?></span></a>
            <a href="/wucportal/students/timetable.php" class="nav-item <?php echo student_nav_active('timetable.php', $navScript, $navPath); ?>"><i class="fas fa-calendar-alt"></i><span>My Timetable</span></a>
            <?php
            $navShowTestTimetable = false;
            if (isset($db) && $db instanceof mysqli) {
                require_once dirname(__DIR__, 2) . '/includes/test_timetable.php';
                $navShowTestTimetable = tt_current_student_visible_period($db) !== null;
            }
            if ($navShowTestTimetable):
            ?>
            <a href="/wucportal/students/test_timetable.php" class="nav-item <?php echo student_nav_active('test_timetable.php', $navScript, $navPath); ?>"><i class="fas fa-calendar-check"></i><span>Test Timetable</span></a>
            <?php endif; ?>
            <a href="/wucportal/students/previous_test_timetables.php" class="nav-item <?php echo student_nav_active('previous_test_timetables.php', $navScript, $navPath); ?>"><i class="fas fa-history"></i><span>Previous Timetables</span></a>
            <?php if ($navHasCityGuilds): ?>
            <a href="/wucportal/students/city_guilds.php" class="nav-item <?php echo student_nav_active('city_guilds.php', $navScript, $navPath); ?>"><i class="fas fa-certificate"></i><span>City &amp; Guilds</span></a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($studentInElearningPortal): ?>
        <div class="nav-section nav-section-elearning">
            <div class="nav-section-title">Learning</div>
            <a href="/wucportal/students/elearning/index.php" class="nav-item <?php echo student_nav_active('/students/elearning/index.php', $navScript, $navPath); ?>"><i class="fas fa-laptop"></i><span>Learning Hub</span></a>
            <a href="/wucportal/students/elearning/live_sessions.php" class="nav-item <?php echo (strpos($navPath, '/students/elearning/live_sessions.php') !== false || strpos($navPath, '/students/elearning/join_meeting.php') !== false) ? 'active' : ''; ?>"><i class="fas fa-video"></i><span>Live Sessions</span></a>
            <a href="/wucportal/students/elearning/assignment.php" class="nav-item <?php echo (strpos($navPath, '/students/elearning/assignment.php') !== false || strpos($navPath, '/students/elearning/quiz.php') !== false) ? 'active' : ''; ?>"><i class="fas fa-clipboard-check"></i><span>Assignments &amp; Quizzes</span></a>
            <a href="/wucportal/students/elearning/student_forum.php" class="nav-item <?php echo (strpos($navPath, '/students/elearning/student_forum.php') !== false || strpos($navPath, '/students/elearning/student_thread.php') !== false) ? 'active' : ''; ?>"><i class="fas fa-comments"></i><span>Discussion Forum</span></a>
            <a href="/wucportal/students/elearning/progress.php" class="nav-item <?php echo student_nav_active('/students/elearning/progress.php', $navScript, $navPath); ?>"><i class="fas fa-chart-simple"></i><span>Learning Progress</span></a>
            <a href="/wucportal/students/learning_assistant.php?portal=elearning" class="nav-item <?php echo student_nav_active('learning_assistant.php', $navScript, $navPath); ?>"><i class="fas fa-graduation-cap"></i><span>My Learning Assistant</span></a>
            <a href="/wucportal/students/ai_study_assistant.php?portal=elearning" class="nav-item <?php echo student_nav_active('ai_study_assistant.php', $navScript, $navPath); ?>"><i class="fas fa-wand-magic-sparkles"></i><span>AI Study Assistant</span></a>
        </div>
        <?php endif; ?>

        <?php if ($studentInAcademicPortal): ?>
        <div class="nav-section">
            <div class="nav-section-title">Student Services</div>
            <a href="/wucportal/students/ai_personal_assistant.php" class="nav-item <?php echo student_nav_active('ai_personal_assistant.php', $navScript, $navPath); ?>"><i class="fas fa-robot"></i><span>AI Personal Assistant</span></a>
            <?php if (!$navIsCertificatePortal): ?>
            <a href="/wucportal/students/skill_discovery.php" class="nav-item <?php echo student_nav_active('skill_discovery.php', $navScript, $navPath); ?>"><i class="fas fa-wand-magic-sparkles"></i><span>Skill Discovery</span></a>
            <?php endif; ?>
            <a href="/wucportal/students/njila.php" class="nav-item <?php echo student_nav_active('njila.php', $navScript, $navPath); ?>"><i class="fas fa-brain"></i><span>Njila AI</span></a>
            <a href="/wucportal/students/fees.php" class="nav-item <?php echo (student_nav_active('fees.php', $navScript, $navPath) !== '' || strpos($navPath, '/accounts/fees_statement.php') !== false || strpos($navPath, '/students/payments/') !== false) ? 'active' : ''; ?>"><i class="fas fa-credit-card"></i><span>Fees</span></a>
            <a href="/wucportal/students/boardingApp.php" class="nav-item <?php echo student_nav_active('boardingApp.php', $navScript, $navPath); ?>"><i class="fas fa-bed"></i><span>Accommodation</span></a>
            <a href="/wucportal/students/campus_services.php" class="nav-item <?php echo student_nav_active('campus_services.php', $navScript, $navPath); ?>"><i class="fas fa-concierge-bell"></i><span>Support &amp; Clearance</span></a>
            <a href="/wucportal/students/course_evaluations.php" class="nav-item <?php echo student_nav_active('course_evaluations.php', $navScript, $navPath); ?>"><i class="fas fa-star-half-alt"></i><span>Course Evaluations</span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">E-Library</div>
            <a href="/wucportal/students/library.php" class="nav-item <?php echo student_nav_active('library.php', $navScript, $navPath); ?>"><i class="fas fa-search"></i><span>Catalog</span></a>
            <a href="/wucportal/students/digital_library.php" class="nav-item <?php echo student_nav_active('digital_library.php', $navScript, $navPath); ?>"><i class="fas fa-cloud-download-alt"></i><span>Digital Library</span></a>
            <a href="/wucportal/students/repository/index.php" class="nav-item <?php echo student_nav_active('/students/repository/index.php', $navScript, $navPath); ?>"><i class="fas fa-folder-open"></i><span>Learning Repository</span></a>
        </div>
        <?php endif; ?>

        <?php
        $hasDualShortSwitch = ($navHasShortCourses && !$navIsShortCourse);
        $hasDualAcademicSwitch = ($navIsShortCourse && (!empty($navPortalViewShort) || (function_exists('sc_student_has_long_program') && sc_student_has_long_program($db, $navStudentId))));
        $hasElearningSwitch = $studentInElearningPortal || $navHasElearning;
        $showPortalSwitchSection = $hasDualShortSwitch || $hasDualAcademicSwitch || $hasElearningSwitch;
        ?>
        <?php if ($showPortalSwitchSection): ?>
        <div class="nav-section nav-section-switchers">
            <div class="nav-section-title">Portal Switch</div>
            <?php if ($hasDualShortSwitch): ?>
            <a href="/wucportal/students/portal_view.php?view=short_course" class="nav-item"><i class="fas fa-arrow-right-arrow-left"></i><span>Short Course Portal</span></a>
            <?php endif; ?>
            <?php if ($hasDualAcademicSwitch): ?>
            <a href="/wucportal/students/portal_view.php?view=academic" class="nav-item"><i class="fas fa-building-columns"></i><span>Academic Portal</span></a>
            <?php endif; ?>
            <?php if ($studentInElearningPortal): ?>
            <a href="<?php echo htmlspecialchars(wuc_portal_switch_url('academic', 'student'), ENT_QUOTES, 'UTF-8'); ?>" class="nav-item"><i class="fas fa-university"></i><span>Academic Portal</span></a>
            <?php elseif ($navHasElearning): ?>
            <a href="<?php echo htmlspecialchars(wuc_portal_switch_url('elearning', 'student'), ENT_QUOTES, 'UTF-8'); ?>" class="nav-item"><i class="fas fa-laptop"></i><span>Go to eLearning</span></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <div class="user-info">
            <?php
            $sidebarImg = (string)($_SESSION['student_profile_image'] ?? '');
            $sidebarName = (string)($_SESSION['student_name'] ?? $_SESSION['Sid'] ?? 'Student');
            $safeSidebarImg = ($sidebarImg !== 'default.jpg' && preg_match('/^[A-Za-z0-9._-]+$/', $sidebarImg)) ? $sidebarImg : '';
            if ($safeSidebarImg !== '') {
                echo '<img src="/wucportal/uploads/profile/' . htmlspecialchars($safeSidebarImg, ENT_QUOTES, 'UTF-8') . '" alt="Profile" class="user-avatar object-fit-cover">';
            } else {
                echo '<div class="user-avatar">' . htmlspecialchars(strtoupper(substr($sidebarName, 0, 1)), ENT_QUOTES, 'UTF-8') . '</div>';
            }
            ?>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($sidebarName, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="user-role">Student</div>
            </div>
        </div>
        <div class="quick-actions">
            <a href="/wucportal/students/editProfile.php?update=<?php echo urlencode($navStudentId); ?>" class="quick-action-btn"><i class="fas fa-user"></i> Profile</a>
            <?php
            if (empty($_SESSION['csrf_token'])) {
                try {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (Exception $e) {
                    $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
                }
            }
            ?>
            <form method="POST" action="/wucportal/logout.php" class="quick-action-inline-form">
                <input type="hidden" name="target" value="student">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="quick-action-btn quick-action-submit-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </form>
        </div>
    </div>
</nav>

<?php
// Shared flash/alert surface for the student portal. Rendered in an offset
// wrapper that matches the page content column, and only when a message exists
// (so it never adds an empty bar). Additive: existing per-page alerts still work.
$wucSuppressFlash = true;
require __DIR__ . '/../../includes/flash_alerts.php';
$wucStudentFlashHtml = wuc_render_flash_alerts();
if ($wucStudentFlashHtml !== '') {
    echo '<div class="content-wrapper wuc-flash-host">' . $wucStudentFlashHtml . '</div>';
}

// Rule-generated portal alerts (academic risk, pending registration, unpaid
// balance) are surfaced only on notifications.php, which lists every alert.

require_once __DIR__ . '/student_document_notifications.php';
if ($studentInAcademicPortal && !$navIsShortCourse && $navStudentId !== '' && isset($db) && $db instanceof mysqli && $navScript !== 'index.php') {
    $wucStudentDocAlertsHtml = student_render_document_availability_alerts($db, $navStudentId);
    if ($wucStudentDocAlertsHtml !== '') {
        echo $wucStudentDocAlertsHtml;
    }
}
?>

<?php require_once __DIR__ . '/ai_widget.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var toggleBtn = document.querySelector('.sidebar-toggle');
    var sidebar = document.querySelector('.sidebar');
    var backdrop = document.querySelector('[data-student-sidebar-backdrop]');
    document.body.classList.add('student-portal', 'has-unified-sidebar', 'portal-context-<?php echo htmlspecialchars($studentPortalContext, ENT_QUOTES, 'UTF-8'); ?>');

    function setSidebar(open) {
        if (!sidebar || !toggleBtn) return;
        sidebar.classList.toggle('show', open);
        toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.classList.toggle('sidebar-open', open);
        if (backdrop) backdrop.classList.toggle('show', open);
    }

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function(e){
            e.stopPropagation();
            setSidebar(!sidebar.classList.contains('show'));
        });
        document.addEventListener('click', function(event){
            if (sidebar.classList.contains('show') && !sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
                setSidebar(false);
            }
        });
        if (backdrop) {
            backdrop.addEventListener('click', function(){ setSidebar(false); });
        }
        document.addEventListener('keydown', function(event){
            if (event.key === 'Escape') setSidebar(false);
        });
    }

    document.querySelectorAll('.sidebar .nav-item').forEach(function(link){
        link.addEventListener('click', function(){
            if (sidebar && sidebar.classList.contains('show')) setSidebar(false);
        });
    });

    document.querySelectorAll('.content-wrapper table, .dash-content table').forEach(function(table){
        if (table.closest('.table-responsive, .table-container, .responsive-table, .student-table-scroll')) return;
        var wrapper = document.createElement('div');
        wrapper.className = 'table-responsive student-table-scroll';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });
});
</script>
