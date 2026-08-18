<?php
declare(strict_types=1);

/**
 * Lightweight portal context helper.
 *
 * Portal access answers "may this user enter academic/eLearning?". Context
 * answers "which shell/menu should this page render?". Keeping the two concerns
 * separate prevents academic pages from leaking LMS tools and vice versa.
 */

if (!function_exists('wuc_portal_context_from_request')) {
    function wuc_portal_context_from_request(?string $uri = null): string
    {
        $path = str_replace('\\', '/', (string)($uri ?? ($_SERVER['REQUEST_URI'] ?? '')));
        $pathOnly = strtolower((string)(parse_url($path, PHP_URL_PATH) ?: $path));
        $query = strtolower((string)(parse_url($path, PHP_URL_QUERY) ?: ''));
        $role = strtolower((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? ''));
        $isStudent = !empty($_SESSION['Sid']) || $role === 'student';
        $isLecturer = $role === 'lecturer'
            || strpos($pathOnly, '/lecturers/') !== false
            || (defined('ROLE_LECTURER') && function_exists('hasRole') && hasRole(ROLE_LECTURER));

        if (strpos($pathOnly, '/enterprise/') !== false || strpos($pathOnly, '/opportunities/') !== false) {
            return 'enterprise';
        }

        if ($isStudent) {
            $studentLearningPages = [
                '/students/ai_study_assistant.php',
                '/students/materials.php',
                '/students/submittedassign.php',
                '/students/assessments.php',
            ];
            $isStudentLearningPage = false;
            foreach ($studentLearningPages as $learningPage) {
                if (substr($pathOnly, -strlen($learningPage)) === $learningPage) {
                    $isStudentLearningPage = true;
                    break;
                }
            }

            if (strpos($pathOnly, '/students/elearning/') !== false
                || $isStudentLearningPage
                || strpos($query, 'portal=elearning') !== false) {
                return 'student_elearning';
            }
            return 'student_academic';
        }

        $lecturerLearningPages = [
            '/lecturers/materials.php',
            '/lecturers/course_resources.php',
            '/lecturers/post_assign.php',
            '/lecturers/ai_question_bank.php',
            '/lecturers/assessments.php',
            '/lecturers/archive_submissions_drive.php',
            '/lecturers/grade_assignment.php',
        ];
        $isLecturerLearningPage = false;
        foreach ($lecturerLearningPages as $learningPage) {
            if (substr($pathOnly, -strlen($learningPage)) === $learningPage) {
                $isLecturerLearningPage = true;
                break;
            }
        }

        if (strpos($pathOnly, '/elearning/') !== false
            || strpos($pathOnly, '/lecturers/elearning/') !== false
            || $isLecturerLearningPage
            || strpos($query, 'portal=elearning') !== false) {
            return $isLecturer ? 'lecturer_elearning' : 'staff_elearning';
        }

        if ($isLecturer) {
            return 'lecturer_academic';
        }

        if (strpos($pathOnly, '/hod/') !== false) {
            return 'head_of_section';
        }
        if (strpos($pathOnly, '/admin/') !== false) {
            return 'admin';
        }
        if (strpos($pathOnly, '/registrar/') !== false) {
            return 'registrar';
        }
        if (strpos($pathOnly, '/accounts/') !== false) {
            return 'accounts';
        }

        return 'staff_academic';
    }
}

if (!function_exists('wuc_set_portal_context')) {
    function wuc_set_portal_context(string $context): string
    {
        $context = strtolower(trim($context));
        if ($context === '') {
            $context = wuc_portal_context_from_request();
        }

        if (strpos($context, 'elearning') !== false) {
            $portalCode = 'elearning';
        } elseif ($context === 'enterprise' || strpos($context, 'enterprise') !== false) {
            $portalCode = 'enterprise';
        } else {
            $portalCode = 'academic';
        }
        $_SESSION['portal_context'] = $context;
        $_SESSION['active_portal'] = $context;
        $_SESSION['active_module'] = $portalCode;
        $_SESSION['current_portal'] = $portalCode;

        return $context;
    }
}

if (!function_exists('wuc_portal_code_from_context')) {
    function wuc_portal_code_from_context(string $context): string
    {
        $ctx = strtolower($context);
        if (strpos($ctx, 'elearning') !== false) {
            return 'elearning';
        }
        if (strpos($ctx, 'enterprise') !== false) {
            return 'enterprise';
        }
        return 'academic';
    }
}

if (!function_exists('wuc_current_portal_context')) {
    function wuc_current_portal_context(?string $default = null): string
    {
        if (isset($GLOBALS['portal_context']) && is_string($GLOBALS['portal_context']) && $GLOBALS['portal_context'] !== '') {
            return strtolower($GLOBALS['portal_context']);
        }

        $sessionContext = $_SESSION['portal_context'] ?? '';
        if (is_string($sessionContext) && trim($sessionContext) !== '') {
            return strtolower(trim($sessionContext));
        }

        return $default !== null ? strtolower($default) : wuc_portal_context_from_request();
    }
}

if (!function_exists('wuc_context_is_elearning')) {
    function wuc_context_is_elearning(?string $context = null): bool
    {
        return strpos(wuc_current_portal_context($context), 'elearning') !== false;
    }
}

if (!function_exists('wuc_context_is_academic')) {
    function wuc_context_is_academic(?string $context = null): bool
    {
        return !wuc_context_is_elearning($context);
    }
}
