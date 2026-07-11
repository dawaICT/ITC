<?php
declare(strict_types=1);
/**
 * AI Assessment Bank — generate TEVETA-aligned exam/assessment questions for a
 * transport program + learning outcome, then SAVE, edit, reuse and export them.
 *
 * Generated questions are no longer throwaway text: they land in an editable
 * editor, can be saved to a persistent bank (transport_assessment_bank), and be
 * re-opened, edited, copied, downloaded (.md) or printed for use in real exams.
 */
require_once __DIR__ . '/includes/transport.php';
require_once __DIR__ . '/includes/teveta_helpers.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';

$page_title = 'AI Assessment Bank';

// Self-heal the bank table where the migration has not been applied yet. The
// DML-only app user cannot run DDL, so this is a graceful no-op there (logged,
// never fatal); apply migrations/20260622_transport_assessment_bank.sql as
// wucportal_migrator for full functionality.
wuc_ensure_tables($db, [
    "CREATE TABLE IF NOT EXISTS transport_assessment_bank (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_id INT NOT NULL,
        outcome_id INT NULL,
        title VARCHAR(200) NOT NULL,
        qtype ENUM('mixed','mcq','short_answer','scenario') NOT NULL DEFAULT 'mixed',
        question_count INT NOT NULL DEFAULT 0,
        content MEDIUMTEXT NOT NULL,
        source ENUM('ai','fallback','manual') NOT NULL DEFAULT 'ai',
        model VARCHAR(120) NULL,
        created_by VARCHAR(80) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_tab_program (program_id),
        KEY idx_tab_outcome (outcome_id),
        KEY idx_tab_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]);
$bankReady = wuc_table_exists($db, 'transport_assessment_bank');

$QTYPES = ['mixed' => 'Mixed', 'mcq' => 'MCQ', 'short_answer' => 'Short answer', 'scenario' => 'Scenario'];
$CONTENT_MAX = 100000;

$programs = [];
$res = $db->query("SELECT id, program_code, program_name, program_type, license_class FROM transport_programs ORDER BY program_name");
while ($r = $res->fetch_assoc()) { $programs[(int)$r['id']] = $r; }
$outcomes = [];
$res = $db->query("SELECT id, code, title, description FROM transport_curriculum_outcomes ORDER BY id");
while ($r = $res->fetch_assoc()) { $outcomes[(int)$r['id']] = $r; }

/** Count questions by leading "1." / "1)" markers; 0 if none found. */
function ab_count_questions(string $content): int
{
    return preg_match_all('/^\s*\d+[.)]\s+\S/m', $content, $m) ? count($m[0]) : 0;
}

/** Friendly default title from the request parameters. */
function ab_default_title(?array $program, ?array $outcome, string $qtype, int $count, array $labels): string
{
    $code = $program['program_code'] ?? 'Program';
    $oc   = $outcome ? (string)$outcome['code'] : 'General';
    $type = $labels[$qtype] ?? 'Mixed';
    return sprintf('%s · %s · %d %s', $code, $oc, max(0, $count), $type);
}

$aiStatus = wuc_ai_local_status();
$result   = null;   // AI generation metadata for a fresh generate
$errors   = [];

// Editor state (drives the right-hand panel; populated by generate / ?edit / failed save)
$editor = [
    'id'         => 0,
    'program_id' => (int)($_POST['program_id'] ?? 0),
    'outcome_id' => (int)($_POST['outcome_id'] ?? 0),
    'qtype'      => (string)($_POST['qtype'] ?? 'mixed'),
    'count'      => (int)($_POST['count'] ?? 8),
    'title'      => '',
    'content'    => '',
    'source'     => 'ai',
    'model'      => '',
];

$action = (string)($_POST['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'generate' : ''));

// ── POST: generate ───────────────────────────────────────────────────────────
if ($action === 'generate') {
    if (!tev_verify_csrf()) { $errors[] = 'Security token mismatch. Please refresh and try again.'; }

    $program = $programs[$editor['program_id']] ?? null;
    $outcome = $outcomes[$editor['outcome_id']] ?? null;
    if (!$program) { $errors[] = 'Select a program.'; }

    $count = max(3, min(20, $editor['count']));
    $qtype = isset($QTYPES[$editor['qtype']]) ? $editor['qtype'] : 'mixed';
    $editor['count'] = $count;
    $editor['qtype'] = $qtype;

    if (!$errors) {
        $rate = wuc_ai_rate_limit('transport_ai_bank', 15, 3600);
        if (!$rate['ok']) {
            $errors[] = 'Too many AI requests. Try again in ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.';
        }
    }

    if (!$errors) {
        $context = [
            'program'        => $program['program_name'] . ' (' . $program['program_code'] . ')',
            'license_class'  => $program['license_class'] ?? '',
            'teveta_outcome' => $outcome ? ($outcome['code'] . ' — ' . $outcome['title']) : 'General transport competency',
            'outcome_detail' => $outcome['description'] ?? '',
            'question_count' => $count,
            'question_type'  => $qtype,
            'rules'          => [
                'align_to_teveta_outcome'            => true,
                'zambia_rtsa_context'                => true,
                'include_answer_key_and_marking_guide' => true,
            ],
        ];
        $ctxJson  = wuc_ai_context_json($context, 6000);
        $typeText = [
            'mixed'        => 'a mix of MCQ, short-answer and scenario questions',
            'mcq'          => 'multiple-choice questions',
            'short_answer' => 'short-answer questions',
            'scenario'     => 'scenario-based questions',
        ][$qtype];

        $result = wuc_ai_generate($db, [
            'feature'       => 'transport_ai_assessment_bank',
            'user_role'     => 'transport',
            'user_id'       => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'transport'),
            'input_summary' => $program['program_code'] . ' | ' . ($outcome['code'] ?? 'gen') . ' | ' . $qtype,
            'context_hash'  => hash('sha256', $ctxJson),
            'messages'      => [
                ['role' => 'system', 'content' => 'You are an assessment author for a Zambian TEVETA/RTSA-accredited transport training centre. '
                    . 'Generate rigorous, fair driver-training assessment questions aligned to the given TEVETA learning outcome and licence class. '
                    . 'Ground content in Zambian road rules and RTSA requirements where relevant. For each question include the correct answer and a brief marking guide. '
                    . 'Use clear Markdown with numbered questions.'],
                ['role' => 'user', 'content' => "Assessment request (JSON):\n{$ctxJson}\n\nWrite {$count} {$typeText} with answer key and marking guide."],
            ],
            'fallback'      => static function () use ($context): string {
                return "AI is offline. Draft a question set manually for outcome "
                    . ($context['teveta_outcome'] ?? '') . " covering: key definitions, a road-rule application, "
                    . "a hazard/defensive-driving scenario, and a practical competency check. Include an answer key.";
            },
        ]);

        // Funnel the generated questions straight into the editable editor.
        $editor['content'] = (string)$result['text'];
        $editor['source']  = $result['used_ai'] ? 'ai' : 'fallback';
        $editor['model']   = (string)$result['model'];
        $editor['title']   = ab_default_title($program, $outcome, $qtype, $count, $QTYPES);
    }
}

// ── POST: save / update ──────────────────────────────────────────────────────
if ($action === 'save') {
    if (!tev_verify_csrf()) {
        tev_flash_set('danger', 'Security token mismatch. Please refresh and try again.');
        tev_redirect_to('ai_assessment_bank.php');
    }

    $bankId    = (int)($_POST['bank_id'] ?? 0);
    $programId = (int)($_POST['program_id'] ?? 0);
    $outcomeId = (int)($_POST['outcome_id'] ?? 0);
    $qtype     = isset($QTYPES[(string)($_POST['qtype'] ?? '')]) ? (string)$_POST['qtype'] : 'mixed';
    $title     = trim((string)($_POST['title'] ?? ''));
    $content   = trim((string)($_POST['content'] ?? ''));
    $source    = (string)($_POST['source'] ?? 'ai');
    $source    = in_array($source, ['ai', 'fallback', 'manual'], true) ? $source : 'ai';
    $model     = trim((string)($_POST['model'] ?? ''));
    $createdBy = (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? '');

    $program = $programs[$programId] ?? null;
    $outcome = $outcomes[$outcomeId] ?? null;

    if (!$bankReady)             { $errors[] = 'Saving is unavailable until the database migration is applied.'; }
    if (!$program)               { $errors[] = 'Select a valid program before saving.'; }
    if ($content === '')         { $errors[] = 'There are no questions to save.'; }
    if (mb_strlen($content) > $CONTENT_MAX) { $errors[] = 'The question set is too long to save.'; }
    if ($title === '')           { $title = ab_default_title($program, $outcome, $qtype, (int)($_POST['count'] ?? 0), $QTYPES); }
    $title = mb_substr($title, 0, 200);

    if (!$errors) {
        $qcount    = ab_count_questions($content) ?: max(0, (int)($_POST['count'] ?? 0));
        $outcomeDb = $outcomeId > 0 ? $outcomeId : null;
        try {
            if ($bankId > 0) {
                $stmt = $db->prepare("UPDATE transport_assessment_bank
                    SET program_id=?, outcome_id=?, title=?, qtype=?, question_count=?, content=? WHERE id=?");
                $stmt->bind_param('iissisi', $programId, $outcomeDb, $title, $qtype, $qcount, $content, $bankId);
                $stmt->execute();
                $stmt->close();
                tev_flash_set('success', 'Question set updated.');
            } else {
                $stmt = $db->prepare("INSERT INTO transport_assessment_bank
                    (program_id, outcome_id, title, qtype, question_count, content, source, model, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('iississss', $programId, $outcomeDb, $title, $qtype, $qcount, $content, $source, $model, $createdBy);
                $stmt->execute();
                $bankId = (int)$stmt->insert_id;
                $stmt->close();
                tev_flash_set('success', 'Question set saved to the bank.');
            }
            tev_redirect_to('ai_assessment_bank.php?edit=' . $bankId);
        } catch (Throwable $e) {
            error_log('ai_assessment_bank save: ' . $e->getMessage());
            $errors[] = 'Could not save the question set. Please try again.';
        }
    }

    // Validation/save failed → keep the user's work in the editor.
    $editor = [
        'id'         => $bankId,
        'program_id' => $programId,
        'outcome_id' => $outcomeId,
        'qtype'      => $qtype,
        'count'      => (int)($_POST['count'] ?? 0),
        'title'      => $title,
        'content'    => $content,
        'source'     => $source,
        'model'      => $model,
    ];
}

// ── POST: delete ─────────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!tev_verify_csrf()) {
        tev_flash_set('danger', 'Security token mismatch. Please refresh and try again.');
        tev_redirect_to('ai_assessment_bank.php');
    }
    $bankId = (int)($_POST['bank_id'] ?? 0);
    if ($bankReady && $bankId > 0) {
        try {
            $stmt = $db->prepare("DELETE FROM transport_assessment_bank WHERE id=?");
            $stmt->bind_param('i', $bankId);
            $stmt->execute();
            $stmt->close();
            tev_flash_set('success', 'Question set deleted.');
        } catch (Throwable $e) {
            error_log('ai_assessment_bank delete: ' . $e->getMessage());
            tev_flash_set('danger', 'Could not delete the question set.');
        }
    }
    tev_redirect_to('ai_assessment_bank.php');
}

// ── GET: load a saved set into the editor ────────────────────────────────────
if ($action === '' && $bankReady && isset($_GET['edit'])) {
    $bankId = (int)$_GET['edit'];
    if ($bankId > 0) {
        $stmt = $db->prepare("SELECT * FROM transport_assessment_bank WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $bankId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $editor = [
                'id'         => (int)$row['id'],
                'program_id' => (int)$row['program_id'],
                'outcome_id' => (int)$row['outcome_id'],
                'qtype'      => (string)$row['qtype'],
                'count'      => (int)$row['question_count'],
                'title'      => (string)$row['title'],
                'content'    => (string)$row['content'],
                'source'     => (string)$row['source'],
                'model'      => (string)$row['model'],
            ];
        } else {
            $errors[] = 'That saved question set no longer exists.';
        }
    }
}

// ── Load the saved bank list ─────────────────────────────────────────────────
$saved = [];
if ($bankReady) {
    $res = $db->query("SELECT b.id, b.title, b.qtype, b.question_count, b.source, b.created_at, b.updated_at,
                              p.program_code, p.program_name, o.code AS outcome_code, o.title AS outcome_title
                       FROM transport_assessment_bank b
                       LEFT JOIN transport_programs p ON p.id = b.program_id
                       LEFT JOIN transport_curriculum_outcomes o ON o.id = b.outcome_id
                       ORDER BY COALESCE(b.updated_at, b.created_at) DESC, b.id DESC");
    if ($res) { while ($r = $res->fetch_assoc()) { $saved[] = $r; } }
}

$hasEditor      = $editor['content'] !== '' || $editor['id'] > 0;
$editorProgram  = $programs[$editor['program_id']] ?? null;
$editorOutcome  = $outcomes[$editor['outcome_id']] ?? null;
// The generate form mirrors the active editor selection for an easy "regenerate".
$form = [
    'program_id' => $editor['program_id'],
    'outcome_id' => $editor['outcome_id'],
    'count'      => max(3, min(20, $editor['count'] ?: 8)),
    'qtype'      => isset($QTYPES[$editor['qtype']]) ? $editor['qtype'] : 'mixed',
];

require_once __DIR__ . '/includes/nav.php';
echo wuc_ai_output_styles();
?>
<style>
.ab-textarea{font-family:'SFMono-Regular',Consolas,'Liberation Mono',monospace;font-size:.85rem;line-height:1.6;min-height:430px;white-space:pre;overflow-wrap:normal;}
.ab-preview{min-height:430px;max-height:620px;overflow:auto;}
.ab-bank-content{max-height:300px;overflow:auto;}
</style>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h3 class="mb-1"><i class="fas fa-wand-magic-sparkles me-2"></i>AI Assessment Bank</h3>
            <p class="text-muted mb-0">Generate TEVETA-aligned questions, then save, edit, reuse and export them for real assessments.</p>
        </div>
        <span class="badge <?php echo $aiStatus['model_ready'] ? 'bg-success' : 'bg-secondary'; ?>" title="<?php echo tev_h($aiStatus['message'] ?? ''); ?>">
            <i class="fas fa-robot me-1"></i><?php echo $aiStatus['model_ready'] ? 'AI online' : 'Fallback'; ?>
        </span>
    </div>

    <?php echo tev_flash_render(); ?>
    <?php if ($errors): ?><div class="alert alert-danger"><?php foreach (array_unique($errors) as $e) echo '<div>' . tev_h($e) . '</div>'; ?></div><?php endif; ?>
    <?php if (!$bankReady): ?>
        <div class="alert alert-warning small d-flex align-items-start gap-2">
            <i class="fas fa-triangle-exclamation mt-1"></i>
            <div>Generation works, but <strong>saving is disabled</strong> until the bank table is created.
            Apply <code>migrations/20260622_transport_assessment_bank.sql</code> as <code>wucportal_migrator</code>.</div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Request -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><strong><i class="fas fa-sliders me-2"></i>Generate</strong></div>
                <div class="card-body">
                    <form method="post" id="abGenForm">
                        <input type="hidden" name="csrf_token" value="<?php echo tev_h(tev_csrf_token()); ?>">
                        <input type="hidden" name="action" value="generate">
                        <div class="mb-3"><label class="form-label">Program</label>
                            <select name="program_id" class="form-select" required>
                                <option value="">Select program</option>
                                <?php foreach ($programs as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $form['program_id'] === (int)$p['id'] ? 'selected' : ''; ?>><?php echo tev_h($p['program_code'] . ' — ' . $p['program_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3"><label class="form-label">TEVETA outcome</label>
                            <select name="outcome_id" class="form-select">
                                <option value="0">General competency</option>
                                <?php foreach ($outcomes as $o): ?><option value="<?php echo (int)$o['id']; ?>" <?php echo $form['outcome_id'] === (int)$o['id'] ? 'selected' : ''; ?>><?php echo tev_h($o['code'] . ' — ' . $o['title']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2">
                            <div class="col-6 mb-3"><label class="form-label">Questions</label><input type="number" min="3" max="20" name="count" class="form-control" value="<?php echo (int)$form['count']; ?>"></div>
                            <div class="col-6 mb-3"><label class="form-label">Type</label>
                                <select name="qtype" class="form-select">
                                    <?php foreach ($QTYPES as $v => $l): ?><option value="<?php echo $v; ?>" <?php echo $form['qtype'] === $v ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <button class="btn btn-primary w-100" id="abGenBtn"><i class="fas fa-wand-magic-sparkles me-1"></i>Generate questions</button>
                        <p class="form-text mt-2 mb-0">Draft only — review and edit before using in an official assessment.</p>
                    </form>
                </div>
            </div>
        </div>

        <!-- Editor -->
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong><i class="fas fa-pen-to-square me-2"></i><?php echo $editor['id'] > 0 ? 'Edit saved question set' : 'Generated questions'; ?></strong>
                    <?php if ($result !== null): ?>
                        <small class="text-muted"><?php echo $result['used_ai'] ? tev_h($result['model']) . ' · ' . number_format($result['duration_ms'] / 1000, 1) . 's' : 'Fallback'; ?></small>
                    <?php elseif ($editor['id'] > 0): ?>
                        <a href="ai_assessment_bank.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-plus me-1"></i>New</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$hasEditor): ?>
                        <div class="text-muted text-center py-5">
                            <i class="fas fa-wand-magic-sparkles fa-2x mb-2 opacity-50"></i>
                            <p class="mb-0">Generate a question set, or open a saved one from the bank below.</p>
                        </div>
                    <?php else: ?>
                        <?php if ($result !== null && !$result['used_ai']): ?>
                            <div class="alert alert-warning small"><?php echo tev_h(wuc_ai_fallback_notice($result)); ?></div>
                        <?php endif; ?>

                        <form method="post" id="abSaveForm">
                            <input type="hidden" name="csrf_token" value="<?php echo tev_h(tev_csrf_token()); ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="bank_id" value="<?php echo (int)$editor['id']; ?>">
                            <input type="hidden" name="program_id" value="<?php echo (int)$editor['program_id']; ?>">
                            <input type="hidden" name="outcome_id" value="<?php echo (int)$editor['outcome_id']; ?>">
                            <input type="hidden" name="qtype" value="<?php echo tev_h($editor['qtype']); ?>">
                            <input type="hidden" name="count" value="<?php echo (int)$editor['count']; ?>">
                            <input type="hidden" name="source" value="<?php echo tev_h($editor['source']); ?>">
                            <input type="hidden" name="model" value="<?php echo tev_h($editor['model']); ?>">

                            <div class="mb-2 small text-muted">
                                <i class="fas fa-graduation-cap me-1"></i><?php echo tev_h($editorProgram['program_code'] ?? '—'); ?>
                                · <?php echo tev_h($editorOutcome ? $editorOutcome['code'] : 'General'); ?>
                                · <?php echo tev_h($QTYPES[$editor['qtype']] ?? 'Mixed'); ?>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1">Title</label>
                                <input type="text" name="title" id="abTitle" class="form-control" maxlength="200" value="<?php echo tev_h($editor['title']); ?>" placeholder="Question set title">
                            </div>

                            <ul class="nav nav-tabs" role="tablist">
                                <li class="nav-item"><button class="nav-link active" type="button" data-bs-toggle="tab" data-bs-target="#abTabEdit"><i class="fas fa-pen me-1"></i>Edit</button></li>
                                <li class="nav-item"><button class="nav-link" type="button" id="abPreviewTab" data-bs-toggle="tab" data-bs-target="#abTabPreview"><i class="fas fa-eye me-1"></i>Preview</button></li>
                            </ul>
                            <div class="tab-content border border-top-0 rounded-bottom p-2 mb-3">
                                <div class="tab-pane fade show active" id="abTabEdit">
                                    <textarea name="content" id="abEditor" class="form-control ab-textarea border-0" spellcheck="false"><?php echo tev_h($editor['content']); ?></textarea>
                                </div>
                                <div class="tab-pane fade" id="abTabPreview">
                                    <div id="abPreview" class="ai-output ab-preview"></div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary" <?php echo $bankReady ? '' : 'disabled'; ?>>
                                    <i class="fas fa-floppy-disk me-1"></i><?php echo $editor['id'] > 0 ? 'Update set' : 'Save to bank'; ?>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="abCopy"><i class="fas fa-copy me-1"></i>Copy</button>
                                <button type="button" class="btn btn-outline-secondary" id="abDownload"><i class="fas fa-download me-1"></i>Download</button>
                                <button type="button" class="btn btn-outline-secondary" id="abPrint"><i class="fas fa-print me-1"></i>Print</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Saved bank -->
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><i class="fas fa-book-bookmark me-2"></i>Saved question bank</strong>
            <span class="badge bg-secondary"><?php echo count($saved); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Title</th><th>Program</th><th>Outcome</th><th>Type</th><th class="text-center">Q's</th><th>Updated</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <?php if (!$saved): ?>
                            <tr><td colspan="7" class="text-muted text-center py-4">No saved question sets yet. Generate a set and click <em>Save to bank</em>.</td></tr>
                        <?php else: foreach ($saved as $s):
                            $when = $s['updated_at'] ?: $s['created_at']; ?>
                            <tr class="<?php echo (int)$s['id'] === (int)$editor['id'] ? 'table-active' : ''; ?>">
                                <td>
                                    <div class="fw-semibold"><?php echo tev_h($s['title']); ?></div>
                                    <?php if ($s['source'] !== 'ai'): ?><span class="badge bg-light text-dark border"><?php echo tev_h(ucfirst($s['source'])); ?></span><?php endif; ?>
                                </td>
                                <td class="small"><?php echo tev_h($s['program_code'] ?? '—'); ?></td>
                                <td class="small"><?php echo tev_h($s['outcome_code'] ?? 'General'); ?></td>
                                <td class="small text-capitalize"><?php echo tev_h(str_replace('_', ' ', $s['qtype'])); ?></td>
                                <td class="text-center"><span class="badge bg-secondary"><?php echo (int)$s['question_count']; ?></span></td>
                                <td class="small text-muted"><?php echo tev_h(date('d M Y', strtotime((string)$when))); ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="ai_assessment_bank.php?edit=<?php echo (int)$s['id']; ?>"><i class="fas fa-folder-open me-1"></i>Open</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this question set? This cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo tev_h(tev_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="bank_id" value="<?php echo (int)$s['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var editor = document.getElementById('abEditor');
    if (!editor) return;
    var titleEl = document.getElementById('abTitle');
    var preview = document.getElementById('abPreview');

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function inlineMd(t) {
        t = t.replace(/`([^`]+)`/g, '<code>$1</code>');
        t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        t = t.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
        return t;
    }
    // Compact Markdown -> HTML for live preview. Escapes first (XSS-safe); the
    // server's wuc_ai_render_markdown remains canonical for saved output.
    function renderMd(src) {
        var lines = esc(String(src || '').replace(/\r\n?/g, '\n')).split('\n');
        var out = [], list = null, para = [];
        function flush() { if (para.length) { out.push('<p>' + inlineMd(para.join('<br>')) + '</p>'); para = []; } }
        function close() { if (list) { out.push('</' + list + '>'); list = null; } }
        for (var i = 0; i < lines.length; i++) {
            var t = lines[i].trim(), m;
            if (t === '') { flush(); close(); continue; }
            if ((m = t.match(/^(#{1,6})\s+(.*)$/))) { flush(); close(); var lv = Math.min(6, Math.max(3, m[1].length + 2)); out.push('<h' + lv + '>' + inlineMd(m[2]) + '</h' + lv + '>'); continue; }
            if (/^(-{3,}|\*{3,}|_{3,})$/.test(t)) { flush(); close(); out.push('<hr>'); continue; }
            if ((m = t.match(/^\d+[.)]\s+(.*)$/))) { flush(); if (list !== 'ol') { close(); out.push('<ol>'); list = 'ol'; } out.push('<li>' + inlineMd(m[1]) + '</li>'); continue; }
            if ((m = t.match(/^[-*+]\s+(.*)$/))) { flush(); if (list !== 'ul') { close(); out.push('<ul>'); list = 'ul'; } out.push('<li>' + inlineMd(m[1]) + '</li>'); continue; }
            if (list) close();
            para.push(t);
        }
        flush(); close();
        return out.join('\n');
    }
    function refreshPreview() {
        if (preview) preview.innerHTML = renderMd(editor.value) || '<p class="text-muted">Nothing to preview.</p>';
    }

    var previewTab = document.getElementById('abPreviewTab');
    if (previewTab) previewTab.addEventListener('shown.bs.tab', refreshPreview);
    editor.addEventListener('input', function () {
        var pane = document.getElementById('abTabPreview');
        if (pane && pane.classList.contains('active')) refreshPreview();
    });

    function slug(s) {
        return (String(s || 'questions').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'questions');
    }
    function flash(btn, label) {
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check me-1"></i>' + label;
        setTimeout(function () { btn.innerHTML = orig; }, 1800);
    }

    var copyBtn = document.getElementById('abCopy');
    if (copyBtn) copyBtn.addEventListener('click', function () {
        var self = this, text = editor.value;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { flash(self, 'Copied'); }).catch(function () { legacyCopy(); });
        } else { legacyCopy(); }
        function legacyCopy() { editor.select(); try { document.execCommand('copy'); flash(self, 'Copied'); } catch (e) {} editor.setSelectionRange(0, 0); }
    });

    var dlBtn = document.getElementById('abDownload');
    if (dlBtn) dlBtn.addEventListener('click', function () {
        var blob = new Blob([editor.value], { type: 'text/markdown;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = slug(titleEl ? titleEl.value : 'questions') + '.md';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
        flash(this, 'Saved');
    });

    var printBtn = document.getElementById('abPrint');
    if (printBtn) printBtn.addEventListener('click', function () {
        var title = titleEl ? titleEl.value : 'Assessment questions';
        var w = window.open('', '_blank');
        if (!w) return;
        w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title>'
            + '<style>body{font-family:Inter,system-ui,Arial,sans-serif;color:#222;line-height:1.6;max-width:780px;margin:32px auto;padding:0 20px;}'
            + 'h1{font-size:1.4rem;border-bottom:2px solid #1B2A4A;padding-bottom:8px;}h3,h4,h5{margin:1.1em 0 .4em;}'
            + 'ol,ul{padding-left:1.4em;}li{margin:.3em 0;}code{background:#f1edf9;padding:.1em .4em;border-radius:4px;}'
            + 'hr{border:0;border-top:1px solid #ccc;margin:1em 0;}@media print{body{margin:0;}}</style></head><body>'
            + '<h1>' + esc(title) + '</h1>' + renderMd(editor.value) + '</body></html>');
        w.document.close(); w.focus();
        setTimeout(function () { w.print(); }, 250);
    });

    var genBtn = document.getElementById('abGenBtn');
    var genForm = document.getElementById('abGenForm');
    if (genBtn && genForm) genForm.addEventListener('submit', function () {
        genBtn.disabled = true;
        genBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generating…';
    });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
