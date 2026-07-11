<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/includes/role_helpers.php';
require_once __DIR__ . '/includes/helpers/redirect_helper.php';
require_once __DIR__ . '/includes/portal_access.php';

// Enforce logged in staff check
$userId = (string) ($_SESSION['user_id'] ?? '');
$userRole = (string) ($_SESSION['user_role'] ?? '');
if ($userId === '' || $userRole !== 'staff') {
    header("Location: staff_login.php");
    exit;
}

wuc_require_portal_access($db, 'academic');

$allRoles = $_SESSION['all_roles'] ?? [];
$allRolesRaw = $_SESSION['all_roles_raw'] ?? [];

// Only offer roles that resolve to a dedicated module dashboard. Module-less
// roles fall back to portal_selection.php (the retired staff dashboard used to
// play this part), which is a router — not a workspace — so it must not appear
// as a selectable option for multi-role accounts like ITC900.
$filteredRoles = [];
foreach ($allRoles as $r) {
    $landing = wuc_staff_landing_url($r);
    if (stripos($landing, 'staff/dashboard.php') === false
        && stripos($landing, 'portal_selection.php') === false) {
        $filteredRoles[] = $r;
    }
}
if (!empty($filteredRoles)) {
    $allRoles = $filteredRoles;
}

if (count($allRoles) <= 1) {
    // No choice needed, go directly to the (non-dashboard) portal
    $role = reset($allRoles) ?: 'staff';
    $_SESSION['role'] = $role;
    $landingUrl = wuc_staff_landing_url($role);
    header("Location: $landingUrl");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $selectedRole = trim((string)($_POST['role'] ?? ''));
        if (in_array($selectedRole, $allRoles, true)) {
            $_SESSION['role'] = $selectedRole;
            
            // Resolve the raw label for compatibility
            $rawLabel = getRoleDisplayName($selectedRole);
            $_SESSION['role_login'] = $rawLabel;
            
            // Set HOD workspace session if needed
            if (in_array($selectedRole, ['head_of_department', 'systems_admin'], true)) {
                require_once __DIR__ . '/includes/hos_section_helpers.php';
                hos_hydrate_section_session($db, $userId);
            }

            $landingUrl = wuc_staff_landing_url($selectedRole);
            header("Location: $landingUrl");
            exit;
        } else {
            $error = 'Invalid workspace selected.';
        }
    }
}

// Hydrate CSRF token if needed
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Workspace | ITC Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/wucportal/css/wuc-premium.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f4ff 0%, #e8ecf8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: #1f2937;
        }
        .selection-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 50px 40px;
            width: 100%;
            max-width: 900px;
            box-shadow: 0 10px 40px rgba(80, 60, 180, 0.08);
            border: 1px solid #e7e6f2;
            border-top: 4px solid #6f42c1;
            position: relative;
            overflow: hidden;
        }
        /* Centered logo watermark inside the card */
        .selection-card::before {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 440px;
            height: 440px;
            background: url('/wucportal/images/itc_logo.png') center/contain no-repeat;
            opacity: 0.045;
            pointer-events: none;
            z-index: 0;
        }
        .brand-header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
            z-index: 1;
        }
        .brand-header img {
            width: 140px;
            height: 140px;
            object-fit: contain;
            margin-bottom: 15px;
            transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        .brand-header img:hover {
            transform: scale(1.06);
        }
        .brand-header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: #1f2937;
            margin: 0;
            letter-spacing: -0.025em;
        }
        .brand-header p {
            color: #6b7280;
            font-size: 0.95rem;
            margin-top: 6px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.5;
        }
        .workspace-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 30px;
            justify-content: center;
            position: relative;
            z-index: 1;
        }
        .workspace-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: #1f2937;
            padding: 24px;
            border-radius: 14px;
            text-align: center;
            transition: all 0.2s ease-in-out;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            width: 100%;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.01);
        }
        .workspace-card:hover {
            border-color: #6f42c1;
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(111, 66, 193, 0.1);
        }
        .workspace-card:active {
            transform: translateY(-1px);
        }
        .workspace-icon {
            width: 56px;
            height: 56px;
            background: rgba(111, 66, 193, 0.05);
            border: 1px solid rgba(111, 66, 193, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: #6f42c1;
            margin-bottom: 16px;
            transition: all 0.2s ease-in-out;
        }
        .workspace-card:hover .workspace-icon {
            background: #f9ad59;
            border-color: #f9ad59;
            color: #ffffff;
        }
        .workspace-name {
            font-weight: 700;
            font-size: 1.1rem;
            line-height: 1.3;
            margin-bottom: 8px;
            color: #1f2937;
            transition: color 0.2s ease-in-out;
        }
        .workspace-card:hover .workspace-name {
            color: #6f42c1;
        }
        .workspace-desc {
            font-size: 0.85rem;
            color: #6b7280;
            line-height: 1.4;
            margin: 0;
        }
        .logout-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 35px;
            color: #6b7280;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        .logout-link:hover {
            color: #dc2626;
        }
        .logout-container {
            text-align: center;
            position: relative;
            z-index: 1;
        }
    </style>

<?php require_once __DIR__ . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
    <div class="selection-card">
        <div class="brand-header">
            <img src="/wucportal/images/itc_logo.png" alt="ITC Logo">
            <h1>Choose Workspace Context</h1>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?>. Please select a role workspace to continue.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="workspace-grid">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <?php foreach ($allRoles as $index => $r): 
                $label = getRoleDisplayName($r);
                $icon = 'fa-user-tie';
                $desc = 'Access portal under ' . $label . ' role';

                if ($r === 'systems_admin') { $icon = 'fa-cogs'; $desc = 'Full system administration and settings'; }
                elseif ($r === 'lecturer') { $icon = 'fa-chalkboard-teacher'; $desc = 'Manage assigned courses, materials and CA'; }
                elseif ($r === 'head_of_department') { $icon = 'fa-sitemap'; $desc = 'Department oversight, approvals and reports'; }
                elseif ($r === 'dean') { $icon = 'fa-university'; $desc = 'Faculty administration and results validation'; }
                elseif ($r === 'registrar') { $icon = 'fa-clipboard-list'; $desc = 'Student registrations, records and transcripts'; }
                elseif ($r === 'accountant') { $icon = 'fa-calculator'; $desc = 'Manage fees, billing and financial records'; }
                elseif ($r === 'admission_officer') { $icon = 'fa-user-plus'; $desc = 'Admissions dashboard, applications and letters'; }
                elseif ($r === 'librarian') { $icon = 'fa-book-open'; $desc = 'Library catalogs, borrowings and fine collections'; }
                elseif ($r === 'transport_officer') { $icon = 'fa-bus'; $desc = 'Fleet, driving instructions and driver logs'; }
                elseif ($r === 'employer') { $icon = 'fa-briefcase'; $desc = 'Manage placements, job vacancies and recruitment'; }
                elseif ($r === 'alumni') { $icon = 'fa-graduation-cap'; $desc = 'Access graduation, transcripts and alumni network'; }
            ?>
                <button type="submit" name="role" value="<?php echo htmlspecialchars($r); ?>" class="workspace-card">
                    <div class="workspace-icon">
                        <i class="fas <?php echo $icon; ?>"></i>
                    </div>
                    <div class="workspace-name"><?php echo htmlspecialchars($label); ?></div>
                    <div class="workspace-desc"><?php echo htmlspecialchars($desc); ?></div>
                </button>
            <?php endforeach; ?>
        </form>

        <div class="logout-container">
            <a href="/wucportal/logout.php?to=staff" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> Logout and exit
            </a>
        </div>
    </div>
</body>
</html>
