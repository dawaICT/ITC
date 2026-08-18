<?php
declare(strict_types=1);

$page_title = 'Skills';
$epGuardMode = 'member';
$activeNav = 'skills';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);

$skills = ep_list_skills($db, $profileId);

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="ep-muted small mb-0">Showcase practical skills linked to your professional profile.</p>
        <a class="btn btn-primary btn-sm" href="/wucportal/enterprise/skills/create.php">Add skill</a>
    </div>
    <?php if ($skills === []): ?>
        <p class="mb-0">No skills yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Skill</th><th>Category</th><th>Level</th><th>Evidence</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($skills as $s): ?>
                    <tr>
                        <td><?= ep_h((string)$s['skill_name']) ?></td>
                        <td><?= ep_h((string)($s['skill_category'] ?? '—')) ?></td>
                        <td><?= ep_h((string)$s['proficiency_level']) ?></td>
                        <td class="small"><?= trim((string)($s['evidence_description'] ?? '')) !== '' ? 'Yes' : '—' ?></td>
                        <td class="text-end"><a href="/wucportal/enterprise/skills/edit.php?id=<?= (int)$s['id'] ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
