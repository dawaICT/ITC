<?php
declare(strict_types=1);

/**
 * Conversational AI chat endpoint for students (multi-turn, with memory).
 *
 * Keeps a rolling conversation history in the session so the assistant can
 * follow up on earlier turns. The student's live academic data is re-injected
 * each turn so answers stay accurate.
 *
 * POST JSON: { message, csrf_token, reset? }
 * Returns JSON: { ok, reply_html, used_ai, model, history_len }
 */

require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';
require_once dirname(__DIR__) . '/includes/chatbot_db.php';
require_once __DIR__ . '/includes/ai_student_context.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = ($raw !== '' && ($d = json_decode($raw, true)) && is_array($d)) ? $d : $_POST;

$token = (string)($payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid security token. Refresh and try again.']);
    exit;
}

$studentId = (string)($_SESSION['Sid'] ?? '');
$historyKey     = 'ai_chat_history_' . md5($studentId);
$convoSessionKey = 'chatbot_convo_student_' . md5($studentId);

// Ensure DB tables exist once per session
if (empty($_SESSION['chatbot_schema_checked'])) {
    wuc_chatbot_ensure_schema($db);
    $_SESSION['chatbot_schema_checked'] = true;
}

// Resolve DB conversation ID (cached in session)
$dbConvoId = isset($_SESSION[$convoSessionKey]) ? (int)$_SESSION[$convoSessionKey] : null;
if ($dbConvoId === null) {
    $dbConvoId = wuc_chatbot_get_or_create_convo($db, $studentId, 'student', 'student');
    if ($dbConvoId !== null) {
        $_SESSION[$convoSessionKey] = $dbConvoId;
    }
}

// Action: return recent chat history for widget/page reload (read-only, no rate limit)
if (($payload['action'] ?? '') === 'history') {
    $msgs = $dbConvoId !== null ? wuc_chatbot_load_history($db, $dbConvoId, 12) : [];
    if (empty($msgs)) {
        $sessHistory = $_SESSION[$historyKey] ?? [];
        if (is_array($sessHistory)) {
            $msgs = array_slice($sessHistory, -12);
        }
    }
    $rendered = [];
    foreach ($msgs as $m) {
        $rendered[] = [
            'role' => $m['role'],
            'html' => $m['role'] === 'assistant'
                ? '<div class="ai-output">' . wuc_ai_render_markdown($m['content']) . '</div>'
                : htmlspecialchars($m['content'], ENT_QUOTES, 'UTF-8'),
        ];
    }
    echo json_encode(['ok' => true, 'messages' => $rendered]);
    exit;
}

if (!empty($payload['reset'])) {
    unset($_SESSION[$historyKey]);
    $newConvoId = wuc_chatbot_new_convo($db, $studentId, 'student', 'student');
    if ($newConvoId !== null) {
        $_SESSION[$convoSessionKey] = $newConvoId;
        $dbConvoId = $newConvoId;
    } else {
        unset($_SESSION[$convoSessionKey]);
        $dbConvoId = null;
    }
    echo json_encode(['ok' => true, 'reset' => true]);
    exit;
}

$message = trim((string)($payload['message'] ?? ''));
if ($message === '') {
    echo json_encode(['ok' => false, 'message' => 'Please type a message.']);
    exit;
}
if (mb_strlen($message) > 800) {
    $message = mb_substr($message, 0, 800);
}

$rate = wuc_ai_rate_limit('student_ai_chat', 40, 3600);
if (!$rate['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Too many messages. Please wait about ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.']);
    exit;
}

// Page context + scope guard: know which portal page the student is on and
// keep the conversation inside academic/portal support. Runs BEFORE any
// FAQ/LLM work so out-of-scope questions cost nothing and never reach the AI.
require_once __DIR__ . '/includes/ai_scope_guard.php';
$pageContext = wuc_ai_student_page_context((string)($payload['page'] ?? ''));
$scope = wuc_ai_message_scope($message, (string)$pageContext['module']);
if (!$scope['allowed']) {
    $reply = wuc_ai_scope_redirect_reply();
    $history = $_SESSION[$historyKey] ?? [];
    if (!is_array($history)) {
        $history = [];
    }
    $history[] = ['role' => 'user', 'content' => $message];
    $history[] = ['role' => 'assistant', 'content' => $reply];
    $_SESSION[$historyKey] = array_slice($history, -16);
    if ($dbConvoId !== null) {
        wuc_chatbot_save_turn($db, $dbConvoId, $message, $reply);
    }
    if (function_exists('wuc_ai_rate_limit_release')) {
        wuc_ai_rate_limit_release('student_ai_chat');
    }
    echo json_encode([
        'ok'          => true,
        'reply_html'  => '<div class="ai-output">' . wuc_ai_render_markdown($reply) . '</div>',
        'used_ai'     => false,
        'model'       => 'scope-guard',
        'history_len' => count($_SESSION[$historyKey]),
    ]);
    exit;
}

// Rolling history: session cache first, fall back to DB when session is empty (new login/device)
$history = $_SESSION[$historyKey] ?? [];
if (!is_array($history)) {
    $history = [];
}
if (empty($history) && $dbConvoId !== null) {
    $dbHistory = wuc_chatbot_load_history($db, $dbConvoId, 16);
    if (!empty($dbHistory)) {
        $history = $dbHistory;
        $_SESSION[$historyKey] = $history;
    }
}

// Fresh academic data context every turn.
$ctx = wuc_ai_student_context($db, $studentId);
$contextJson = wuc_ai_context_json($ctx, 12000);
$studentName   = trim((string)($ctx['name'] ?? ''));
$programName   = trim((string)($ctx['program_name'] ?? ''));
$yearOfStudy   = (int)($ctx['year_of_study'] ?? 0);
$periodLabel   = (string)($ctx['period_label'] ?? 'Semester');

$messageLower = strtolower($message);
$isRegisteredCoursesQuestion = preg_match('/\b(course|courses|class|classes)\b/', $messageLower)
    && preg_match('/\b(registered|enrolled|taking|doing|my)\b/', $messageLower);

if ($isRegisteredCoursesQuestion) {
    $courses = $ctx['registered_courses'] ?? [];
    $academicYear = trim((string)($ctx['semester_academic_year'] ?? $ctx['academic_year'] ?? ''));
    $periodLabel = (string)($ctx['period_label'] ?? (strtolower((string)($ctx['period_mode'] ?? 'semester')) === 'term' ? 'Term' : 'Semester'));
    $titleYear = $academicYear !== '' ? ' (' . $academicYear . ')' : '';
    if (!$courses) {
        $reply = "### Your Registered Courses{$titleYear}\n\nI could not find any active course registrations for your current registered period.";
    } else {
        $reply = "### Your Registered Courses{$titleYear}\n\n";
        $reply .= "| {$periodLabel} | Course Code | Course Name | Status |\n";
        $reply .= "|---|---|---|---|\n";
        foreach ($courses as $course) {
            $reply .= '| ' . (string)($course['period'] ?? '')
                . ' | ' . (string)($course['code'] ?? '')
                . ' | ' . (string)($course['name'] ?? '')
                . ' | ' . (string)($course['status'] ?? 'active')
                . " |\n";
        }
        $reply .= "\nThese are the active courses linked to your current registration record.";
    }

    $history[] = ['role' => 'user', 'content' => $message];
    $history[] = ['role' => 'assistant', 'content' => $reply];
    $_SESSION[$historyKey] = array_slice($history, -16);
    if ($dbConvoId !== null) {
        wuc_chatbot_save_turn($db, $dbConvoId, $message, $reply);
    }
    if (function_exists('wuc_ai_rate_limit_release')) {
        wuc_ai_rate_limit_release('student_ai_chat');
    }

    echo json_encode([
        'ok'          => true,
        'reply_html'  => '<div class="ai-output">' . wuc_ai_render_markdown($reply) . '</div>',
        'used_ai'     => false,
        'model'       => 'portal-data',
        'history_len' => count($_SESSION[$historyKey]),
    ]);
    exit;
}

// FAQ-first (Sprint 9): confident knowledge-base matches answer instantly with
// zero AI cost; everything else continues to the tutoring/LLM path below.
require_once dirname(__DIR__) . '/includes/faq_chat_engine.php';
$faqReply = wuc_faq_response($db, $message, 'student', $studentId);
if ($faqReply !== null) {
    $history[] = ['role' => 'user', 'content' => $message];
    $history[] = ['role' => 'assistant', 'content' => $faqReply];
    $_SESSION[$historyKey] = array_slice($history, -16);
    if ($dbConvoId !== null) {
        wuc_chatbot_save_turn($db, $dbConvoId, $message, $faqReply);
    }
    if (function_exists('wuc_ai_rate_limit_release')) {
        wuc_ai_rate_limit_release('student_ai_chat');
    }
    echo json_encode([
        'ok'          => true,
        'reply_html'  => '<div class="ai-output">' . wuc_ai_render_markdown($faqReply) . '</div>',
        'used_ai'     => false,
        'model'       => 'faq-knowledge-base',
        'history_len' => count($_SESSION[$historyKey]),
    ]);
    exit;
}

// Detect tutoring intent (explanations, exam prep, revision, syllabus) and, when a
// specific registered course can be identified from the message, ground the reply in
// that course's actual lesson material instead of only registration/marks data. This
// is what makes the "Study Sessions with AI Tutor" promo genuinely subject-specific.
$courseMaterialsContext = null;
$isTutoringQuestion = preg_match(
    '/\b(explain|understand|teach|tutor|study|revise|revision|exam|test|quiz|syllabus|topic|chapter|concept|notes|lesson)\b/i',
    $message
) === 1;
if ($isTutoringQuestion) {
    $registeredCourses = $ctx['registered_courses'] ?? [];
    $matchedCourse = null;
    foreach ($registeredCourses as $course) {
        $code = (string)($course['code'] ?? '');
        $name = (string)($course['name'] ?? '');
        if ($code !== '' && preg_match('/\b' . preg_quote($code, '/') . '\b/i', $message)) {
            $matchedCourse = $course;
            break;
        }
        if ($name !== '' && stripos($message, $name) !== false) {
            $matchedCourse = $course;
            break;
        }
    }
    if ($matchedCourse === null && count($registeredCourses) === 1) {
        $matchedCourse = $registeredCourses[0];
    }
    if ($matchedCourse !== null) {
        $materials = wuc_ai_course_study_materials($db, (string)$matchedCourse['code'], 8);
        if ($materials) {
            $courseMaterialsContext = [
                'course_code' => $matchedCourse['code'],
                'course_name' => $matchedCourse['name'],
                'materials' => $materials,
            ];
        }
    }
}

// Build personalised identity line for the system prompt
$_identityParts = [];
if ($studentName !== '') {
    $_identityParts[] = "You are speaking with {$studentName}";
    if ($programName !== '') {
        $_identityParts[] = "enrolled in {$programName}";
        if ($yearOfStudy > 0) {
            $_identityParts[] = "Year {$yearOfStudy}";
        }
    }
}
$_identityLine = !empty($_identityParts)
    ? implode(', ', $_identityParts) . '. Address them by their first name when appropriate.'
    : 'You are speaking with a student on the ITC portal.';

$messages = [
    [
        'role' => 'system',
        'content' => 'You are the ITC Student Assistant, an academic adviser on the ITC student portal. '
                   . $_identityLine . ' '
                   . 'Engage in natural, multi-turn conversation. Answer questions about the student\'s '
                   . 'registration, courses, fees (always in ZMW), and assessments using only the academic data '
                   . 'provided. If asked about something not in the provided data, respond helpfully but indicate '
                   . 'that the specific record is not available and direct the student to the relevant portal page '
                   . 'or office. '
                   . 'You also act as a subject tutor for study sessions: when a later system message supplies '
                   . 'course study material, use it to give specific, accurate explanations, exam preparation, '
                   . 'and syllabus guidance grounded strictly in that material — do not invent syllabus content. '
                   . 'If no course material is supplied for a tutoring question, say so and ask the student which '
                   . 'course or topic to focus on rather than answering generically. '
                   . 'Data field guide — use these exactly: '
                   . '`period_label` is the correct word for an academic period ("Semester", "Term", "Module", or "Intake") — always use this word, never substitute "semester" if the label says otherwise. '
                   . '`current_period` is the period number (1, 2, etc.) within the academic year — combine with `period_label` to say e.g. "Term 1". '
                   . '`attendance_mode` ("Full Time" or "Part Time") describes how the student attends physically — it is NOT the academic period structure; never use it to describe periods. '
                   . '`period_mode` is the internal code (e.g. "term", "semester") — prefer `period_label` when speaking to the student. '
                   . 'Write in clear, formal English appropriate for an academic institution. Use structured '
                   . 'headings or tables only when the content genuinely requires them. Avoid emojis, decorative '
                   . 'symbols, unnecessary bold text, excessive bullet points, and phrases that sound scripted or '
                   . 'artificially generated such as "Certainly", "I hope this helps", or "As an AI". '
                   . 'Keep responses direct, respectful, and professionally appropriate. '
                   . 'Never invent grades, balances, or records not present in the context.',
    ],
    [
        'role' => 'system',
        'content' => "Current student academic data (JSON, refreshed this turn):\n{$contextJson}",
    ],
    [
        'role' => 'system',
        'content' => 'Current page context: the student is on the "' . $pageContext['module'] . '" page ('
            . $pageContext['page_file'] . '). When they ask what to do "here" or about "this page", explain that page. '
            . 'Typical help topics for this page: ' . implode(', ', (array)$pageContext['allowed_help_topics']) . '. '
            . 'Scope rules: only answer student portal, academic, and institutional support questions. Never discuss '
            . 'unrelated topics (politics, sports, entertainment, dating, investments, medical advice); if asked, reply '
            . 'exactly with a polite redirect to academic support. Never reveal other students\' data, staff private '
            . 'information, database structure, SQL, credentials, or internal system paths.',
    ],
];

if ($courseMaterialsContext !== null) {
    $messages[] = [
        'role' => 'system',
        'content' => "Course study material for {$courseMaterialsContext['course_code']} ({$courseMaterialsContext['course_name']}), refreshed this turn:\n"
            . wuc_ai_context_json($courseMaterialsContext, 8000),
    ];
}

// Replay capped history.
$recent = array_slice($history, -16);
foreach ($recent as $turn) {
    $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $messages[] = ['role' => $role, 'content' => (string)($turn['content'] ?? '')];
}
$messages[] = ['role' => 'user', 'content' => $message];

$result = wuc_ai_generate($db, [
    'feature'       => 'student_ai_chat',
    'user_role'     => 'student',
    'user_id'       => $studentId,
    'input_summary' => mb_substr($message, 0, 200),
    'context_hash'  => hash('sha256', $contextJson),
    'messages'      => $messages,
    'fallback'      => static function () use ($ctx): string {
        $bal = number_format((float)($ctx['fees']['balance_due'] ?? 0), 2);
        $courses = count($ctx['registered_courses'] ?? []);
        return "I can't reach the AI service right now, but here's a quick snapshot:\n\n"
            . "- **Registered courses:** {$courses}\n"
            . "- **Outstanding balance:** ZMW {$bal}\n\n"
            . "Please try again in a moment, or check the Fees and My Courses pages.";
    },
]);

$reply = (string)$result['text'];

// Persist this turn.
$history[] = ['role' => 'user', 'content' => $message];
$history[] = ['role' => 'assistant', 'content' => $reply];
$_SESSION[$historyKey] = array_slice($history, -16);

// Save to DB for cross-session memory
if ($dbConvoId !== null) {
    wuc_chatbot_save_turn($db, $dbConvoId, $message, $reply);
}

echo json_encode([
    'ok'          => true,
    'reply_html'  => '<div class="ai-output">' . wuc_ai_render_markdown($reply) . '</div>',
    'used_ai'     => (bool)$result['used_ai'],
    'model'       => (string)$result['model'],
    'history_len' => count($_SESSION[$historyKey]),
]);
