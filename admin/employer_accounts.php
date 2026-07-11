<?php
declare(strict_types=1);

$page_title = 'Employer Partner Accounts';
require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/audit.php';
require_once dirname(__DIR__) . '/includes/portal_access.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** Next EMP-#### login id (numeric suffix of the highest existing one + 1). */
function wuc_next_employer_login_id(mysqli $db): string
{
    $res = $db->query("SELECT staff_id FROM staff WHERE staff_id LIKE 'EMP-%' ORDER BY CAST(SUBSTRING(staff_id, 5) AS UNSIGNED) DESC LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    $next = $row ? ((int)substr((string)$row['staff_id'], 4)) + 1 : 1001;
    return 'EMP-' . $next;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['errorMessage'] = 'CSRF token validation failed.';
        header('Location: employer_accounts.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_employer') {
        $company = mb_substr(trim($_POST['company_name'] ?? ''), 0, 150);
        $fname = mb_substr(trim($_POST['contact_fname'] ?? ''), 0, 50);
        $lname = mb_substr(trim($_POST['contact_lname'] ?? ''), 0, 50);
        $email = mb_substr(trim($_POST['contact_email'] ?? ''), 0, 100);
        $phone = mb_substr(trim($_POST['contact_phone'] ?? ''), 0, 20);
        $industry = mb_substr(trim($_POST['industry'] ?? ''), 0, 100);
        $location = mb_substr(trim($_POST['location'] ?? ''), 0, 150);

        $errors = [];
        if ($company === '' || $fname === '' || $lname === '') {
            $errors[] = 'Company name and contact person are required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid contact email address.';
        }
        if ($phone !== '' && !preg_match('/^\+?[0-9 \-()]{6,20}$/', $phone)) {
            $errors[] = 'Enter a valid contact phone number.';
        }
        if (!$errors) {
            $dup = $db->prepare("SELECT ep.id FROM employer_profiles ep WHERE ep.company_name = ? AND ep.status <> 'disabled' LIMIT 1");
            $dup->bind_param('s', $company);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $errors[] = 'An active employer account already exists for this company.';
            }
            $dup->close();
        }

        if ($errors) {
            $_SESSION['errorMessage'] = implode(' ', $errors);
            header('Location: employer_accounts.php');
            exit;
        }

        $loginId = wuc_next_employer_login_id($db);
        $tempPassword = bin2hex(random_bytes(6)); // 12 hex chars, shown once
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $adminId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'admin');

        $db->begin_transaction();
        try {
            $stmt = $db->prepare("INSERT INTO staff (staff_id, Fname, Lname, email, mobile, password, role, status) VALUES (?, ?, ?, ?, ?, ?, 'employer', 'active')");
            $stmt->bind_param('ssssss', $loginId, $fname, $lname, $email, $phone, $hash);
            $stmt->execute();
            $stmt->close();

            require_once dirname(__DIR__) . '/includes/helpers/staff_provisioning.php';
            $provision = wuc_provision_staff_account($db, $loginId, 'employer', $tempPassword, $adminId);
            if (!$provision['ok']) {
                throw new RuntimeException('Employer provisioning failed: ' . implode('; ', $provision['messages']));
            }
            $newUserId = (int)$provision['user_id'];
            if ($newUserId <= 0) {
                throw new RuntimeException('Employer users row missing after provision.');
            }

            $contact = trim($fname . ' ' . $lname);
            $stmt = $db->prepare("INSERT INTO employer_profiles (user_id, company_name, industry, location, contact_person, contact_email, contact_phone, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', ?)");
            $stmt->bind_param('isssssss', $newUserId, $company, $industry, $location, $contact, $email, $phone, $adminId);
            $stmt->execute();
            $stmt->close();

            wuc_grant_user_portal_access($db, $newUserId, ['employer'], $adminId);

            $db->commit();
            audit_log_current_user($db, 'employer_account.create', [
                'login_id' => $loginId,
                'user_id' => $newUserId,
                'company' => $company,
            ]);
            $_SESSION['successMessage'] = "Employer account created. Login ID: {$loginId} — temporary password: {$tempPassword} (share securely; it is shown only once).";
        } catch (Throwable $e) {
            $db->rollback();
            error_log('Employer account creation failed: ' . $e->getMessage());
            $_SESSION['errorMessage'] = 'Could not create the employer account. Please try again.';
        }

        header('Location: employer_accounts.php');
        exit;
    }

    if ($action === 'toggle_status') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $enable = (int)($_POST['enable'] ?? 0) === 1;
        $newStatus = $enable ? 'active' : 'inactive';
        $profileStatus = $enable ? 'approved' : 'disabled';

        $stmt = $db->prepare("SELECT user_id, staff_id FROM users WHERE user_id = ? AND primary_role = 'employer' LIMIT 1");
        $stmt->bind_param('i', $targetUserId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target) {
            $_SESSION['errorMessage'] = 'Employer account not found.';
        } else {
            $db->begin_transaction();
            try {
                $stmt = $db->prepare("UPDATE users SET status = ? WHERE user_id = ?");
                $stmt->bind_param('si', $newStatus, $targetUserId);
                $stmt->execute();
                $stmt->close();

                $staffId = (string)$target['staff_id'];
                $stmt = $db->prepare("UPDATE staff SET status = ? WHERE staff_id = ?");
                $stmt->bind_param('ss', $newStatus, $staffId);
                $stmt->execute();
                $stmt->close();

                $stmt = $db->prepare("UPDATE employer_profiles SET status = ? WHERE user_id = ?");
                $stmt->bind_param('si', $profileStatus, $targetUserId);
                $stmt->execute();
                $stmt->close();

                if ($enable) {
                    wuc_grant_user_portal_access($db, $targetUserId, ['employer'], (string)($_SESSION['staff_id'] ?? 'admin'));
                } else {
                    wuc_revoke_user_portal_access($db, $targetUserId, ['employer'], (string)($_SESSION['staff_id'] ?? 'admin'));
                }

                $db->commit();
                audit_log_current_user($db, 'employer_account.' . ($enable ? 'enable' : 'disable'), ['target_user_id' => $targetUserId]);
                $_SESSION['successMessage'] = $enable ? 'Employer account re-activated.' : 'Employer account deactivated.';
            } catch (Throwable $e) {
                $db->rollback();
                error_log('Employer account status change failed: ' . $e->getMessage());
                $_SESSION['errorMessage'] = 'Could not update the employer account.';
            }
        }

        header('Location: employer_accounts.php');
        exit;
    }

    header('Location: employer_accounts.php');
    exit;
}

// Load navigation UI
require "includes/nav.php";

$employers = [];
$res = $db->query("
    SELECT u.user_id, u.username, u.status AS account_status,
           ep.company_name, ep.industry, ep.location, ep.contact_person, ep.contact_email, ep.contact_phone, ep.status AS profile_status,
           (SELECT COUNT(*) FROM employer_internships ei WHERE ei.logged_by_user_id = u.user_id) AS internships_logged
    FROM users u
    LEFT JOIN employer_profiles ep ON ep.user_id = u.user_id
    WHERE u.primary_role = 'employer'
    ORDER BY u.username ASC");
while ($res && $row = $res->fetch_assoc()) {
    $employers[] = $row;
}

$successMessage = $_SESSION['successMessage'] ?? '';
$errorMessage = $_SESSION['errorMessage'] ?? '';
unset($_SESSION['successMessage'], $_SESSION['errorMessage']);
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-briefcase me-2"></i>Employer Partner Accounts</h1>
            <p class="text-muted small mb-0">Create and manage external employer logins. Accounts sign in at the staff login page and land in the Employer Portal only.</p>
        </div>
        <a href="portal_access.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-door-open me-1"></i>Portal Access Manager</a>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-plus-circle me-2"></i>New Employer Account</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="employer_accounts.php">
                        <input type="hidden" name="action" value="create_employer">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Company / Organization *</label>
                            <input type="text" name="company_name" class="form-control" maxlength="150" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col">
                                <label class="form-label small fw-bold">Contact First Name *</label>
                                <input type="text" name="contact_fname" class="form-control" maxlength="50" required>
                            </div>
                            <div class="col">
                                <label class="form-label small fw-bold">Contact Last Name *</label>
                                <input type="text" name="contact_lname" class="form-control" maxlength="50" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Contact Email *</label>
                            <input type="email" name="contact_email" class="form-control" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Contact Phone</label>
                            <input type="text" name="contact_phone" class="form-control" maxlength="20" placeholder="+260 ...">
                        </div>
                        <div class="row mb-3">
                            <div class="col">
                                <label class="form-label small fw-bold">Industry</label>
                                <input type="text" name="industry" class="form-control" maxlength="100" placeholder="e.g. Logistics">
                            </div>
                            <div class="col">
                                <label class="form-label small fw-bold">Location</label>
                                <input type="text" name="location" class="form-control" maxlength="150" placeholder="e.g. Lusaka">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-user-plus me-1"></i>Create Account</button>
                        <p class="text-muted small mt-2 mb-0">A login ID (EMP-####) and one-time temporary password are generated automatically.</p>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-building me-2"></i>Registered Employers</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($employers)): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="fas fa-building fa-3x mb-3 text-light"></i>
                            <p class="mb-0">No employer accounts yet. Create the first one with the form on the left.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th>Login ID</th>
                                        <th>Company</th>
                                        <th>Contact</th>
                                        <th class="text-center">Placements</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($employers as $emp):
                                        $active = ($emp['account_status'] ?? '') === 'active';
                                    ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($emp['username']) ?></code></td>
                                        <td>
                                            <strong><?= htmlspecialchars($emp['company_name'] ?? '(profile missing)') ?></strong>
                                            <?php if (!empty($emp['industry']) || !empty($emp['location'])): ?>
                                                <div class="text-muted"><?= htmlspecialchars(trim(($emp['industry'] ?? '') . ' · ' . ($emp['location'] ?? ''), ' ·')) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($emp['contact_person'] ?? '—') ?>
                                            <div class="text-muted"><?= htmlspecialchars($emp['contact_email'] ?? '') ?></div>
                                        </td>
                                        <td class="text-center"><?= (int)$emp['internships_logged'] ?></td>
                                        <td class="text-center">
                                            <span class="badge <?= $active ? 'bg-success' : 'bg-secondary' ?>"><?= $active ? 'Active' : 'Disabled' ?></span>
                                        </td>
                                        <td class="text-end">
                                            <form method="post" action="employer_accounts.php" class="d-inline">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="user_id" value="<?= (int)$emp['user_id'] ?>">
                                                <input type="hidden" name="enable" value="<?= $active ? 0 : 1 ?>">
                                                <button type="submit" class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                                    <?= $active ? '<i class="fas fa-ban me-1"></i>Deactivate' : '<i class="fas fa-check me-1"></i>Re-activate' ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require "includes/footer.php"; ?>
