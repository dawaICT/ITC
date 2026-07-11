<?php
/**
 * Normalize browser tab titles and favicon links across the portal.
 * Run: php scripts/sync_portal_meta.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/page_meta.php';

$pageTitleMap = [
    'registrar/editStaff.php' => 'Edit Staff',
    'registrar/students_by_admin.php' => 'Students',
    'admin/studentPop.php' => 'Student Details',
    'admin/courseLecturer.php' => 'Course Lecturers',
    'vc/students_by_admin.php' => 'Students',
    'vc/assessments.php' => 'Assessments',
    'registrar/hostels.php' => 'Hostels',
    'registrar/assessments.php' => 'Assessments',
    'admin/view_all_courses.php' => 'All Courses',
    'registrar/editCourseLecturer.php' => 'Edit Course Lecturer',
    'admin/editCourseLecturer.php' => 'Edit Course Lecturer',
    'registrar/courses.php' => 'Courses',
    'vc/view_student_admin.php' => 'View Student',
    'vc/view_staff.php' => 'View Staff',
    'registrar/view_staff.php' => 'View Staff',
    'registrar/editProgram.php' => 'Edit Program',
    'registrar/editCourse.php' => 'Edit Course',
    'admin/edit_course_program.php' => 'Edit Course Program',
    'admin/editProgram.php' => 'Edit Program',
    'admin/editCourse.php' => 'Edit Course',
    'registrar/departments.php' => 'Departments',
    'vc/student_fees.php' => 'Student Fees',
    'vc/final_results.php' => 'Final Results',
    'registrar/student_fees.php' => 'Student Fees',
    'registrar/final_results.php' => 'Final Results',
    'admin/student_index.php' => 'Student Index',
    'admin/student_fees.php' => 'Student Fees',
    'vc/student_dashboard.php' => 'Student Dashboard',
    'vc/MyCourses.php' => 'My Courses',
    'registrar/student_dashboard.php' => 'Student Dashboard',
    'admin/student_dashboard.php' => 'Student Dashboard',
    'admin/MyCourses.php' => 'My Courses',
    'students/course.php' => 'Course',
    'students/backupMycourses.php' => 'My Courses',
    'students/moveCourseReg.php' => 'Move Course Registration',
    'vc/admitStudent.php' => 'Admit Student',
    'students/submit_ca.php' => 'Submit Assessment',
    'students/index.php' => 'Dashboard',
    'students/myCourses.php' => 'My Courses',
    'students/fees.php' => 'Financial Records',
    'students/examTranscript.php' => 'Exam Transcript',
    'students/continuousAssessment.php' => 'Continuous Assessment',
    'students/balanceStatement.php' => 'Balance Statement',
    'students/courseReg.php' => 'Course Registration',
    'students/timetable.php' => 'My Timetable',
    'students/assignments.php' => 'Assignments',
    'students/submittedAssign.php' => 'Submitted Assignments',
    'students/course_evaluations.php' => 'Course Evaluations',
    'students/semesterReg.php' => 'Semester Registration',
    'students/semesterRegReturning.php' => 'Semester Registration',
    'students/view_ca.php' => 'Continuous Assessment',
    'students/library.php' => 'Library Catalog',
    'students/campus_services.php' => 'Campus Services',
    'students/editProfile.php' => 'Edit Profile',
    'students/editStudent.php' => 'Edit Student Information',
    'students/elearning/index.php' => 'eLearning Dashboard',
    'students/elearning/live_sessions.php' => 'Live Sessions',
    'students/completeReg.php' => 'Complete Registration',
    'student_login.php' => 'Student Login',
    'staff_login.php' => 'Staff Login',
    'elearning_login.php' => 'eLearning Login',
    'portal_selection.php' => 'Select Portal',
    'role_selection.php' => 'Select Workspace',
    'staff_forgot_password.php' => 'Set / Reset Password',
    'studentPasswordReset.php' => 'Set / Reset Password',
    'passwordResetSuccess.php' => 'Password Reset',
];

$authPages = [
    'student_login.php',
    'staff_login.php',
    'elearning_login.php',
    'portal_selection.php',
    'role_selection.php',
    'staff_forgot_password.php',
    'studentPasswordReset.php',
    'passwordResetSuccess.php',
    'staffPasswordReset.php',
    'studentAccount.php',
];

function relPathToPageMeta(string $filePath, string $root): string
{
    $dir = dirname($filePath);
    $depth = substr_count(str_replace('\\', '/', substr($dir, strlen($root))), '/');
    if ($depth <= 0) {
        return "__DIR__ . '/includes/page_meta.php'";
    }
    return "__DIR__ . '/" . str_repeat('../', $depth) . "includes/page_meta.php'";
}

function patchFile(string $path, callable $patcher): bool
{
    if (!is_file($path)) {
        echo "SKIP missing: $path\n";
        return false;
    }
    $original = file_get_contents($path);
    if ($original === false) {
        echo "SKIP unreadable: $path\n";
        return false;
    }
    $updated = $patcher($original);
    if ($updated === $original) {
        echo "UNCHANGED: $path\n";
        return false;
    }
    file_put_contents($path, $updated);
    echo "FIXED: $path\n";
    return true;
}

function normalizeTitleContent(string $title, bool $isAuthPage): string
{
    $title = html_entity_decode(trim($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($title === '' || $title === 'student-portal') {
        return '';
    }

    $title = preg_replace('/\s*\|\s*Student Portal\s*$/i', '', $title) ?? $title;
    $title = preg_replace('/\s*\|\s*Industrial Training Centre(?: Staff)?\s*$/i', '', $title) ?? $title;
    $title = preg_replace('/\s*-\s*ITC\s*$/i', '', $title) ?? $title;
    $title = preg_replace('/\s*\|\s*ITC Portal\s*$/i', '', $title) ?? $title;
    $title = preg_replace('/\s*\|\s*ITC Staff Portal\s*$/i', '', $title) ?? $title;
    $title = trim($title);

    if ($title === '') {
        return '';
    }

    return wuc_portal_title($title, $isAuthPage ? 'pipe' : 'dash');
}

function faviconPhpSnippet(string $metaRequire): string
{
    return "<?php require_once {$metaRequire}; wuc_portal_favicon_links(); ?>\n";
}

$faviconBlockPattern = '/\s*<link rel="icon" href="[^"]*\/images\/favicon\.ico" sizes="any">\s*\n'
    . '\s*<link rel="icon" type="image/png" sizes="32x32" href="[^"]*\/images\/favicon-32\.png">\s*\n'
    . '\s*<link rel="icon" type="image/png" sizes="16x16" href="[^"]*\/images\/favicon-16\.png">\s*\n'
    . '\s*<link rel="apple-touch-icon" href="[^"]*\/images\/apple-touch-icon\.png">\s*\n/s';

$singleFaviconPattern = '/\s*<link rel="icon" href="[^"]*\/images\/favicon\.ico" sizes="any">\s*\n/s';

$fixed = 0;

foreach ($pageTitleMap as $rel => $pageTitle) {
    $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $isAuth = in_array($rel, $authPages, true);
    $docTitle = wuc_portal_title($pageTitle, $isAuth ? 'pipe' : 'dash');
    $metaRequire = relPathToPageMeta($path, $root);
    $faviconSnippet = faviconPhpSnippet($metaRequire);

    patchFile($path, static function (string $src) use ($rel, $pageTitle, $docTitle, $isAuth, $faviconSnippet, $faviconBlockPattern, $singleFaviconPattern): string {
        if (strpos($src, '$page_title') === false) {
            if (preg_match('/include\s+["\']includes\/admin\.php["\'];/', $src)) {
                $src = preg_replace(
                    '/(<\?php\s*\r?\n)(include\s+["\']includes\/admin\.php["\'];)/',
                    '$1$page_title = ' . var_export($pageTitle, true) . ";\n$2",
                    $src,
                    1
                ) ?? $src;
            } elseif (preg_match('/include\s+["\']student_nav\.php["\'];/', $src)) {
                $src = preg_replace(
                    '/(<\?php\s*\r?\n)(include\s+["\']student_nav\.php["\'];)/',
                    '$1$page_title = ' . var_export($pageTitle, true) . ";\n$2",
                    $src,
                    1
                ) ?? $src;
            }
        }

        if (preg_match('/<title>\s*<\/title>/i', $src)) {
            $src = preg_replace(
                '/<title>\s*<\/title>/i',
                '<title>' . htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') . '</title>',
                $src,
                1
            ) ?? $src;
        } elseif (preg_match('/<title>([^<]*)<\/title>/i', $src, $m)) {
            $normalized = normalizeTitleContent($m[1], $isAuth);
            if ($normalized !== '' && $normalized !== trim($m[1])) {
                $src = preg_replace(
                    '/<title>[^<]*<\/title>/i',
                    '<title>' . htmlspecialchars($normalized, ENT_QUOTES, 'UTF-8') . '</title>',
                    $src,
                    1
                ) ?? $src;
            }
        }

        if (strpos($src, 'wuc_portal_favicon_links') === false) {
            if (preg_match($faviconBlockPattern, $src)) {
                $src = preg_replace($faviconBlockPattern, "\n" . $faviconSnippet, $src, 1) ?? $src;
            } elseif (preg_match($singleFaviconPattern, $src)) {
                $src = preg_replace($singleFaviconPattern, "\n" . $faviconSnippet, $src, 1) ?? $src;
            } elseif (preg_match('#</head>#i', $src) && !preg_match('#rel=["\']icon["\']#i', $src)) {
                $src = preg_replace('#</head>#i', "\n" . $faviconSnippet . '</head>', $src, 1) ?? $src;
            }
        }

        return $src;
    }) && $fixed++;
}

// Sweep remaining PHP pages under students/ for missing favicons or inconsistent titles.
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/students', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $path = $fileInfo->getPathname();
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (isset($pageTitleMap[$rel]) || strpos($rel, '/includes/') !== false || strpos($rel, '/dist/') !== false) {
        continue;
    }
    if (!preg_match('/<head\b/i', (string) file_get_contents($path))) {
        continue;
    }

    $metaRequire = relPathToPageMeta($path, $root);
    $faviconSnippet = faviconPhpSnippet($metaRequire);

    patchFile($path, static function (string $src) use ($faviconSnippet, $faviconBlockPattern, $singleFaviconPattern): string {
        if (strpos($src, 'wuc_portal_favicon_links') !== false) {
            return $src;
        }
        if (preg_match($faviconBlockPattern, $src)) {
            return preg_replace($faviconBlockPattern, "\n" . $faviconSnippet, $src, 1) ?? $src;
        }
        if (preg_match($singleFaviconPattern, $src)) {
            return preg_replace($singleFaviconPattern, "\n" . $faviconSnippet, $src, 1) ?? $src;
        }
        if (preg_match('#</head>#i', $src) && !preg_match('#rel=["\']icon["\']#i', $src)) {
            return preg_replace('#</head>#i', "\n" . $faviconSnippet . '</head>', $src, 1) ?? $src;
        }
        return $src;
    });
}

echo "Done.\n";
