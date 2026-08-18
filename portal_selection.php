<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/includes/portal_access.php';
require_once __DIR__ . '/includes/role_helpers.php';
require_once __DIR__ . '/includes/portal_switch.php';

$userId = wuc_resolve_session_user_id($db);
$userKind = !empty($_SESSION['Sid']) || ($_SESSION['user_role'] ?? '') === 'student' ? 'student' : 'staff';
// Primary role only — systems_admin / multi-role staff keep institutional copy.
$isLecturer = $userKind !== 'student' && (string)($_SESSION['role'] ?? '') === 'lecturer';

if ($userId <= 0 || empty($_SESSION['logged_in'])) {
    wuc_redirect($userKind === 'student' ? 'student_login.php' : 'staff_login.php');
}

$portals = array_values(array_filter(
    wuc_user_active_portals($db, $userId),
    static fn(array $portal): bool => strtolower((string)($portal['portal_code'] ?? '')) !== 'enterprise'
));
if (count($portals) === 0) {
    $_SESSION[$userKind === 'student' ? 'errorMssg' : 'errorMessage'] =
        'Your account is active, but no portal access has been assigned. Please contact the system administrator.';
    wuc_redirect($userKind === 'student' ? 'student_login.php' : 'staff_login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $selectedPortal = strtolower(trim((string)($_POST['portal'] ?? '')));
        $allowedCodes = array_map(static fn($portal) => (string)$portal['portal_code'], $portals);
        if (in_array($selectedPortal, $allowedCodes, true) && wuc_user_has_portal_access($db, $userId, $selectedPortal)) {
            try {
                wuc_redirect(wuc_apply_portal_switch($db, $selectedPortal, null));
            } catch (Throwable $e) {
                error_log('portal_selection.php failed: ' . $e->getMessage());
                $error = $e instanceof DomainException
                    ? $e->getMessage()
                    : 'You do not have permission to access the selected portal.';
            }
        } else {
            $error = 'You do not have permission to access the selected portal.';
        }
    }
}

if (count($portals) === 1 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && empty($error)) {
    try {
        wuc_redirect(wuc_apply_portal_switch($db, (string)$portals[0]['portal_code'], null));
    } catch (Throwable $e) {
        error_log('portal_selection.php auto-switch failed: ' . $e->getMessage());
        $error = $e instanceof DomainException
            ? $e->getMessage()
            : 'Unable to open your assigned portal. Please contact the system administrator.';
    }
}

// Access-denied guards across the portal redirect here with a session flash;
// surface it once the page actually renders (auto-switch above keeps the flash
// for the destination page instead).
$flashError = '';
foreach (['errorMessage', 'errorMssg'] as $flashKey) {
    if (!empty($_SESSION[$flashKey])) {
        $flashError = (string)$_SESSION[$flashKey];
        unset($_SESSION[$flashKey]);
    }
}

$csrfToken = wuc_csrf_token();
$displayName = (string)($_SESSION['user_name'] ?? $_SESSION['user_id'] ?? 'User');
// Role-aware card copy: DB portals.description is staff-admin oriented and must
// not be shown verbatim to students/lecturers on the picker.
$portalMeta = [
    'academic' => ['icon' => 'fa-building-columns', 'accent' => 'academic', 'summary' => 'Admissions, registration, finance, results, reports, library, HOS and staff administration.'],
    'elearning' => ['icon' => 'fa-laptop-file', 'accent' => 'elearning', 'summary' => 'Teaching and learning workspace for courses, lessons, assignments, quizzes, discussions and progress.'],
    'library' => ['icon' => 'fa-book-open', 'accent' => 'library', 'summary' => 'Library resources, loans, fines, repositories and digital collections.'],
    'applicant' => ['icon' => 'fa-file-signature', 'accent' => 'applicant', 'summary' => 'Applications, admissions requirements and applicant follow-up.'],
    'alumni' => ['icon' => 'fa-graduation-cap', 'accent' => 'alumni', 'summary' => 'Graduate services, certificates and alumni engagement.'],
    'employer' => ['icon' => 'fa-briefcase', 'accent' => 'employer', 'summary' => 'Employer placements, internships and recruitment services.'],
];
if ($userKind === 'student') {
    $portalMeta['academic']['summary'] = 'Registration, fees, results, timetable and student academic services.';
    $portalMeta['elearning']['summary'] = 'Your courses, lessons, assignments, quizzes and learning progress.';
    $portalMeta['alumni']['summary'] = 'Graduate records, certificates and alumni services.';
    $portalMeta['library']['summary'] = 'Borrowing, digital collections and library account services.';
} elseif ($isLecturer) {
    $portalMeta['academic']['summary'] = 'Class lists, marks entry, CA upload, timetable and teaching tools.';
    $portalMeta['elearning']['summary'] = 'Course materials, assignments, quizzes, discussions and learner progress.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Portal | ITC Portal</title>
<?php require_once __DIR__ . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --portal-purple: #6f42c1;
            --portal-purple-dark: #5a32a3;
            --portal-ink: #1f2937;
            --portal-muted: #64748b;
            --portal-border: #e2e8f0;
        }
        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 18px;
            background: linear-gradient(135deg, #f7f8fc 0%, #edf1f8 100%);
            font-family: Inter, "Segoe UI", system-ui, sans-serif;
            color: var(--portal-ink);
        }
        .portal-shell {
            width: min(960px, 100%);
            background: #fff;
            border: 1px solid var(--portal-border);
            border-radius: 16px;
            box-shadow: 0 18px 55px rgba(35, 42, 70, 0.12);
            padding: 32px;
        }
        .portal-header {
            text-align: center;
            margin-bottom: 26px;
        }
        .portal-header .wuc-logo-frame {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            margin: 0 auto 14px;
            min-height: 72px;
        }
        .portal-header img,
        .portal-header .wuc-logo-img {
            display: block;
            max-width: 220px;
            width: auto;
            height: auto;
            max-height: 72px;
            margin: 0 auto;
            object-fit: contain;
            object-position: center center;
        }
        .portal-header h1 {
            margin: 0 0 4px;
            font-size: 1.55rem;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: 0;
        }
        .portal-header p {
            margin: 0 auto;
            color: var(--portal-muted);
            font-size: 0.92rem;
            max-width: 600px;
            line-height: 1.5;
        }
        .portal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 14px;
        }
        .portal-card {
            width: 100%;
            min-height: 182px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
            border: 1px solid var(--portal-border);
            border-radius: 8px;
            background: #fff;
            padding: 18px;
            text-align: left;
            color: inherit;
            transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease;
        }
        .portal-card:hover,
        .portal-card:focus-visible {
            border-color: rgba(111, 66, 193, .55);
            box-shadow: 0 12px 30px rgba(111, 66, 193, .13);
            transform: translateY(-2px);
            outline: none;
        }
        .portal-icon {
            width: 46px;
            height: 46px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: linear-gradient(135deg, var(--portal-purple), var(--portal-purple-dark));
            font-size: 1.15rem;
        }
        .portal-card.elearning .portal-icon { background: linear-gradient(135deg, #0f766e, #0e7490); }
        .portal-card.library .portal-icon { background: linear-gradient(135deg, #b45309, #c2410c); }
        .portal-name {
            margin: 0;
            font-size: 1.02rem;
            font-weight: 800;
            line-height: 1.25;
        }
        .portal-desc {
            margin: 0;
            color: var(--portal-muted);
            font-size: .84rem;
            line-height: 1.45;
        }
        .portal-open {
            margin-top: auto;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--portal-purple);
            font-size: .82rem;
            font-weight: 750;
        }
        .portal-footer {
            display: flex;
            justify-content: center;
            margin-top: 24px;
        }
        .portal-footer a {
            color: var(--portal-muted);
            text-decoration: none;
            font-weight: 650;
            font-size: .88rem;
        }
        .portal-footer a:hover { color: #dc2626; }
        @media (max-width: 640px) {
            .portal-shell { padding: 22px; }
            .portal-header img,
            .portal-header .wuc-logo-img { max-height: 56px; }
        }
    </style>
</head>
<body>
    <main class="portal-shell">
        <div class="portal-header">
            <span class="wuc-logo-frame">
                <img src="/wucportal/images/itc_logo.png" alt="ITC Logo" class="wuc-logo-img">
            </span>
            <h1>Choose Portal</h1>
            <p>Welcome, <?= htmlspecialchars($displayName) ?>. Select where you want to work.</p>
        </div>

        <?php if (!empty($flashError)): ?>
            <div class="alert alert-warning" role="alert"><?= htmlspecialchars($flashError) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" class="portal-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <?php foreach ($portals as $portal): ?>
                <?php
                $code = (string)$portal['portal_code'];
                $meta = $portalMeta[$code] ?? ['icon' => 'fa-table-columns', 'accent' => 'academic', 'summary' => (string)($portal['description'] ?? '')];
                $dbDesc = trim((string)($portal['description'] ?? ''));
                // Prefer audience-specific summary over shared DB description.
                $desc = trim((string)($meta['summary'] ?? '')) !== '' ? (string)$meta['summary'] : $dbDesc;
                ?>
                <button type="submit" name="portal" value="<?= htmlspecialchars($code) ?>" class="portal-card <?= htmlspecialchars($meta['accent']) ?>">
                    <span class="portal-icon"><i class="fas <?= htmlspecialchars($meta['icon']) ?>"></i></span>
                    <span class="portal-name"><?= htmlspecialchars((string)$portal['portal_name']) ?></span>
                    <span class="portal-desc"><?= htmlspecialchars($desc) ?></span>
                    <span class="portal-open">Open portal <i class="fas fa-arrow-right"></i></span>
                </button>
            <?php endforeach; ?>

        </form>

        <div class="portal-footer">
            <a href="/wucportal/logout.php?to=<?= htmlspecialchars($userKind) ?>">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </main>
</body>
</html>
