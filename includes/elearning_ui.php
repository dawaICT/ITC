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

if (!function_exists('elearningCourseSelection')) {
    /**
     * Render the shared lecturer course picker used by global eLearning links.
     *
     * @param array<int,array{course_code:mixed,course_name:mixed}> $courses
     */
    function elearningCourseSelection(
        array $courses,
        string $destination,
        string $actionLabel,
        string $icon = 'fas fa-arrow-right'
    ): void {
        $destination = basename((string) parse_url($destination, PHP_URL_PATH));
        if (!preg_match('/^[A-Za-z0-9._-]+\.php$/', $destination)) {
            $destination = 'courses.php';
        }

        echo '<section class="elearning-panel">';
        echo '<div class="elearning-panel-header"><strong>Choose a course</strong></div>';
        echo '<div class="elearning-panel-body">';

        if ($courses === []) {
            echo '<div class="elearning-empty">';
            echo '<i class="fas fa-book-open"></i>';
            echo '<p>No assigned courses are available. Contact your department head or administrator.</p>';
            echo '</div>';
        } else {
            echo '<div class="elearning-grid">';
            foreach ($courses as $course) {
                $courseCode = trim((string) ($course['course_code'] ?? ''));
                if ($courseCode === '') {
                    continue;
                }
                $courseName = trim((string) ($course['course_name'] ?? $courseCode));
                $href = $destination . '?course_code=' . rawurlencode($courseCode);

                echo '<article class="elearning-card">';
                echo '<div class="elearning-card-main">';
                echo '<div class="elearning-course-icon" aria-hidden="true"><i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></div>';
                echo '<div>';
                echo '<h2 class="elearning-course-title">' . htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8') . '</h2>';
                echo '<p class="elearning-course-name">' . htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8') . '</p>';
                echo '</div></div>';
                echo '<div class="elearning-actions">';
                echo '<a class="btn btn-primary" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
                echo '<i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i> ' . htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8');
                echo '</a></div></article>';
            }
            echo '</div>';
        }

        echo '</div></section>';
    }
}
