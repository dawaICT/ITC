<?php
require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/includes/portal_access.php';

// Parallel eLearning entry point. Uses the SAME student credentials and the SAME
// session as the main portal (student_login.php sets $_SESSION['Sid']). The only
// difference from student_login.php is the branding and the post-login landing
// page: students drop straight into the Learning Hub instead of the dashboard.
$ELEARNING_DEST = 'students/elearning/index.php';

// Already signed in? Go straight to the Learning Hub — shared session means no
// second login is ever needed between the portal and eLearning.
if (!empty($_SESSION['Sid'])) {
    $sessionUserId = (int)($_SESSION['user_id_db'] ?? 0);
    if ($sessionUserId <= 0) {
        $sessionUserId = wuc_resolve_session_user_id($db);
        if ($sessionUserId > 0) {
            $_SESSION['user_id_db'] = $sessionUserId;
        }
    }

    if ($sessionUserId > 0 && !wuc_user_has_portal_access($db, $sessionUserId, 'elearning')) {
        try {
            wuc_grant_user_portal_access($db, $sessionUserId, ['elearning'], 'elearning_login');
        } catch (Throwable $e) {
            error_log('eLearning portal grant failed for session user ' . $sessionUserId . ': ' . $e->getMessage());
        }
    }

    if ($sessionUserId > 0 && wuc_user_has_portal_access($db, $sessionUserId, 'elearning')) {
        $_SESSION['current_portal'] = 'elearning';
        wuc_redirect($ELEARNING_DEST);
    }

    // Access still blocked after grant attempt — show the form with an error
    // (do not redirect to this same page; that would loop).
    $_SESSION['errorMssg'] = 'Your account is active, but eLearning access has not been assigned. Please contact the Registrar or eLearning Administrator.';
}

$csrf_token = wuc_csrf_token();

// Consolidate legacy flash error keys into the canonical key this page renders.
foreach (['errorMessage', 'loginSuperadmin'] as $legacyKey) {
    if (!empty($_SESSION[$legacyKey]) && empty($_SESSION['errorMssg'])) {
        $_SESSION['errorMssg'] = (string) $_SESSION[$legacyKey];
    }
    unset($_SESSION[$legacyKey]);
}

wuc_security_headers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="eLearning sign-in for the Industrial Training Centre (ITC) Lusaka. Access course materials, live sessions, assignments, quizzes and recordings using your student portal credentials.">
    <title>eLearning Login | ITC Portal</title>
<?php require_once __DIR__ . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="css/wuc-premium.css?v=20260613">
    <script src="js/wuc-premium.js?v=20260613" defer></script>
</head>
<body class="login-page student-login elearning-login">
    <!-- Top-left branding (links back to the public ITC website) -->
    <a class="top-brand" href="http://localhost/itc-website/index.php" title="Back to ITC website" style="text-decoration:none;gap:9px;">
        <img src="images/itc_logo.png" alt="Industrial Training Centre - Ministry of Technology and Science">
        <span style="display:inline-flex;align-items:center;gap:5px;font-size:0.72rem;font-weight:600;color:#0f2742;"><i class="fas fa-arrow-left"></i> Website</span>
    </a>

    <!-- Switch back to the standard portal login -->
    <a href="student_login.php" class="corner-switch" aria-label="Switch to portal login">
        <i class="fas fa-table-columns"></i> Portal Login
    </a>

    <div class="slideshow" id="slideshow">
        <div class="slide active" data-index="0">
            <img src="images/slide_computer.png" alt="Online Learning Hub">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-laptop-code"></i> Learning Hub</div>
                <h2 class="slide-title">Your Courses, Online</h2>
                <p class="slide-desc">Reach every course in one place — lecture materials, recorded classes, and a dashboard built around what you are studying right now.</p>
            </div>
        </div>
        <div class="slide" data-index="1">
            <img src="images/slide_shortcourses.png" alt="Live Online Sessions">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-video"></i> Live Sessions</div>
                <h2 class="slide-title">Join Live Classes</h2>
                <p class="slide-desc">Connect to scheduled live lessons and catch up later with recordings whenever it suits your timetable.</p>
            </div>
        </div>
        <div class="slide" data-index="2">
            <img src="images/slide_graduation.png" alt="Assignments and Quizzes">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-clipboard-check"></i> Assignments &amp; Quizzes</div>
                <h2 class="slide-title">Submit &amp; Track Progress</h2>
                <p class="slide-desc">Hand in assignments, take quizzes, join the discussion forum, and watch your learning progress build across the term.</p>
            </div>
        </div>
    </div>

    <!-- Slide indicators -->
    <div class="slide-indicators" id="indicators"></div>

    <!-- ── MAIN CONTENT ── -->
    <div class="page-wrapper">
        <div class="login-card">
            <div class="card-logo">
                <img src="images/itc_logo.png" alt="Industrial Training Centre - Ministry of Technology and Science">
            </div>

            <div class="card-header">
                <div class="logo-mark">
                    <i class="fas fa-laptop"></i>
                </div>
                <div class="card-brand">
                    <h1>eLearning</h1>
                    <span>Sign in to your Learning Hub</span>
                </div>
            </div>

            <?php if (isset($_SESSION['errorMssg'])): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?= htmlspecialchars($_SESSION['errorMssg']) ?></div>
                </div>
                <?php unset($_SESSION['errorMssg']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['loginStudent'])): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle"></i>
                    <div><?= htmlspecialchars($_SESSION['loginStudent']) ?></div>
                </div>
                <?php unset($_SESSION['loginStudent']); ?>
            <?php endif; ?>

            <section class="form-section" aria-label="eLearning login form">
                <div class="login-context">
                    <span><i class="fas fa-shield-halved"></i> Same portal credentials</span>
                    <span><i class="fas fa-clock"></i> 30 minute idle timeout</span>
                </div>
                <form method="post" action="student_login.php" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="login" value="1">
                    <input type="hidden" name="next" value="<?= htmlspecialchars($ELEARNING_DEST) ?>">

                    <div class="form-group">
                        <label for="Sid">Student ID</label>
                        <div class="input-wrap">
                            <i class="fas fa-id-badge"></i>
                            <input type="text" id="Sid" name="Sid" placeholder="e.g. CSE26456789" required autocomplete="username">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="Password">Password</label>
                        <div class="input-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="Password" name="Password" placeholder="Enter your password" required autocomplete="current-password">
                            <button type="button" class="toggle-pw" id="togglePassword" aria-label="Toggle password visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-login">
                        <i class="fas fa-laptop"></i><span>Enter eLearning</span>
                    </button>

                    <div class="helper-row">
                        <a href="studentPasswordReset.php"><i class="fas fa-key"></i> Set / Reset Password</a>
                        <span>Use your portal login.</span>
                    </div>
                </form>
            </section>

            <div class="divider">Need the full portal?</div>

            <div class="stats-row">
                <a href="student_login.php" class="stat-chip" style="text-decoration:none;cursor:pointer;">
                    <span class="num"><i class="fas fa-table-columns"></i></span>
                    <span class="lbl">Portal Login</span>
                </a>
                <div class="stat-chip">
                    <span class="num"><i class="fas fa-video"></i></span>
                    <span class="lbl">Live Classes</span>
                </div>
                <div class="stat-chip">
                    <span class="num"><i class="fas fa-clipboard-check"></i></span>
                    <span class="lbl">Quizzes</span>
                </div>
            </div>

            <p style="text-align:center;margin:0.5rem 0 0;">
                <a href="http://localhost/itc-website/index.php" style="font-size:0.72rem;font-weight:600;color:rgba(255,255,255,0.55);text-decoration:none;"><i class="fas fa-arrow-left"></i> Back to Website</a>
            </p>
            <p class="footer-note">Ministry of Technology &amp; Science - TEVETA Accredited - Est. 1986 Lusaka, Zambia</p>
        </div>
    </div>

    <script src="assets/js/main.js"></script>
</body>
</html>
