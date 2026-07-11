<?php
require_once __DIR__ . '/_common.php';

$materials = repo_accessible_materials($db, [], 200);
$answer = null;
$selectedId = (int)($_POST['material_id'] ?? $_GET['material_id'] ?? 0);
$question = trim((string)($_POST['question'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $errors = [];
    repo_require_csrf($errors);
    $material = $selectedId > 0 ? repo_fetch_material($db, $selectedId) : null;
    if (!$material || !repo_can_access_material($db, $material, 'ai')) {
        $errors[] = 'Select an approved material you are allowed to access.';
    }
    if ($question === '') {
        $errors[] = 'Enter a study question.';
    }
    $rate = wuc_ai_rate_limit('repository_student_ai', 20, 3600);
    if (!$rate['ok']) {
        $errors[] = 'Too many AI study requests. Please try again later.';
    }
    if (!$errors && $material) {
        $stmt = repo_table_exists($db, 'repository_ai_metadata')
            ? $db->prepare("SELECT summary, keywords, topics, study_guide FROM repository_ai_metadata WHERE material_id=? LIMIT 1")
            : false;
        $ai = null;
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $selectedId);
            $stmt->execute();
            $ai = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if (!$ai) {
            repo_process_ai($db, $selectedId);
            $stmt = repo_table_exists($db, 'repository_ai_metadata')
                ? $db->prepare("SELECT summary, keywords, topics, study_guide FROM repository_ai_metadata WHERE material_id=? LIMIT 1")
                : false;
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $selectedId);
                $stmt->execute();
                $ai = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        }
        $context = [
            'material' => [
                'title' => $material['title'],
                'description' => $material['description'],
                'course_code' => $material['course_code'],
                'summary' => $ai['summary'] ?? '',
                'keywords' => $ai['keywords'] ?? '',
                'topics' => $ai['topics'] ?? '',
                'study_guide' => $ai['study_guide'] ?? '',
            ],
            'student_id' => $_SESSION['Sid'] ?? '',
            'rules' => [
                'answer_only_from_this_approved_material' => true,
                'refuse_unrelated_questions' => true,
                'no_exam_leakage' => true,
            ],
        ];
        $contextJson = wuc_ai_context_json($context, 16000);
        $result = wuc_ai_generate($db, [
            'feature' => 'repository_student_ai',
            'user_role' => 'student',
            'user_id' => (string)($_SESSION['Sid'] ?? ''),
            'input_summary' => substr($question, 0, 200),
            'context_hash' => hash('sha256', $contextJson),
            'messages' => [
                ['role' => 'system', 'content' => 'You are an academic study assistant. Use only the approved repository material context. If the question is unrelated or asks for restricted exam leakage, politely redirect to study support.'],
                ['role' => 'user', 'content' => "Context:\n{$contextJson}\n\nStudent question: {$question}"],
            ],
            'fallback' => static function () use ($material): string {
                return 'Review the approved material "' . (string)$material['title'] . '". Focus on the summary, list key concepts, and write short answers in your own words.';
            },
        ]);
        $answer = $result;
    } else {
        repo_flash('danger', implode(' ', $errors));
    }
}

repo_student_header('Repository AI Study', 'Ask study questions based only on approved repository materials you can access.');
?>
<div class="row g-4">
    <div class="col-lg-5">
        <section class="data-table-card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>Ask AI Study Support</h5></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
                    <div class="mb-3">
                        <label class="form-label">Approved material</label>
                        <select class="form-select" name="material_id" required>
                            <option value="">Select material</option>
                            <?php foreach ($materials as $material): ?>
                                <option value="<?php echo (int)$material['id']; ?>"<?php echo (int)$material['id'] === $selectedId ? ' selected' : ''; ?>><?php echo repo_h($material['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Your study question</label>
                        <textarea class="form-control" name="question" rows="5" maxlength="1000" required><?php echo repo_h($question); ?></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Ask Repository AI</button>
                </form>
            </div>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="data-table-card">
            <div class="card-header"><h5 class="mb-0">AI Response</h5></div>
            <div class="card-body">
                <?php echo $answer ? wuc_ai_output_block((string)$answer['text']) : '<p class="text-muted mb-0">Choose an approved material and ask a study question.</p>'; ?>
            </div>
        </section>
    </div>
</div>
<?php repo_student_footer(); ?>
