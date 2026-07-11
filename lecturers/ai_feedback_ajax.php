<?php
declare(strict_types=1);

/**
 * AJAX endpoint: AI-drafted feedback + suggested mark for an assignment submission.
 * Used by grade_assignment.php. Advisory only — the lecturer reviews and decides.
 *
 * POST (JSON or form): submission_id, csrf_token
 * Returns JSON: { ok, feedback, suggested_mark, used_ai, model, note }
 */

require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/ai_portal.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

// Accept JSON or form-encoded.
$raw = file_get_contents('php://input');
$payload = [];
if ($raw !== '' && ($decoded = json_decode($raw, true)) && is_array($decoded)) {
    $payload = $decoded;
} else {
    $payload = $_POST;
}

$token = (string)($payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid security token. Refresh and try again.']);
    exit;
}

$staffId = (string)($_SESSION['staff_id'] ?? '');
$submissionId = (int)($payload['submission_id'] ?? 0);
if ($submissionId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid submission.']);
    exit;
}

// Load submission + assignment (same shape as grade_assignment.php).
$sql = "SELECT s.id, s.assignment_id, s.Sid, s.text_body, s.submitted_at, s.due_at AS s_due,
               a.course_code, a.title AS assignment_title, a.description AS assignment_description, a.due_at
        FROM el_submissions s
        INNER JOIN el_assignments a ON a.id = s.assignment_id
        WHERE s.id = ? LIMIT 1";
$submission = null;
if ($stmt = $db->prepare($sql)) {
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$submission) {
    echo json_encode(['ok' => false, 'message' => 'Submission not found.']);
    exit;
}

// Authorization: lecturer must be assigned to the course.
if (!isLecturerAssignedToCourse($db, $staffId, (string)$submission['course_code'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'You are not assigned to this course.']);
    exit;
}

$studentText = trim((string)($submission['text_body'] ?? ''));
if ($studentText === '') {
    echo json_encode([
        'ok' => true,
        'used_ai' => false,
        'feedback' => "No text response was submitted for this assignment, so automated feedback on written content is not possible. "
                    . "If the work was submitted as a file, please open and review it manually before awarding a mark.",
        'suggested_mark' => '',
        'note' => 'No text body to analyse.',
    ]);
    exit;
}

$rate = wuc_ai_rate_limit('lecturer_feedback_assistant', 30, 3600);
if (!$rate['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Too many AI requests. Try again in about ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.']);
    exit;
}

// Privacy: send the work + task, not the student's identity.
$context = [
    'assignment_title'       => (string)$submission['assignment_title'],
    'assignment_description' => wuc_ai_truncate((string)$submission['assignment_description'], 1500),
    'course_code'            => (string)$submission['course_code'],
    'student_submission'     => wuc_ai_truncate($studentText, 6000),
    'rules' => [
        'advisory_only_lecturer_decides_final_mark' => true,
        'base_only_on_submitted_text_and_task' => true,
        'constructive_and_specific' => true,
    ],
];
$contextJson = wuc_ai_context_json($context, 12000);

$fallback = static function () use ($studentText): string {
    $words = str_word_count($studentText);
    return "FEEDBACK (AI offline — manual review needed)\n\n"
        . "The submission contains approximately {$words} words.\n\n"
        . "Suggested review checklist:\n"
        . "1. Does the response address all parts of the assignment task?\n"
        . "2. Is the argument/structure clear and logically organised?\n"
        . "3. Is there evidence of understanding of key concepts?\n"
        . "4. Are claims supported with examples or references?\n"
        . "5. Note specific strengths and 2-3 concrete improvements.\n\n"
        . "SUGGESTED_MARK:";
};

$result = wuc_ai_generate($db, [
    'feature'       => 'lecturer_feedback_assistant',
    'user_role'     => 'lecturer',
    'user_id'       => $staffId,
    'input_summary' => 'submission#' . $submissionId . ' | ' . $submission['course_code'],
    'context_hash'  => hash('sha256', $contextJson),
    'messages'      => [
        [
            'role' => 'system',
            'content' => 'You are a marking assistant for an ITC lecturer. Read the assignment task and the student\'s '
                       . 'submitted text, then write constructive, specific feedback the lecturer can adapt. '
                       . 'Structure it as: Strengths, Areas for improvement, and 2-3 concrete suggestions. '
                       . 'Base your assessment ONLY on the submitted text and the task. You are advisory; the lecturer '
                       . 'sets the final mark. End your reply with a separate final line in EXACTLY this format: '
                       . '"SUGGESTED_MARK: <number 0-100>".',
        ],
        [
            'role' => 'user',
            'content' => "Marking context (JSON):\n{$contextJson}\n\nWrite the feedback now, ending with the SUGGESTED_MARK line.",
        ],
    ],
    'fallback' => $fallback,
]);

// Extract the suggested mark from the trailing line.
$text = (string)$result['text'];
$suggestedMark = '';
if (preg_match('/SUGGESTED[_\s]?MARK\s*[:=]?\s*([0-9]{1,3}(?:\.[0-9]+)?)/i', $text, $m)) {
    $val = (float)$m[1];
    if ($val >= 0 && $val <= 100) {
        $suggestedMark = (string)$val;
    }
    // Remove the machine line from the human-facing feedback.
    $text = trim(preg_replace('/\n?\s*SUGGESTED[_\s]?MARK\s*[:=]?\s*[0-9.]*\s*$/i', '', $text));
}

echo json_encode([
    'ok'             => true,
    'used_ai'        => (bool)$result['used_ai'],
    'feedback'       => $text,
    'suggested_mark' => $suggestedMark,
    'model'          => (string)$result['model'],
    'note'           => $result['used_ai'] ? '' : wuc_ai_fallback_notice($result),
]);
