<?php
declare(strict_types=1);

$page_title = 'Dashboard';
$epGuardMode = 'member';
$activeNav = 'dashboard';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
$completion = ep_profile_completion($db, $epMembership, $epProfile);
$skills = $profileId > 0 ? ep_list_skills($db, $profileId) : [];
// Dashboard only renders 8 opportunity rows — fetch a bounded page, not the full set.
$opportunities = $profileId > 0 ? ep_list_opportunities_for_profile($db, $profileId, 8) : [];
$interests = $profileId > 0 ? ep_list_interests_for_profile($db, $profileId, 5) : [];
$outcomes = $profileId > 0 ? ep_list_outcomes_for_profile($db, $profileId, 5) : [];
$opportunityCounts = $profileId > 0 && function_exists('ep_count_opportunities_for_profile')
    ? ep_count_opportunities_for_profile($db, $profileId)
    : ['total' => count($opportunities), 'published' => 0, 'pending_review' => 0];

$careerPlacement = null;
$epStudentSid = trim((string)(ep_current_student_id() ?? ''));
if ($epStudentSid !== '') {
    $hasInternships = function_exists('wuc_table_exists')
        ? wuc_table_exists($db, 'employer_internships')
        : false;
    if (!$hasInternships && !function_exists('wuc_table_exists')) {
        $tableCheck = @$db->query("SHOW TABLES LIKE 'employer_internships'");
        $hasInternships = $tableCheck && $tableCheck->num_rows > 0;
        if ($tableCheck) {
            $tableCheck->free();
        }
    }
    if ($hasInternships && ($careerStmt = $db->prepare(
        'SELECT company_name, supervisor_name, status, start_date, end_date, feedback
         FROM employer_internships WHERE student_id = ? ORDER BY start_date DESC LIMIT 1'
    ))) {
        $careerStmt->bind_param('s', $epStudentSid);
        $careerStmt->execute();
        $careerPlacement = $careerStmt->get_result()->fetch_assoc() ?: null;
        $careerStmt->close();
    }
}

$stats = [
    'skills' => count($skills),
    'opportunities' => (int)$opportunityCounts['total'],
    'published' => (int)$opportunityCounts['published'],
    'pending_review' => (int)$opportunityCounts['pending_review'],
    'interests' => $profileId > 0
        ? (function_exists('ep_count_interests_for_profile')
            ? ep_count_interests_for_profile($db, $profileId)
            : count($interests))
        : 0,
];

require_once __DIR__ . '/includes/layout.php';
?>
<section class="stats-grid" aria-label="Enterprise summary">
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-screwdriver-wrench"></i></div>
        <div>
            <div class="stat-label">Skills</div>
            <div class="stat-value"><?= (int)$stats['skills'] ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-briefcase"></i></div>
        <div>
            <div class="stat-label">Opportunities</div>
            <div class="stat-value"><?= (int)$stats['opportunities'] ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-globe"></i></div>
        <div>
            <div class="stat-label">Published</div>
            <div class="stat-value"><?= (int)$stats['published'] ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon neutral"><i class="fas fa-handshake"></i></div>
        <div>
            <div class="stat-label">Expressions of interest</div>
            <div class="stat-value"><?= (int)$stats['interests'] ?></div>
        </div>
    </div>
</section>

<?php if ($completion['percent'] < 100): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Profile readiness: <strong><?= (int)$completion['percent'] ?>%</strong> — complete setup to improve visibility.</span>
        <a class="btn btn-sm btn-primary" href="/wucportal/enterprise/onboarding.php">Continue setup</a>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-3" aria-label="Quick links">
    <a class="btn btn-sm btn-outline-primary" href="/wucportal/opportunities/index.php" target="_blank" rel="noopener"><i class="fas fa-globe me-1"></i>Public Directory</a>
    <a class="btn btn-sm btn-outline-primary" href="/wucportal/enterprise/tools/skill_discovery.php"><i class="fas fa-wand-magic-sparkles me-1"></i>Skill Discovery</a>
    <a class="btn btn-sm btn-outline-primary" href="/wucportal/enterprise/tools/ai_assist.php"><i class="fas fa-wand-magic-sparkles me-1"></i>AI Writing Assist</a>
    <a class="btn btn-sm btn-outline-secondary" href="/wucportal/portal_selection.php"><i class="fas fa-table-columns me-1"></i>Switch Portal</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-12">
        <article class="card">
            <div class="card-hdr d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h2 class="h6 mb-0"><i class="fas fa-briefcase text-primary me-1"></i> Skills &amp; career</h2>
                <a class="badge bg-primary text-decoration-none" href="/wucportal/enterprise/tools/skill_discovery.php">Open Skill Discovery</a>
            </div>
            <div class="card-body">
                <p class="small ep-muted mb-2">Evidence-backed skills from your academic record plus experience-based discovery — use this to strengthen your enterprise profile and opportunities.</p>
                <?php if (is_array($careerPlacement) && $careerPlacement !== []): ?>
                    <div class="border rounded p-2 mb-2 bg-light">
                        <strong><?= ep_h((string)($careerPlacement['company_name'] ?? 'Placement')) ?></strong>
                        <span class="text-muted small"> — <?= ep_h((string)($careerPlacement['status'] ?? 'Active')) ?> internship</span>
                        <?php if (!empty($careerPlacement['supervisor_name'])): ?>
                            <div class="small text-muted">Supervisor: <?= ep_h((string)$careerPlacement['supervisor_name']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($careerPlacement['feedback'])): ?>
                            <p class="small mb-0 mt-1"><?= ep_h((string)$careerPlacement['feedback']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="small ep-muted mb-2"><i class="fas fa-info-circle me-1"></i>No employer placement is linked yet. Skill Discovery still shows course-based career relevance from your ITC record.</p>
                <?php endif; ?>
                <a class="btn btn-sm btn-primary" href="/wucportal/enterprise/tools/skill_discovery.php"><i class="fas fa-search me-1"></i>View skill evidence</a>
            </div>
        </article>
    </div>
</div>

<div class="row g-3">
        <div class="col-lg-7">
        <article class="card">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0">Your opportunities</h2>
                <a class="btn btn-sm btn-primary" href="/wucportal/enterprise/opportunities/create.php">New</a>
            </div>
            <?php if ($opportunities === []): ?>
                <p class="small ep-muted mb-0">No opportunities yet. <a href="/wucportal/enterprise/opportunities/create.php">Create one</a>.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Title</th><th>Status</th><th>Updated</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($opportunities, 0, 8) as $o): ?>
                            <tr>
                                <td><?= ep_h((string)$o['title']) ?></td>
                                <td><span class="badge bg-<?= ep_h(ep_status_badge_class((string)$o['status'])) ?>"><?= ep_h(ep_status_label((string)$o['status'])) ?></span></td>
                                <td class="small text-muted"><?= ep_h(substr((string)($o['updated_at'] ?? ''), 0, 10)) ?></td>
                                <td class="text-end"><a href="/wucportal/enterprise/opportunities/view.php?id=<?= (int)$o['id'] ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <a class="small" href="/wucportal/enterprise/opportunities/index.php">View all</a>
            <?php endif; ?>
        </article>
        </div>
        <div class="col-lg-5">
        <article class="card mb-3">
            <h2 class="h6">Recent interest</h2>
            <?php if ($interests === []): ?>
                <p class="small ep-muted mb-0">No leads yet.</p>
            <?php else: ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($interests as $i): ?>
                        <li class="mb-2">
                            <strong><?= ep_h((string)$i['visitor_name']) ?></strong>
                            — <?= ep_h(ep_interest_types()[(string)$i['interest_type']] ?? (string)$i['interest_type']) ?>
                            <br><span class="text-muted"><?= ep_h((string)$i['title']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="small" href="/wucportal/enterprise/interests/index.php">All interest</a>
            <?php endif; ?>
        </article>
        <article class="card">
            <h2 class="h6">Quick links</h2>
            <div class="d-grid gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="/wucportal/opportunities/index.php" target="_blank" rel="noopener"><i class="fas fa-globe me-1"></i>Public Directory</a>
                <a class="btn btn-outline-secondary btn-sm" href="/wucportal/enterprise/tools/skill_discovery.php"><i class="fas fa-wand-magic-sparkles me-1"></i>Skill Discovery</a>
                <a class="btn btn-outline-secondary btn-sm" href="/wucportal/enterprise/profile/edit.php">Edit profile</a>
                <a class="btn btn-outline-secondary btn-sm" href="/wucportal/enterprise/tools/cost_calculator.php">Cost calculator</a>
                <a class="btn btn-outline-secondary btn-sm" href="/wucportal/enterprise/tools/readiness_assessment.php">Readiness assessment</a>
                <a class="btn btn-outline-secondary btn-sm" href="/wucportal/enterprise/tools/ai_assist.php">AI writing assist</a>
            </div>
        </article>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
