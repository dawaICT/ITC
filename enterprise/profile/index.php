<?php
declare(strict_types=1);

$page_title = 'Professional profile';
$epGuardMode = 'member';
$activeNav = 'profile';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

if (!$epProfile) {
    wuc_redirect('/wucportal/enterprise/profile/edit.php');
}

$profileId = ep_member_profile_id($epProfile);
$skills = ep_list_skills($db, $profileId);
$completion = ep_profile_completion($db, $epMembership, $epProfile);
$goals = json_decode((string)($epMembership['participation_goals_json'] ?? '[]'), true) ?: [];
$goalLabels = ep_participation_goals();

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="ep-card">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h2 class="h4 mb-1"><?= ep_h(trim((string)($epProfile['business_name'] ?? '')) !== '' ? (string)$epProfile['business_name'] : 'Professional profile') ?></h2>
                    <?php if (trim((string)($epProfile['professional_title'] ?? '')) !== ''): ?>
                        <p class="ep-muted mb-0"><?= ep_h((string)$epProfile['professional_title']) ?></p>
                    <?php endif; ?>
                </div>
                <a class="btn btn-primary btn-sm" href="/wucportal/enterprise/profile/edit.php">Edit</a>
            </div>
            <?php if (trim((string)($epProfile['short_bio'] ?? '')) !== ''): ?>
                <p><?= nl2br(ep_h((string)$epProfile['short_bio'])) ?></p>
            <?php endif; ?>
            <?php if (trim((string)($epProfile['full_description'] ?? '')) !== ''): ?>
                <h3 class="h6">About</h3>
                <p class="small"><?= nl2br(ep_h((string)$epProfile['full_description'])) ?></p>
            <?php endif; ?>
            <dl class="row small mb-0">
                <dt class="col-sm-4">Location</dt>
                <dd class="col-sm-8"><?= ep_h(trim((string)($epProfile['province'] ?? '') . ', ' . (string)($epProfile['district'] ?? ''), ', ')) ?: '—' ?></dd>
                <dt class="col-sm-4">Contact method</dt>
                <dd class="col-sm-8"><?= ep_h(str_replace('_', ' ', (string)($epProfile['preferred_contact_method'] ?? 'portal_mediated'))) ?></dd>
                <dt class="col-sm-4">Public phone</dt>
                <dd class="col-sm-8"><?= ep_h((string)($epProfile['public_phone'] ?? '—')) ?></dd>
                <dt class="col-sm-4">Public email</dt>
                <dd class="col-sm-8"><?= ep_h((string)($epProfile['public_email'] ?? '—')) ?></dd>
                <dt class="col-sm-4">Programme</dt>
                <dd class="col-sm-8"><?= ep_h((string)($epProfile['programme_name'] ?? $epProfile['programme_code'] ?? '—')) ?></dd>
            </dl>
        </div>
        <div class="ep-card mt-3">
            <h3 class="h6">Skills (<?= count($skills) ?>)</h3>
            <?php if ($skills === []): ?>
                <p class="small ep-muted">No skills listed. <a href="/wucportal/enterprise/skills/create.php">Add a skill</a>.</p>
            <?php else: ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach (array_slice($skills, 0, 6) as $s): ?>
                        <li class="mb-1"><strong><?= ep_h((string)$s['skill_name']) ?></strong> — <?= ep_h((string)$s['proficiency_level']) ?></li>
                    <?php endforeach; ?>
                </ul>
                <a class="small" href="/wucportal/enterprise/skills/index.php">Manage skills</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="ep-card">
            <h3 class="h6">Readiness</h3>
            <div class="progress mb-2" style="height: 8px;"><div class="progress-bar" style="width: <?= (int)$completion['percent'] ?>%;"></div></div>
            <p class="small mb-0"><?= (int)$completion['percent'] ?>% complete</p>
        </div>
        <?php if ($goals !== []): ?>
            <div class="ep-card mt-3">
                <h3 class="h6">Participation goals</h3>
                <ul class="small mb-0">
                    <?php foreach ($goals as $g): ?>
                        <li><?= ep_h($goalLabels[$g] ?? $g) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
