<?php
declare(strict_types=1);

// ===== Session & authentication (admissions pattern) =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

if (!checkSessionTimeout(30) || !isAdminAuthenticated()) {
    setFlashMessage('error', 'Session expired or unauthorized access');
    header('Location: /wucportal/staff_login.php');
    exit;
}

// Admissions' legacy login uses index=admin. Global staff sessions must also
// hold an admissions-capable role; staff_id alone is not authorization.
$legacyAdmissionsLogin = isset($_SESSION['index']) && $_SESSION['index'] === 'admin';
if (!$legacyAdmissionsLogin && !canAccessAdmissions()) {
    http_response_code(403);
    setFlashMessage('error', 'You do not have permission to use admissions screening tools.');
    header('Location: /wucportal/staff_login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function ai_appl_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Load active programs for the dropdown. */
function ai_appl_programs(mysqli $db): array
{
    $out = [];
    if ($res = @$db->query("SELECT program_code, program_name, program_type, program_duration FROM programs WHERE is_active = 1 OR is_active IS NULL ORDER BY program_name")) {
        while ($row = $res->fetch_assoc()) {
            $code = trim((string)$row['program_code']);
            if ($code !== '') {
                $out[$code] = $row;
            }
        }
        $res->free();
    }
    return $out;
}

/** Load a processed applicant by id (privacy-safe subset). */
function ai_appl_load(mysqli $db, int $id): ?array
{
    // Checklist fields (nrc_file, deposit_slip, contacts…) stay server-side for
    // the rules engine only — they are never added to the LLM context.
    $stmt = $db->prepare(
        "SELECT id, program, program_code, mode, intake, year, results, status,
                nrc_pass, nrc_file, deposit_slip, email, mobile, next_kin
         FROM processed_applicants WHERE id = ? LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// AJAX endpoint to fetch applicant details
if (isset($_GET['ajax_get_applicant']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];
    $appl = null;
    if ($id > 0) {
        $appl = ai_appl_load($db, $id);
    }
    echo json_encode($appl);
    exit;
}

/** Recent applicants for the picker. */
function ai_appl_recent(mysqli $db): array
{
    $out = [];
    if ($res = @$db->query(
        "SELECT id, Fname, Lname, program, program_code, status
         FROM processed_applicants ORDER BY id DESC LIMIT 100"
    )) {
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        $res->free();
    }
    return $out;
}

function ai_appl_fallback(array $ctx): string
{
    $prog = (string)($ctx['program_applied'] ?? 'N/A');
    $qual = trim((string)($ctx['qualifications'] ?? ''));
    $lines = [];
    $lines[] = 'ADMISSIONS SCREENING — STRUCTURED SUMMARY (AI offline)';
    $lines[] = '';
    $lines[] = 'Program applied for: ' . $prog;
    $lines[] = 'Study mode: ' . (string)($ctx['study_mode'] ?? 'N/A');
    if ($qual === '') {
        $lines[] = 'No qualifications/results text was provided. Request the applicant\'s academic results before assessing eligibility.';
    } else {
        $lines[] = 'Qualifications on record:';
        $lines[] = $qual;
    }
    $lines[] = '';
    $lines[] = 'Manual checklist:';
    $lines[] = '1. Confirm minimum entry requirements for the program are met.';
    $lines[] = '2. Verify certificates/results are authentic and complete.';
    $lines[] = '3. Check English/maths or program-specific prerequisites.';
    $lines[] = '4. Record the evidence reviewed, gaps found, and the officer\'s independent decision.';
    return implode("\n", $lines);
}

$programs = ai_appl_programs($db);
$recent = ai_appl_recent($db);
$aiStatus = wuc_ai_local_status();
$officerId = (string)($_SESSION['staff_id'] ?? ($_SESSION['user_name'] ?? 'admissions'));

$errors = [];
$result = null;

$applicantId = (int)($_REQUEST['applicant_id'] ?? 0);
$old = [
    'applicant_id' => $applicantId,
    'program_code' => trim((string)($_POST['program_code'] ?? '')),
    'qualifications' => trim((string)($_POST['qualifications'] ?? '')),
    'focus' => (string)($_POST['focus'] ?? 'overall'),
];

$applicant = null;
$disableProgramSelect = false;

if ($applicantId > 0) {
    $applicant = ai_appl_load($db, $applicantId);
    if ($applicant === null) {
        $errors[] = 'Selected applicant could not be found.';
    } else {
        if (trim((string)($applicant['program_code'] ?? '')) !== '') {
            $old['program_code'] = trim((string)($applicant['program_code'] ?? ''));
            $disableProgramSelect = true;
        }
        if (trim((string)($applicant['results'] ?? '')) !== '') {
            $old['qualifications'] = trim((string)($applicant['results'] ?? ''));
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    }

    if ($old['program_code'] === '' && ($applicant === null || trim((string)($applicant['program'] ?? '')) === '')) {
        $errors[] = 'Select the program applied for.';
    }
    if ($old['qualifications'] === '') {
        $errors[] = 'Enter the applicant\'s qualifications/results to assess.';
    }
    if ($old['program_code'] !== '' && !isset($programs[$old['program_code']])) {
        $errors[] = 'Select a valid active program from the list.';
    }

    $allowedFocus = ['overall', 'eligibility', 'decision_note'];
    $focus = in_array($old['focus'], $allowedFocus, true) ? $old['focus'] : 'overall';

    if (!$errors) {
        $rate = wuc_ai_rate_limit('admissions_applicant_assistant', 20, 3600);
        if (!$rate['ok']) {
            $errors[] = 'Too many AI requests. Try again in about ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.';
        }
    }

    if (!$errors) {
        $prog = $programs[$old['program_code']] ?? null;
        $programName = $prog['program_name'] ?? ($applicant['program'] ?? $old['program_code']);

        // Privacy: send ONLY academic-relevant fields. No NRC, no contact details,
        // no full name. First name omitted too — screening is qualification-based.
        $context = [
            'program_applied'      => $programName,
            'program_type'         => $prog['program_type'] ?? '',
            'program_duration'     => $prog['program_duration'] ?? '',
            'study_mode'           => $applicant['mode'] ?? '',
            'qualifications'       => wuc_ai_truncate($old['qualifications'], 4000),
            'evaluation_focus'     => $focus,
            'rules'                => [
                'advisory_only_human_makes_final_decision' => true,
                'assess_only_academic_fit_and_stated_requirements' => true,
                'no_bias_on_gender_age_religion_origin_disability' => true,
                'flag_missing_information_rather_than_assume' => true,
            ],
        ];
        $contextJson = wuc_ai_context_json($context, 12000);

        $focusInstruction = [
            'overall' => 'Summarize the academic evidence, uncertainties, and items an admissions officer must verify. Do not recommend or predict an admission outcome.',
            'eligibility' => 'Map the supplied qualifications to the stated program information. List apparent matches, missing evidence, and what must be verified. Do not decide eligibility or recommend an outcome.',
            'decision_note' => 'Draft a short, factual internal review note (4-6 sentences) describing evidence reviewed, gaps, and next verification steps. Do not include a recommended admission action.',
        ][$focus];

        $result = wuc_ai_generate($db, [
            'feature'       => 'admissions_applicant_assistant',
            'user_role'     => 'admissions',
            'user_id'       => $officerId,
            'input_summary' => $old['program_code'] . ' | ' . $focus,
            'context_hash'  => hash('sha256', $contextJson),
            'messages'      => [
                [
                    'role' => 'system',
                    'content' => 'You are an admissions screening assistant for ITC, a technical/vocational college in Zambia. '
                               . 'You help an admissions officer assess applicants fairly and consistently. '
                               . 'Use ONLY the supplied JSON. Your output is advisory; a human officer makes the final decision. '
                               . 'Do not rank the applicant, decide eligibility, or recommend admit/reject/waitlist outcomes. '
                               . 'Never discriminate on gender, age, religion, origin, or disability. '
                               . 'If key information is missing, say what to request rather than assuming. Be concise and structured.',
                ],
                [
                    'role' => 'user',
                    'content' => "Applicant screening data (JSON):\n{$contextJson}\n\nTask: {$focusInstruction}",
                ],
            ],
            'fallback' => static function () use ($context): string {
                return ai_appl_fallback($context);
            },
        ]);
    }
}

// ===== Rule layer (Sprint 5): deterministic screening BEFORE any LLM call =====
// Runs whenever an applicant is selected or qualifications were submitted, and
// works identically whether Ollama/cloud AI is up or not.
$rulesBlockHtml = '';
try {
    require_once dirname(__DIR__) . '/includes/admissions_rules_engine.php';
    $rulesSource = $applicant;
    if ($rulesSource === null && $old['qualifications'] !== '') {
        $rulesSource = ['results' => $old['qualifications'], 'program_code' => $old['program_code'], 'status' => 'pending'];
    }
    if ($rulesSource !== null) {
        $admChecklist = wuc_adm_document_checklist($rulesSource);
        $admCompleteness = wuc_adm_completeness($admChecklist);
        $admFit = wuc_adm_program_fit($db, (string)($rulesSource['results'] ?? $old['qualifications']));
        $admStatusText = wuc_adm_status_explanation((string)($rulesSource['status'] ?? 'pending'));
        $admNextSteps = wuc_adm_next_steps($admCompleteness, (string)($rulesSource['status'] ?? 'pending'));
        $rulesBlockHtml = wuc_adm_render_rules_block($admChecklist, $admCompleteness, $admFit, $admStatusText, $admNextSteps);
    }
} catch (Throwable $e) {
    error_log('ai_applicant_assistant rules layer failed: ' . $e->getMessage());
}

require "includes/nav.php";
?>

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h3 class="mb-1"><i class="fas fa-user-check me-2" style="color:#2E3190;"></i>AI Applicant Assistant</h3>
            <p class="text-muted mb-0">Review qualifications against program information and prepare a factual verification note.</p>
        </div>
        <span class="badge <?php echo $aiStatus['model_ready'] ? 'bg-success' : 'bg-secondary'; ?> fs-6">
            <i class="fas fa-robot me-1"></i><?php echo $aiStatus['model_ready'] ? 'AI online' : 'Fallback mode'; ?>
        </span>
    </div>

    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="fas fa-circle-info mt-1"></i>
        <div class="small">
            This tool organizes academic evidence and missing information; it does not rank applicants or recommend
            admission outcomes. The final assessment remains with the admissions officer. Only qualification and
            program data are sent to the AI — no NRC, names, sponsor, or contact details.
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach (array_unique($errors) as $e): ?>
                <div><?php echo ai_appl_h($e); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong><i class="fas fa-clipboard-list me-2"></i>Applicant details</strong></div>
                <div class="card-body">
                    <form method="post" action="ai_applicant_assistant.php">
                        <input type="hidden" name="csrf_token" value="<?php echo ai_appl_h($_SESSION['csrf_token'] ?? ''); ?>">

                        <div class="mb-3">
                            <label class="form-label" for="applicant_id">Processed applicant (optional)</label>
                            <select class="form-select" id="applicant_id" name="applicant_id">
                                <option value="0">— Manual entry / no applicant selected —</option>
                                <?php foreach ($recent as $a): ?>
                                    <option value="<?php echo (int)$a['id']; ?>" <?php echo $old['applicant_id'] === (int)$a['id'] ? 'selected' : ''; ?>>
                                        <?php echo ai_appl_h(trim($a['Fname'] . ' ' . $a['Lname']) . ' — ' . ($a['program'] ?: $a['program_code']) . ' (' . $a['status'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <?php echo $recent ? 'Selecting an applicant uses their stored results & program.' : 'No processed applicants yet — use manual entry below.'; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="program_code">Program applied for</label>
                            <select class="form-select" id="program_code" name="program_code"<?php echo $disableProgramSelect ? ' disabled' : ''; ?>>
                                <option value="">— Select program —</option>
                                <?php foreach ($programs as $code => $p): ?>
                                    <option value="<?php echo ai_appl_h($code); ?>" <?php echo $old['program_code'] === $code ? 'selected' : ''; ?>>
                                        <?php echo ai_appl_h($p['program_name'] . ' (' . $p['program_type'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="program-lock-warning" class="form-text text-danger mt-1" style="<?php echo $disableProgramSelect ? 'display: block;' : 'display: none;'; ?>">
                                <i class="fas fa-lock me-1"></i> Program is locked because the applicant already selected this program.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="qualifications">Qualifications / results</label>
                            <textarea class="form-control" id="qualifications" name="qualifications" rows="7" maxlength="4000"
                                placeholder="e.g. Grade 12 Certificate: English C, Mathematics D, Science C, ... or prior diplomas/experience."><?php echo ai_appl_h($old['qualifications']); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="focus">Assessment focus</label>
                            <select class="form-select" id="focus" name="focus">
                                <?php foreach (['overall' => 'Evidence summary', 'eligibility' => 'Requirements mapping', 'decision_note' => 'Draft review note'] as $v => $label): ?>
                                    <option value="<?php echo $v; ?>" <?php echo $old['focus'] === $v ? 'selected' : ''; ?>><?php echo ai_appl_h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button class="btn btn-primary w-100" type="submit" style="background:#2E3190;border-color:#2E3190;">
                            <i class="fas fa-wand-magic-sparkles me-2"></i>Review evidence
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><i class="fas fa-clipboard-check me-2"></i>Assessment</strong>
                    <?php if ($result !== null): ?>
                        <small class="text-muted">
                            <?php echo $result['used_ai'] ? ai_appl_h($result['model']) . ' · ' . number_format($result['duration_ms'] / 1000, 1) . 's' : 'Fallback'; ?>
                        </small>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($rulesBlockHtml !== ''): ?>
                        <?php echo $rulesBlockHtml; ?>
                    <?php endif; ?>
                    <?php if ($result === null): ?>
                        <?php if ($rulesBlockHtml === ''): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-user-check fa-3x mb-3 opacity-25"></i>
                            <p class="mb-1 fw-semibold">Screening assistant</p>
                            <p class="small mb-0">Pick an applicant or enter qualifications, choose a focus, and click <em>Assess</em>.</p>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert <?php echo $result['used_ai'] ? 'alert-info' : 'alert-warning'; ?> small py-2">
                            <?php echo $result['used_ai']
                                ? '<i class="fas fa-robot me-1"></i>AI-generated advisory assessment. Verify and decide manually.'
                                : ai_appl_h(wuc_ai_fallback_notice($result)); ?>
                        </div>
                        <div class="border rounded p-3 bg-light"><?php echo wuc_ai_output_block($result['text']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const applicantSelect = document.getElementById('applicant_id');
    const programSelect = document.getElementById('program_code');
    const qualificationsTextarea = document.getElementById('qualifications');
    const lockWarning = document.getElementById('program-lock-warning');

    function updateLockState(isLocked) {
        if (isLocked) {
            programSelect.disabled = true;
            if (lockWarning) lockWarning.style.display = 'block';
        } else {
            programSelect.disabled = false;
            if (lockWarning) lockWarning.style.display = 'none';
        }
    }

    applicantSelect.addEventListener('change', function() {
        const applicantId = parseInt(this.value, 10);
        
        if (applicantId <= 0) {
            qualificationsTextarea.value = '';
            programSelect.value = '';
            updateLockState(false);
            return;
        }

        applicantSelect.disabled = true;
        
        fetch('ai_applicant_assistant.php?ajax_get_applicant=1&id=' + applicantId)
            .then(response => response.json())
            .then(data => {
                applicantSelect.disabled = false;
                if (data) {
                    qualificationsTextarea.value = data.results || '';
                    
                    const progCode = (data.program_code || '').trim();
                    if (progCode !== '') {
                        programSelect.value = progCode;
                        updateLockState(true);
                    } else {
                        programSelect.value = '';
                        updateLockState(false);
                    }
                } else {
                    updateLockState(false);
                }
            })
            .catch(error => {
                applicantSelect.disabled = false;
                console.error('Error fetching applicant data:', error);
                updateLockState(false);
            });
    });

    const form = applicantSelect.closest('form');
    if (form) {
        form.addEventListener('submit', function() {
            programSelect.disabled = false;
        });
    }
});
</script>

<?php require "includes/footer.php"; ?>
