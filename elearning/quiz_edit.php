<?php
error_reporting(0);
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_assessment_security.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = $_GET['course_code'] ?? ($_POST['course_code'] ?? '');
$quizId = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0));
if (!$staffId || !$courseCode) { die('Unauthorized'); }

// Verify lecturer has access to this course
enforceLecturerCourseAccess($db, $staffId, $courseCode);
elearningAssessmentEnsureSchema($db);
$courseOfferingId = getLecturerCourseOfferingId($db, $staffId, $courseCode);
$quizHasOffering = elearningTableHasCourseOffering($db, 'el_quizzes');

$errors = [];
$successMsg = '';
$csrfToken = elearningAssessmentCsrfToken();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!elearningAssessmentValidateCsrf($_POST)) {
        $errors[] = 'Security check failed. Please refresh the page and try again.';
    }
    $action = $_POST['action'] ?? 'save_quiz';
    
    if ($action === 'save_quiz') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $timeLimit = isset($_POST['time_limit']) && $_POST['time_limit'] !== '' ? (int)$_POST['time_limit'] : null;
        $shuffleQuestions = isset($_POST['shuffle_questions']) ? 1 : 0;
        $shuffleOptions = isset($_POST['shuffle_options']) ? 1 : 0;
        $isPublished = isset($_POST['is_published']) ? 1 : 0;
        $moduleId = isset($_POST['module_id']) && $_POST['module_id'] !== '' ? (int)$_POST['module_id'] : null;
        $assessmentType = in_array(($_POST['assessment_type'] ?? 'quiz'), ['quiz', 'test'], true) ? (string)$_POST['assessment_type'] : 'quiz';
        $dueAt = elearningAssessmentNormalizeDateTime(trim($_POST['due_at'] ?? ''), $errors, 'Due date');
        $availableFrom = elearningAssessmentNormalizeDateTime(trim($_POST['available_from'] ?? ''), $errors, 'Open date');
        $settings = elearningAssessmentQuizSettingsFromPost($_POST);
        $settings['available_from'] = $availableFrom ?? '';
        $settingsJson = elearningAssessmentJson($settings);

        if ($title === '') { $errors[] = 'Title is required'; }
        if ($timeLimit !== null && ($timeLimit < 1 || $timeLimit > 480)) { $errors[] = 'Time limit must be between 1 and 480 minutes.'; }
        if ($availableFrom && $dueAt && strtotime($availableFrom) >= strtotime($dueAt)) { $errors[] = 'Open date must be before due date.'; }

        if (!$errors) {
            if ($quizId > 0) {
                if ($quizHasOffering && $courseOfferingId !== null) {
                    $stmt = $db->prepare("UPDATE el_quizzes SET title=?, description=?, module_id=?, time_limit_minutes=?, shuffle_questions=?, shuffle_options=?, is_published=?, assessment_type=?, due_at=?, settings_json=?, course_offering_id=COALESCE(course_offering_id, ?) WHERE id=? AND course_code=? AND (course_offering_id=? OR course_offering_id IS NULL)");
                    $stmt->bind_param('ssiiiiisssiisi', $title, $description, $moduleId, $timeLimit, $shuffleQuestions, $shuffleOptions, $isPublished, $assessmentType, $dueAt, $settingsJson, $courseOfferingId, $quizId, $courseCode, $courseOfferingId);
                } else {
                    $stmt = $db->prepare("UPDATE el_quizzes SET title=?, description=?, module_id=?, time_limit_minutes=?, shuffle_questions=?, shuffle_options=?, is_published=?, assessment_type=?, due_at=?, settings_json=? WHERE id=? AND course_code=?");
                    $stmt->bind_param('ssiiiiisssis', $title, $description, $moduleId, $timeLimit, $shuffleQuestions, $shuffleOptions, $isPublished, $assessmentType, $dueAt, $settingsJson, $quizId, $courseCode);
                }
                $stmt->execute();
                $stmt->close();
                $successMsg = 'Quiz updated successfully.';
            } else {
                if ($quizHasOffering) {
                    $stmt = $db->prepare("INSERT INTO el_quizzes (course_offering_id, course_code, module_id, title, description, time_limit_minutes, shuffle_questions, shuffle_options, is_published, assessment_type, due_at, settings_json, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('isissiiiissss', $courseOfferingId, $courseCode, $moduleId, $title, $description, $timeLimit, $shuffleQuestions, $shuffleOptions, $isPublished, $assessmentType, $dueAt, $settingsJson, $staffId);
                } else {
                    $stmt = $db->prepare("INSERT INTO el_quizzes (course_code, module_id, title, description, time_limit_minutes, shuffle_questions, shuffle_options, is_published, assessment_type, due_at, settings_json, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('sissiiiissss', $courseCode, $moduleId, $title, $description, $timeLimit, $shuffleQuestions, $shuffleOptions, $isPublished, $assessmentType, $dueAt, $settingsJson, $staffId);
                }
                $stmt->execute();
                $quizId = (int)$stmt->insert_id;
                $stmt->close();
                $successMsg = 'Quiz created. Now add questions below.';
            }
        }
    } elseif ($action === 'add_question' && $quizId > 0) {
        $questionText = trim($_POST['question_text'] ?? '');
        $questionType = $_POST['question_type'] ?? 'mcq_single';
        $points = (float)($_POST['points'] ?? 1.0);
        $position = (int)($_POST['position'] ?? 0);
        $validTypes = ['mcq_single', 'mcq_multi', 'true_false', 'short_answer', 'essay'];
        $optionTexts = array_map('trim', (array)($_POST['option_text'] ?? []));
        $optionTexts = array_values(array_filter($optionTexts, static fn($text) => $text !== ''));
        $correctOptions = array_map('strval', (array)($_POST['correct_option'] ?? []));

        if ($questionText === '') { $errors[] = 'Question text is required'; }
        if (!in_array($questionType, $validTypes, true)) { $errors[] = 'Invalid question type.'; }
        if ($points <= 0 || $points > 100) { $errors[] = 'Points must be greater than 0 and no more than 100.'; }
        if (in_array($questionType, ['mcq_single', 'mcq_multi'], true) && count($optionTexts) < 2) { $errors[] = 'Multiple-choice questions need at least two options.'; }
        if (in_array($questionType, ['mcq_single', 'true_false'], true) && count($correctOptions) !== 1) { $errors[] = 'Single-answer questions need exactly one correct answer.'; }
        if ($questionType === 'mcq_multi' && count($correctOptions) < 1) { $errors[] = 'Multiple-answer questions need at least one correct option.'; }

        if (!$errors) {
            $stmt = $db->prepare("INSERT INTO el_questions (quiz_id, question_text, question_type, points, position) VALUES (?,?,?,?,?)");
            $stmt->bind_param('issdi', $quizId, $questionText, $questionType, $points, $position);
            $stmt->execute();
            $questionId = (int)$stmt->insert_id;
            $stmt->close();

            // Add options if MCQ type
            if (in_array($questionType, ['mcq_single', 'mcq_multi', 'true_false'])) {
                foreach ((array)($_POST['option_text'] ?? []) as $idx => $optText) {
                    $optText = trim($optText);
                    if ($optText !== '') {
                        $isCorrect = in_array((string)$idx, $correctOptions, true) ? 1 : 0;
                        $optPos = $idx;
                        $stmt = $db->prepare("INSERT INTO el_question_options (question_id, option_text, is_correct, position) VALUES (?,?,?,?)");
                        $stmt->bind_param('isii', $questionId, $optText, $isCorrect, $optPos);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
            $successMsg = 'Question added successfully.';
        }
    } elseif ($action === 'delete_question' && $quizId > 0) {
        $delQid = (int)($_POST['delete_question_id'] ?? 0);
        if ($delQid > 0) {
            $stmt = $db->prepare("DELETE FROM el_questions WHERE id=? AND quiz_id=?");
            $stmt->bind_param('ii', $delQid, $quizId);
            $stmt->execute();
            $stmt->close();
            $successMsg = 'Question deleted.';
        }
    }
}

// Load quiz data
$quiz = null;
if ($quizId > 0) {
    if ($quizHasOffering && $courseOfferingId !== null) {
        $stmt = $db->prepare("SELECT * FROM el_quizzes WHERE id=? AND course_code=? AND (course_offering_id=? OR course_offering_id IS NULL) LIMIT 1");
        $stmt->bind_param('isi', $quizId, $courseCode, $courseOfferingId);
    } else {
        $stmt = $db->prepare("SELECT * FROM el_quizzes WHERE id=? AND course_code=? LIMIT 1");
        $stmt->bind_param('is', $quizId, $courseCode);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $quiz = $res->fetch_assoc();
    $stmt->close();
}
$quizSettings = elearningAssessmentSettings($quiz['settings_json'] ?? null, [
    'max_attempts' => 1,
    'available_from' => '',
    'require_fullscreen' => true,
    'disable_copy_paste' => true,
    'show_result_immediately' => true,
]);

// Load questions
$questions = [];
if ($quizId > 0) {
    if ($stmt = $db->prepare("SELECT * FROM el_questions WHERE quiz_id=? ORDER BY position, id")) {
        $stmt->bind_param('i', $quizId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $questions[] = $row; }
        $stmt->close();
    }
}

// Load options for each question
$optionsByQuestion = [];
foreach ($questions as $q) {
    if ($stmt = $db->prepare("SELECT * FROM el_question_options WHERE question_id=? ORDER BY position, id")) {
        $stmt->bind_param('i', $q['id']);
        $stmt->execute();
        $res = $stmt->get_result();
        $optionsByQuestion[$q['id']] = [];
        while ($o = $res->fetch_assoc()) { $optionsByQuestion[$q['id']][] = $o; }
        $stmt->close();
    }
}

// Load modules for dropdown
$modules = [];
$courseOfferingIds = getLecturerCourseOfferingIds($db, $staffId, $courseCode);
$types = 's';
$params = [$courseCode];
$offeringSql = elearningOfferingScopeCondition($db, 'el_course_modules', null, $courseOfferingIds, $types, $params);
if ($stmt = $db->prepare("SELECT id, title FROM el_course_modules WHERE course_code=? {$offeringSql} ORDER BY position, id")) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $modules[] = $row; }
    $stmt->close();
}
$page_title = ($quiz ? 'Edit Quiz' : 'New Quiz') . ' - ' . $courseCode;
require_once __DIR__ . '/../lecturers/includes/nav.php';
?>
<style>
    .quiz-editor-page { padding-bottom: 40px; }
    .quiz-editor-stack { display: grid; gap: 18px; }
    .quiz-field { margin-bottom: 15px; }
    .settings-panel { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; margin-bottom: 4px; }
    .settings-panel-title { margin: 0 0 12px; font-size: 15px; font-weight: 700; color: #111827; }
    .settings-checks { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px 16px; }
    .settings-check { display: flex; align-items: center; gap: 8px; min-height: 28px; margin: 0; font-weight: 500; color: #374151; }
    .settings-check input { margin: 0; flex: 0 0 auto; }
    .question-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 15px; }
    .question-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; }
    .question-main { min-width: 0; }
    .question-actions { flex: 0 0 auto; }
    .question-type-badge { display: inline-block; font-size: 0.75rem; padding: 3px 8px; border-radius: 4px; background: #4b5563; color: #fff; margin-left: 6px; }
    .option-list { margin-top: 10px; padding-left: 20px; }
    .option-correct { color: #1f8f4d; font-weight: 600; }
    .input-group .form-control { min-width: 0; }
    .input-group-text .form-check-input { margin: 0; }
    .option-row { margin-bottom: 8px; }
    .quiz-editor-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
    .quiz-editor-actions .btn { display: inline-flex; align-items: center; gap: 6px; }
    @media (max-width: 991px) {
        .settings-checks { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 767px) {
        .settings-checks { grid-template-columns: 1fr; }
        .question-header { display: block; }
        .question-actions { margin-top: 12px; }
        .quiz-editor-actions .btn { width: 100%; justify-content: center; }
    }
</style>

<div class="elearning-shell quiz-editor-page">
    <div class="elearning-header">
        <div>
            <h1 class="elearning-title"><i class="fas fa-pen"></i> <?php echo $quiz ? 'Edit Quiz' : 'Create New Quiz'; ?></h1>
            <p class="elearning-subtitle">Course: <strong><?php echo htmlspecialchars($courseCode); ?></strong></p>
        </div>
        <div class="elearning-actions">
            <a class="btn btn-secondary" href="assessments.php?course_code=<?php echo urlencode($courseCode); ?>">
                <i class="fas fa-arrow-left"></i> Back to Assessments
            </a>
        </div>
    </div>
    
    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
    <?php endif; ?>
    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMsg); ?></div>
    <?php endif; ?>
    
    <div class="quiz-editor-stack">
    <section class="elearning-panel">
        <div class="elearning-panel-header"><strong><i class="fas fa-sliders-h"></i> Quiz Details</strong></div>
        <div class="elearning-panel-body">
            <form method="post">
                <input type="hidden" name="action" value="save_quiz">
                <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
                <input type="hidden" name="quiz_id" value="<?php echo (int)$quizId; ?>">
                <?php echo elearningAssessmentCsrfField(); ?>
                
                <div class="row">
                    <div class="col-md-6 quiz-field">
                        <label class="form-label">Title *</label>
                        <input class="form-control" type="text" name="title" value="<?php echo htmlspecialchars($quiz['title'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-2 quiz-field">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="assessment_type">
                            <option value="quiz" <?php echo (($quiz['assessment_type'] ?? 'quiz') === 'quiz') ? 'selected' : ''; ?>>Quiz</option>
                            <option value="test" <?php echo (($quiz['assessment_type'] ?? '') === 'test') ? 'selected' : ''; ?>>Test</option>
                        </select>
                    </div>
                    <div class="col-md-4 quiz-field">
                        <label class="form-label">Module (optional)</label>
                        <select class="form-select" name="module_id">
                            <option value="">-- No Module --</option>
                            <?php foreach ($modules as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>" <?php echo (isset($quiz['module_id']) && $quiz['module_id'] == $m['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="quiz-field">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($quiz['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-2 quiz-field">
                        <label class="form-label">Time Limit (minutes)</label>
                        <input class="form-control" type="number" name="time_limit" min="1" value="<?php echo isset($quiz['time_limit_minutes']) ? (int)$quiz['time_limit_minutes'] : ''; ?>" placeholder="No limit">
                    </div>
                    <div class="col-md-2 quiz-field">
                        <label class="form-label">Max Attempts</label>
                        <input class="form-control" type="number" name="max_attempts" min="1" max="10" value="<?php echo (int)($quizSettings['max_attempts'] ?? 1); ?>">
                    </div>
                    <div class="col-md-4 quiz-field">
                        <label class="form-label">Open From</label>
                        <input class="form-control" type="datetime-local" name="available_from" value="<?php echo !empty($quizSettings['available_from']) ? date('Y-m-d\TH:i', strtotime((string)$quizSettings['available_from'])) : ''; ?>">
                    </div>
                    <div class="col-md-4 quiz-field">
                        <label class="form-label">Due Date</label>
                        <input class="form-control" type="datetime-local" name="due_at" value="<?php echo !empty($quiz['due_at']) ? date('Y-m-d\TH:i', strtotime((string)$quiz['due_at'])) : ''; ?>">
                    </div>
                </div>

                <div class="settings-panel">
                    <div class="settings-panel-title"><i class="fas fa-shield-alt"></i> Delivery & Security</div>
                    <div class="settings-checks">
                        <label class="settings-check" for="shuffleQ">
                            <input class="form-check-input" type="checkbox" name="shuffle_questions" id="shuffleQ" <?php echo (!$quiz || ($quiz['shuffle_questions'] ?? 0)) ? 'checked' : ''; ?>>
                            <span>Shuffle questions</span>
                        </label>
                        <label class="settings-check" for="shuffleO">
                            <input class="form-check-input" type="checkbox" name="shuffle_options" id="shuffleO" <?php echo (!$quiz || ($quiz['shuffle_options'] ?? 0)) ? 'checked' : ''; ?>>
                            <span>Shuffle options</span>
                        </label>
                        <label class="settings-check" for="published">
                            <input class="form-check-input" type="checkbox" name="is_published" id="published" <?php echo ($quiz && ($quiz['is_published'] ?? 0)) ? 'checked' : ''; ?>>
                            <span>Published</span>
                        </label>
                        <label class="settings-check" for="fullscreen">
                            <input class="form-check-input" type="checkbox" name="require_fullscreen" id="fullscreen" <?php echo !empty($quizSettings['require_fullscreen']) ? 'checked' : ''; ?>>
                            <span>Monitor fullscreen</span>
                        </label>
                        <label class="settings-check" for="copyPaste">
                            <input class="form-check-input" type="checkbox" name="disable_copy_paste" id="copyPaste" <?php echo !empty($quizSettings['disable_copy_paste']) ? 'checked' : ''; ?>>
                            <span>Block copy/paste</span>
                        </label>
                        <label class="settings-check" for="showResult">
                            <input class="form-check-input" type="checkbox" name="show_result_immediately" id="showResult" <?php echo !empty($quizSettings['show_result_immediately']) ? 'checked' : ''; ?>>
                            <span>Show score after submit</span>
                        </label>
                    </div>
                </div>
                
                <div class="quiz-editor-actions">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save Quiz</button>
                    <a class="btn btn-secondary" href="assessments.php?course_code=<?php echo urlencode($courseCode); ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>
    
    <?php if ($quizId > 0): ?>
    <!-- Questions Section -->
    <section class="elearning-panel">
        <div class="elearning-panel-header"><strong><i class="fas fa-list-check"></i> Questions (<?php echo count($questions); ?>)</strong></div>
        <div class="elearning-panel-body">
            <?php foreach ($questions as $idx => $q): ?>
                <div class="question-card">
                    <div class="question-header">
                        <div class="question-main">
                            <strong>Q<?php echo $idx + 1; ?>.</strong> <?php echo htmlspecialchars($q['question_text']); ?>
                            <span class="question-type-badge"><?php echo htmlspecialchars($q['question_type']); ?></span>
                            <small class="text-muted">(<?php echo (float)$q['points']; ?> pts)</small>
                        </div>
                        <form method="post" class="question-actions" onsubmit="return confirm('Delete this question?');">
                            <input type="hidden" name="action" value="delete_question">
                            <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
                            <input type="hidden" name="quiz_id" value="<?php echo (int)$quizId; ?>">
                            <input type="hidden" name="delete_question_id" value="<?php echo (int)$q['id']; ?>">
                            <?php echo elearningAssessmentCsrfField(); ?>
                            <button class="btn btn-sm btn-danger" type="submit"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                    <?php if (isset($optionsByQuestion[$q['id']]) && count($optionsByQuestion[$q['id']]) > 0): ?>
                        <ul class="option-list">
                            <?php foreach ($optionsByQuestion[$q['id']] as $opt): ?>
                                <li class="<?php echo $opt['is_correct'] ? 'option-correct' : ''; ?>">
                                    <?php echo htmlspecialchars($opt['option_text']); ?>
                                    <?php if ($opt['is_correct']): ?><i class="fas fa-check"></i><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <?php if (count($questions) === 0): ?>
                <p class="elearning-empty">No questions added yet.</p>
            <?php endif; ?>
        </div>
    </section>
    
    <section class="elearning-panel">
        <div class="elearning-panel-header"><strong><i class="fas fa-plus-circle"></i> Add New Question</strong></div>
        <div class="elearning-panel-body">
            <form method="post" id="questionForm">
                <input type="hidden" name="action" value="add_question">
                <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
                <input type="hidden" name="quiz_id" value="<?php echo (int)$quizId; ?>">
                <?php echo elearningAssessmentCsrfField(); ?>
                
                <div class="row">
                    <div class="col-md-8 quiz-field">
                        <label class="form-label">Question Text *</label>
                        <textarea class="form-control" name="question_text" rows="2" required></textarea>
                    </div>
                    <div class="col-md-2 quiz-field">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="question_type" id="questionType">
                            <option value="mcq_single">MCQ (Single)</option>
                            <option value="mcq_multi">MCQ (Multiple)</option>
                            <option value="true_false">True/False</option>
                            <option value="short_answer">Short Answer</option>
                            <option value="essay">Essay</option>
                        </select>
                    </div>
                    <div class="col-md-2 quiz-field">
                        <label class="form-label">Points</label>
                        <input class="form-control" type="number" name="points" value="1" min="0" step="0.5">
                    </div>
                </div>
                
                <div id="optionsSection">
                    <label class="form-label">Options (check correct answers)</label>
                    <div id="optionsList">
                        <div class="input-group option-row">
                            <span class="input-group-text">
                                <input class="form-check-input" type="radio" name="correct_option[]" value="0">
                            </span>
                            <input type="text" class="form-control" name="option_text[]" placeholder="Option A">
                        </div>
                        <div class="input-group option-row">
                            <span class="input-group-text">
                                <input class="form-check-input" type="radio" name="correct_option[]" value="1">
                            </span>
                            <input type="text" class="form-control" name="option_text[]" placeholder="Option B">
                        </div>
                        <div class="input-group option-row">
                            <span class="input-group-text">
                                <input class="form-check-input" type="radio" name="correct_option[]" value="2">
                            </span>
                            <input type="text" class="form-control" name="option_text[]" placeholder="Option C">
                        </div>
                        <div class="input-group option-row">
                            <span class="input-group-text">
                                <input class="form-check-input" type="radio" name="correct_option[]" value="3">
                            </span>
                            <input type="text" class="form-control" name="option_text[]" placeholder="Option D">
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addOption()">
                        <i class="fas fa-plus"></i> Add Option
                    </button>
                </div>
                
                <div class="quiz-editor-actions">
                    <button class="btn btn-success" type="submit"><i class="fas fa-plus"></i> Add Question</button>
                </div>
            </form>
        </div>
    </section>
    <?php endif; ?>
    </div><!-- /.quiz-editor-stack -->
</div><!-- /.elearning-shell -->
</div><!-- /.main-content -->
</div><!-- /.main-wrapper -->

<script>
var optionIndex = 4;
function addOption() {
    var typeEl = document.getElementById('questionType');
    var inputType = typeEl && typeEl.value === 'mcq_multi' ? 'checkbox' : 'radio';
    var html = '<div class="input-group option-row">' +
        '<span class="input-group-text"><input class="form-check-input" type="' + inputType + '" name="correct_option[]" value="' + optionIndex + '"></span>' +
        '<input type="text" class="form-control" name="option_text[]" placeholder="Option">' +
        '<button type="button" class="btn btn-danger remove-option"><i class="fas fa-times"></i></button>' +
        '</div>';
    document.getElementById('optionsList').insertAdjacentHTML('beforeend', html);
    optionIndex++;
}

function renderQuestionOptions(type) {
    var optSec = document.getElementById('optionsSection');
    var list = document.getElementById('optionsList');
    if (!optSec || !list) return;
    if (type === 'short_answer' || type === 'essay') {
        optSec.style.display = 'none';
        return;
    }

    optSec.style.display = 'block';
    if (type === 'true_false') {
        optionIndex = 2;
        list.innerHTML =
            '<div class="input-group option-row"><span class="input-group-text"><input class="form-check-input" type="radio" name="correct_option[]" value="0"></span><input type="text" class="form-control" name="option_text[]" value="True" readonly></div>' +
            '<div class="input-group option-row"><span class="input-group-text"><input class="form-check-input" type="radio" name="correct_option[]" value="1"></span><input type="text" class="form-control" name="option_text[]" value="False" readonly></div>';
        return;
    }

    var inputType = type === 'mcq_multi' ? 'checkbox' : 'radio';
    optionIndex = 4;
    list.innerHTML =
        '<div class="input-group option-row"><span class="input-group-text"><input class="form-check-input" type="' + inputType + '" name="correct_option[]" value="0"></span><input type="text" class="form-control" name="option_text[]" placeholder="Option A"></div>' +
        '<div class="input-group option-row"><span class="input-group-text"><input class="form-check-input" type="' + inputType + '" name="correct_option[]" value="1"></span><input type="text" class="form-control" name="option_text[]" placeholder="Option B"></div>' +
        '<div class="input-group option-row"><span class="input-group-text"><input class="form-check-input" type="' + inputType + '" name="correct_option[]" value="2"></span><input type="text" class="form-control" name="option_text[]" placeholder="Option C"></div>' +
        '<div class="input-group option-row"><span class="input-group-text"><input class="form-check-input" type="' + inputType + '" name="correct_option[]" value="3"></span><input type="text" class="form-control" name="option_text[]" placeholder="Option D"></div>';
}

var questionType = document.getElementById('questionType');
if (questionType) {
    questionType.addEventListener('change', function() {
        renderQuestionOptions(this.value);
    });
}

var optionsList = document.getElementById('optionsList');
if (optionsList) {
    optionsList.addEventListener('click', function(event) {
        var target = event.target;
        var button = target && target.closest ? target.closest('.remove-option') : null;
        if (!button) return;
        var row = button.closest('.input-group');
        if (row) row.remove();
    });
}
</script>
</body>
</html>
