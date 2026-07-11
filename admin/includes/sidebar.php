<?php
/**
 * Admin Sidebar with Role-Based Access Control
 * 
 * Displays navigation menu items based on the user's role.
 * Integrates with the access_right table for role management.
 * 
 * @version 2.0
 * @updated 2026-02-03
 */

// Include role helpers
require_once dirname(__FILE__) . '/../../includes/role_helpers.php';

// Fetch current user for sidebar footer
$user_name = 'Guest';
$user_role_display = 'Guest';
$user_role = $_SESSION['role'] ?? '';

if (isset($_SESSION['staff_id'])) {
    $stmt = $db->prepare("SELECT s.title, s.Fname, s.Lname, ar.assigned_access 
                          FROM staff s 
                          LEFT JOIN access_right ar ON s.staff_id = ar.staff_id 
                          WHERE s.staff_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $_SESSION['staff_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_object()) {
            $user_name = trim(($row->title ?? '') . ' ' . ($row->Fname ?? '') . ' ' . ($row->Lname ?? ''));
            $user_role_display = getRoleDisplayName($row->assigned_access);
            // Update session role if not set
            if (empty($_SESSION['role'])) {
                $_SESSION['role'] = normalizeRole($row->assigned_access);
                $_SESSION['role_raw'] = $row->assigned_access;
            }
            $user_role = $_SESSION['role'];
        }
        $stmt->close();
    } else {
        error_log("Sidebar user query prepare failed: " . $db->error);
    }
}

// Define current page variables for active state handling
$current_page = basename($_SERVER['PHP_SELF']);
$is_elearning = strpos($_SERVER['REQUEST_URI'], '/elearning/') !== false;
$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$is_transport = strpos($request_path, '/wucportal/transport/') === 0;
?>

<nav class="sidebar" id="sidebar">
	<div class="sidebar-header">
		<div class="logo-container">
			<img src="<?php echo $base_url; ?>/images/favicon.png" alt="ITC Logo" class="logo">
			<span class="logo-text">ITC</span>
		</div>
	</div>

	<div class="sidebar-content">
		<div class="nav-section">
			<div class="nav-section-title">Main</div>
			<a href="<?php echo $base_url; ?>/index.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
				<i class="fas fa-home"></i>
				<span>Dashboard</span>
			</a>
			<a href="<?php echo $base_url; ?>/analytics_dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'analytics_dashboard.php' ? 'active' : ''; ?>">
				<i class="fas fa-chart-bar"></i>
				<span>Decision Support</span>
			</a>
		</div>

		<div class="nav-section">
			<div class="nav-section-title">Departments</div>
			<a href="<?php echo $base_url; ?>/departments.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'departments.php' ? 'active' : ''; ?>">
				<i class="fas fa-building"></i>
				<span>Departments</span>
			</a>
		</div>
		
		<?php if (canAccessAdmissions()): ?>
		<div class="nav-section">
			<div class="nav-section-title">Admissions</div>
			<a href="<?php echo $base_url; ?>/applicants.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'applicants.php' ? 'active' : ''; ?>">
				<i class="fas fa-user-plus"></i>
				<span>Applicants</span>
			</a>
			<a href="<?php echo $base_url; ?>/manage_admitted_students.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'manage_admitted_students.php' ? 'active' : ''; ?>">
				<i class="fas fa-user-check"></i>
				<span>Admitted Students</span>
			</a>
			<a href="<?php echo $base_url; ?>/processedApp.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'processedApp.php' ? 'active' : ''; ?>">
				<i class="fas fa-tasks"></i>
				<span>Processed Applications</span>
			</a>
		</div>
		<?php endif; ?>

		<?php if (canAccessAcademics()): ?>
		<div class="nav-section">
			<div class="nav-section-title">Academics</div>
			<a href="<?php echo $base_url; ?>/course_program_mgmt.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'course_program_mgmt.php' ? 'active' : ''; ?>">
				<i class="fas fa-sitemap"></i>
				<span>Academic Structure</span>
			</a>
		</div>

		<div class="nav-section">
			<div class="nav-section-title">Catalogue &amp; Structure</div>
			<a href="<?php echo $base_url; ?>/programs.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'programs.php' ? 'active' : ''; ?>">
				<i class="fas fa-graduation-cap"></i>
				<span>Programs</span>
			</a>
			<a href="<?php echo $base_url; ?>/course_catalogue.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'course_catalogue.php' ? 'active' : ''; ?>">
				<i class="fas fa-book"></i>
				<span>Course Catalogue</span>
			</a>
			<a href="<?php echo $base_url; ?>/courses.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'courses.php' ? 'active' : ''; ?>">
				<i class="fas fa-book-open"></i>
				<span>Program Courses</span>
			</a>
			<a href="<?php echo $base_url; ?>/course_prerequisites.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'course_prerequisites.php' ? 'active' : ''; ?>">
				<i class="fas fa-project-diagram"></i>
				<span>Prerequisites</span>
			</a>
		</div>

		<div class="nav-section">
			<div class="nav-section-title">Short Courses &amp; Intakes</div>
			<a href="<?php echo $base_url; ?>/short_courses.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'short_courses.php' ? 'active' : ''; ?>">
				<i class="fas fa-certificate"></i>
				<span>Training Catalogue</span>
			</a>
			<a href="<?php echo $base_url; ?>/itc_intakes.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'itc_intakes.php' ? 'active' : ''; ?>">
				<i class="fas fa-calendar-check"></i>
				<span>Training Intakes</span>
			</a>
			<a href="<?php echo $base_url; ?>/short_course_registration.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'short_course_registration.php' ? 'active' : ''; ?>">
				<i class="fas fa-user-check"></i>
				<span>Short Course Registration</span>
			</a>
		</div>

		<div class="nav-section">
			<div class="nav-section-title">Scheduling &amp; Registration</div>
			<a href="<?php echo $base_url; ?>/registration_setup.php" class="nav-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['registration_setup.php', 'setup_semester_registration.php'], true) ? 'active' : ''; ?>">
				<i class="fas fa-tools"></i>
				<span>Registration Setup</span>
			</a>
			<a href="<?php echo $base_url; ?>/semester_registration.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'semester_registration.php' ? 'active' : ''; ?>">
				<i class="fas fa-user-edit"></i>
				<span>Term Registration</span>
			</a>
			<a href="<?php echo $base_url; ?>/courseReg.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'courseReg.php' ? 'active' : ''; ?>">
				<i class="fas fa-clipboard-list"></i>
				<span>Course Enrollment</span>
			</a>
			<a href="<?php echo $base_url; ?>/timetable_settings.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'timetable_settings.php' ? 'active' : ''; ?>">
				<i class="fas fa-calendar-alt"></i>
				<span>Timetable Settings</span>
			</a>
		</div>

		<div class="nav-section">
			<div class="nav-section-title">Teaching Assignment</div>
			<a href="<?php echo $base_url; ?>/assign_course.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'assign_course.php' ? 'active' : ''; ?>">
				<i class="fas fa-chalkboard-teacher"></i>
				<span>Faculty Assignment</span>
			</a>
		</div>
		<?php endif; ?>

		<?php if (function_exists('canAccessTransport') ? canAccessTransport() : canAccessAcademics()): ?>
		<div class="nav-section">
			<div class="nav-section-title">Transport</div>
			<a href="/wucportal/transport.php" class="nav-item <?php echo $is_transport ? 'active' : ''; ?>">
				<i class="fas fa-bus"></i>
				<span>Transport Operations</span>
			</a>
		</div>
		<?php endif; ?>

		<?php if (canAccessElearning()): ?>
		<div class="nav-section">
			<div class="nav-section-title">E-Learning</div>
			<a href="<?php echo $base_url; ?>/elearning/index.php" class="nav-item <?php echo $current_page == 'index.php' && $is_elearning ? 'active' : ''; ?>">
				<i class="fas fa-chalkboard"></i>
				<span>Dashboard</span>
			</a>
			<a href="<?php echo $base_url; ?>/elearning/manage.php" class="nav-item <?php echo $current_page == 'manage.php' && $is_elearning ? 'active' : ''; ?>">
				<i class="fas fa-cogs"></i>
				<span>Course Management</span>
			</a>
			<a href="<?php echo $base_url; ?>/elearning/assessments.php" class="nav-item <?php echo $current_page == 'assessments.php' && $is_elearning ? 'active' : ''; ?>">
				<i class="fas fa-clipboard-check"></i>
				<span>Assessments</span>
			</a>
			<a href="<?php echo $base_url; ?>/elearning/sessions.php" class="nav-item <?php echo $current_page == 'sessions.php' && $is_elearning ? 'active' : ''; ?>">
				<i class="fas fa-video"></i>
				<span>Live Sessions</span>
			</a>
			<a href="<?php echo $base_url; ?>/elearning/session_analytics.php" class="nav-item <?php echo $current_page == 'session_analytics.php' && $is_elearning ? 'active' : ''; ?>">
				<i class="fas fa-clipboard-list"></i>
				<span>Class Reports</span>
			</a>
			<a href="<?php echo $base_url; ?>/elearning/forum.php" class="nav-item <?php echo $current_page == 'forum.php' && $is_elearning ? 'active' : ''; ?>">
				<i class="fas fa-comments"></i>
				<span>Discussion Forum</span>
			</a>
			<a href="<?php echo $base_url; ?>/elearning/analytics.php" class="nav-item <?php echo $current_page == 'analytics.php' && $is_elearning ? 'active' : ''; ?>">
				<i class="fas fa-chart-bar"></i>
				<span>Analytics</span>
			</a>
		</div>
		<?php endif; ?>
		
		<?php if (canAccessFinance()): ?>
		<div class="nav-section">
			<div class="nav-section-title">Finance</div>
			<a href="<?php echo $base_url; ?>/finance.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'finance.php' ? 'active' : ''; ?>">
				<i class="fas fa-money-bill-wave"></i>
				<span>Finance Dashboard</span>
			</a>
			<a href="<?php echo $base_url; ?>/financial_overview.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'financial_overview.php' ? 'active' : ''; ?>">
				<i class="fas fa-chart-line"></i>
				<span>Financial Overview</span>
			</a>
			<a href="<?php echo $base_url; ?>/fee_structure.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'fee_structure.php' ? 'active' : ''; ?>">
				<i class="fas fa-file-invoice-dollar"></i>
				<span>Fee Structure</span>
			</a>
			<a href="<?php echo $base_url; ?>/payments.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'payments.php' ? 'active' : ''; ?>">
				<i class="fas fa-cash-register"></i>
				<span>Payments</span>
			</a>
		</div>
		<?php endif; ?>

		<?php if (canAccessExams()): ?>
		<div class="nav-section">
			<div class="nav-section-title">Assessments</div>
			<a href="<?php echo $base_url; ?>/assessments.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'assessments.php' ? 'active' : ''; ?>">
				<i class="fas fa-tasks"></i>
				<span>Assessments</span>
			</a>
			<a href="<?php echo $base_url; ?>/upload_ca.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'upload_ca.php' ? 'active' : ''; ?>">
				<i class="fas fa-upload"></i>
				<span>Upload CA</span>
			</a>
		</div>

		<div class="nav-section">
			<div class="nav-section-title">Exams</div>
			<a href="<?php echo $base_url; ?>/exams.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'exams.php' ? 'active' : ''; ?>">
				<i class="fas fa-file-alt"></i>
				<span>Exams</span>
			</a>
			<?php if (function_exists('canEnterExamMarks') && canEnterExamMarks()): ?>
			<a href="<?php echo $base_url; ?>/upload_exam_results.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'upload_exam_results.php' ? 'active' : ''; ?>">
				<i class="fas fa-upload"></i>
				<span>Upload Results</span>
			</a>
			<a href="<?php echo $base_url; ?>/process_exam_results.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'process_exam_results.php' ? 'active' : ''; ?>">
				<i class="fas fa-cogs"></i>
				<span>Process Results</span>
			</a>
			<a href="<?php echo $base_url; ?>/publishResults.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'publishResults.php' ? 'active' : ''; ?>">
				<i class="fas fa-bullhorn"></i>
				<span>Publish Results</span>
			</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if (canManageStudents() || canManageStaff()): ?>
		<div class="nav-section">
			<div class="nav-section-title">Users</div>
			<?php if (canManageStudents()): ?>
			<a href="<?php echo $base_url; ?>/students_by_admin.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'students_by_admin.php' ? 'active' : ''; ?>">
				<i class="fas fa-user-graduate"></i>
				<span>Students</span>
			</a>
			<?php endif; ?>
			<?php if (canManageStaff()): ?>
			<a href="<?php echo $base_url; ?>/staff.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'staff.php' ? 'active' : ''; ?>">
				<i class="fas fa-user-tie"></i>
				<span>Staff</span>
			</a>
			<a href="<?php echo $base_url; ?>/lecturers.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'lecturers.php' ? 'active' : ''; ?>">
				<i class="fas fa-chalkboard-teacher"></i>
				<span>Lecturers</span>
			</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if (canAccessLibrary()): ?>
		<div class="nav-section">
			<div class="nav-section-title">Library</div>
			<a href="<?php echo $base_url; ?>/library.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'library.php' ? 'active' : ''; ?>">
				<i class="fas fa-book"></i>
				<span>Library</span>
			</a>
			<a href="<?php echo $base_url; ?>/library_catalog.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'library_catalog.php' ? 'active' : ''; ?>">
				<i class="fas fa-th-list"></i>
				<span>Catalog</span>
			</a>
			<a href="<?php echo $base_url; ?>/library_circulation.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'library_circulation.php' ? 'active' : ''; ?>">
				<i class="fas fa-exchange-alt"></i>
				<span>Circulation</span>
			</a>
			<a href="<?php echo $base_url; ?>/library_fines.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'library_fines.php' ? 'active' : ''; ?>">
				<i class="fas fa-coins"></i>
				<span>Fines</span>
			</a>
			<a href="<?php echo $base_url; ?>/library_digital.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'library_digital.php' ? 'active' : ''; ?>">
				<i class="fas fa-cloud"></i>
				<span>Digital Library</span>
			</a>
		</div>
		<?php endif; ?>

		<div class="nav-section">
			<div class="nav-section-title">Hostels</div>
			<a href="<?php echo $base_url; ?>/hostels.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'hostels.php' ? 'active' : ''; ?>">
				<i class="fas fa-bed"></i>
				<span>Hostels</span>
			</a>
		</div>

		<?php if (canAccessReports()): ?>
		<div class="nav-section">
			<div class="nav-section-title">Reports</div>
			<a href="<?php echo $base_url; ?>/admittedStud_report.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'admittedStud_report.php' ? 'active' : ''; ?>">
				<i class="fas fa-user-graduate"></i>
				<span>Admitted Students</span>
			</a>
			<a href="<?php echo $base_url; ?>/semesterReg_stud.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'semesterReg_stud.php' ? 'active' : ''; ?>">
				<i class="fas fa-clipboard-list"></i>
				<span>Registration Reports</span>
			</a>
			<a href="<?php echo $base_url; ?>/report_year_intake.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'report_year_intake.php' ? 'active' : ''; ?>">
				<i class="fas fa-calendar"></i>
				<span>Year & Intake</span>
			</a>
			<a href="<?php echo $base_url; ?>/reportStudy_mode.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'reportStudy_mode.php' ? 'active' : ''; ?>">
				<i class="fas fa-book-reader"></i>
				<span>Study Mode</span>
			</a>
			<a href="<?php echo $base_url; ?>/print_registers.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'print_registers.php' ? 'active' : ''; ?>">
				<i class="fas fa-print"></i>
				<span>Print Registers</span>
			</a>
			<a href="<?php echo $base_url; ?>/login_activity.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'login_activity.php' ? 'active' : ''; ?>">
				<i class="fas fa-chart-bar"></i>
				<span>Login Activity</span>
			</a>
		</div>
		<?php endif; ?>

		<?php if (canAccessSettings()): ?>
		<div class="nav-section">
			<div class="nav-section-title">Settings</div>
			<a href="<?php echo $base_url; ?>/users_roles.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'users_roles.php' ? 'active' : ''; ?>">
				<i class="fas fa-users-cog"></i>
				<span>Staff &amp; Lecturer Roles</span>
			</a>
			<a href="<?php echo $base_url; ?>/ca_settings.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'ca_settings.php' ? 'active' : ''; ?>">
				<i class="fas fa-sliders-h"></i>
				<span>CA Settings</span>
			</a>
			<a href="<?php echo $base_url; ?>/portal_permission_scope.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'portal_permission_scope.php' ? 'active' : ''; ?>">
				<i class="fas fa-shield-halved"></i>
				<span>Portal Permissions</span>
			</a>
			<a href="<?php echo $base_url; ?>/enterprise_audit.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'enterprise_audit.php' ? 'active' : ''; ?>">
				<i class="fas fa-shield-halved"></i>
				<span>Enterprise Audit</span>
			</a>
			<a href="<?php echo $base_url; ?>/resetPassword.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'resetPassword.php' ? 'active' : ''; ?>">
				<i class="fas fa-key"></i>
				<span>Reset Password</span>
			</a>
		</div>
		<?php endif; ?>
	</div>

	<div class="sidebar-footer">
		<div class="user-info">
			<div class="user-avatar"><?php echo htmlspecialchars($user_name ? mb_substr($user_name, 0, 1) : 'G'); ?></div>
			<div class="user-details">
				<div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
				<div class="user-role"><?php echo htmlspecialchars($user_role_display); ?></div>
			</div>
		</div>
		<div class="quick-actions">
			<a href="<?php echo $base_url; ?>/profile.php" class="quick-action-btn">
				<i class="fas fa-user"></i>
				Profile
			</a>
			<a href="<?php echo $base_url; ?>/staffLogout.php" class="quick-action-btn">
				<i class="fas fa-sign-out-alt"></i>
				Logout
			</a>
		</div>
	</div>
</nav>
