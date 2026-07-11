<?php
// Lecturers module navigation via unified include.
require_once dirname(__DIR__, 2) . '/includes/role_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/portal_access.php';
require_once dirname(__DIR__, 2) . '/includes/portal_context.php';
require_once dirname(__DIR__, 2) . '/includes/portal_switch.php';

$lecturerCanEnterExams = function_exists('canEnterLecturerExamResults') && canEnterLecturerExamResults();
$lecturerPortalContext = wuc_current_portal_context(wuc_portal_context_from_request());
$lecturerInElearningPortal = strpos($lecturerPortalContext, 'elearning') !== false;
$lecturerSwitchKind = strpos($lecturerPortalContext, 'lecturer_') === 0 ? 'lecturer' : 'staff';

$lecturerHasElearningPortal = false;
if (isset($db) && $db instanceof mysqli && function_exists('wuc_user_has_portal_access')) {
    $lecturerUserIdDb = (int)($_SESSION['user_id_db'] ?? 0);
    $lecturerHasElearningPortal = $lecturerUserIdDb > 0 && wuc_user_has_portal_access($db, $lecturerUserIdDb, 'elearning');
}

$academicMenuSections = [
    [
        'title' => 'Main',
        'items' => [
            ['href' => '/wucportal/lecturers/index.php', 'icon' => 'fas fa-home', 'label' => 'Dashboard', 'active_on' => 'index.php'],
            ['href' => '/wucportal/lecturers/myCourses.php', 'icon' => 'fas fa-book', 'label' => 'My Courses', 'active_on' => 'myCourses.php'],
            ['href' => '/wucportal/lecturers/timetable.php', 'icon' => 'fas fa-calendar-alt', 'label' => 'My Timetable', 'active_on' => 'timetable.php'],
            ['href' => '/wucportal/lecturers/myStudent.php', 'icon' => 'fas fa-user-graduate', 'label' => 'My Students', 'active_on' => 'myStudent.php'],
        ],
    ],
    [
        'title' => 'Assessment',
        'items' => array_values(array_filter([
            ['href' => '/wucportal/lecturers/upload_ca.php', 'icon' => 'fas fa-upload', 'label' => 'Upload CA', 'active_on' => ['upload_ca.php', 'ca_upload.php', 'ca_upload-2.php']],
            ['href' => '/wucportal/lecturers/viewCaRes.php', 'icon' => 'fas fa-chart-line', 'label' => 'CA Results', 'active_on' => 'viewCaRes.php'],
            $lecturerCanEnterExams
                ? ['href' => '/wucportal/lecturers/enter_results.php', 'icon' => 'fas fa-pen-alt', 'label' => 'Exam Results', 'active_on' => 'enter_results.php']
                : null,
        ], static function ($item) { return $item !== null; })),
    ],
    [
        'title' => 'Reports',
        'items' => [
            ['href' => '/wucportal/lecturers/student_login_log.php', 'icon' => 'fas fa-history', 'label' => 'Student Login Log', 'active_on' => 'student_login_log.php'],
            ['href' => '/wucportal/lecturers/student_progression_report.php', 'icon' => 'fas fa-triangle-exclamation', 'label' => 'Progression Alerts', 'active_on' => 'student_progression_report.php'],
            ['href' => '/wucportal/lecturers/ai_progression_insights.php', 'icon' => 'fas fa-wand-magic-sparkles', 'label' => 'AI Academic Insights', 'active_on' => 'ai_progression_insights.php'],
        ],
    ],
    [
        'title' => 'Learning Repository',
        'items' => [
            ['href' => '/wucportal/lecturers/repository/index.php', 'icon' => 'fas fa-folder-open', 'label' => 'Repository', 'active_on' => 'repository/index.php'],
            ['href' => '/wucportal/lecturers/repository/upload.php', 'icon' => 'fas fa-upload', 'label' => 'Upload Material', 'active_on' => 'repository/upload.php'],
            ['href' => '/wucportal/lecturers/repository/my_uploads.php', 'icon' => 'fas fa-list-check', 'label' => 'My Uploads', 'active_on' => 'repository/my_uploads.php'],
        ],
    ],
];

if ($lecturerHasElearningPortal) {
    $academicMenuSections[] = [
        'title' => 'Portal Switch',
        'items' => [
            ['href' => wuc_portal_switch_url('elearning', 'lecturer'), 'icon' => 'fas fa-laptop', 'label' => 'Go to eLearning', 'active_on' => 'elearning/index.php'],
        ],
    ];
}

$elearningMenuSections = [
    [
        'title' => 'Learning',
        'items' => [
            ['href' => '/wucportal/lecturers/elearning/index.php', 'icon' => 'fas fa-chalkboard', 'label' => 'eLearning Dashboard', 'active_on' => 'elearning/index.php'],
            ['href' => '/wucportal/elearning/courses.php', 'icon' => 'fas fa-book-open', 'label' => 'Online Courses', 'active_on' => 'elearning/courses.php'],
            ['href' => '/wucportal/elearning/sessions.php', 'icon' => 'fas fa-video', 'label' => 'Live Sessions', 'active_on' => 'elearning/sessions.php'],
            ['href' => '/wucportal/elearning/analytics.php', 'icon' => 'fas fa-chart-line', 'label' => 'Learning Progress', 'active_on' => 'elearning/analytics.php'],
        ],
    ],
    [
        'title' => 'Teaching Tools',
        'items' => [
            ['href' => '/wucportal/lecturers/materials.php?portal=elearning', 'icon' => 'fas fa-file-alt', 'label' => 'Upload Materials', 'active_on' => 'lecturers/materials.php'],
            ['href' => '/wucportal/lecturers/repository/index.php?portal=elearning', 'icon' => 'fas fa-folder-open', 'label' => 'Learning Repository', 'active_on' => 'lecturers/repository/index.php'],
            ['href' => '/wucportal/lecturers/course_resources.php?portal=elearning', 'icon' => 'fas fa-book', 'label' => 'Course Resources', 'active_on' => 'lecturers/course_resources.php'],
            ['href' => '/wucportal/lecturers/post_assign.php?portal=elearning', 'icon' => 'fas fa-clipboard', 'label' => 'Assignments', 'active_on' => 'lecturers/post_assign.php'],
            ['href' => '/wucportal/lecturers/ai_question_bank.php?portal=elearning', 'icon' => 'fas fa-wand-magic-sparkles', 'label' => 'AI Question Bank', 'active_on' => 'lecturers/ai_question_bank.php'],
            ['href' => '/wucportal/lecturers/assessments.php?portal=elearning', 'icon' => 'fas fa-tasks', 'label' => 'Submissions', 'active_on' => 'lecturers/assessments.php'],
            ['href' => '/wucportal/elearning/forum.php', 'icon' => 'fas fa-comments', 'label' => 'Discussions', 'active_on' => ['elearning/forum.php', 'elearning/thread.php']],
            ['href' => '/wucportal/lecturers/archive_submissions_drive.php?portal=elearning', 'icon' => 'fab fa-google-drive', 'label' => 'Drive Archive', 'active_on' => 'lecturers/archive_submissions_drive.php'],
        ],
    ],
    [
        'title' => 'Portal Switch',
        'items' => [
            ['href' => wuc_portal_switch_url('academic', $lecturerSwitchKind), 'icon' => 'fas fa-university', 'label' => 'Academic Portal', 'active_on' => 'lecturers/index.php'],
        ],
    ],
];

$module_config = [
    'role_label' => $lecturerSwitchKind === 'lecturer' ? 'Lecturer' : 'eLearning Staff',
    'required_access' => $lecturerInElearningPortal ? 'elearning' : 'academics',
    'footer_profile_href' => '/wucportal/lecturers/editStaff.php',
    'footer_logout_href' => '/wucportal/logout.php?to=staff',
    'additional_css' => [
        'lecturers/css/lecturer-dashboard.css',
        'lecturers/css/module-reusable.css',
        'css/elearning-ui.css',
    ],
    'menu_sections' => $lecturerInElearningPortal ? $elearningMenuSections : $academicMenuSections,
];

require dirname(__DIR__, 2) . '/includes/nav_unified.php';
