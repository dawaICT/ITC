<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';
require_once __DIR__ . '/../../includes/elearning_assessment_security.php';

$sid = $_SESSION['Sid'] ?? null;
if (!$sid) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
elearningAssessmentEnsureSchema($db);
if (!elearningAssessmentValidateCsrf($_POST)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Security check failed. Please refresh and try again.']);
    exit;
}

$attemptId = isset($_POST['attempt_id']) ? (int)$_POST['attempt_id'] : 0;
if ($attemptId <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid attempt']); exit; }

// Ensure attempt belongs to this student
$stmt = $db->prepare("SELECT a.quiz_id, a.started_at, a.submitted_at, q.* FROM el_attempts a INNER JOIN el_quizzes q ON a.quiz_id = q.id WHERE a.id=? AND a.Sid=? LIMIT 1");
$stmt->bind_param('is', $attemptId, $sid); $stmt->execute();
$res = $stmt->get_result(); $row = $res->fetch_assoc(); $stmt->close();
if (!$row) { echo json_encode(['success'=>false,'error'=>'Attempt not found']); exit; }
$quizId = (int)$row['quiz_id'];
$courseCode = $row['course_code'] ?? '';
if (!empty($row['submitted_at'])) {
    echo json_encode(['success'=>false,'error'=>'This attempt has already been submitted.']);
    exit;
}

// Verify student enrollment in course
if ($courseCode && !canStudentAccessElearningCourse($db, $sid, $courseCode)) {
    echo json_encode(['success'=>false,'error'=>'Access denied - not enrolled in this course']); exit;
}
$quizOfferingId = (int)($row['course_offering_id'] ?? 0);
if ($quizOfferingId > 0 && !in_array($quizOfferingId, getStudentCourseOfferingIds($db, $sid, (string)$courseCode), true)) {
    echo json_encode(['success'=>false,'error'=>'Access denied - assessment is not assigned to your class']); exit;
}
if ((int)($row['is_published'] ?? 0) !== 1) {
    echo json_encode(['success'=>false,'error'=>'Assessment is not published.']); exit;
}
$availability = elearningAssessmentQuizAvailability($db, $row, (string)$sid);
if (empty($availability['ok'])) {
    echo json_encode(['success'=>false,'error'=>(string)($availability['reason'] ?? 'Assessment is closed.')]); exit;
}
$settings = (array)($availability['settings'] ?? elearningAssessmentSettings($row['settings_json'] ?? null, ['max_attempts' => 1]));
$timeLimit = isset($row['time_limit_minutes']) ? (int)$row['time_limit_minutes'] : 0;
if ($timeLimit > 0 && !empty($row['started_at'])) {
    $startedAt = strtotime((string)$row['started_at']);
    if ($startedAt && time() > ($startedAt + ($timeLimit * 60) + 60)) {
        echo json_encode(['success'=>false,'error'=>'Time limit exceeded. Contact your lecturer if you need this attempt reviewed.']);
        exit;
    }
}


// Load questions/options for grading
$questions = [];
$qStmt = $db->prepare("SELECT * FROM el_questions WHERE quiz_id=?");
$qStmt->bind_param('i', $quizId); $qStmt->execute();
$qRes = $qStmt->get_result();
while ($q = $qRes->fetch_assoc()) { $questions[$q['id']] = $q; }
$qStmt->close();

$optionsByQuestion = [];
foreach (array_keys($questions) as $qid) {
    $oStmt = $db->prepare("SELECT * FROM el_question_options WHERE question_id=?");
    $oStmt->bind_param('i', $qid); $oStmt->execute();
    $oRes = $oStmt->get_result();
    $optionsByQuestion[$qid] = [];
    while ($o = $oRes->fetch_assoc()) { $optionsByQuestion[$qid][$o['id']] = $o; }
    $oStmt->close();
}

$total = 0.0; $earned = 0.0;
$documentResponse = trim((string)($_POST['document_response'] ?? ''));
$hasAnyQuestion = (count($questions) > 0);
if (!$hasAnyQuestion && $documentResponse === '') {
    echo json_encode(['success'=>false,'error'=>'Please type your response before submitting.']);
    exit;
}

if ($deleteAnswers = $db->prepare("DELETE FROM el_attempt_answers WHERE attempt_id=?")) {
    $deleteAnswers->bind_param('i', $attemptId);
    $deleteAnswers->execute();
    $deleteAnswers->close();
}

foreach ($questions as $qid => $q) {
    $total += (float)$q['points'];
    $field = 'q_' . $qid . ($q['question_type']==='mcq_multi' ? '' : '');
    if ($q['question_type'] === 'mcq_multi') {
        $selected = isset($_POST['q_'.$qid]) ? (array)$_POST['q_'.$qid] : [];
        $selectedInt = array_map('intval', $selected);
        $correct = array_keys(array_filter($optionsByQuestion[$qid], fn($o)=> (int)$o['is_correct']===1));
        sort($selectedInt); sort($correct);
        $isCorrect = ($selectedInt === $correct);
        $points = $isCorrect ? (float)$q['points'] : 0.0;
        $earned += $points;
        $ansStmt = $db->prepare("INSERT INTO el_attempt_answers (attempt_id, question_id, selected_option_ids, is_correct, points_awarded) VALUES (?,?,?,?,?)");
        $sel = implode(',', $selectedInt);
        $ic = $isCorrect ? 1 : 0; $ansStmt->bind_param('iisid', $attemptId, $qid, $sel, $ic, $points);
        $ansStmt->execute(); $ansStmt->close();
    } elseif ($q['question_type'] === 'mcq_single' || $q['question_type']==='true_false') {
        $selected = isset($_POST['q_'.$qid]) ? (int)$_POST['q_'.$qid] : 0;
        $isCorrect = $selected && isset($optionsByQuestion[$qid][$selected]) && (int)$optionsByQuestion[$qid][$selected]['is_correct'] === 1;
        $points = $isCorrect ? (float)$q['points'] : 0.0;
        $earned += $points;
        $ansStmt = $db->prepare("INSERT INTO el_attempt_answers (attempt_id, question_id, selected_option_ids, is_correct, points_awarded) VALUES (?,?,?,?,?)");
        $sel = $selected ? (string)$selected : '';
        $ic = $isCorrect ? 1 : 0; $ansStmt->bind_param('iisid', $attemptId, $qid, $sel, $ic, $points);
        $ansStmt->execute(); $ansStmt->close();
    } else {
        $text = trim((string)($_POST['q_'.$qid] ?? ''));
        $ansStmt = $db->prepare("INSERT INTO el_attempt_answers (attempt_id, question_id, text_answer, is_correct, points_awarded) VALUES (?,?,?,NULL,NULL)");
        $ansStmt->bind_param('iis', $attemptId, $qid, $text);
        $ansStmt->execute(); $ansStmt->close();
    }
}

// finalize attempt
$score = $total > 0 ? round(($earned / $total) * 100, 2) : 0.0;
$status = 'auto';
$malpracticeFlags = trim((string)($_POST['malpractice_flags'] ?? '{}'));
$decodedFlags = json_decode($malpracticeFlags, true);
$malpracticeFlags = is_array($decodedFlags) ? json_encode($decodedFlags, JSON_UNESCAPED_SLASHES) : '{}';
$timeSpent = max(0, (int)($_POST['time_spent_seconds'] ?? 0));
$submittedReason = substr(preg_replace('/[^a-z_]/', '', (string)($_POST['submitted_reason'] ?? 'manual')), 0, 40);
if ($submittedReason === '') { $submittedReason = 'manual'; }
$stmt = $db->prepare("UPDATE el_attempts SET submitted_at=NOW(), score=?, grading_status=?, response_text=?, malpractice_flags_json=?, time_spent_seconds=?, submitted_reason=? WHERE id=?");
$stmt->bind_param('dsssisi', $score, $status, $documentResponse, $malpracticeFlags, $timeSpent, $submittedReason, $attemptId); $stmt->execute(); $stmt->close();

$showScore = !array_key_exists('show_result_immediately', $settings) || !empty($settings['show_result_immediately']);
echo json_encode(['success'=>true, 'score'=>$showScore ? $score : null]);
exit;


