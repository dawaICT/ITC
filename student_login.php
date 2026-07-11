<?php
// Canonical student authentication endpoint: render on GET and process on POST.
// studentLogin.php remains as a compatibility endpoint for older integrations.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require __DIR__ . '/studentLogin.php';
    exit;
}

require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
$csrf_token = wuc_csrf_token();
$requested_next = trim((string) ($_GET['redirect'] ?? ''));

// FIX: Consolidate any flash error keys set by other modules into the canonical
// $_SESSION['errorMssg'] that this page already displays. Without this, messages
// set by code using $_SESSION['errorMessage'] (e.g. staff_login.php style) would
// silently disappear after a redirect here.
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
    <meta name="description" content="Student portal login for the Industrial Training Centre (ITC) Lusaka, Zambia. Access course registration, results, timetables and fees.">
    <title>Student Login | ITC Portal</title>
<?php require_once __DIR__ . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/wuc-premium.css?v=20260613">
    <link rel="stylesheet" href="assets/css/login.css?v=20260702">
    <script src="js/wuc-premium.js?v=20260613" defer></script>
</head>
<body class="login-page student-login">
    <!-- Top-left branding (links back to the public ITC website) -->
    <a class="top-brand" href="http://localhost/itc-website/index.php" title="Back to ITC website" style="text-decoration:none;gap:9px;">
        <img src="images/itc_logo.png" alt="Industrial Training Centre - Ministry of Technology and Science">
        <span style="display:inline-flex;align-items:center;gap:5px;font-size:0.72rem;font-weight:600;color:#0f2742;"><i class="fas fa-arrow-left"></i> Website</span>
    </a>

    <!-- ── SLIDESHOW ── -->
    <a href="staff_login.php" class="corner-switch" aria-label="Switch to staff login">
        <i class="fas fa-user-tie"></i> Staff Login
    </a>

    <div class="slideshow" id="slideshow">
        <div class="slide active" data-index="0">
            <img src="images/slide_automotive.jpg" alt="Motor Vehicle Engineering Workshop">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-cog"></i> Engineering Department</div>
                <h2 class="slide-title">Motor Vehicle Engineering</h2>
                <p class="slide-desc">TEVETA-accredited diploma and technician certificate programmes. Master automotive diagnostics, engine systems, and modern vehicle technology in our fully-equipped workshops.</p>
            </div>
        </div>
        <div class="slide" data-index="1">
            <img src="images/slide_electrical.jpg" alt="Power Electrical & Electronics Training">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-bolt"></i> Electrical & Electronics</div>
                <h2 class="slide-title">Power Electrical & Auto-Electronics</h2>
                <p class="slide-desc">Craft certificates in Power Electrical, Auto-Electrical & Electronics, and Telecommunications. Hands-on training with circuit design, PLC systems, and industrial wiring.</p>
            </div>
        </div>
        <div class="slide" data-index="2">
            <img src="images/slide_computer.jpg" alt="Computer Systems Engineering Lab">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-laptop-code"></i> ICT Department</div>
                <h2 class="slide-title">Computer Systems Engineering</h2>
                <p class="slide-desc">2-year diploma covering LAN administration, fibre optics, server management, Python, Java, C++ programming, and modern web technologies. City & Guilds affiliated.</p>
            </div>
        </div>
        <div class="slide" data-index="3">
            <img src="images/slide_welding.jpg" alt="Welding and Fabrication Workshop">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-fire"></i> Industrial Skills</div>
                <h2 class="slide-title">Welding, Rigging & Heavy Equipment</h2>
                <p class="slide-desc">Short intensive courses in MIG/TIG welding, scaffolding, excavator operation, and occupational health & safety. Industry-ready skills in 10-20 days.</p>
            </div>
        </div>
        <div class="slide" data-index="4">
            <img src="images/slide_driving.jpg" alt="Professional Driving Training">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-truck"></i> Transport & Logistics</div>
                <h2 class="slide-title">Professional Driving & Transport</h2>
                <p class="slide-desc">Class B, C, and EC professional driving courses, defensive driving, chauffeur training, and motorbike riding. Plus a 3-year UNZA-affiliated Diploma in Transport and Logistics.</p>
            </div>
        </div>
        <div class="slide" data-index="5">
            <img src="images/slide_shortcourses.jpg" alt="Short Intensive Courses">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-certificate"></i> Short Intensive Courses</div>
                <h2 class="slide-title">Upskill in 5-20 Days</h2>
                <p class="slide-desc">Occupational Health & Safety, First Aid, scaffolding, rigging, excavator & front-end loader operation, solar technology, basic domestic wiring, PLC, and programming bootcamps.</p>
            </div>
        </div>
        <div class="slide" data-index="6">
            <img src="images/slide_graduation.jpg" alt="ITC Graduation Ceremony">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-graduation-cap"></i> Student Success</div>
                <h2 class="slide-title">Building Zambia's Skilled Workforce</h2>
                <p class="slide-desc">Since 1986, ITC has produced thousands of qualified craftsmen for Zambia's manufacturing, transport, and service industries. Our graduates power the nation's growth.</p>
            </div>
        </div>
        <div class="slide" data-index="7">
            <img src="images/slide_campus.jpg" alt="ITC Campus Lusaka">
            <div class="slide-overlay"></div>
            <div class="slide-content">
                <div class="slide-tag"><i class="fas fa-university"></i> Our Campus</div>
                <h2 class="slide-title">Buyantanshi Road, Heavy Industrial Area</h2>
                <p class="slide-desc">Located in Lusaka's industrial heartland, our campus features purpose-built workshops, modern computer labs, and dedicated training bays. A Zambia-Germany partnership since 1986.</p>
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
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="card-brand">
                    <h1>Student Portal</h1>
                    <span>Sign in to continue</span>
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

            <section class="form-section" aria-label="Student login form">
                <div class="login-context">
                    <span><i class="fas fa-shield-halved"></i> Secure access</span>
                    <span><i class="fas fa-clock"></i> 5 minute idle timeout</span>
                </div>
                <div id="react-login-root">
                    <form method="post" action="student_login.php" id="loginForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="login" value="1">
                        <?php if ($requested_next !== ''): ?>
                            <input type="hidden" name="next" value="<?= htmlspecialchars($requested_next) ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="Sid">Student ID</label>
                            <div class="input-wrap">
                                <i class="fas fa-id-badge"></i>
                                <input type="text" id="Sid" name="Sid" placeholder="e.g. CSE26456789" required autocomplete="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.@\-]{3,50}" title="3-50 letters, digits, or _ . @ -">
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
                            <i class="fas fa-arrow-right-to-bracket"></i><span>Sign In</span>
                        </button>

                        <div class="helper-row">
                            <a href="studentPasswordReset.php"><i class="fas fa-key"></i> Set / Reset Password</a>
                            <a href="elearning_login.php"><i class="fas fa-laptop"></i> eLearning Sign-in</a>
                        </div>
                    </form>
                </div>
            </section>

            <div class="divider">ITC at a glance</div>

            <div class="stats-row">
                <div class="stat-chip">
                    <span class="num">38+</span>
                    <span class="lbl">Years</span>
                </div>
                <div class="stat-chip">
                    <span class="num">20+</span>
                    <span class="lbl">Courses</span>
                </div>
                <div class="stat-chip">
                    <span class="num">5K+</span>
                    <span class="lbl">Graduates</span>
                </div>
            </div>

            <p style="text-align:center;margin:0.5rem 0 0;">
                <a href="http://localhost/itc-website/index.php" style="font-size:0.72rem;font-weight:600;color:rgba(255,255,255,0.55);text-decoration:none;"><i class="fas fa-arrow-left"></i> Back to Website</a>
            </p>
            <p class="footer-note">Ministry of Technology & Science - TEVETA Accredited - Est. 1986 Lusaka, Zambia</p>
        </div>
    </div>

    <script src="assets/js/main.js"></script>
</body>
</html>
