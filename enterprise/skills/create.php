<?php
declare(strict_types=1);

$page_title = 'Add skill';
$epGuardMode = 'member';
$activeNav = 'skills';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);

$errors = [];
$form = [
    'skill_name' => '',
    'skill_category' => '',
    'proficiency_level' => 'intermediate',
    'years_experience' => '',
    'evidence_description' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        foreach (array_keys($form) as $key) {
            $form[$key] = trim((string)($_POST[$key] ?? ''));
        }
        ep_save_skill($db, $profileId, $form);
        $_SESSION['flash_success'] = 'Skill saved.';
        wuc_redirect('/wucportal/enterprise/skills/index.php');
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="skill_name">Skill name *</label>
                <input class="form-control" id="skill_name" name="skill_name" required value="<?= ep_h($form['skill_name']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="skill_category">Category</label>
                <input class="form-control" id="skill_category" name="skill_category" value="<?= ep_h($form['skill_category']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="proficiency_level">Proficiency</label>
                <select class="form-select" id="proficiency_level" name="proficiency_level">
                    <?php foreach (['beginner', 'intermediate', 'advanced', 'expert'] as $lvl): ?>
                        <option value="<?= ep_h($lvl) ?>"<?= $form['proficiency_level'] === $lvl ? ' selected' : '' ?>><?= ep_h(ucfirst($lvl)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="years_experience">Years experience</label>
                <input class="form-control" type="number" step="0.5" min="0" id="years_experience" name="years_experience" value="<?= ep_h($form['years_experience']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="evidence_description">Evidence / portfolio notes</label>
                <textarea class="form-control" id="evidence_description" name="evidence_description" rows="3"><?= ep_h($form['evidence_description']) ?></textarea>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save</button>
            <a class="btn btn-outline-secondary" href="/wucportal/enterprise/skills/index.php">Cancel</a>
        </div>
    </form>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
