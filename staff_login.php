<?php
// Canonical staff authentication endpoint: render on GET and process on POST.
// staffLogin.php remains as a compatibility endpoint for older integrations.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require __DIR__ . '/staffLogin.php';
    exit;
}

require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
$csrf_token = wuc_csrf_token();

// Consolidate flash errors set by any module into one canonical key.
// FIX: Other modules across the codebase set $_SESSION['errorMssg'] (auth_check.php,
// role_helpers.php, admin pages) and the legacy $_SESSION['loginSuperadmin']. Without
// merging them here, "Access denied"/"Please log in" messages from those modules were
// silently dropped after the redirect to this page.
foreach (['errorMssg', 'loginSuperadmin'] as $legacyKey) {
    if (!empty($_SESSION[$legacyKey]) && empty($_SESSION['errorMessage'])) {
        $_SESSION['errorMessage'] = (string) $_SESSION[$legacyKey];
    }
    unset($_SESSION[$legacyKey]);
}

if (!isset($_SESSION['errorMessage'])) {
    if (isset($_GET['error']) && $_GET['error'] === 'unauthorized') {
        $_SESSION['errorMessage'] = 'Unauthorized access. Please log in as a staff member.';
    } elseif (isset($_GET['timeout'])) {
        $_SESSION['errorMessage'] = 'Session timed out. Please log in again.';
    }
}

wuc_security_headers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Staff portal login for the Industrial Training Centre (ITC) Lusaka, Zambia. Access class lists, assessments, finance tools and reports.">
    <title>Staff Login | ITC Portal</title>
<?php require_once __DIR__ . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="css/wuc-premium.css?v=20260613">
    <script src="js/wuc-premium.js?v=20260613" defer></script>
</head>
<body class="login-page staff-login">
    <div class="top-brand">
        <img src="images/itc_logo.png" alt="Industrial Training Centre - Ministry of Technology and Science">
    </div>

    <!-- ── SLIDESHOW ── -->
    <a href="student_login.php" class="corner-switch" aria-label="Switch to student login">
        <i class="fas fa-user-graduate"></i> Student Login
    </a>

    <div class="slideshow" id="slideshow">
        <div class="slide active" data-index="0">
            <img src="images/slide_campus.jpg" alt="ITC Campus Lusaka">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-building"></i> Administration</div>
                <h2 class="slide-title">Welcome to ITC Staff Portal</h2>
                <p class="slide-desc">Manage academic operations, student records, and institutional processes. Built for lecturers, HOS, registrars, and administrative staff across all departments.</p>
            </div>
        </div>
        <div class="slide" data-index="1">
            <img src="images/slide_automotive.jpg" alt="Engineering Workshops">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-chalkboard-teacher"></i> Academic Management</div>
                <h2 class="slide-title">Engineering & ICT Departments</h2>
                <p class="slide-desc">Oversee Motor Vehicle Engineering, Computer Systems, Power Electrical, and Electronics programmes. Manage marks entry, course assignments, and exam schedules.</p>
            </div>
        </div>
        <div class="slide" data-index="2">
            <img src="images/slide_electrical.jpg" alt="Technical Training Oversight">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-clipboard-check"></i> Assessment Tools</div>
                <h2 class="slide-title">TEVETA-Aligned Assessment System</h2>
                <p class="slide-desc">Continuous assessment tracking, exam result processing, and transcript generation aligned with TEVETA and City & Guilds accreditation standards.</p>
            </div>
        </div>
        <div class="slide" data-index="3">
            <img src="images/slide_graduation.jpg" alt="Student Success">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-chart-line"></i> Institutional Reports</div>
                <h2 class="slide-title">Data-Driven Decision Making</h2>
                <p class="slide-desc">Generate departmental dashboards, enrollment analytics, fee reconciliation reports, and student performance summaries for informed institutional planning.</p>
            </div>
        </div>
        <div class="slide" data-index="4">
            <img src="images/slide_driving.jpg" alt="Professional Driving Training">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-truck"></i> Transport & Logistics</div>
                <h2 class="slide-title">Professional Driving & Logistics</h2>
                <p class="slide-desc">Manage Class B/C/EC driving programmes, defensive driving courses, chauffeur training, motorbike riding, and the UNZA-affiliated Diploma in Transport & Logistics.</p>
            </div>
        </div>
        <div class="slide" data-index="5">
            <img src="images/slide_shortcourses.jpg" alt="Short Intensive Courses">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-certificate"></i> Short Intensive Courses</div>
                <h2 class="slide-title">5-20 Day Upskilling Programmes</h2>
                <p class="slide-desc">Administer OHS, first aid, scaffolding, excavator operation, PLC, solar technology, basic domestic wiring, fibre optics, and programming bootcamps.</p>
            </div>
        </div>
        <div class="slide" data-index="6">
            <img src="images/slide_welding.jpg" alt="Welding & Fabrication">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-fire"></i> Industrial Workshops</div>
                <h2 class="slide-title">Welding, Rigging & Heavy Equipment</h2>
                <p class="slide-desc">Oversee MIG/TIG welding, scaffolding, rigging, and heavy equipment operation training. Monitor student progress and workshop safety compliance.</p>
            </div>
        </div>
        <div class="slide" data-index="7">
            <img src="images/slide_computer.jpg" alt="Digital Systems">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-laptop-code"></i> ICT & E-Learning</div>
                <h2 class="slide-title">Computer Systems & Digital Tools</h2>
                <p class="slide-desc">Manage LAN admin, fibre optics, server management, and programming courses. Access e-learning platform, upload materials, and track digital assessments.</p>
            </div>
        </div>
        <div class="slide" data-index="8">
            <img src="images/slide_mechanical.jpg" alt="Mechanical Engineering Workshop">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-wrench"></i> Hands-On Training</div>
                <h2 class="slide-title">Mechanical Assembly & Maintenance</h2>
                <p class="slide-desc">Practical engine assembly, brake systems, and mechanical maintenance training. Students gain real-world experience under expert guidance in fully-equipped workshops.</p>
            </div>
        </div>
        <div class="slide" data-index="9">
            <img src="images/slide_machining.jpg" alt="Precision Machining Workshop">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-cogs"></i> Precision Engineering</div>
                <h2 class="slide-title">CNC & Manual Machining</h2>
                <p class="slide-desc">Master lathe operations, milling, and precision machining on industrial-grade equipment. TEVETA-accredited training for Zambia's manufacturing sector.</p>
            </div>
        </div>
    </div>

    <div class="slide-indicators" id="indicators"></div>

    <div class="page-wrapper">
        <div class="login-card">
            <div class="card-logo">
                <img src="images/itc_logo.png" alt="Industrial Training Centre - Ministry of Technology and Science">
            </div>

            <div class="card-header">
                <div class="logo-mark">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="card-brand">
                    <h1>Staff Portal</h1>
                    <span>Sign in to continue</span>
                </div>
            </div>

            <?php if (isset($_SESSION['errorMessage'])): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?= htmlspecialchars($_SESSION['errorMessage']) ?></div>
                </div>
                <?php unset($_SESSION['errorMessage']); ?>
            <?php endif; ?>

            <section class="form-section" aria-label="Staff login form">
                <div class="login-context">
                    <span><i class="fas fa-shield-halved"></i> Secure access</span>
                    <span><i class="fas fa-user-lock"></i> Role-based portal</span>
                </div>
                <div class="login-form-root">
                    <form method="post" action="staff_login.php" id="loginForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                        <div class="form-group">
                            <label for="user_id">Staff ID</label>
                            <div class="input-wrap">
                                <i class="fas fa-user-shield"></i>
                                <!-- minlength/maxlength match the backend regex /^[A-Za-z0-9_.@-]{3,50}$/ -->
                                <input type="text" id="user_id" name="user_id" placeholder="e.g. ITC900" required autocomplete="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.@\-]{3,50}" title="3-50 letters, digits, or _ . @ -">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="input-wrap">
                                <i class="fas fa-lock"></i>
                                <!-- FIX: removed minlength="8"; backend (wuc_password_verify_legacy) supports legacy
                                     accounts whose existing passwords may be shorter than 8 chars. The form must
                                     not block them from submitting. -->
                                <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                                <button type="button" class="toggle-pw" id="togglePassword" aria-label="Toggle password visibility">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn-login">
                            <i class="fas fa-arrow-right-to-bracket"></i><span>Sign In</span>
                        </button>

                        <div class="helper-row">
                            <a href="staff_forgot_password.php"><i class="fas fa-key"></i> Set / Reset Password</a>
                            <a href="https://wucportal.zm/contact" target="_blank" rel="noopener">Need Help?</a>
                        </div>
                    </form>
                </div>
            </section>

            <div class="divider">Quick Access After Login</div>

            <div class="quick-access">
                <div class="qa-item"><i class="fas fa-users"></i><span>Class Lists</span></div>
                <div class="qa-item"><i class="fas fa-pen-to-square"></i><span>Marks Entry</span></div>
                <div class="qa-item"><i class="fas fa-file-invoice-dollar"></i><span>Finance</span></div>
                <div class="qa-item"><i class="fas fa-chart-bar"></i><span>Reports</span></div>
            </div>

            <p class="footer-note">Ministry of Technology & Science - TEVETA Accredited - Est. 1986 Lusaka, Zambia</p>
        </div>
    </div>

    <script src="assets/js/main.js"></script>
</body>
</html>
