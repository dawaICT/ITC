<?php
declare(strict_types=1);

$page_title = 'AI Study Assistant';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/elearning_access.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';
require_once __DIR__ . '/includes/ai_student_context.php';

function student_study_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function student_study_course_map(mysqli $db, string $studentId): array
{
    $codes = getStudentEnrolledCourses($db, $studentId);
    $courses = [];
    foreach ($codes as $code) {
        $code = trim((string)$code);
        if ($code !== '') {
            $courses[$code] = $code;
        }
    }

    if (!$courses || !elearningTableExists($db, 'courses')) {
        return $courses;
    }

    $courseCodeCol = elearningDetectColumn($db, 'courses', ['course_code', 'code']);
    $courseNameCol = elearningDetectColumn($db, 'courses', ['course_name', 'name', 'title']);
    if ($courseCodeCol === null || $courseNameCol === null) {
        return $courses;
    }

    $codes = array_keys($courses);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    $sql = "SELECT `{$courseCodeCol}` AS course_code, `{$courseNameCol}` AS course_name
            FROM courses
            WHERE `{$courseCodeCol}` IN ({$placeholders})";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$codes);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $code = (string)($row['course_code'] ?? '');
                if ($code !== '') {
                    $courses[$code] = (string)($row['course_name'] ?? $code);
                }
            }
        }
        $stmt->close();
    }

    return $courses;
}

function student_study_materials(mysqli $db, string $courseCode): array
{
    return wuc_ai_course_study_materials($db, $courseCode, 10);
}

function student_study_fallback(array $context): string
{
    $course = (string)($context['course_code'] ?? '');
    $goal = (string)($context['study_goal'] ?? 'summarize');
    $materials = $context['materials'] ?? [];

    $lines = [];
    $lines[] = "Study assistant fallback";
    $lines[] = "Course: {$course}";
    $lines[] = "Goal: {$goal}";
    $lines[] = "";

    if (!$materials) {
        $lines[] = "No readable lesson-note text was found for this course. Check the materials page for downloadable files.";
    } else {
        $lines[] = "Materials found: " . count($materials);
        foreach (array_slice($materials, 0, 5) as $index => $material) {
            $title = trim((string)($material['title'] ?? 'Material ' . ($index + 1)));
            $excerpt = trim((string)($material['excerpt'] ?? ''));
            $lines[] = "- " . ($title !== '' ? $title : 'Untitled material');
            if ($excerpt !== '') {
                $lines[] = "  Key note: " . wuc_ai_truncate($excerpt, 220);
            }
        }
    }

    $lines[] = "";
    $lines[] = "Revision prompts:";
    $lines[] = "1. Define the key terms from the selected topic.";
    $lines[] = "2. Explain the topic in your own words.";
    $lines[] = "3. Create one practical example or case scenario.";
    $lines[] = "4. List questions to ask your lecturer where the material is unclear.";

    return implode("\n", $lines);
}

$studentId = (string)($_SESSION['Sid'] ?? '');
$courses = student_study_course_map($db, $studentId);
$aiStatus = wuc_ai_local_status();
$errors = [];
$result = null;
$old = [
    'course_code' => trim((string)($_POST['course_code'] ?? ($_GET['course'] ?? ''))),
    'study_goal' => trim((string)($_POST['study_goal'] ?? 'summarize')),
    'question' => trim((string)($_POST['question'] ?? '')),
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $errors[] = 'Your request could not be verified. Refresh and try again.';
    }

    $courseCode = trim($old['course_code']);
    if ($courseCode === '') {
        $errors[] = 'Select a course.';
    } elseif (!isset($courses[$courseCode]) && !isStudentEnrolledInCourse($db, $studentId, $courseCode)) {
        $errors[] = 'You are not enrolled in the selected course.';
    }

    $allowedGoals = ['summarize', 'explain', 'quiz', 'revision_plan'];
    $studyGoal = in_array($old['study_goal'], $allowedGoals, true) ? $old['study_goal'] : 'summarize';
    $question = wuc_ai_truncate($old['question'], 800);

    $rate = wuc_ai_rate_limit('student_study_assistant', 10, 3600);
    if (!$rate['ok']) {
        $errors[] = 'Too many AI requests. Please try again in about ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.';
    }

    if (!$errors) {
        $context = [
            'student_id' => $studentId,
            'course_code' => $courseCode,
            'course_name' => $courses[$courseCode] ?? $courseCode,
            'study_goal' => $studyGoal,
            'student_question' => $question,
            'materials' => student_study_materials($db, $courseCode),
            'rules' => [
                'study_support_only' => true,
                'do_not_answer_as_official_exam_key' => true,
                'use_only_supplied_material_context' => true,
            ],
        ];
        $contextJson = wuc_ai_context_json($context);
        $result = wuc_ai_generate($db, [
            'feature' => 'student_study_assistant',
            'user_role' => 'student',
            'user_id' => $studentId,
            'input_summary' => $courseCode . ' | ' . $studyGoal . ' | ' . $question,
            'context_hash' => hash('sha256', $contextJson),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are the ITC Portal student study assistant. Use only supplied course material context. Help the student study through summaries, explanations, quizzes, or revision plans. Do not claim to know exam answers or replace lecturer guidance.',
                ],
                [
                    'role' => 'user',
                    'content' => "Study request JSON:\n{$contextJson}\n\nProduce a concise, structured study response.",
                ],
            ],
            'fallback' => static function () use ($context): string {
                return student_study_fallback($context);
            },
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title . ' - ITC', ENT_QUOTES, 'UTF-8'); ?></title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="content-wrapper pt-3 pb-5">
    <div class="container-fluid px-3 px-lg-4 portal-dashboard ai-study-assistant-page">
        <div class="dashboard-header student-section mb-4">
            <div class="row align-items-center g-3">
                <div class="col">
                    <h1 class="dashboard-title">AI Study Assistant</h1>
                    <p class="text-muted mb-0">Summarize course materials, generate revision questions, and build study plans.</p>
                </div>
                <div class="col-auto">
                    <span class="badge <?php echo $aiStatus['model_ready'] ? 'bg-success' : 'bg-secondary'; ?>">
                        <?php echo $aiStatus['model_ready'] ? 'AI ready' : 'Fallback mode'; ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (array_unique($errors) as $error): ?>
                    <div><?php echo student_study_h($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-xl-5">
                <section class="data-table-card h-100">
                    <div class="card-header"><h5 class="mb-0"><i class="fas fa-book-reader me-2"></i>Study request</h5></div>
                    <div class="card-body">
                        <form method="post" action="ai_study_assistant.php">
                            <input type="hidden" name="csrf_token" value="<?php echo student_study_h($_SESSION['csrf_token'] ?? ''); ?>">
                            <div class="mb-3">
                                <label class="form-label" for="course_code">Course</label>
                                <select class="form-select" id="course_code" name="course_code" required>
                                    <option value="">Select course</option>
                                    <?php foreach ($courses as $code => $name): ?>
                                        <option value="<?php echo student_study_h($code); ?>" <?php echo strcasecmp($old['course_code'], $code) === 0 ? 'selected' : ''; ?>>
                                            <?php echo student_study_h($code . ' - ' . $name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="study_goal">Goal</label>
                                <select class="form-select" id="study_goal" name="study_goal">
                                    <?php foreach (['summarize' => 'Summarize material', 'explain' => 'Explain a topic', 'quiz' => 'Generate quiz questions', 'revision_plan' => 'Build revision plan'] as $value => $label): ?>
                                        <option value="<?php echo student_study_h($value); ?>" <?php echo $old['study_goal'] === $value ? 'selected' : ''; ?>><?php echo student_study_h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="question">Question or focus area</label>
                                <textarea class="form-control" id="question" name="question" rows="5" maxlength="800" placeholder="Optional: focus on a specific topic or ask what you want explained."><?php echo student_study_h($old['question']); ?></textarea>
                            </div>
                            <button class="btn btn-primary" type="submit" <?php echo $courses ? '' : 'disabled'; ?>>
                                <i class="fas fa-wand-magic-sparkles me-2"></i>Generate study help
                            </button>
                        </form>
                    </div>
                </section>
            </div>
            <div class="col-xl-7">
                <section class="data-table-card h-100">
                    <div class="card-header"><h5 class="mb-0"><i class="fas fa-lightbulb me-2"></i>Study response</h5></div>
                    <div class="card-body">
                        <?php if ($result === null): ?>
                            <div class="text-muted py-4">Choose a course and study goal to generate support from your available course materials.</div>
                        <?php else: ?>
                            <div class="alert <?php echo $result['used_ai'] ? 'alert-info' : 'alert-warning'; ?> small">
                                <?php echo $result['used_ai'] ? 'Generated by local AI model ' . student_study_h($result['model']) . '.' : student_study_h(wuc_ai_fallback_notice($result)); ?>
                            </div>
                            <div class="bg-light border rounded p-3"><?php echo wuc_ai_output_block($result['text']); ?></div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>
</body>
</html>
