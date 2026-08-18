<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/legacy_academic_guard.php';
ep_redirect_certificate_from_legacy_skills_hub($db, (string)($_SESSION['Sid'] ?? ''));
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.profile.manage_own');

$base = '/wucportal/students/enterprise';
$profile = eh_get_profile_for_owner($db, eh_current_owner_user_id(), eh_current_student_id());

if (!$profile) {
    wuc_set_flash('info', 'Create your enterprise profile to get started.');
    header('Location: ' . $base . '/profile_edit.php');
    exit;
}

eh_assert_owns_profile($profile);

$profileTypes = eh_profile_types();
$regLabels = [
    'not_registered' => 'Not registered',
    'registered' => 'Registered',
    'pending' => 'Registration pending',
    'other' => 'Other',
];

$pageTitle = 'Enterprise profile';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main class="content-wrapper portal-dashboard eh-hub pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4">
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title mb-1"><i class="fas fa-id-card me-2"></i>Enterprise profile</h1>
                <p class="text-muted mb-0">Your public-facing business identity for the Skills-to-Trade showcase.</p>
            </div>
            <div class="col-auto d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/index.php"><i class="fas fa-arrow-left me-1"></i>Hub home</a>
                <a class="btn btn-primary" href="<?php echo eh_h($base); ?>/profile_edit.php"><i class="fas fa-pen me-1"></i>Edit profile</a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <section class="data-table-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo eh_h((string)$profile['business_name']); ?></h5>
                    <span class="badge bg-<?php echo ((string)$profile['status'] === 'active') ? 'success' : 'secondary'; ?>">
                        <?php echo eh_h(ucfirst((string)$profile['status'])); ?>
                    </span>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Profile type</dt>
                        <dd class="col-sm-8"><?php echo eh_h($profileTypes[(string)$profile['profile_type']] ?? (string)$profile['profile_type']); ?></dd>

                        <dt class="col-sm-4">Description</dt>
                        <dd class="col-sm-8"><?php echo nl2br(eh_h((string)($profile['description'] ?? ''))); ?></dd>

                        <dt class="col-sm-4">Province</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($profile['province'] ?? '—')); ?></dd>

                        <dt class="col-sm-4">District</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($profile['district'] ?? '—')); ?></dd>

                        <dt class="col-sm-4">Public phone</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($profile['public_phone'] ?? '—')); ?></dd>

                        <dt class="col-sm-4">Public email</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($profile['public_email'] ?? '—')); ?></dd>

                        <dt class="col-sm-4">Registration status</dt>
                        <dd class="col-sm-8">
                            <?php
                            $reg = (string)($profile['business_registration_status'] ?? 'not_registered');
                            echo eh_h($regLabels[$reg] ?? $reg);
                            ?>
                        </dd>

                        <dt class="col-sm-4">Registration number</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($profile['registration_number'] ?? '—')); ?></dd>

                        <dt class="col-sm-4">Years operating</dt>
                        <dd class="col-sm-8"><?php echo $profile['years_operating'] !== null && $profile['years_operating'] !== '' ? (int)$profile['years_operating'] : '—'; ?></dd>

                        <dt class="col-sm-4">Programme code</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($profile['programme_code'] ?? '—')); ?></dd>

                        <dt class="col-sm-4">Programme name</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($profile['programme_name'] ?? '—')); ?></dd>

                        <dt class="col-sm-4">Student ID</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($profile['student_id'] ?? '—')); ?></dd>

                        <dt class="col-sm-4">Updated</dt>
                        <dd class="col-sm-8"><?php echo eh_h((string)($profile['updated_at'] ?? '')); ?></dd>
                    </dl>
                </div>
            </section>
        </div>
        <div class="col-lg-4">
            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick links</h5></div>
                <div class="card-body d-grid gap-2">
                    <a class="btn btn-outline-primary" href="<?php echo eh_h($base); ?>/item_create.php"><i class="fas fa-plus me-1"></i>Create item</a>
                    <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/items.php"><i class="fas fa-boxes-stacked me-1"></i>My items</a>
                    <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/submissions.php"><i class="fas fa-inbox me-1"></i>Submissions</a>
                    <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/interests.php"><i class="fas fa-handshake me-1"></i>Interests</a>
                </div>
            </section>
        </div>
    </div>
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
