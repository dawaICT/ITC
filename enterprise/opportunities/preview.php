<?php
declare(strict_types=1);

/**
 * Private public-style preview before / without publication.
 * Does not increment public view counts and never shows private academic identifiers.
 */

$page_title = 'Public preview';
$activeNav = 'opportunities';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);
$id = (int)($_GET['id'] ?? 0);
$opp = ep_get_opportunity($db, $id);
ep_assert_own_opportunity($opp, $profileId);
$media = ep_list_media($db, $id);

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="alert alert-info small">This is a private preview of how your listing would appear publicly. It is not visible in the Skills and Enterprise Directory until published.</div>
<div class="ep-card">
    <span class="badge bg-secondary">Preview only</span>
    <span class="badge bg-light text-dark"><?= ep_h(ep_status_label((string)$opp['opportunity_type'])) ?></span>
    <h1 class="h4 mt-2"><?= ep_h((string)$opp['title']) ?></h1>
    <p class="ep-muted"><?= ep_h((string)$opp['short_description']) ?></p>
    <p><?= nl2br(ep_h((string)($opp['full_description'] ?? ''))) ?></p>
    <dl class="row small">
        <dt class="col-sm-3">Display name</dt>
        <dd class="col-sm-9"><?= ep_h(trim((string)(($epProfile['business_name'] ?? '') ?: ($epProfile['professional_title'] ?? ''))) ?: 'Participant') ?></dd>
        <dt class="col-sm-3">Contact method</dt>
        <dd class="col-sm-9"><?= ep_h((string)($epProfile['preferred_contact_method'] ?? 'portal_mediated')) ?></dd>
        <dt class="col-sm-3">Availability</dt>
        <dd class="col-sm-9"><?= ep_h(ep_status_label((string)$opp['availability_status'])) ?></dd>
    </dl>
    <?php if ($media): ?>
        <div class="row g-2">
            <?php foreach ($media as $m): ?>
                <div class="col-4 col-md-2"><img src="<?= ep_h(ep_media_serve_url((int)$m['id'])) ?>" class="img-fluid rounded border" alt=""></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p class="small ep-muted mt-3 mb-0">
        <?= ep_h(function_exists('ep_public_verification_disclaimer')
            ? ep_public_verification_disclaimer()
            : 'Listings reflect institutional verification of submitted information. Verification does not guarantee employment, sales, funding or investment returns.') ?>
    </p>
    <a class="btn btn-outline-secondary mt-3" href="/wucportal/enterprise/opportunities/view.php?id=<?= $id ?>">Back</a>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
