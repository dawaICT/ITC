<?php
declare(strict_types=1);

$page_title = 'Edit skill';
$epGuardMode = 'member';
$activeNav = 'skills';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);

$skillId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$skill = $skillId > 0 ? ep_get_skill_for_profile($db, $skillId, $profileId) : null;
if (!$skill) {
    $_SESSION['flash_error'] = 'Skill not found.';
    wuc_redirect('/wucportal/enterprise/skills/index.php');
}

$errors = [];
$form = [
    'skill_name' => (string)$skill['skill_name'],
    'skill_category' => (string)($skill['skill_category'] ?? ''),
    'proficiency_level' => (string)$skill['proficiency_level'],
    'years_experience' => isset($skill['years_experience']) ? (string)$skill['years_experience'] : '',
    'evidence_description' => (string)($skill['evidence_description'] ?? ''),
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'delete') {
            $stmt = $db->prepare('DELETE FROM enterprise_skills WHERE id = ? AND enterprise_profile_id = ?');
            $stmt->bind_param('ii', $skillId, $profileId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Skill removed.';
            wuc_redirect('/wucportal/enterprise/skills/index.php');
        }
        foreach (array_keys($form) as $key) {
            $form[$key] = trim((string)($_POST[$key] ?? ''));
        }
        ep_save_skill($db, $profileId, $form, $skillId);
        $_SESSION['flash_success'] = 'Skill updated.';
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
        <input type="hidden" name="id" value="<?= (int)$skillId ?>">
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
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <button type="submit" name="action" value="save" class="btn btn-primary">Save</button>
            <a class="btn btn-outline-secondary" href="/wucportal/enterprise/skills/index.php">Cancel</a>
            <button type="submit" name="action" value="delete" class="btn btn-outline-danger ms-auto" onclick="return confirm('Remove this skill?');">Delete</button>
        </div>
    </form>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
