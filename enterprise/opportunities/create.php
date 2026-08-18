<?php
declare(strict_types=1);

$page_title = 'Create opportunity';
$epGuardMode = 'member';
$activeNav = 'opportunities';
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);

$categories = ep_list_categories($db, true);
$errors = [];
$form = array_fill_keys([
    'opportunity_type', 'title', 'short_description', 'full_description', 'category_id',
    'pricing_type', 'unit_price', 'minimum_price', 'maximum_price', 'currency',
    'service_area', 'availability_status', 'current_capacity', 'capacity_period',
    'innovation_stage', 'prototype_status', 'problem_statement', 'proposed_solution',
    'investment_required', 'investment_purpose', 'expected_capacity', 'expected_capacity_period',
    'employment_potential', 'preferred_employment_type', 'preferred_location', 'cost_visibility',
], '');
$form['opportunity_type'] = 'product';
$form['availability_status'] = 'available';
$form['currency'] = 'ZMW';
$form['cost_visibility'] = 'private';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        $form = ep_opportunity_post_data();
        $result = ep_save_opportunity($db, $profileId, $form);
        if ($result['ok']) {
            $_SESSION['flash_success'] = $result['message'];
            wuc_redirect('/wucportal/enterprise/opportunities/edit.php?id=' . (int)$result['id']);
        }
        $errors[] = $result['message'];
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

require_once __DIR__ . '/../includes/layout.php';
?>
<div class="ep-card">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <?php require __DIR__ . '/_form.inc.php'; ?>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save draft</button>
            <a class="btn btn-outline-secondary" href="/wucportal/enterprise/opportunities/index.php">Cancel</a>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
