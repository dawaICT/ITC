<?php
declare(strict_types=1);

$page_title = 'Edit profile';
$epGuardMode = 'member';
$activeNav = 'profile';
require_once dirname(__DIR__) . '/includes/guard.php';

$errors = [];
$programmeCode = '';
$programmeName = '';
$sid = ep_current_student_id();
if ($sid) {
    $progStmt = $db->prepare(
        "SELECT sp.program_code, COALESCE(p.program_name, sp.program_code) AS program_name
         FROM student_program sp
         LEFT JOIN programs p ON p.program_code = sp.program_code
         WHERE sp.Sid = ?
           AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')
         ORDER BY sp.id DESC LIMIT 1"
    );
    if ($progStmt) {
        $progStmt->bind_param('s', $sid);
        $progStmt->execute();
        $progRow = $progStmt->get_result()->fetch_assoc();
        $progStmt->close();
        if ($progRow) {
            $programmeCode = trim((string)($progRow['program_code'] ?? ''));
            $programmeName = trim((string)($progRow['program_name'] ?? ''));
        }
    }
}

$p = $epProfile ?? [];
$form = [
    'profile_type' => (string)($p['profile_type'] ?? 'professional'),
    'business_name' => (string)($p['business_name'] ?? ''),
    'professional_title' => (string)($p['professional_title'] ?? ''),
    'short_bio' => (string)($p['short_bio'] ?? ''),
    'full_description' => (string)($p['full_description'] ?? ''),
    'province' => (string)($p['province'] ?? ''),
    'district' => (string)($p['district'] ?? ''),
    'preferred_contact_method' => (string)($p['preferred_contact_method'] ?? 'portal_mediated'),
    'public_phone' => (string)($p['public_phone'] ?? ''),
    'public_email' => (string)($p['public_email'] ?? ''),
    'business_registration_status' => (string)($p['business_registration_status'] ?? ''),
    'registration_number' => (string)($p['registration_number'] ?? ''),
    'years_operating' => isset($p['years_operating']) ? (string)$p['years_operating'] : '',
    'programme_code' => (string)($p['programme_code'] ?? $programmeCode),
    'programme_name' => (string)($p['programme_name'] ?? $programmeName),
];

$membershipId = (int)($epMembership['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        foreach (array_keys($form) as $key) {
            $form[$key] = trim((string)($_POST[$key] ?? ''));
        }
        if ($form['short_bio'] === '' && $form['professional_title'] === '' && $form['business_name'] === '') {
            $errors[] = 'Provide a business name, professional title, or short bio.';
        }
        if ($errors === [] && $membershipId > 0) {
            ep_save_member_profile($db, $membershipId, $form);
            $_SESSION['flash_success'] = 'Profile saved.';
            wuc_redirect('/wucportal/enterprise/profile/index.php');
        }
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
                <label class="form-label" for="profile_type">Profile type</label>
                <select class="form-select" id="profile_type" name="profile_type">
                    <?php foreach (['professional' => 'Professional', 'student_project' => 'Student project', 'enterprise' => 'Registered enterprise'] as $k => $lbl): ?>
                        <option value="<?= ep_h($k) ?>"<?= $form['profile_type'] === $k ? ' selected' : '' ?>><?= ep_h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="business_name">Business / display name</label>
                <input class="form-control" id="business_name" name="business_name" maxlength="180" value="<?= ep_h($form['business_name']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="professional_title">Professional title</label>
                <input class="form-control" id="professional_title" name="professional_title" maxlength="120" value="<?= ep_h($form['professional_title']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="short_bio">Short bio</label>
                <textarea class="form-control" id="short_bio" name="short_bio" rows="2" maxlength="500"><?= ep_h($form['short_bio']) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="full_description">Full description</label>
                <textarea class="form-control" id="full_description" name="full_description" rows="4"><?= ep_h($form['full_description']) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="province">Province</label>
                <input class="form-control" id="province" name="province" value="<?= ep_h($form['province']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="district">District</label>
                <input class="form-control" id="district" name="district" value="<?= ep_h($form['district']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="preferred_contact_method">Preferred contact</label>
                <select class="form-select" id="preferred_contact_method" name="preferred_contact_method">
                    <?php foreach (['portal_mediated' => 'Portal mediated', 'public_phone' => 'Public phone', 'public_email' => 'Public email'] as $k => $lbl): ?>
                        <option value="<?= ep_h($k) ?>"<?= $form['preferred_contact_method'] === $k ? ' selected' : '' ?>><?= ep_h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="public_phone">Public phone</label>
                <input class="form-control" id="public_phone" name="public_phone" value="<?= ep_h($form['public_phone']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="public_email">Public email</label>
                <input class="form-control" type="email" id="public_email" name="public_email" value="<?= ep_h($form['public_email']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="business_registration_status">Registration status</label>
                <input class="form-control" id="business_registration_status" name="business_registration_status" value="<?= ep_h($form['business_registration_status']) ?>" placeholder="e.g. not_registered">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="registration_number">Registration number</label>
                <input class="form-control" id="registration_number" name="registration_number" value="<?= ep_h($form['registration_number']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="years_operating">Years operating</label>
                <input class="form-control" type="number" min="0" id="years_operating" name="years_operating" value="<?= ep_h($form['years_operating']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="programme_code">Programme code</label>
                <input class="form-control" id="programme_code" name="programme_code" value="<?= ep_h($form['programme_code']) ?>" readonly>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="programme_name">Programme name</label>
                <input class="form-control" id="programme_name" name="programme_name" value="<?= ep_h($form['programme_name']) ?>" readonly>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save profile</button>
            <a class="btn btn-outline-secondary" href="/wucportal/enterprise/profile/index.php">Cancel</a>
        </div>
    </form>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
