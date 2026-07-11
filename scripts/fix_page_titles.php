<?php
/**
 * One-shot fix for legacy pages with empty or missing tab titles.
 * Run: php scripts/fix_page_titles.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/page_meta.php';

$adminIncludes = [
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
];

$studentNavPages = [
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
];

$standalonePages = [
    'students/course.php' => 'Course',
    'students/backupMycourses.php' => 'My Courses',
    'students/moveCourseReg.php' => 'Move Course Registration',
    'vc/admitStudent.php' => 'Admit Student',
];

function patchFile(string $path, callable $patcher): bool
{
    if (!is_file($path)) {
        echo "SKIP missing: $path\n";
        return false;
    }
    $original = file_get_contents($path);
    $updated = $patcher($original);
    if ($updated === $original) {
        echo "UNCHANGED: $path\n";
        return false;
    }
    file_put_contents($path, $updated);
    echo "FIXED: $path\n";
    return true;
}

foreach ($adminIncludes as $rel => $title) {
    $path = $root . '/' . $rel;
    patchFile($path, static function (string $src) use ($title): string {
        if (strpos($src, '$page_title') !== false) {
            return $src;
        }
        $src = preg_replace(
            '/(<\?php\s*\r?\n)(include\s+["\']includes\/admin\.php["\'];)/',
            '$1$page_title = ' . var_export($title, true) . ";\n$2",
            $src,
            1
        ) ?? $src;
        return str_replace('<title></title>', '<title>' . htmlspecialchars(wuc_portal_title($title), ENT_QUOTES, 'UTF-8') . '</title>', $src);
    });
}

foreach ($studentNavPages as $rel => $title) {
    $path = $root . '/' . $rel;
    patchFile($path, static function (string $src) use ($title): string {
        if (strpos($src, '$page_title') === false && preg_match('/include\s+["\']student_nav\.php["\'];/', $src)) {
            $src = preg_replace(
                '/(<\?php\s*\r?\n)(include\s+["\']student_nav\.php["\'];)/',
                '$1$page_title = ' . var_export($title, true) . ";\n$2",
                $src,
                1
            ) ?? $src;
        }
        return preg_replace('/\s*<title>\s*<\/title>\s*/', "\n", $src) ?? $src;
    });
}

foreach ($standalonePages as $rel => $title) {
    $path = $root . '/' . $rel;
    patchFile($path, static function (string $src) use ($title): string {
        require_once dirname(__DIR__) . '/includes/page_meta.php';
        $docTitle = wuc_portal_title($title);
        $favicon = '';
        ob_start();
        wuc_portal_favicon_links();
        $favicon = ob_get_clean();

        if (strpos($src, '<title></title>') !== false) {
            $replacement = '<title>' . htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') . "</title>\n" . $favicon;
            return str_replace('<title></title>', $replacement, $src);
        }
        return $src;
    });
}

// submit_ca — standalone legacy form page
$submitCa = $root . '/students/submit_ca.php';
patchFile($submitCa, static function (string $src): string {
    require_once dirname(__DIR__) . '/includes/page_meta.php';
    $docTitle = wuc_portal_title('Submit Assessment');
    ob_start();
    wuc_portal_favicon_links();
    $favicon = ob_get_clean();
    $replacement = '<title>' . htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') . "</title>\n" . $favicon;
    return str_replace('<title></title>', $replacement, $src);
});

echo "Done.\n";
