<?php
declare(strict_types=1);

/**
 * Scope guard + page-context detection + safe avatar for the Student AI
 * Personal Assistant.
 *
 * - wuc_ai_student_page_context(): maps the (client-supplied, sanitised) page
 *   path to a portal module so the assistant can give page-specific help.
 * - wuc_ai_message_scope(): rule-based allowed/blocked topic check that runs
 *   BEFORE any FAQ/LLM work. Purely lexical — instruction-like input cannot
 *   steer it. The system prompt repeats the scope rules as a second layer.
 * - wuc_ai_student_avatar_url(): validated profile-picture URL for the chat
 *   (session-cached filename, whitelisted extensions, existence check,
 *   default avatar fallback).
 */

if (!function_exists('wuc_ai_student_page_context')) {
    /**
     * Map a portal path to a module + allowed help topics. The path comes from
     * the client, so it is treated as untrusted display data only — it never
     * touches the filesystem or SQL.
     */
    function wuc_ai_student_page_context(?string $clientPath): array
    {
        $path = strtolower(trim((string)$clientPath));
        // Keep only a conservative charset, then take the script name.
        $path = (string)preg_replace('/[^a-z0-9\/\._-]/', '', $path);
        $file = basename($path);
        $inElearning = strpos($path, 'elearning') !== false;

        $map = [
            'index.php' => ['Dashboard', ['portal navigation', 'academic snapshot', 'alerts', 'announcements']],
            'registration.php' => ['Registration', ['course registration', 'registered courses', 'academic year', 'term or semester selection', 'registration status']],
            'courilesreg.php' => ['Registration', []],
            'coursereg.php' => ['Registration', ['course registration', 'adding courses']],
            'completereg.php' => ['Registration', ['completing registration']],
            'course.php' => ['Courses', ['registered courses', 'course details']],
            'mycourses.php' => ['Courses', ['registered courses', 'course details']],
            'assessments.php' => ['Results', ['continuous assessment', 'marks', 'assessment status']],
            'continuousassessment.php' => ['Results', ['continuous assessment', 'CA marks']],
            'exam_transcript.php' => ['Results', ['exam results', 'transcript']],
            'ca_report.php' => ['Results', ['CA report', 'assessment marks']],
            'results.php' => ['Results', ['results', 'grades']],
            'fees.php' => ['Fees', ['fee balance', 'payments', 'billing', 'payment plans']],
            'balancestatement.php' => ['Fees', ['fee statement', 'balance']],
            'timetable.php' => ['Timetable', ['class schedule', 'timetable']],
            'profile.php' => ['Profile', ['personal details', 'profile picture', 'contact information']],
            'change_password.php' => ['Profile', ['password', 'account security']],
            'assignments.php' => ['eLearning', ['assignments', 'submissions', 'deadlines']],
            'digital_library.php' => ['Library', ['library resources', 'digital library']],
            'ai_personal_assistant.php' => ['AI Assistant', ['any academic or portal topic']],
        ];

        if (isset($map[$file])) {
            [$module, $topics] = $map[$file];
        } elseif ($inElearning) {
            $module = 'eLearning';
            $topics = ['course materials', 'lessons', 'assignments', 'live sessions'];
        } else {
            $module = 'General Student Portal';
            $topics = ['portal navigation', 'academic support'];
        }

        return [
            'page_file' => $file !== '' ? $file : 'unknown',
            'module' => $module,
            'allowed_help_topics' => $topics,
        ];
    }
}

if (!function_exists('wuc_ai_message_scope')) {
    /**
     * Basic rule-based scope check.
     *
     * Blocked keyword → refused (even if academic words also appear, blocked
     * wins only when no academic keyword is present — "fees for my football
     * club" is still redirected, but "investment of time in my course" is not
     * penalised because 'course' matches first).
     * Academic keyword → allowed. Unclear → allowed only when the current
     * module is an academic one (the assistant page itself counts).
     */
    function wuc_ai_message_scope(string $message, string $module): array
    {
        $text = mb_strtolower(' ' . trim($message) . ' ');

        $academicKeywords = [
            'registration', 'register', 'course', 'result', ' ca ', 'assessment', 'assignment',
            'timetable', 'fee', 'balance', 'payment', 'elearning', 'e-learning', 'profile',
            'programme', 'program', 'semester', 'term', 'academic', 'dashboard', 'portal',
            'lecturer', 'class', 'exam', 'study', 'revision', 'transcript', 'library',
            'certificate', 'intake', 'enrol', 'password', 'login', 'graduation', 'marks',
            'grade', 'syllabus', 'lesson', 'module', 'student',
        ];
        $blockedKeywords = [
            'football', 'soccer', 'premier league', 'politics', 'election', 'president',
            'dating', 'girlfriend', 'boyfriend', 'celebrity', 'gossip', 'movie', 'netflix',
            'music video', 'bitcoin', 'crypto', 'forex', 'investment advice', 'gambling',
            'betting', 'lottery', 'medical diagnosis', 'prescription', 'weapon', 'drugs',
            'alcohol', 'joke', 'riddle', 'horoscope', 'recipe', 'weather',
        ];

        // Blocked topics win even when an academic word co-occurs, so
        // "football results" is redirected despite containing "results".
        foreach ($blockedKeywords as $kw) {
            if (strpos($text, $kw) !== false) {
                return ['allowed' => false, 'reason' => 'blocked_topic'];
            }
        }
        foreach ($academicKeywords as $kw) {
            if (strpos($text, $kw) !== false) {
                return ['allowed' => true, 'reason' => 'academic_keyword'];
            }
        }

        // Unclear: short conversational messages ("hello", "thanks", "what should
        // I do here?") are fine on academic pages/modules.
        $academicModules = ['Dashboard', 'Registration', 'Courses', 'Results', 'Fees', 'Timetable', 'Profile', 'eLearning', 'Library', 'AI Assistant', 'General Student Portal'];
        return [
            'allowed' => in_array($module, $academicModules, true),
            'reason' => 'unclear_default',
        ];
    }
}

if (!function_exists('wuc_ai_scope_redirect_reply')) {
    function wuc_ai_scope_redirect_reply(): string
    {
        return 'I can only assist with student portal and academic-related support. '
            . 'Please ask me about your registration, courses, results, timetable, fees, '
            . 'eLearning, or student profile.';
    }
}

if (!function_exists('wuc_ai_student_avatar_url')) {
    /**
     * Safe profile-picture URL for the logged-in student, with default-avatar
     * fallback. Filename comes from the session (cached by the navbar from the
     * students table) and is strictly validated before being used in a URL.
     */
    function wuc_ai_student_avatar_url(): string
    {
        $default = '/wucportal/uploads/profile/avatar.svg';
        $file = trim((string)($_SESSION['student_profile_image'] ?? ''));

        if ($file === '' || $file === 'default.jpg') {
            return $default;
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $file) || strpos($file, '..') !== false) {
            return $default;
        }
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $default;
        }
        $abs = dirname(__DIR__, 2) . '/uploads/profile/' . $file;
        if (!is_file($abs)) {
            return $default;
        }
        return '/wucportal/uploads/profile/' . rawurlencode($file);
    }
}
