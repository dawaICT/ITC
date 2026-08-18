<?php
declare(strict_types=1);

$page_title = 'Edit opportunity';
$activeNav = 'opportunities';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);
$id = (int)($_GET['id'] ?? 0);
$opp = ep_get_opportunity($db, $id);
ep_assert_own_opportunity($opp, $profileId);

$categories = ep_list_categories($db, true);
$errors = [];
$form = array_merge($opp, []);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        if (isset($_POST['upload_media']) && !empty($_FILES['image'])) {
            $up = ep_upload_opportunity_media($db, $id, $_FILES['image'], !empty($_POST['is_primary']));
            if ($up['ok']) {
                $_SESSION['flash_success'] = $up['message'];
                wuc_redirect('/wucportal/enterprise/opportunities/edit.php?id=' . $id);
            }
            $errors[] = $up['message'];
        } else {
            $form = ep_opportunity_post_data();
            $result = ep_save_opportunity($db, $profileId, $form, $id);
            if ($result['ok']) {
                $_SESSION['flash_success'] = $result['message'];
                wuc_redirect('/wucportal/enterprise/opportunities/view.php?id=' . $id);
            }
            $errors[] = $result['message'];
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$media = ep_list_media($db, $id);
require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <div class="d-flex justify-content-between mb-3">
        <h2 class="h5 mb-0">Edit opportunity</h2>
        <a href="/wucportal/enterprise/opportunities/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">View</a>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <?php require __DIR__ . '/_form.inc.php'; ?>
        <button type="submit" class="btn btn-primary mt-3">Save changes</button>
    </form>
</div>
<div class="ep-card">
    <h3 class="h6">Images</h3>
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <input type="hidden" name="upload_media" value="1">
        <div class="col-md-6"><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp" class="form-control" required></div>
        <div class="col-md-3"><label class="form-check"><input type="checkbox" name="is_primary" value="1" class="form-check-input"> Primary</label></div>
        <div class="col-md-3"><button class="btn btn-outline-primary w-100" type="submit">Upload</button></div>
    </form>
    <?php if ($media): ?>
        <div class="row g-2 mt-2">
            <?php foreach ($media as $m): ?>
                <div class="col-4 col-md-2">
                    <img src="<?= ep_h(ep_media_serve_url((int)$m['id'])) ?>" class="img-fluid rounded border" alt="">
                    <?php if (!empty($m['is_primary'])): ?><div class="small">Primary</div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
