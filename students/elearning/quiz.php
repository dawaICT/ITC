<?php
error_reporting(0);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';
require_once __DIR__ . '/../../includes/elearning_assessment_security.php';

$sid = $_SESSION['Sid'] ?? null;
$quizId = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
if (!$sid || !$quizId) { die('Unauthorized'); }

// Load quiz
$stmt = $db->prepare("SELECT * FROM el_quizzes WHERE id=? AND is_published=1 LIMIT 1");
$stmt->bind_param('i', $quizId); $stmt->execute();
$res = $stmt->get_result(); $quiz = $res->fetch_assoc(); $stmt->close();
if (!$quiz) { die('Quiz not available'); }

$courseCode = $quiz['course_code'];
$attachmentPath = trim((string)($quiz['attachment_path'] ?? ''));
$attachmentExt = strtolower(pathinfo($attachmentPath, PATHINFO_EXTENSION));
$hasAttachment = ($attachmentPath !== '');
$baseHref = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/wucportal/students/elearning/quiz.php'))), '/') . '/';

// Verify student is enrolled in this course
enforceStudentCourseAccess($db, $sid, $courseCode);
$quizOfferingId = (int)($quiz['course_offering_id'] ?? 0);
if ($quizOfferingId > 0 && !in_array($quizOfferingId, getStudentCourseOfferingIds($db, $sid, $courseCode), true)) {
    die('Quiz not available for your registered class.');
}
elearningAssessmentEnsureSchema($db);
$availability = elearningAssessmentQuizAvailability($db, $quiz, (string)$sid);
if (empty($availability['ok'])) {
    die(htmlspecialchars((string)($availability['reason'] ?? 'Assessment is not available.')));
}
$quizSettings = (array)($availability['settings'] ?? elearningAssessmentSettings($quiz['settings_json'] ?? null, ['max_attempts' => 1]));
$csrfToken = elearningAssessmentCsrfToken();

$attemptId = elearningAssessmentFindOrCreateAttempt($db, $quizId, (string)$sid);
if ($attemptId <= 0) { die('Could not start assessment attempt.'); }

// Load questions (shuffle if enabled)
$questions = [];
if ($stmt = $db->prepare("SELECT * FROM el_questions WHERE quiz_id=? ORDER BY position, id")) {
    $stmt->bind_param('i', $quizId);
    $stmt->execute(); $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $questions[] = $row; }
    $stmt->close();
}
if ($quiz['shuffle_questions']) { shuffle($questions); }
$hasStructuredQuestions = (count($questions) > 0);

// Calculate total points
$totalPoints = 0;
foreach ($questions as $q) { $totalPoints += (float)$q['points']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($quiz['title']); ?> - Quiz</title>
    <base href="<?php echo htmlspecialchars($baseHref); ?>">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .quiz-header { background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: #fff; padding: 25px; border-radius: 12px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; }
        .quiz-header h2 { margin: 0; }
        .quiz-info { display: flex; gap: 20px; }
        .quiz-info-item { text-align: center; }
        .quiz-info-item .value { font-size: 1.5rem; font-weight: 600; }
        .quiz-info-item .label { font-size: 0.85rem; opacity: 0.9; }
        .question-card { background: #fff; border: 1px solid #e9ecef; border-radius: 12px; margin-bottom: 20px; overflow: hidden; }
        .question-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; }
        .question-number { background: #ff9800; color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 0.85rem; }
        .question-points { color: #666; font-size: 0.9rem; }
        .question-text { padding: 20px; font-size: 1.05rem; }
        .question-options { padding: 0 20px 20px; }
        .option-item { display: flex; align-items: center; padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 8px; margin-bottom: 10px; cursor: pointer; transition: all 0.2s; }
        .option-item:hover { border-color: #ff9800; background: #fff8e1; }
        .option-item input { margin-right: 12px; }
        .option-item.selected { border-color: #ff9800; background: #fff8e1; }
        .timer-box { background: rgba(255,255,255,0.2); padding: 10px 20px; border-radius: 8px; }
        #timer { font-size: 1.5rem; font-weight: 600; }
        .submit-section { position: sticky; bottom: 20px; background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 -4px 20px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; }
        .doc-preview { width: 100%; height: 600px; border: 1px solid #dee2e6; border-radius: 8px; background: #fff; }
        .doc-response textarea { min-height: 180px; }
    </style>

<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="content-wrapper">
    <div class="quiz-header">
        <div>
            <h2><i class="fas fa-pen"></i> <?php echo htmlspecialchars($quiz['title']); ?></h2>
            <p style="margin: 5px 0 0; opacity: 0.9;"><?php echo htmlspecialchars($courseCode); ?></p>
        </div>
        <div class="quiz-info">
            <div class="quiz-info-item">
                <div class="value"><?php echo count($questions); ?></div>
                <div class="label">Questions</div>
            </div>
            <div class="quiz-info-item">
                <div class="value"><?php echo $totalPoints; ?></div>
                <div class="label">Points</div>
            </div>
            <?php if ($quiz['time_limit_minutes']): ?>
            <div class="timer-box">
                <div id="timer"><?php echo (int)$quiz['time_limit_minutes']; ?>:00</div>
                <div class="label">Remaining</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (!empty($quiz['description'])): ?>
    <div class="alert alert-info mb-4">
        <i class="fas fa-info-circle"></i> <?php echo nl2br(htmlspecialchars($quiz['description'])); ?>
    </div>
    <?php endif; ?>

    <?php if ($hasAttachment): ?>
    <div class="card mb-4">
        <div class="card-header"><strong><i class="fas fa-file-alt me-1"></i>Assessment Document</strong></div>
        <div class="card-body">
            <?php if ($attachmentExt === 'pdf'): ?>
                <iframe class="doc-preview" src="../<?php echo htmlspecialchars($attachmentPath); ?>"></iframe>
            <?php else: ?>
                <div class="alert alert-warning mb-2">
                    Preview is available for PDF files only.
                </div>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary mt-2" href="../<?php echo htmlspecialchars($attachmentPath); ?>" target="_blank">
                <i class="fas fa-download"></i> Download Document
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <form id="quizForm">
        <input type="hidden" name="attempt_id" value="<?php echo (int)$attemptId; ?>">
        <?php echo elearningAssessmentCsrfField(); ?>
        <input type="hidden" name="malpractice_flags" id="malpracticeFlags" value="{}">
        <input type="hidden" name="time_spent_seconds" id="timeSpentSeconds" value="0">
        <input type="hidden" name="submitted_reason" id="submittedReason" value="manual">
        <?php foreach ($questions as $idx => $q): 
            $options = [];
            if (in_array($q['question_type'], ['mcq_single','mcq_multi','true_false'])) {
                if ($stmt = $db->prepare("SELECT * FROM el_question_options WHERE question_id=? ORDER BY position, id")) {
                    $stmt->bind_param('i', $q['id']); $stmt->execute();
                    $r = $stmt->get_result(); 
                    while ($row = $r->fetch_assoc()) { $options[] = $row; } 
                    $stmt->close();
                }
                if ($quiz['shuffle_options']) { shuffle($options); }
            }
        ?>
            <div class="question-card">
                <div class="question-header">
                    <span class="question-number">Question <?php echo $idx + 1; ?></span>
                    <span class="question-points"><?php echo (float)$q['points']; ?> point<?php echo $q['points'] != 1 ? 's' : ''; ?></span>
                </div>
                <div class="question-text"><?php echo htmlspecialchars($q['question_text']); ?></div>
                <div class="question-options">
                    <?php if (in_array($q['question_type'], ['mcq_single', 'mcq_multi', 'true_false'])): ?>
                        <?php foreach ($options as $opt): ?>
                            <label class="option-item" onclick="selectOption(this, '<?php echo $q['question_type']; ?>')">
                                <input type="<?php echo $q['question_type'] === 'mcq_multi' ? 'checkbox' : 'radio'; ?>" 
                                       name="q_<?php echo $q['id']; ?><?php echo $q['question_type'] === 'mcq_multi' ? '[]' : ''; ?>" 
                                       value="<?php echo (int)$opt['id']; ?>">
                                <?php echo htmlspecialchars($opt['option_text']); ?>
                            </label>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <textarea class="form-control" name="q_<?php echo $q['id']; ?>" rows="4" placeholder="Type your answer here..."></textarea>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$hasStructuredQuestions): ?>
            <div class="question-card doc-response">
                <div class="question-header">
                    <span class="question-number">Online Response</span>
                    <span class="question-points">Required</span>
                </div>
                <div class="question-text">Type your answer based on the assessment document/instructions above.</div>
                <div class="question-options">
                    <textarea class="form-control" name="document_response" rows="8" placeholder="Write your answer here..." required></textarea>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="submit-section">
            <div><span id="answeredCount">0</span> of <?php echo $hasStructuredQuestions ? count($questions) : 1; ?> answered</div>
            <button class="btn btn-primary btn-lg" type="button" onclick="submitQuiz()">
                <i class="fas fa-paper-plane"></i> <?php echo $hasStructuredQuestions ? 'Submit Quiz' : 'Submit Response'; ?>
            </button>
        </div>
    </form>
</div>

<script>
// Option selection highlighting
function selectOption(el, type) {
    if (type !== 'mcq_multi') {
        el.closest('.question-options').querySelectorAll('.option-item').forEach(o => o.classList.remove('selected'));
    }
    setTimeout(() => {
        if (el.querySelector('input').checked) {
            el.classList.add('selected');
        } else {
            el.classList.remove('selected');
        }
        updateAnsweredCount();
    }, 10);
}

// Count answered questions
function updateAnsweredCount() {
    let answered = 0;
    document.querySelectorAll('.question-card').forEach(card => {
        const inputs = card.querySelectorAll('input:checked, textarea');
        let hasAnswer = false;
        inputs.forEach(inp => {
            if (inp.type === 'checkbox' || inp.type === 'radio') {
                if (inp.checked) hasAnswer = true;
            } else if (inp.value.trim() !== '') {
                hasAnswer = true;
            }
        });
        if (hasAnswer) answered++;
    });
    document.getElementById('answeredCount').textContent = answered;
}

// Timer
<?php if ($quiz['time_limit_minutes']): ?>
let timeLeft = <?php echo (int)$quiz['time_limit_minutes']; ?> * 60;
const timerEl = document.getElementById('timer');
const timerInterval = setInterval(() => {
    timeLeft--;
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        alert('Time is up! Submitting quiz...');
        document.getElementById('submittedReason').value = 'time_expired';
        submitQuiz();
        return;
    }
    const mins = Math.floor(timeLeft / 60);
    const secs = timeLeft % 60;
    timerEl.textContent = mins + ':' + (secs < 10 ? '0' : '') + secs;
    if (timeLeft <= 60) {
        timerEl.style.color = '#dc3545';
    }
}, 1000);
<?php endif; ?>

async function submitQuiz() {
    document.getElementById('timeSpentSeconds').value = String(Math.max(0, Math.floor((Date.now() - quizStartedAt) / 1000)));
    document.getElementById('malpracticeFlags').value = JSON.stringify(malpracticeFlags);
    if (!confirm('Are you sure you want to submit this quiz?')) return;
    
    const form = document.getElementById('quizForm');
    const data = new FormData(form);
    const btn = document.querySelector('.submit-section button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    
    try {
        const res = await fetch('elearning/quiz_submit.php', { method: 'POST', body: data });
        const j = await res.json();
        if (j.success) {
            const scoreMessage = j.score === null || typeof j.score === 'undefined' ? 'Pending' : (j.score + '%');
            alert('Quiz submitted successfully!\n\nYour score: ' + scoreMessage);
            window.location.href = 'elearning/course.php?course_code=<?php echo urlencode($courseCode); ?>';
        } else {
            alert('Error: ' + (j.error || 'Failed to submit'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Quiz';
        }
    } catch (e) {
        alert('Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Quiz';
    }
}

// Track quiz start
const quizStartedAt = Date.now();
const malpracticeFlags = {
    tab_blurs: 0,
    visibility_hidden: 0,
    copy_attempts: 0,
    paste_attempts: 0,
    context_menu_attempts: 0,
    fullscreen_exits: 0
};

function bumpFlag(name) {
    malpracticeFlags[name] = (malpracticeFlags[name] || 0) + 1;
    const field = document.getElementById('malpracticeFlags');
    if (field) field.value = JSON.stringify(malpracticeFlags);
}

<?php if (!empty($quizSettings['disable_copy_paste'])): ?>
['copy', 'paste', 'cut'].forEach(function(eventName) {
    document.addEventListener(eventName, function(e) {
        bumpFlag(eventName === 'paste' ? 'paste_attempts' : 'copy_attempts');
        e.preventDefault();
    });
});
document.addEventListener('contextmenu', function(e) {
    bumpFlag('context_menu_attempts');
    e.preventDefault();
});
<?php endif; ?>

window.addEventListener('blur', function() { bumpFlag('tab_blurs'); });
document.addEventListener('visibilitychange', function() {
    if (document.hidden) bumpFlag('visibility_hidden');
});
document.addEventListener('fullscreenchange', function() {
    if (!document.fullscreenElement) bumpFlag('fullscreen_exits');
});

document.addEventListener('DOMContentLoaded', function() {
    updateAnsweredCount();
    document.querySelectorAll('#quizForm textarea, #quizForm input').forEach(function(el) {
        el.addEventListener('input', updateAnsweredCount);
        el.addEventListener('change', updateAnsweredCount);
    });
    <?php if (!empty($quizSettings['require_fullscreen'])): ?>
    document.body.addEventListener('click', function requestAssessmentFullscreen() {
        if (document.documentElement.requestFullscreen && !document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(function() {});
        }
        document.body.removeEventListener('click', requestAssessmentFullscreen);
    });
    <?php endif; ?>
});
</script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
<script src="track_event.js"></script>
<script>
if (typeof ElearnTrack !== 'undefined') {
    ElearnTrack.quizStart('<?php echo htmlspecialchars($courseCode); ?>', <?php echo (int)$quizId; ?>);
}
</script>
</body>
</html>



