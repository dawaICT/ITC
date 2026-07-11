<?php
declare(strict_types=1);

/**
 * AI Curriculum Analyzer (admin)
 * ------------------------------------------------------------------
 * Runs a deterministic curriculum-health audit of the programme catalogue
 * (empty programmes, year gaps, duplicate names, identical module sets,
 * enrolled-without-fees, diploma/craft level mismatches) and — on request —
 * layers an AI briefing that prioritises the findings into an action plan.
 *
 * The deterministic audit always renders (no AI needed); AI is opt-in per POST,
 * rate-limited, and degrades to the deterministic fallback when Ollama/cloud is
 * offline. Follows the admin/ai_reports.php conventions.
 */

$page_title = 'AI Curriculum Analyzer';
require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/portal_access.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';
require_once dirname(__DIR__) . '/includes/curriculum_analyzer.php';

wuc_require_portal_access($db, 'academic');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function cur_ai_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Programme list for the focus dropdown (also used to validate POST input).
$programOptions = [];
if ($res = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_name")) {
    while ($row = $res->fetch_assoc()) {
        $programOptions[$row['program_code']] = $row['program_name'];
    }
    $res->free();
}

$errors = [];
$aiStatus = wuc_ai_local_status();

// Focus programme (from GET or POST) — validated against the real list.
$focus = trim((string)($_REQUEST['program'] ?? ''));
if ($focus !== '' && !isset($programOptions[$focus])) {
    $focus = '';
}
$focusCode = $focus !== '' ? $focus : null;

// Deterministic audit always runs — it is fast and needs no AI.
$analysis = wuc_curriculum_analyze($db, $focusCode);

// AI briefing is opt-in (POST "generate").
$briefing = null;
$wantAi = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && (($_POST['action'] ?? '') === 'generate');
if ($wantAi) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $errors[] = 'Your request could not be verified. Refresh and try again.';
    }
    if (!$errors) {
        $rate = wuc_ai_rate_limit('admin_ai_curriculum', 8, 600);
        if (!$rate['ok']) {
            $errors[] = 'Too many AI briefings requested. Please try again in about '
                . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.';
        }
    }
    if (!$errors) {
        // Compact the analysis for the model: summary + only programmes with issues.
        $problems = array_values(array_filter(
            $analysis['programs'],
            static fn($p) => $p['issues'] !== []
        ));
        $context = [
            'scope' => $analysis['scope'],
            'generated_for' => $analysis['generated_for'],
            'summary' => $analysis['summary'],
            'programmes_with_findings' => array_map(static function ($p) {
                return [
                    'code' => $p['code'],
                    'name' => $p['name'],
                    'type' => $p['type'],
                    'health_score' => $p['health_score'],
                    'course_count' => $p['course_count'],
                    'enrolled' => $p['enrolled'],
                    'issues' => $p['issues'],
                ];
            }, array_slice($problems, 0, 20)),
            'rules' => [
                'use_only_supplied_audit' => true,
                'do_not_invent_programmes_or_courses' => true,
                'prioritise_actions' => true,
            ],
        ];
        $contextJson = wuc_ai_context_json($context, 18000);

        $briefing = wuc_ai_generate($db, [
            'feature' => 'admin_ai_curriculum',
            'user_role' => 'admin',
            'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'admin'),
            'input_summary' => 'curriculum audit ' . $analysis['generated_for']
                . ' | crit=' . $analysis['summary']['critical_findings']
                . ' warn=' . $analysis['summary']['warning_findings'],
            'context_hash' => hash('sha256', $contextJson),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are the ITC Portal curriculum quality assistant for an academic registrar. '
                        . 'You receive a deterministic curriculum audit already computed from the live database. '
                        . 'Summarise the programme portfolio\'s curriculum health, then give a short, prioritised action '
                        . 'plan (most urgent first). Explain the practical trade-offs of duplicate-name and identical-module-set '
                        . 'findings (they usually mean two programme codes represent the same offering, or a variant that should '
                        . 'have its own modules). Never invent programmes, courses, or numbers not present in the audit. '
                        . 'Keep it concise and use Markdown headings, bold, and bullet lists.',
                ],
                [
                    'role' => 'user',
                    'content' => "Curriculum audit JSON:\n{$contextJson}\n\n"
                        . 'Give a curriculum-health briefing and a prioritised action plan.',
                ],
            ],
            'fallback' => static function () use ($analysis): string {
                return wuc_curriculum_fallback($analysis);
            },
        ]);
    }
}

// Health-score → badge colour helper.
function cur_ai_health_class(int $score): string
{
    if ($score >= 90) return 'bg-success';
    if ($score >= 70) return 'bg-warning text-dark';
    return 'bg-danger';
}
function cur_ai_sev_class(string $sev): string
{
    return match ($sev) {
        'critical' => 'text-danger',
        'warning'  => 'text-warning',
        default    => 'text-muted',
    };
}

$summary = $analysis['summary'];
require __DIR__ . '/includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard admin-ai-curriculum-page">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title">AI Curriculum Analyzer</h1>
                <p class="text-muted mb-0">Automated curriculum-health audit of the programme catalogue, with an AI action plan.</p>
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
                <div><?php echo cur_ai_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Summary stat row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="data-table-card h-100 p-3 text-center">
                <div class="h2 mb-0"><?php echo (int)$summary['programs_analysed']; ?></div>
                <div class="text-muted small">Programmes analysed</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="data-table-card h-100 p-3 text-center">
                <div class="h2 mb-0 text-danger"><?php echo (int)$summary['critical_findings']; ?></div>
                <div class="text-muted small">Critical findings</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="data-table-card h-100 p-3 text-center">
                <div class="h2 mb-0 text-warning"><?php echo (int)$summary['warning_findings']; ?></div>
                <div class="text-muted small">Warnings</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="data-table-card h-100 p-3 text-center">
                <div class="h2 mb-0"><?php echo count($summary['programs_without_courses']); ?></div>
                <div class="text-muted small">Programmes without courses</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Controls + AI briefing -->
        <div class="col-xl-5">
            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-sliders me-2"></i>Scope &amp; AI briefing</h5></div>
                <div class="card-body">
                    <form method="post" action="ai_curriculum_analyzer.php">
                        <input type="hidden" name="csrf_token" value="<?php echo cur_ai_h($_SESSION['csrf_token'] ?? ''); ?>">
                        <input type="hidden" name="action" value="generate">
                        <label class="form-label" for="program">Focus programme (optional)</label>
                        <select class="form-select" id="program" name="program">
                            <option value="">All programmes (portfolio audit)</option>
                            <?php foreach ($programOptions as $code => $name): ?>
                                <option value="<?php echo cur_ai_h($code); ?>" <?php echo $focus === $code ? 'selected' : ''; ?>>
                                    <?php echo cur_ai_h($code . ' — ' . $name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">The audit below always runs. Click below to add an AI-prioritised action plan.</div>
                        <button class="btn btn-primary mt-3" type="submit">
                            <i class="fas fa-wand-magic-sparkles me-2"></i>Generate AI briefing
                        </button>
                    </form>
                </div>
            </section>

            <?php if ($briefing !== null): ?>
                <section class="data-table-card">
                    <div class="card-header"><h5 class="mb-0"><i class="fas fa-robot me-2"></i>AI action plan</h5></div>
                    <div class="card-body">
                        <div class="alert <?php echo $briefing['used_ai'] ? 'alert-info' : 'alert-warning'; ?> small">
                            <?php
                            echo $briefing['used_ai']
                                ? 'Generated by ' . cur_ai_h((string)($briefing['provider'] ?? 'AI')) . ' model ' . cur_ai_h((string)$briefing['model']) . '.'
                                : cur_ai_h(wuc_ai_fallback_notice($briefing));
                            ?>
                        </div>
                        <div class="bg-light border rounded p-3"><?php echo wuc_ai_output_block($briefing['text']); ?></div>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <!-- Deterministic audit table -->
        <div class="col-xl-7">
            <section class="data-table-card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Curriculum audit</h5>
                    <span class="text-muted small">worst health first</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($summary['duplicate_name_groups']) || !empty($summary['identical_curriculum_groups'])): ?>
                        <div class="alert alert-secondary small mb-3">
                            <?php if (!empty($summary['duplicate_name_groups'])): ?>
                                <div><strong>Duplicate names:</strong>
                                    <?php
                                    $parts = array_map(static fn($g) => implode(' = ', $g), $summary['duplicate_name_groups']);
                                    echo cur_ai_h(implode('; ', $parts));
                                    ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($summary['identical_curriculum_groups'])): ?>
                                <div><strong>Shared module sets:</strong>
                                    <?php
                                    $parts = array_map(static fn($g) => implode(', ', $g), $summary['identical_curriculum_groups']);
                                    echo cur_ai_h(implode('; ', $parts));
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Programme</th>
                                    <th class="text-center">Courses</th>
                                    <th class="text-center">Enrolled</th>
                                    <th class="text-center">Health</th>
                                    <th>Findings</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analysis['programs'] as $p): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo cur_ai_h($p['code']); ?></div>
                                            <div class="small text-muted"><?php echo cur_ai_h($p['name']); ?></div>
                                            <div class="small text-muted"><?php echo cur_ai_h($p['type']); ?> · <?php echo cur_ai_h($p['department']); ?></div>
                                        </td>
                                        <td class="text-center">
                                            <?php echo (int)$p['course_count']; ?>
                                            <?php if (!empty($p['missing_years'])): ?>
                                                <div class="small text-warning">yr gap</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?php echo (int)$p['enrolled']; ?></td>
                                        <td class="text-center">
                                            <span class="badge <?php echo cur_ai_health_class((int)$p['health_score']); ?>"><?php echo (int)$p['health_score']; ?></span>
                                        </td>
                                        <td>
                                            <?php if ($p['issues'] === []): ?>
                                                <span class="text-success small"><i class="fas fa-check me-1"></i>OK</span>
                                            <?php else: ?>
                                                <ul class="list-unstyled mb-0 small">
                                                    <?php foreach ($p['issues'] as $i): ?>
                                                        <li class="<?php echo cur_ai_sev_class($i['severity']); ?>">
                                                            <i class="fas fa-circle-exclamation me-1"></i><?php echo cur_ai_h($i['message']); ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
