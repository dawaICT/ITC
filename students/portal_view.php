<?php
/**
 * Session-scoped portal view switcher for dual-enrolled students.
 *
 * A student with BOTH a long-term programme and short-course enrolments lands
 * on the long-term portal by default. This endpoint lets them flip into the
 * Short Course Portal (and back) for the remainder of the session:
 *
 *   ?view=short_course  → Short Course Portal (short_course_portal.php)
 *   ?view=academic      → back to the long-term programme portal (index.php)
 *
 * The override is honored by students/index.php (sub-portal gate + dashboard
 * branch) and students/includes/navbar.php (menu mode). It is only granted
 * when the student genuinely holds both enrolment types — everyone else is
 * bounced to their resolved portal home.
 */
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/short_course_student.php';

$sid = (string)($_SESSION['Sid'] ?? '');
$view = strtolower(trim((string)($_GET['view'] ?? '')));

$dest = '/wucportal/students/index.php';

if ($sid !== '') {
    $hasLongProgram = sc_student_has_long_program($db, $sid);
    $hasShortCourses = sc_student_enrolments($db, $sid) !== [];
    $isDualEnrolled = $hasLongProgram && $hasShortCourses;

    if ($view === 'short_course' && $isDualEnrolled) {
        $_SESSION['student_portal_view'] = 'short_course';
        $dest = '/wucportal/students/short_course_portal.php';
    } elseif ($view === 'academic') {
        unset($_SESSION['student_portal_view']);
        $dest = '/wucportal/students/index.php';
    } elseif ($view === 'short_course' && !$isDualEnrolled) {
        // Not dual-enrolled: short-only students already live in the
        // short-course portal; long-only students have nothing to switch to.
        unset($_SESSION['student_portal_view']);
    }

    // Navbar mode flags are cached for ten minutes; bust the cache so the
    // menu re-renders in the newly selected portal mode immediately.
    unset($_SESSION['nav_flags'], $_SESSION['nav_flags_at']);
}

header('Location: ' . $dest, true, 302);
exit;
