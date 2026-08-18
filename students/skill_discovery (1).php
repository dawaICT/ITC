<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';
require_once dirname(__DIR__) . '/ai/match.php';
require_once __DIR__ . '/includes/StudentSkillDiscoveryService.php';

const SKILL_MAX_EXPERIENCES = 5;
const SKILL_MAX_ENTRY_LENGTH = 1200;

$studentId = trim((string)($_SESSION['Sid'] ?? ''));
$skillService = new StudentSkillDiscoveryService($db);
$studentProfile = $skillService->buildStudentProfile($studentId);
$academicSkills = $studentProfile['valid'] ? $skillService->discoverAcademicSkills($studentId) : [];
$experienceResults = null;

$experienceEntries = [];
$seenExperienceKeys = [];
if (isset($_POST['experiences']) && is_array($_POST['experiences'])) {
    foreach ($_POST['experiences'] as $experienceInput) {
        $experienceInput = trim((string)$experienceInput);
        if ($experienceInput === '') {
            continue;
        }
        $experienceKey = function_exists('mb_strtolower')
            ? mb_strtolower($experienceInput, 'UTF-8')
            : strtolower($experienceInput);
        if (isset($seenExperienceKeys[$experienceKey])) {
            continue;
        }
        $seenExperienceKeys[$experienceKey] = true;
        $experienceEntries[] = $experienceInput;
    }
} elseif (isset($_POST['experience'])) {
    $legacyExperience = trim((string)$_POST['experience']);
    if ($legacyExperience !== '') {
        $experienceEntries[] = $legacyExperience;
    }
}

$query = implode("\n\n", $experienceEntries);
$formExperiences = $experienceEntries;
while (count($formExperiences) < 2) {
    $formExperiences[] = '';
}
$error = null;
$matchModeUsed = null;
$skillCount = 0;
$embeddedCount = 0;
$currentModelEmbeddedCount = 0;
$otherModelEmbeddedCount = 0;
$missingEmbeddingCount = 0;

if (!function_exists('student_skill_text_length')) {
    function student_skill_text_length(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

$taxonomyTableExists = false;
if (isset($db) && $db instanceof mysqli) {
    if ($tableCheck = $db->query("SHOW TABLES LIKE 'ai_skill_taxonomy'")) {
        $taxonomyTableExists = $tableCheck->num_rows > 0;
        $tableCheck->free();
    }
}

if ($taxonomyTableExists) {
    $indexSql = "
        SELECT
            COUNT(*) AS total,
            SUM(embedding IS NOT NULL) AS embedded,
            SUM(embedding IS NOT NULL AND (embed_model = ? OR embed_model = ?)) AS current_model_embedded,
            SUM(embedding IS NOT NULL AND embed_model IS NOT NULL AND embed_model NOT IN (?, ?)) AS other_model_embedded,
            SUM(embedding IS NULL) AS missing_embedding
        FROM ai_skill_taxonomy
    ";
    if ($countStmt = $db->prepare($indexSql)) {
        $indexModel = AI_EMBED_MODEL;
        $latestModel = strpos(AI_EMBED_MODEL, ':') === false ? AI_EMBED_MODEL . ':latest' : AI_EMBED_MODEL;
        $countStmt->bind_param('ssss', $indexModel, $latestModel, $indexModel, $latestModel);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $countRow = $countResult->fetch_assoc();
        $skillCount = (int)($countRow['total'] ?? 0);
        $embeddedCount = (int)($countRow['embedded'] ?? 0);
        $currentModelEmbeddedCount = (int)($countRow['current_model_embedded'] ?? 0);
        $otherModelEmbeddedCount = (int)($countRow['other_model_embedded'] ?? 0);
        $missingEmbeddingCount = (int)($countRow['missing_embedding'] ?? 0);
        $countResult->free();
        $countStmt->close();
    }
}

$ollamaUp = ollama_available();
$embeddingModelReady = $ollamaUp && ollama_model_available(AI_EMBED_MODEL);
$semanticMatchReady = $embeddingModelReady && $currentModelEmbeddedCount > 0;
$lexicalMatchReady = $taxonomyTableExists && $skillCount > 0;
$canDiscover = $lexicalMatchReady;
$aiReady = $semanticMatchReady;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shortExperienceFound = false;
    $longExperienceFound = false;
    foreach ($experienceEntries as $experienceEntry) {
        $entryLength = student_skill_text_length($experienceEntry);
        if ($entryLength < 12) {
            $shortExperienceFound = true;
        }
        if ($entryLength > SKILL_MAX_ENTRY_LENGTH) {
            $longExperienceFound = true;
        }
    }

    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
        $error = 'Your session has changed since this page was opened. Please try again.';
    } elseif (count($experienceEntries) < 2) {
        $error = 'Enter at least two separate experiences before discovering skills.';
    } elseif (count($experienceEntries) > SKILL_MAX_EXPERIENCES) {
        $error = 'You can submit up to ' . SKILL_MAX_EXPERIENCES . ' experience entries at a time.';
    } elseif ($shortExperienceFound) {
        $error = 'Each experience needs a little more detail so the matcher has enough context.';
    } elseif ($longExperienceFound) {
        $error = 'Keep each experience under ' . SKILL_MAX_ENTRY_LENGTH . ' characters.';
    } elseif (student_skill_text_length($query) > 4000) {
        $error = 'Keep the combined experience descriptions under 4000 characters.';
    } elseif (!$taxonomyTableExists || $skillCount === 0) {
        $error = 'The skill taxonomy has not been set up yet. Please contact ICT.';
    } elseif (!$canDiscover) {
        $error = 'Skill matching is not available yet. Please contact ICT.';
    } else {
        $rate = function_exists('wuc_ai_rate_limit')
            ? wuc_ai_rate_limit('student_skill_discovery', 12, 3600)
            : ['ok' => true];
        if (empty($rate['ok'])) {
            $error = 'Too many skill discovery requests. Please try again in '
                . max(1, (int)ceil(((int)($rate['retry_after'] ?? 3600)) / 60))
                . ' minutes.';
        } else {
            try {
                // Each entry triggers up to 10 CPU embedding calls (~1.5s each), so a
                // full 5-entry submit can approach the default execution limit.
                set_time_limit(300);
                $rawResults = ai_match_skills_from_experiences($experienceEntries, 8, 2);
                $matchModeUsed = ai_match_last_mode();
                $experienceResults = $skillService->enrichExperienceMatches($rawResults, $experienceEntries, $studentId);
            } catch (Throwable $e) {
                error_log('skill_discovery.php: ' . $e->getMessage());
                if ($lexicalMatchReady) {
                    try {
                        $rawResults = ai_match_skills_from_experiences($experienceEntries, 8, 2, true);
                        $matchModeUsed = 'lexical';
                        $experienceResults = $skillService->enrichExperienceMatches($rawResults, $experienceEntries, $studentId);
                    } catch (Throwable $fallbackError) {
                        error_log('skill_discovery.php lexical fallback: ' . $fallbackError->getMessage());
                        $error = 'Skill matching is temporarily unavailable. Please try again later.';
                    }
                } else {
                    $error = 'Skill matching is temporarily unavailable. Please try again later.';
                }
            }
        }
    }
}

$examples = [
    'I sell vegetables at the market, serve customers, handle mobile money payments, and keep records of daily sales.',
    'I help organise community events, speak to visitors, schedule volunteers, and solve problems on the day.',
    'I repair phones and computers, explain technical issues to customers, and keep track of spare parts.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Skill Discovery - ITC</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <style>
        .skill-page {
            max-width: 1100px;
            margin: 0 auto;
        }
        .skill-hero {
            background: #ffffff;
            border: 1px solid rgba(111, 66, 193, .14);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 10px 28px rgba(17, 24, 39, .06);
        }
        .skill-hero h1 {
            color: #59359a;
            font-size: 1.65rem;
            margin: 0;
        }
        .skill-hero p {
            color: #64748b;
            margin: .4rem 0 0;
            max-width: 720px;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            border-radius: 999px;
            padding: .35rem .7rem;
            font-size: .82rem;
            font-weight: 700;
        }
        .status-pill.ready {
            background: #e8f7ee;
            color: #177245;
        }
        .status-pill.down {
            background: #fde8e8;
            color: #b42318;
        }
        .status-pill.degraded {
            background: #fff4e5;
            color: #b54708;
        }
        .skill-card {
            background: #ffffff;
            border: 1px solid #e8e2f4;
            border-radius: 8px;
            box-shadow: 0 8px 22px rgba(17, 24, 39, .05);
        }
        .skill-card .card-header {
            background: #6f42c1;
            color: #ffffff;
            border-radius: 8px 8px 0 0;
            padding: 1rem 1.25rem;
            font-weight: 700;
        }
        .example-chip {
            border: 1px solid #ddd3f1;
            background: #f7f3ff;
            color: #4b2e83;
            border-radius: 999px;
            padding: .35rem .7rem;
            font-size: .82rem;
            margin: .2rem .25rem .2rem 0;
            max-width: 100%;
            white-space: normal;
            text-align: left;
        }
        .skill-detail-card {
            border: 1px solid #e8e2f4;
            border-radius: 8px;
            padding: 1rem 1.1rem;
            margin-bottom: .85rem;
            background: #fcfbff;
        }
        .skill-detail-card:last-child {
            margin-bottom: 0;
        }
        .skill-detail-head {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: flex-start;
        }
        .skill-detail-title {
            font-size: 1rem;
            font-weight: 700;
            color: #101828;
            margin: 0 0 .35rem;
        }
        .skill-badge {
            font-size: .72rem;
            font-weight: 700;
            border-radius: 999px;
            padding: .2rem .55rem;
            margin-right: .25rem;
            margin-bottom: .25rem;
            display: inline-block;
        }
        .skill-badge.level-beginner { background: #eef2ff; color: #4338ca; }
        .skill-badge.level-intermediate { background: #e8f7ee; color: #177245; }
        .skill-badge.level-advanced { background: #fff4e5; color: #b54708; }
        .skill-badge.source { background: #f2f4f7; color: #475467; }
        .skill-badge.inferred { background: #fef3f2; color: #b42318; }
        .skill-detail-body {
            margin-top: .85rem;
            padding-top: .85rem;
            border-top: 1px solid #edf0f5;
        }
        .skill-detail-body h4 {
            font-size: .82rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #667085;
            margin: 0 0 .35rem;
        }
        .skill-detail-body p,
        .skill-detail-body li {
            font-size: .92rem;
            color: #344054;
        }
        .skill-detail-body ul {
            margin-bottom: .75rem;
            padding-left: 1.1rem;
        }
        .profile-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border-radius: 999px;
            background: #f7f3ff;
            color: #4b2e83;
            padding: .3rem .65rem;
            font-size: .82rem;
            font-weight: 600;
            margin: .15rem .25rem .15rem 0;
        }
        .skill-empty-state i {
            color: #b9a6e4;
        }
        @media (max-width: 700px) {
            .skill-detail-head {
                flex-direction: column;
            }
        }
    </style>
</head>
<body class="bg-light student-portal">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="content-wrapper pt-3 pb-5">
  <div class="skill-page">
    <section class="skill-hero">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
                <span class="text-uppercase small fw-bold text-muted">Student Services</span>
                <h1>Skill Discovery</h1>
                <p>Review skills linked to your programme, courses, and assessments, then discover additional strengths from work, volunteering, or projects you describe below.</p>
                <?php if (!empty($studentProfile['valid'])): ?>
                    <div class="mt-2">
                        <?php if (!empty($studentProfile['name'])): ?>
                            <span class="profile-chip"><i class="fas fa-user"></i><?= ssd_h($studentProfile['name']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($studentProfile['program_name']) || !empty($studentProfile['program_code'])): ?>
                            <span class="profile-chip"><i class="fas fa-graduation-cap"></i><?= ssd_h($studentProfile['program_name'] ?: $studentProfile['program_code']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($studentProfile['year_of_study'])): ?>
                            <span class="profile-chip"><i class="fas fa-layer-group"></i>Year <?= ssd_h((string)$studentProfile['year_of_study']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-column align-items-lg-end gap-2">
                <?php
                    $statusClass = $aiReady ? 'ready' : ($canDiscover ? 'degraded' : 'down');
                    $statusIcon = $aiReady ? 'fa-circle-check' : ($canDiscover ? 'fa-key' : 'fa-triangle-exclamation');
                    $statusText = $aiReady ? 'AI ready' : ($canDiscover ? 'Keyword mode' : ($ollamaUp ? 'AI setup needed' : 'AI offline'));
                ?>
                <span class="status-pill <?= $statusClass ?>">
                    <i class="fas <?= $statusIcon ?>"></i>
                    <?= htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <small class="text-muted">
                    <?= htmlspecialchars((string)$currentModelEmbeddedCount) ?> of <?= htmlspecialchars((string)$skillCount) ?>
                    skills indexed for <?= htmlspecialchars(AI_EMBED_MODEL, ENT_QUOTES, 'UTF-8') ?>
                </small>
                <?php if ($canDiscover && !$aiReady): ?>
                    <small class="text-muted">
                        Local AI is unavailable, so matches use keyword evidence only.
                        <?php if (!$ollamaUp): ?>
                            Start Ollama and run <code>ollama pull <?= htmlspecialchars(AI_EMBED_MODEL, ENT_QUOTES, 'UTF-8') ?></code> for stronger results.
                        <?php endif; ?>
                    </small>
                <?php elseif ($missingEmbeddingCount > 0 || $otherModelEmbeddedCount > 0): ?>
                    <small class="text-muted">
                        <?= htmlspecialchars((string)$missingEmbeddingCount) ?> missing,
                        <?= htmlspecialchars((string)$otherModelEmbeddedCount) ?> indexed with another model
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="skill-card mb-3">
        <div class="card-header">
            <i class="fas fa-id-card-clip me-2"></i>Your skills from the portal
        </div>
        <div class="card-body p-4">
            <?php if (empty($studentProfile['valid'])): ?>
                <div class="alert alert-warning mb-0">Your session could not be verified. Please log in again.</div>
            <?php elseif (!$academicSkills): ?>
                <div class="skill-empty-state text-center text-muted py-3">
                    <i class="fas fa-inbox fa-2x mb-3 d-block"></i>
                    <p class="mb-2">No skill records have been found yet. Skills can be discovered from your registered courses, completed assessments, uploaded work, and programme outcomes.</p>
                    <?php if (!empty($studentProfile['program_name']) || !empty($studentProfile['program_code'])): ?>
                        <p class="mb-0 small">Use the experience form below, or register for courses on <?= ssd_h($studentProfile['program_name'] ?: $studentProfile['program_code']) ?> to build your skill profile.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="text-muted small mb-3">Based on your programme, registered courses, and assessment results. Skills marked <span class="skill-badge inferred">Inferred</span> are suggested from course titles and programme context, not a separate skills record.</p>
                <?php foreach ($academicSkills as $index => $skill): ?>
                    <?php ssd_render_skill_card($skill, 'academic-skill-' . $index, $index === 0); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <div class="row g-3">
        <div class="col-lg-5">
            <section class="skill-card">
                <div class="card-header">
                    <i class="fas fa-wand-magic-sparkles me-2"></i>Describe experience
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="post" action="skill_discovery.php" id="skill-experience-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <label class="form-label fw-semibold">Experience entries</label>
                        <div id="experience-list">
                            <?php foreach ($formExperiences as $index => $experienceValue): ?>
                                <div class="experience-entry mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="small fw-semibold text-muted">Experience <?= htmlspecialchars((string)($index + 1)) ?></span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary remove-experience" <?= count($formExperiences) <= 2 ? 'disabled' : '' ?>>Remove</button>
                                    </div>
                                    <textarea class="form-control experience-text" name="experiences[]" rows="4" maxlength="1200" <?= $index < 2 ? 'required' : '' ?>><?= htmlspecialchars($experienceValue, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Add at least two separate activities, jobs, projects, or responsibilities. Skills are matched after the entries are reviewed together.</div>
                        <button type="button" class="btn btn-outline-primary btn-sm mt-3" id="add-experience">
                            <i class="fas fa-plus me-2"></i>Add another experience
                        </button>
                        <button type="submit" class="btn btn-primary mt-3" id="discover-skills" <?= $canDiscover ? '' : 'disabled' ?>>
                            <i class="fas fa-magnifying-glass me-2"></i>Discover skills
                        </button>
                        <div class="text-muted small mt-2 d-none" id="matching-status">
                            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            Matching skills&hellip; this runs on the local AI and can take up to a minute.
                        </div>
                    </form>

                    <div class="mt-4">
                        <div class="small text-muted fw-semibold mb-2">Examples</div>
                        <?php foreach ($examples as $example): ?>
                            <button type="button" class="example-chip" data-example="<?= htmlspecialchars($example, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($example, ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-7">
            <section class="skill-card">
                <div class="card-header">
                    <i class="fas fa-list-check me-2"></i>Skills from your experience
                </div>
                <div class="card-body p-4">
                    <?php if ($matchModeUsed === 'lexical' && $experienceResults !== null): ?>
                        <div class="alert alert-warning py-2">
                            Results use keyword matching
                            <?= $semanticMatchReady ? 'because the embedding service could not run.' : 'because the local AI embedding service is offline.' ?>
                        </div>
                    <?php elseif ($matchModeUsed === 'semantic' && $experienceResults !== null): ?>
                        <div class="alert alert-success py-2">
                            Results use local AI semantic matching (<?= htmlspecialchars(AI_EMBED_MODEL, ENT_QUOTES, 'UTF-8') ?>).
                        </div>
                    <?php endif; ?>
                    <?php if ($experienceResults === null): ?>
                        <div class="skill-empty-state text-center text-muted py-4">
                            <i class="fas fa-lightbulb fa-2x mb-3 d-block"></i>
                            <p class="mb-0">Submit at least two experience entries to discover additional skills with explanations and guidance.</p>
                        </div>
                    <?php elseif (!$experienceResults): ?>
                        <div class="skill-empty-state text-center text-muted py-4">
                            <i class="fas fa-magnifying-glass-minus fa-2x mb-3 d-block"></i>
                            <p class="mb-0">No matching skills were found. Try describing what you did in more everyday words.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($experienceResults as $index => $skill): ?>
                            <?php ssd_render_skill_card($skill, 'experience-skill-' . $index, $index === 0); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var list = document.getElementById('experience-list');
    var addButton = document.getElementById('add-experience');
    var form = document.getElementById('skill-experience-form');
    var submitButton = document.getElementById('discover-skills');
    var matchingStatus = document.getElementById('matching-status');
    var maxExperiences = 5;

    if (form && submitButton) {
        var canSubmit = <?= $canDiscover ? 'true' : 'false' ?>;
        form.addEventListener('submit', function () {
            if (!canSubmit) {
                return;
            }
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Matching…';
            if (matchingStatus) {
                matchingStatus.classList.remove('d-none');
            }
        });
    }

    // Restore the button if the user navigates back to a bfcache copy of the page.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted && submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = '<i class="fas fa-magnifying-glass me-2"></i>Discover skills';
            if (matchingStatus) {
                matchingStatus.classList.add('d-none');
            }
        }
    });

    function refreshEntries() {
        var entries = list.querySelectorAll('.experience-entry');
        entries.forEach(function (entry, index) {
            var label = entry.querySelector('.small.fw-semibold');
            var removeButton = entry.querySelector('.remove-experience');
            var textarea = entry.querySelector('textarea');

            if (label) {
                label.textContent = 'Experience ' + (index + 1);
            }
            if (removeButton) {
                removeButton.disabled = entries.length <= 2;
            }
            if (textarea) {
                textarea.required = index < 2;
            }
        });

        if (addButton) {
            addButton.disabled = entries.length >= maxExperiences;
        }
    }

    function createExperience(value) {
        var entry = document.createElement('div');
        entry.className = 'experience-entry mb-3';
        entry.innerHTML = [
            '<div class="d-flex justify-content-between align-items-center mb-1">',
            '<span class="small fw-semibold text-muted">Experience</span>',
            '<button type="button" class="btn btn-sm btn-outline-secondary remove-experience">Remove</button>',
            '</div>',
            '<textarea class="form-control experience-text" name="experiences[]" rows="4" maxlength="1200"></textarea>'
        ].join('');
        entry.querySelector('textarea').value = value || '';
        list.appendChild(entry);
        refreshEntries();
        return entry;
    }

    if (addButton) {
        addButton.addEventListener('click', function () {
            var entry = createExperience('');
            entry.querySelector('textarea').focus();
        });
    }

    list.addEventListener('click', function (event) {
        var button = event.target.closest('.remove-experience');
        if (!button || button.disabled) {
            return;
        }
        button.closest('.experience-entry').remove();
        refreshEntries();
    });

    document.querySelectorAll('[data-example]').forEach(function (button) {
        button.addEventListener('click', function () {
            var example = button.getAttribute('data-example') || '';
            var target = Array.prototype.find.call(
                list.querySelectorAll('textarea'),
                function (textarea) {
                    return textarea.value.trim() === '';
                }
            );

            if (!target && list.querySelectorAll('.experience-entry').length < maxExperiences) {
                target = createExperience('').querySelector('textarea');
            }

            if (target) {
                target.value = example;
                target.focus();
            }
        });
    });
    refreshEntries();
});
</script>
</body>
</html>
