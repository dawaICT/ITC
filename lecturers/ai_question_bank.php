<?php
declare(strict_types=1);

$page_title = 'AI Question Bank';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/elearning_access.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';

function lecturer_ai_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Pull lesson-notes context for a course.
 * Tries lesson_notes first, then supplements with el_contents body text
 * when el_content_id is set, so lecturers with rich e-learning material
 * get better AI context.
 */
function lecturer_ai_material_context(mysqli $db, string $courseCode): array
{
    if ($courseCode === '' || !elearningTableExists($db, 'lesson_notes')) {
        return [];
    }

    $cols = elearningTableColumns($db, 'lesson_notes');
    $courseCol = $cols['course_code'] ?? null;
    if ($courseCol === null) {
        return [];
    }

    $topicCol  = $cols['topic']      ?? null;
    $notesCol  = $cols['notes']      ?? null;
    $dateCol   = $cols['dte']        ?? ($cols['created_at'] ?? null);
    $contentIdCol = $cols['el_content_id'] ?? null;

    $topicSelect  = $topicCol  ? "`{$topicCol}` AS topic"         : "'' AS topic";
    $notesSelect  = $notesCol  ? "`{$notesCol}` AS notes"         : "'' AS notes";
    $dateSelect   = $dateCol   ? "`{$dateCol}` AS material_date"  : "NULL AS material_date";
    $cidSelect    = $contentIdCol ? "`{$contentIdCol}` AS el_content_id" : "NULL AS el_content_id";

    $orderBy = $dateCol ? "`{$dateCol}` DESC, id DESC" : 'id DESC';
    $sql = "SELECT {$topicSelect}, {$notesSelect}, {$dateSelect}, {$cidSelect}
            FROM lesson_notes
            WHERE UPPER(TRIM(`{$courseCol}`)) = UPPER(TRIM(?))
            ORDER BY {$orderBy}
            LIMIT 8";

    $materials = [];
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $courseCode);
        if ($stmt->execute()) {
            $res = $stmt->get_result();

            // Pre-collect content IDs for a batch lookup
            $rows      = [];
            $contentIds = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
                $cid = (int)($row['el_content_id'] ?? 0);
                if ($cid > 0) {
                    $contentIds[] = $cid;
                }
            }

            // Optionally enrich with el_contents body text
            $bodyMap = [];
            if ($contentIds !== [] && elearningTableExists($db, 'el_contents')) {
                $elCols   = elearningTableColumns($db, 'el_contents');
                $bodyCol  = $elCols['body'] ?? ($elCols['content'] ?? ($elCols['description'] ?? null));
                $idCol    = $elCols['id']   ?? null;
                if ($bodyCol !== null && $idCol !== null) {
                    $ph   = implode(',', array_fill(0, count($contentIds), '?'));
                    $types = str_repeat('i', count($contentIds));
                    $s2 = $db->prepare(
                        "SELECT `{$idCol}` AS cid, `{$bodyCol}` AS body FROM el_contents WHERE `{$idCol}` IN ({$ph})"
                    );
                    if ($s2) {
                        $s2->bind_param($types, ...$contentIds);
                        if ($s2->execute()) {
                            $r2 = $s2->get_result();
                            while ($erow = $r2->fetch_assoc()) {
                                $bodyMap[(int)$erow['cid']] = (string)($erow['body'] ?? '');
                            }
                        }
                        $s2->close();
                    }
                }
            }

            foreach ($rows as $row) {
                $baseNotes = (string)($row['notes'] ?? '');
                $cid       = (int)($row['el_content_id'] ?? 0);
                $richBody  = $cid > 0 && isset($bodyMap[$cid]) ? strip_tags($bodyMap[$cid]) : '';
                $combined  = $richBody !== '' ? $richBody : strip_tags($baseNotes);
                $materials[] = [
                    'topic'          => (string)($row['topic'] ?? ''),
                    'notes_excerpt'  => wuc_ai_truncate($combined, 700),
                    'material_date'  => (string)($row['material_date'] ?? ''),
                ];
            }
        }
        $stmt->close();
    }

    return $materials;
}

function lecturer_ai_fallback_questions(array $context): string
{
    $topic      = trim((string)($context['topic'] ?? 'the selected topic'));
    $type       = (string)($context['question_type'] ?? 'mixed');
    $count      = max(3, min(20, (int)($context['question_count'] ?? 8)));
    $difficulty = (string)($context['difficulty'] ?? 'intermediate');

    $lines   = [];
    $lines[] = "Draft question bank fallback";
    $lines[] = "Course: " . (string)($context['course_code'] ?? '') . " - " . (string)($context['course_name'] ?? '');
    $lines[] = "Topic: {$topic}";
    $lines[] = "Difficulty: {$difficulty}";
    $lines[] = "";

    for ($i = 1; $i <= $count; $i++) {
        if ($type === 'mcq' || ($type === 'mixed' && $i % 3 === 1)) {
            $lines[] = "{$i}. MCQ: Which statement best explains {$topic}?";
            $lines[] = "   A. Correct concept linked to {$topic}";
            $lines[] = "   B. Common misconception";
            $lines[] = "   C. Partially related idea";
            $lines[] = "   D. Unrelated answer";
            $lines[] = "   Answer: A";
        } elseif ($type === 'essay' || ($type === 'mixed' && $i % 3 === 0)) {
            $lines[] = "{$i}. Essay: Discuss {$topic}, including key principles, practical examples, and common challenges.";
            $lines[] = "   Marking guide: concept accuracy, structure, application, and clarity.";
        } else {
            $lines[] = "{$i}. Short answer: Identify and explain one important principle of {$topic}.";
            $lines[] = "   Expected answer: A concise explanation with a relevant example.";
        }
        $lines[] = "";
    }

    $lines[] = "Review and edit these draft questions before using them in an official assessment.";
    return implode("\n", $lines);
}

// ── Data ────────────────────────────────────────────────────────────────────
$staffId  = (string)($_SESSION['staff_id'] ?? '');
$courses  = getLecturerCourseDetails($db, $staffId);
$courseMap = [];
foreach ($courses as $course) {
    $courseMap[strtoupper((string)$course['course_code'])] = $course;
}

$aiStatus = [
    'available' => false,
    'model_ready' => false,
    'model' => defined('AI_CHAT_MODEL') ? AI_CHAT_MODEL : '',
    'message' => 'AI status unavailable.',
];
try {
    $aiStatus = wuc_ai_local_status();
} catch (Throwable $e) {
    error_log('lecturers/ai_question_bank.php: AI status check failed: ' . $e->getMessage());
}
$errors   = [];
$result   = null;
$old = [
    'course_code'    => trim((string)($_POST['course_code']    ?? '')),
    'topic'          => trim((string)($_POST['topic']          ?? '')),
    'question_type'  => trim((string)($_POST['question_type']  ?? 'mixed')),
    'difficulty'     => trim((string)($_POST['difficulty']      ?? 'intermediate')),
    'question_count' => trim((string)($_POST['question_count'] ?? '8')),
    'instructions'   => trim((string)($_POST['instructions']   ?? '')),
];

// ── POST handler ────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wuc_verify_csrf();

    $courseCode    = strtoupper($old['course_code']);
    $topic         = wuc_ai_truncate($old['topic'], 300);
    $instructions  = wuc_ai_truncate($old['instructions'], 1000);
    $allowedTypes  = ['mixed', 'mcq', 'short_answer', 'essay'];
    $allowedDiff   = ['introductory', 'intermediate', 'advanced'];
    $questionType  = in_array($old['question_type'], $allowedTypes, true) ? $old['question_type'] : 'mixed';
    $difficulty    = in_array($old['difficulty'], $allowedDiff, true) ? $old['difficulty'] : 'intermediate';
    $questionCount = max(3, min(20, (int)$old['question_count']));

    if ($courseCode === '') {
        $errors[] = 'Select a course.';
    } elseif (!isset($courseMap[$courseCode]) || !isLecturerAssignedToCourse($db, $staffId, $courseCode)) {
        $errors[] = 'You are not assigned to the selected course.';
    }
    if ($topic === '') {
        $errors[] = 'Enter a topic or learning outcome.';
    }

    $rate = wuc_ai_rate_limit('lecturer_question_bank', 10, 3600);
    if (!$rate['ok']) {
        $errors[] = 'Too many AI requests. Please try again in about ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.';
    }

    if (!$errors) {
        $course      = $courseMap[$courseCode];
        $context     = [
            'course_code'          => $courseCode,
            'course_name'          => (string)($course['course_name'] ?? $courseCode),
            'topic'                => $topic,
            'question_type'        => $questionType,
            'difficulty'           => $difficulty,
            'question_count'       => $questionCount,
            'lecturer_instructions' => $instructions,
            'recent_materials'     => lecturer_ai_material_context($db, $courseCode),
            'rules'                => [
                'draft_only'                    => true,
                'lecturer_must_review_before_use' => true,
                'do_not_generate_final_grades'  => true,
            ],
        ];
        $contextJson = wuc_ai_context_json($context);

        $result = wuc_ai_generate($db, [
            'feature'       => 'lecturer_question_bank',
            'user_role'     => 'lecturer',
            'user_id'       => $staffId,
            'input_summary' => $courseCode . ' | ' . $topic . ' | ' . $questionType . ' | ' . $difficulty,
            'context_hash'  => hash('sha256', $contextJson),
            'messages'      => [
                [
                    'role'    => 'system',
                    'content' => 'You are the ITC Portal lecturer question-bank assistant. Generate draft assessment questions only. Use only the supplied course context. Include answers or marking guides. Do not assign grades, do not claim final approval, and remind the lecturer to review before publication.',
                ],
                [
                    'role'    => 'user',
                    'content' => "Create a question bank from this JSON context:\n{$contextJson}\n\nFormat with numbered questions, answer keys for objective questions, and marking guides for written questions.",
                ],
            ],
            'fallback'      => static function () use ($context): string {
                return lecturer_ai_fallback_questions($context);
            },
        ]);
    }
}

require_once __DIR__ . '/includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard lecturer-workflow-page ai-question-bank-page">

    <!-- Page header -->
    <div class="dashboard-header lecturer-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title">
                    <i class="fas fa-wand-magic-sparkles me-2 text-purple"></i>AI Question Bank
                </h1>
                <p class="text-muted mb-0">Generate draft questions for your assigned courses. Always review before using in assessments.</p>
            </div>
            <div class="col-auto d-flex align-items-center gap-2">
                <?php if ($result !== null): ?>
                    <a href="ai_question_bank.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-rotate-left me-1"></i>New draft
                    </a>
                <?php endif; ?>
                <span class="badge <?php echo $aiStatus['model_ready'] ? 'bg-success' : 'bg-secondary'; ?>"
                      title="<?php echo lecturer_ai_h($aiStatus['message'] ?? ''); ?>">
                    <i class="fas <?php echo $aiStatus['model_ready'] ? 'fa-circle-check' : 'fa-circle-xmark'; ?> me-1"></i>
                    <?php echo $aiStatus['model_ready'] ? 'AI ready' : 'Fallback mode'; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Navigation command bar -->
    <nav class="assignment-command-bar mb-4" aria-label="Assessment navigation">
        <a class="command-link" href="assessments.php"><i class="fas fa-file-alt"></i><span>Student Submissions</span></a>
        <a class="command-link" href="post_assign.php"><i class="fas fa-tasks"></i><span>Post Assignments</span></a>
        <a class="command-link active" href="ai_question_bank.php" aria-current="page"><i class="fas fa-wand-magic-sparkles"></i><span>AI Question Bank</span></a>
        <a class="command-link" href="upload_ca.php"><i class="fas fa-upload"></i><span>Upload CA</span></a>
        <a class="command-link" href="viewCaRes.php"><i class="fas fa-eye"></i><span>View CA Results</span></a>
    </nav>

    <!-- AI status detail (when not ready) -->
    <?php if (!$aiStatus['model_ready']): ?>
        <div class="alert alert-warning alert-sm d-flex align-items-start gap-2 mb-4" role="alert">
            <i class="fas fa-triangle-exclamation mt-1 flex-shrink-0"></i>
            <div>
                <strong>AI model not available.</strong>
                <?php echo lecturer_ai_h($aiStatus['message'] ?? ''); ?>
                <?php if (!empty($aiStatus['install_hint'])): ?>
                    <br><code class="user-select-all"><?php echo lecturer_ai_h($aiStatus['install_hint']); ?></code>
                <?php endif; ?>
                <br><span class="text-muted small">The form will still generate a structured fallback draft without AI.</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Error alerts -->
    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <div class="fw-semibold mb-1"><i class="fas fa-exclamation-circle me-2"></i>Check the question-bank request</div>
            <?php foreach (array_unique($errors) as $error): ?>
                <div><?php echo lecturer_ai_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($courses)): ?>
        <!-- Empty state: no courses assigned -->
        <div class="qb-empty-state">
            <div class="qb-empty-icon">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <h4>No courses assigned</h4>
            <p>You haven't been assigned to any courses yet. The question bank is available once course assignments are configured by the administrator.</p>
            <div class="qb-empty-actions">
                <a href="myCourses.php" class="btn btn-outline-primary">
                    <i class="fas fa-book me-2"></i>View My Courses
                </a>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fas fa-home me-2"></i>Dashboard
                </a>
            </div>
        </div>

    <?php else: ?>
        <div class="row g-4">

            <!-- Left panel: form -->
            <div class="col-xl-5">
                <section class="data-table-card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-sliders me-2"></i>Question settings</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="ai_question_bank.php" id="qbForm">
                            <input type="hidden" name="csrf_token" value="<?php echo lecturer_ai_h($_SESSION['csrf_token'] ?? ''); ?>">

                            <!-- Course -->
                            <div class="mb-3">
                                <label for="course_code" class="form-label fw-semibold">Course</label>
                                <select class="form-select" id="course_code" name="course_code" required>
                                    <option value="">— Select course —</option>
                                    <?php foreach ($courses as $course): ?>
                                        <?php $code = (string)$course['course_code']; ?>
                                        <option value="<?php echo lecturer_ai_h($code); ?>" <?php echo strcasecmp($old['course_code'], $code) === 0 ? 'selected' : ''; ?>>
                                            <?php echo lecturer_ai_h($code . ' – ' . (string)$course['course_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Topic -->
                            <div class="mb-3">
                                <label for="topic" class="form-label fw-semibold">Topic or learning outcome</label>
                                <input class="form-control" id="topic" name="topic" maxlength="300"
                                       value="<?php echo lecturer_ai_h($old['topic']); ?>"
                                       placeholder="e.g. Infection prevention principles" required>
                                <div class="form-text">Be specific — better context = better questions.</div>
                            </div>

                            <!-- Type & difficulty -->
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="question_type" class="form-label fw-semibold">Question type</label>
                                    <select class="form-select" id="question_type" name="question_type">
                                        <?php foreach (['mixed' => 'Mixed', 'mcq' => 'MCQ only', 'short_answer' => 'Short answer', 'essay' => 'Essay'] as $value => $label): ?>
                                            <option value="<?php echo lecturer_ai_h($value); ?>" <?php echo $old['question_type'] === $value ? 'selected' : ''; ?>><?php echo lecturer_ai_h($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="difficulty" class="form-label fw-semibold">Difficulty</label>
                                    <select class="form-select" id="difficulty" name="difficulty">
                                        <?php foreach (['introductory' => 'Introductory', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'] as $value => $label): ?>
                                            <option value="<?php echo lecturer_ai_h($value); ?>" <?php echo $old['difficulty'] === $value ? 'selected' : ''; ?>><?php echo lecturer_ai_h($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Question count -->
                            <div class="mb-3">
                                <label for="question_count" class="form-label fw-semibold">
                                    Number of questions
                                    <span class="qb-count-badge" id="countDisplay"><?php echo lecturer_ai_h($old['question_count']); ?></span>
                                </label>
                                <input type="range" min="3" max="20" step="1"
                                       class="form-range" id="question_count" name="question_count"
                                       value="<?php echo lecturer_ai_h($old['question_count']); ?>"
                                       oninput="document.getElementById('countDisplay').textContent=this.value">
                                <div class="d-flex justify-content-between form-text"><span>3</span><span>20</span></div>
                            </div>

                            <!-- Additional instructions -->
                            <div class="mb-4">
                                <label for="instructions" class="form-label fw-semibold">Additional instructions <span class="text-muted fw-normal">(optional)</span></label>
                                <textarea class="form-control" id="instructions" name="instructions"
                                          rows="3" maxlength="1000"
                                          placeholder="e.g. Include case-based questions, focus on practical application…"><?php echo lecturer_ai_h($old['instructions']); ?></textarea>
                                <div class="form-text" id="instrCount">0 / 1000 characters</div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100" id="qbSubmit">
                                <i class="fas fa-wand-magic-sparkles me-2"></i>Generate draft
                            </button>
                        </form>
                    </div>
                </section>
            </div>

            <!-- Right panel: output -->
            <div class="col-xl-7">
                <section class="data-table-card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h5 class="mb-0"><i class="fas fa-list-ol me-2"></i>Generated draft</h5>
                        <?php if ($result !== null): ?>
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted small" id="outputCharCount"></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="copyBtn"
                                        title="Copy to clipboard">
                                    <i class="fas fa-copy me-1"></i>Copy
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($result === null): ?>
                            <div class="qb-output-placeholder">
                                <i class="fas fa-wand-magic-sparkles qb-placeholder-icon"></i>
                                <p class="text-muted mb-1">Generated draft questions will appear here.</p>
                                <p class="text-muted small">Nothing is saved to an assessment until you review and post it yourself.</p>
                            </div>
                        <?php else: ?>
                            <div class="alert <?php echo $result['used_ai'] ? 'alert-info' : 'alert-warning'; ?> small mb-3" role="alert">
                                <i class="fas <?php echo $result['used_ai'] ? 'fa-robot' : 'fa-triangle-exclamation'; ?> me-1"></i>
                                <?php echo $result['used_ai']
                                    ? '<strong>AI generated</strong> · Model: ' . lecturer_ai_h($result['model']) . ' · ' . number_format((int)$result['duration_ms']) . ' ms'
                                    : lecturer_ai_h(wuc_ai_fallback_notice($result)); ?>
                            </div>
                            <div class="qb-output-wrapper">
                                <textarea class="form-control font-monospace qb-output-textarea"
                                          id="outputText" rows="22" readonly
                                          spellcheck="false"><?php echo lecturer_ai_h($result['text']); ?></textarea>
                            </div>
                            <div class="qb-output-footer">
                                <span class="text-muted small">
                                    <i class="fas fa-triangle-exclamation me-1 text-warning"></i>
                                    Draft only — review all questions before publishing to students.
                                </span>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="copyBtnFooter">
                                    <i class="fas fa-copy me-1"></i>Copy all
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';

    // Instructions character counter
    var instr = document.getElementById('instructions');
    var instrCount = document.getElementById('instrCount');
    if (instr && instrCount) {
        function updateInstrCount() {
            instrCount.textContent = instr.value.length + ' / 1000 characters';
        }
        instr.addEventListener('input', updateInstrCount);
        updateInstrCount();
    }

    // Output character count
    var outputText = document.getElementById('outputText');
    var outputCharCount = document.getElementById('outputCharCount');
    if (outputText && outputCharCount) {
        var len = outputText.value.length;
        outputCharCount.textContent = len.toLocaleString() + ' characters';
    }

    // Copy to clipboard helper
    function copyOutput(btn) {
        if (!outputText) return;
        var text = outputText.value;
        if (!text) return;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                flashCopied(btn);
            }).catch(function () {
                fallbackCopy(text, btn);
            });
        } else {
            fallbackCopy(text, btn);
        }
    }

    function fallbackCopy(text, btn) {
        outputText.select();
        try {
            document.execCommand('copy');
            flashCopied(btn);
        } catch (e) {
            // silent
        }
    }

    function flashCopied(btn) {
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
        btn.classList.add('btn-success');
        btn.classList.remove('btn-outline-secondary', 'btn-outline-primary');
        setTimeout(function () {
            btn.innerHTML = orig;
            btn.classList.remove('btn-success');
            btn.classList.add(btn.id === 'copyBtnFooter' ? 'btn-outline-primary' : 'btn-outline-secondary');
        }, 2000);
    }

    var copyBtn = document.getElementById('copyBtn');
    if (copyBtn) copyBtn.addEventListener('click', function () { copyOutput(this); });

    var copyBtnFooter = document.getElementById('copyBtnFooter');
    if (copyBtnFooter) copyBtnFooter.addEventListener('click', function () { copyOutput(this); });

    // Loading state on form submit
    var form = document.getElementById('qbForm');
    var submitBtn = document.getElementById('qbSubmit');
    if (form && submitBtn) {
        form.addEventListener('submit', function () {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Generating…';
        });
    }
})();
</script>
