<?php
declare(strict_types=1);
/** Shared Skill Discovery markup. Requires skill_discovery_run.php variables. */
?>
<section class="skill-hero">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
        <div>
            <span class="text-uppercase small fw-bold text-muted"><?= htmlspecialchars((string)$skillDiscoveryEyebrow, ENT_QUOTES, 'UTF-8') ?></span>
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
                    <div class="alert alert-danger"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <form method="post" action="<?= htmlspecialchars((string)$skillDiscoveryFormAction, ENT_QUOTES, 'UTF-8') ?>" id="skill-experience-form">
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
