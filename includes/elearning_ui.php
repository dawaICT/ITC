<?php
/**
 * Shared e-learning UI helpers for lecturer pages.
 */

if (!function_exists('elearningCourseTabs')) {
    function elearningCourseTabs(string $courseCode, string $active = ''): void
    {
        $tabs = [
            'manage' => ['href' => 'manage.php', 'icon' => 'fas fa-layer-group', 'label' => 'Modules'],
            'materials' => ['href' => '/wucportal/lecturers/materials.php', 'icon' => 'fas fa-file-alt', 'label' => 'Materials'],
            'sessions' => ['href' => 'sessions.php', 'icon' => 'fas fa-video', 'label' => 'Live Sessions'],
            'recordings' => ['href' => 'recordings.php', 'icon' => 'fas fa-record-vinyl', 'label' => 'Recordings'],
            'assessments' => ['href' => 'assessments.php', 'icon' => 'fas fa-clipboard-check', 'label' => 'Assessments'],
            'competencies' => ['href' => 'competencies.php', 'icon' => 'fas fa-tasks', 'label' => 'Competencies'],
            'forum' => ['href' => 'forum.php', 'icon' => 'fas fa-comments', 'label' => 'Discussions'],
            'student_progress' => ['href' => 'student_progress.php', 'icon' => 'fas fa-user-check', 'label' => 'Student Progress'],
            'analytics' => ['href' => 'analytics.php', 'icon' => 'fas fa-chart-line', 'label' => 'Analytics'],
        ];

        echo '<nav class="elearning-tabs" aria-label="Course e-learning sections">';
        foreach ($tabs as $key => $tab) {
            $paramName = $key === 'materials' ? 'code' : 'course_code';
            $href = $tab['href'] . '?' . $paramName . '=' . urlencode($courseCode);
            $class = 'elearning-tab' . ($active === $key ? ' active' : '');
            echo '<a class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
            echo '<i class="' . htmlspecialchars($tab['icon'], ENT_QUOTES, 'UTF-8') . '"></i>';
            echo '<span>' . htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8') . '</span>';
            echo '</a>';
        }
        echo '</nav>';
    }
}
