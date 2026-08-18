<?php
declare(strict_types=1);

$page_title = 'Get started';
$epGuardMode = 'member';
$activeNav = 'dashboard';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/participant_helpers.php';

$completion = ep_profile_completion($db, $epMembership, $epProfile);
if ($completion['percent'] >= 100) {
    wuc_redirect('/wucportal/enterprise/index.php');
}

$profileId = ep_member_profile_id($epProfile);
$skills = $profileId > 0 ? ep_list_skills($db, $profileId) : [];
$opps = $profileId > 0 ? ep_list_opportunities_for_profile($db, $profileId) : [];

$steps = [
    [
        'label' => 'Professional profile',
        'done' => $epProfile && (
            trim((string)($epProfile['short_bio'] ?? '')) !== ''
            || trim((string)($epProfile['professional_title'] ?? '')) !== ''
            || trim((string)($epProfile['business_name'] ?? '')) !== ''
        ),
        'href' => '/wucportal/enterprise/profile/edit.php',
    ],
    [
        'label' => 'Add at least one skill',
        'done' => $skills !== [],
        'href' => '/wucportal/enterprise/skills/create.php',
    ],
    [
        'label' => 'Set contact preferences',
        'done' => $epProfile && trim((string)($epProfile['preferred_contact_method'] ?? '')) !== '',
        'href' => '/wucportal/enterprise/profile/edit.php',
    ],
    [
        'label' => 'Create your first opportunity',
        'done' => $opps !== [],
        'href' => '/wucportal/enterprise/opportunities/create.php',
    ],
];

require_once __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="ep-card">
            <h2 class="h5 mb-2">Welcome — complete your setup</h2>
            <p class="ep-muted small mb-3">Finish these steps to strengthen your public presence and unlock submission workflows.</p>
            <div class="progress mb-3" style="height: 8px;">
                <div class="progress-bar bg-primary" style="width: <?= (int)$completion['percent'] ?>%;"></div>
            </div>
            <p class="small mb-3"><strong><?= (int)$completion['percent'] ?>%</strong> profile readiness</p>
            <ul class="list-group list-group-flush">
                <?php foreach ($steps as $step): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>
                            <i class="fas fa-<?= $step['done'] ? 'circle-check text-success' : 'circle text-muted' ?> me-2"></i>
                            <?= ep_h($step['label']) ?>
                        </span>
                        <?php if (!$step['done']): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= ep_h($step['href']) ?>">Continue</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="ep-card">
            <h3 class="h6">Still missing</h3>
            <?php if ($completion['missing'] === []): ?>
                <p class="small ep-muted mb-0">Nothing critical — open your dashboard.</p>
            <?php else: ?>
                <ul class="small ep-muted mb-0">
                    <?php foreach ($completion['missing'] as $m): ?>
                        <li><?= ep_h($m) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a class="btn btn-primary btn-sm mt-3" href="/wucportal/enterprise/index.php">Skip to dashboard</a>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
