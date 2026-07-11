<?php require_once __DIR__ . '/includes/guard.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - ITC Portal</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 250px;
            --primary-color: #6f42c1;
            --secondary-color: #5a32a3;
            --success-color: #198754;
            --text-dark: #2c3e50;
            --text-light: #6c757d;
            --bg-light: #f8f9fa;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #f5f7fb;
            margin: 0;
            padding: 0;
            color: var(--text-dark);
            min-height: 100vh;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 2rem;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .registration-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            text-align: center;
            margin-bottom: 3rem;
            animation: fadeInDown 0.6s ease;
        }

        .page-title {
            color: var(--text-dark);
            font-weight: 800;
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--text-light);
            font-size: 1.2rem;
        }

        .header-icon {
            font-size: 3.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .registration-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            padding: 2.5rem;
            margin-bottom: 2rem;
            transition: all 0.3s ease;
            animation: fadeInUp 0.6s ease;
        }

        .registration-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.12);
        }

        .card-icon {
            width: 80px;
            height: 80px;
            background: rgba(111, 66, 193, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }

        .card-icon i {
            font-size: 2.5rem;
            color: var(--primary-color);
        }

        .card-icon.success {
            background: rgba(25, 135, 84, 0.1);
        }

        .card-icon.success i {
            color: var(--success-color);
        }

        .card-title {
            color: var(--text-dark);
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-align: center;
        }

        .card-text {
            color: var(--text-light);
            font-size: 1.1rem;
            text-align: center;
            margin-bottom: 2rem;
        }

        .btn-registration {
            display: inline-block;
            padding: 1rem 2.5rem;
            border-radius: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            border: none;
            color: white;
            text-decoration: none;
            width: 100%;
            font-size: 1rem;
        }

        .btn-registration.btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .btn-registration.btn-success {
            background: linear-gradient(135deg, #198754, #157347);
        }

        .btn-registration:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            opacity: 0.95;
            color: white;
        }

        .btn-registration:active {
            transform: translateY(0);
        }

        .btn-registration i {
            margin-right: 0.5rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .status-badge.open {
            background-color: #d1f4e0;
            color: #0f5132;
        }

        .status-badge.closed {
            background-color: #f8d7da;
            color: #842029;
        }

        .info-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            animation: fadeIn 0.8s ease;
        }

        .info-box h4 {
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .info-box p {
            margin-bottom: 0;
            opacity: 0.95;
        }

        .features-list {
            list-style: none;
            padding: 0;
            margin: 1.5rem 0;
        }

        .features-list li {
            padding: 0.75rem 0;
            padding-left: 2rem;
            position: relative;
            color: var(--text-dark);
        }

        .features-list li:before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 0;
            color: var(--success-color);
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
        }

        @media (max-width: 768px) {
            .page-title {
                font-size: 2rem;
            }

            .registration-card {
                padding: 1.5rem;
            }
        }

        .loading-spinner {
            display: none;
            text-align: center;
            padding: 2rem;
        }

        .loading-spinner.active {
            display: block;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="main-content">
        <div class="registration-container">
            <!-- Page Header -->
            <div class="page-header">
                <i class="fas fa-graduation-cap header-icon"></i>
                <h1 class="page-title">Semester Registration</h1>
                <p class="page-subtitle">Choose your registration type to begin</p>
            </div>

            <!-- Registration Status Info -->
            <div id="registrationStatus" class="info-box">
                <div class="loading-spinner active">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p class="mt-2">Loading registration status...</p>
                </div>
            </div>

            <!-- Registration Options -->
            <div class="row g-4">
                <!-- New Student Registration -->
                <div class="col-lg-6">
                    <div class="registration-card">
                        <div class="card-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h3 class="card-title">New Student</h3>
                        <p class="card-text">First time registration for new students</p>
                        
                        <ul class="features-list">
                            <li>Create your student profile</li>
                            <li>Select your program</li>
                            <li>Register for courses</li>
                            <li>Generate fee invoice</li>
                        </ul>
                        
                        <a href="new_student_registration.php" class="btn-registration btn-primary">
                            <i class="fas fa-arrow-right"></i> Register as New Student
                        </a>
                    </div>
                </div>

                <!-- Returning Student Registration -->
                <div class="col-lg-6">
                    <div class="registration-card">
                        <div class="card-icon success">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <h3 class="card-title">Returning Student</h3>
                        <p class="card-text">Semester registration for existing students</p>
                        
                        <ul class="features-list">
                            <li>View registration history</li>
                            <li>Register for new semester</li>
                            <li>Manage course selections</li>
                            <li>Track registration status</li>
                        </ul>
                        
                        <a href="searchReturning_Stud.php" class="btn-registration btn-success">
                            <i class="fas fa-arrow-right"></i> Continue Registration
                        </a>
                    </div>
                </div>
            </div>

            <!-- Additional Information -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="alert alert-info" role="alert">
                        <h5 class="alert-heading"><i class="fas fa-info-circle"></i> Important Information</h5>
                        <ul class="mb-0">
                            <li>Ensure all financial obligations from previous semesters are cleared before registration</li>
                            <li>Review the course catalog and prerequisites before selecting courses</li>
                            <li>Minimum 12 credits and maximum 21 credits allowed per semester</li>
                            <li>Registration must be completed before the deadline to avoid late fees</li>
                            <li>Contact the registrar's office for any registration assistance</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Registration status check
        async function checkRegistrationStatus() {
            try {
                const response = await fetch('../api/registration.php/check-status');
                const data = await response.json();
                
                const statusBox = document.getElementById('registrationStatus');
                const spinner = statusBox.querySelector('.loading-spinner');
                
                if (data.success) {
                    const session = data.session;
                    const isOpen = data.is_open;
                    
                    statusBox.innerHTML = `
                        <h4><i class="fas fa-calendar-alt"></i> ${session ? session.session_name : 'No Active Session'}</h4>
                        <p>
                            <span class="status-badge ${isOpen ? 'open' : 'closed'}">
                                ${isOpen ? 'Registration Open' : 'Registration Closed'}
                            </span>
                        </p>
                        ${session && session.registration_start_date && session.registration_end_date ? `
                            <p class="mb-0">
                                <small>
                                    Registration Period: ${formatDate(session.registration_start_date)} - ${formatDate(session.registration_end_date)}
                                </small>
                            </p>
                        ` : ''}
                    `;
                } else {
                    statusBox.innerHTML = `
                        <h4><i class="fas fa-exclamation-triangle"></i> Registration Status Unavailable</h4>
                        <p class="mb-0">Unable to load registration status. Please contact support.</p>
                    `;
                }
            } catch (error) {
                console.error('Error checking registration status:', error);
                const statusBox = document.getElementById('registrationStatus');
                statusBox.innerHTML = `
                    <h4><i class="fas fa-exclamation-triangle"></i> Connection Error</h4>
                    <p class="mb-0">Unable to connect to registration system. Please try again later.</p>
                `;
            }
        }
        
        // Format date helper
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
        }
        
        // Load status on page load
        document.addEventListener('DOMContentLoaded', checkRegistrationStatus);
    </script>
</body>
</html>
