<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/legacy_academic_guard.php';
ep_redirect_certificate_from_legacy_skills_hub($db, (string)($_SESSION['Sid'] ?? ''));
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.profile.manage_own');

$base = '/wucportal/students/enterprise';
$ownerUserId = eh_current_owner_user_id();
$studentId = eh_current_student_id();
$profile = eh_get_profile_for_owner($db, $ownerUserId, $studentId);
$profileTypes = eh_profile_types();
$errors = [];

$regOptions = [
    'not_registered' => 'Not registered',
    'registered' => 'Registered',
    'pending' => 'Registration pending',
    'other' => 'Other',
];

$programmeCode = '';
$programmeName = '';
if ($studentId) {
    $progStmt = $db->prepare("
        SELECT sp.program_code, COALESCE(p.program_name, sp.program_code) AS program_name
        FROM student_program sp
        LEFT JOIN programs p ON p.program_code = sp.program_code
        WHERE sp.Sid = ?
          AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')
        ORDER BY sp.id DESC
        LIMIT 1
    ");
    if ($progStmt) {
        $progStmt->bind_param('s', $studentId);
        $progStmt->execute();
        $progRow = $progStmt->get_result()->fetch_assoc();
        $progStmt->close();
        if ($progRow) {
            $programmeCode = trim((string)($progRow['program_code'] ?? ''));
            $programmeName = trim((string)($progRow['program_name'] ?? ''));
        }
    }
}

$form = [
    'business_name' => (string)($profile['business_name'] ?? ''),
    'profile_type' => (string)($profile['profile_type'] ?? 'student_project'),
    'description' => (string)($profile['description'] ?? ''),
    'province' => (string)($profile['province'] ?? ''),
    'district' => (string)($profile['district'] ?? ''),
    'public_phone' => (string)($profile['public_phone'] ?? ''),
    'public_email' => (string)($profile['public_email'] ?? ''),
    'business_registration_status' => (string)($profile['business_registration_status'] ?? 'not_registered'),
    'registration_number' => (string)($profile['registration_number'] ?? ''),
    'years_operating' => $profile['years_operating'] ?? '',
    'programme_code' => (string)($profile['programme_code'] ?? $programmeCode),
    'programme_name' => (string)($profile['programme_name'] ?? $programmeName),
    'status' => (string)($profile['status'] ?? 'active'),
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        eh_require_post_csrf();
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    foreach (array_keys($form) as $key) {
        if ($key === 'years_operating') {
            $form[$key] = trim((string)($_POST['years_operating'] ?? ''));
            continue;
        }
        $form[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if ($form['business_name'] === '' || mb_strlen($form['business_name']) > 180) {
        $errors[] = 'Business name is required (max 180 characters).';
    }
    if (!array_key_exists($form['profile_type'], $profileTypes)) {
        $errors[] = 'Select a valid profile type.';
    }
    if ($form['description'] === '') {
        $errors[] = 'Description is required.';
    }
    if ($form['public_email'] !== '' && !filter_var($form['public_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid public email address.';
    }
    if (!array_key_exists($form['business_registration_status'], $regOptions)) {
        $errors[] = 'Select a valid registration status.';
    }
    if ($form['years_operating'] !== '' && (!ctype_digit($form['years_operating']) || (int)$form['years_operating'] < 0 || (int)$form['years_operating'] > 100)) {
        $errors[] = 'Years operating must be a whole number between 0 and 100.';
    }
    if (!in_array($form['status'], ['active', 'inactive'], true)) {
        $form['status'] = 'active';
    }

    if ($errors === []) {
        $payload = [
            'owner_user_id' => $ownerUserId > 0 ? $ownerUserId : 0,
            'student_id' => $studentId,
            'business_name' => $form['business_name'],
            'profile_type' => $form['profile_type'],
            'description' => $form['description'],
            'province' => $form['province'] !== '' ? $form['province'] : null,
            'district' => $form['district'] !== '' ? $form['district'] : null,
            'public_phone' => $form['public_phone'] !== '' ? $form['public_phone'] : null,
            'public_email' => $form['public_email'] !== '' ? $form['public_email'] : null,
            'business_registration_status' => $form['business_registration_status'],
            'registration_number' => $form['registration_number'] !== '' ? $form['registration_number'] : null,
            'years_operating' => $form['years_operating'] !== '' ? (int)$form['years_operating'] : null,
            'programme_code' => $form['programme_code'] !== '' ? $form['programme_code'] : null,
            'programme_name' => $form['programme_name'] !== '' ? $form['programme_name'] : null,
            'status' => $form['status'],
        ];

        try {
            if ($profile) {
                eh_assert_owns_profile($profile);
                eh_save_profile($db, $payload, (int)$profile['id']);
                wuc_set_flash('success', 'Enterprise profile updated.');
            } else {
                if ($ownerUserId <= 0) {
                    $errors[] = 'Your account is missing a linked user ID. Please contact ICT support.';
                } else {
                    eh_save_profile($db, $payload, null);
                    wuc_set_flash('success', 'Enterprise profile created.');
                }
            }
            if ($errors === []) {
                header('Location: ' . $base . '/profile.php');
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not save profile. Please try again.';
            error_log('enterprise profile_edit: ' . $e->getMessage());
        }
    }
}

$pageTitle = $profile ? 'Edit enterprise profile' : 'Create enterprise profile';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main class="content-wrapper portal-dashboard eh-hub pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4">
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title mb-1">
                    <i class="fas fa-user-edit me-2"></i><?php echo eh_h($pageTitle); ?>
                </h1>
                <p class="text-muted mb-0">Provide accurate public details. Do not include NRC or private personal data.</p>
            </div>
            <div class="col-auto">
                <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/<?php echo $profile ? 'profile.php' : 'index.php'; ?>">Cancel</a>
            </div>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach (array_unique($errors) as $error): ?>
                <div><?php echo eh_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="data-table-card">
        <div class="card-body">
            <form method="post" action="<?php echo eh_h($base); ?>/profile_edit.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo eh_h(wuc_csrf_token()); ?>">

                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="business_name">Business / project name <span class="text-danger">*</span></label>
                        <input class="form-control" type="text" id="business_name" name="business_name" maxlength="180" required
                               value="<?php echo eh_h($form['business_name']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="profile_type">Profile type <span class="text-danger">*</span></label>
                        <select class="form-select" id="profile_type" name="profile_type" required>
                            <?php foreach ($profileTypes as $key => $label): ?>
                                <option value="<?php echo eh_h($key); ?>" <?php echo $form['profile_type'] === $key ? 'selected' : ''; ?>>
                                    <?php echo eh_h($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Description <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="description" name="description" rows="5" required><?php echo eh_h($form['description']); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="province">Province</label>
                        <input class="form-control" type="text" id="province" name="province" maxlength="80" value="<?php echo eh_h($form['province']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="district">District</label>
                        <input class="form-control" type="text" id="district" name="district" maxlength="80" value="<?php echo eh_h($form['district']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="public_phone">Public phone</label>
                        <input class="form-control" type="text" id="public_phone" name="public_phone" maxlength="40" value="<?php echo eh_h($form['public_phone']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="public_email">Public email</label>
                        <input class="form-control" type="email" id="public_email" name="public_email" maxlength="120" value="<?php echo eh_h($form['public_email']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="business_registration_status">Business registration</label>
                        <select class="form-select" id="business_registration_status" name="business_registration_status">
                            <?php foreach ($regOptions as $key => $label): ?>
                                <option value="<?php echo eh_h($key); ?>" <?php echo $form['business_registration_status'] === $key ? 'selected' : ''; ?>>
                                    <?php echo eh_h($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="registration_number">Registration number</label>
                        <input class="form-control" type="text" id="registration_number" name="registration_number" maxlength="80"
                               value="<?php echo eh_h($form['registration_number']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="years_operating">Years operating</label>
                        <input class="form-control" type="number" id="years_operating" name="years_operating" min="0" max="100"
                               value="<?php echo eh_h((string)$form['years_operating']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="programme_code">Programme code</label>
                        <input class="form-control" type="text" id="programme_code" name="programme_code" maxlength="50"
                               value="<?php echo eh_h($form['programme_code']); ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="programme_name">Programme name</label>
                        <input class="form-control" type="text" id="programme_name" name="programme_name" maxlength="180"
                               value="<?php echo eh_h($form['programme_name']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="status">Profile status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active" <?php echo $form['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $form['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Save profile</button>
                    <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/<?php echo $profile ? 'profile.php' : 'index.php'; ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
