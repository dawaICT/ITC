<?php
require "includes/nav.php";
require_once __DIR__ . '/../db/connect.php';
require_once dirname(__DIR__) . '/add_courses.php';

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development' || isset($_GET['debug'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// DB operations
$success_message = $error_message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// ASSIGN LECTURER
	if (isset($_POST['course_code'], $_POST['staff_id']) && !isset($_POST['course_name'])) {
		$course_code = trim($_POST["course_code"]);
		$staff_id = trim($_POST["staff_id"]);
		if (empty($course_code) || empty($staff_id)) {
			$error_message = 'Course and lecturer must be selected.';
		} else {
			$check = $db->prepare("SELECT 1 FROM course_lecturer WHERE course_code = ? AND staff_id = ? LIMIT 1");
			$check->bind_param('ss', $course_code, $staff_id);
			$exists = false;
			if ($check->execute()) {
				$res = $check->get_result();
				if ($res->num_rows > 0) { $exists = true; }
			}
			$check->close();
			if ($exists) {
				$error_message = 'This course has already been assigned to the lecturer.';
			} else {
				$insert = $db->prepare("INSERT INTO course_lecturer (course_code, staff_id) VALUES (?,?)");
				$insert->bind_param("ss", $course_code, $staff_id);
				if ($insert->execute()) {
					$success_message = 'Course assigned successfully.';
				} else {
					$error_message = 'Failed to assign course.';
				}
				$insert->close();
			}
		}
	}

	// ADD COURSE
	if (isset($_POST['course_code'], $_POST['course_name'], $_POST['credits'])) {
		$course_code = trim($_POST['course_code']);
		$course_name = trim($_POST['course_name']);
		$credits = (int)$_POST['credits'];
		if ($course_code === '' || $course_name === '' || $credits <= 0) {
			$error_message = 'All fields required and credits must be positive.';
		} else {
			// uniqueness check
			if ($chk = $db->prepare('SELECT 1 FROM courses WHERE course_code = ? OR course_name = ? LIMIT 1')) {
				$chk->bind_param('ss', $course_code, $course_name);
				$chk->execute();
				$chk->store_result();
				if ($chk->num_rows > 0) {
					$error_message = 'Course code or name already exists.';
				}
				$chk->close();
			} else {
				$error_message = 'Database error (check prepare).';
			}

			if (empty($error_message)) {
				if ($ins = $db->prepare('INSERT INTO courses (course_code, course_name, credits, status) VALUES (?,?,?, "active")')) {
					$ins->bind_param('ssi', $course_code, $course_name, $credits);
					if ($ins->execute()) {
						$success_message = 'New course added successfully.';
					} else {
						$error_message = 'Failed to insert course.';
					}
					$ins->close();
				} else {
					$error_message = 'Database error (insert prepare).';
				}
			}
		}
	}
}

// Fetch courses
$courses = [];
if ($res = $db->query('SELECT course_code, course_name, credits, status FROM courses ORDER BY course_name')) {
	while ($r = $res->fetch_assoc()) { $courses[] = $r; }
	$res->free();
}
?>
<div class="container-fluid px-4 py-4 portal-dashboard">
			<div class="dashboard-header admin-section mb-3 d-flex align-items-center justify-content-between">
				<h3 class="dashboard-title mb-0"><i class="fas fa-book me-2"></i>Courses</h3>
				<div class="d-flex gap-2">
					<button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#assignModal"><i class="fas fa-tags me-1"></i> Assign lecturer</button>
					<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCourseModal"><i class="fas fa-plus me-1"></i> Add course</button>
				</div>
			</div>

			<?php if ($success_message): ?>
			<div class="alert alert-success alert-dismissible fade show" role="alert">
				<?= htmlspecialchars($success_message) ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
			</div>
			<?php endif; ?>

			<?php if ($error_message): ?>
			<div class="alert alert-danger alert-dismissible fade show" role="alert">
				<?= htmlspecialchars($error_message) ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
			</div>
			<?php endif; ?>

			<div class="data-table-card">
				<div class="card-header">
					<div class="d-flex justify-content-between align-items-center">
						<h5 class="mb-0"><i class="fas fa-book me-2"></i>Courses List</h5>
					</div>
				</div>
				<div class="card-body">
					<?php if (empty($courses)): ?>
						<p>No courses found.</p>
					<?php else: ?>
						<table class="table table-hover align-middle">
							<thead class="table-light">
								<tr>
									<th>Course Code</th>
									<th>Course Name</th>
									<th>Credits</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($courses as $course): ?>
								<tr>
									<td><?= htmlspecialchars($course['course_code']) ?></td>
									<td><?= htmlspecialchars($course['course_name']) ?></td>
									<td><?= htmlspecialchars($course['credits']) ?></td>
									<td><?= htmlspecialchars($course['status']) ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<!-- Assign Lecturer Modal -->
			<div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
				<div class="modal-dialog">
					<div class="modal-content">
						<div class="modal-header">
							<h5 class="modal-title" id="assignModalLabel"><i class="fas fa-tasks me-2"></i>Assign course to lecturer</h5>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
						</div>
						<div class="modal-body">
							<form action="" method="post" class="row g-3 needs-validation" novalidate>
								<div class="col-12">
									<label for="course_code" class="form-label">Course code</label>
									<select class="form-select" name="course_code" id="course_code" required>
										<option disabled selected value="">Select course</option>
										<?php
										$records = [];
										if($results = $db->query("SELECT * FROM courses")) {
											while($row = $results->fetch_object()) { $records[] = $row; }
											$results->free();
										}
										foreach($records as $r): ?>
											<option value="<?php echo htmlspecialchars($r->course_code); ?>"><?php echo htmlspecialchars($r->course_name); ?></option>
										<?php endforeach; ?>
									</select> 
									<div class="invalid-feedback">Please select a course</div>
								</div>
								<div class="col-12">
									<label for="staff_id" class="form-label">Lecturer ID</label>
									<select class="form-select" name="staff_id" id="staff_id" required>
										<option disabled selected value="">Assign lecturer</option>
										<?php
										$records1 = [];
										if($results1 = $db->query("SELECT * FROM staff")) {
											while($row = $results1->fetch_object()) { $records1[] = $row; }
											$results1->free();
										}
										foreach($records1 as $r): ?>
											<option value="<?php echo htmlspecialchars($r->staff_id); ?>"><?php echo htmlspecialchars($r->title.' '.$r->Fname.' '.$r->Lname); ?></option>
										<?php endforeach; ?>
									</select>
									<div class="invalid-feedback">Please select a lecturer</div>
								</div>
								<div class="col-12">
									<button class="btn btn-primary" type="submit">Assign</button>
								</div>
							</form>
						</div>
					</div>
				</div>
			</div>

			<!-- Add Course Modal -->
			<div class="modal fade" id="addCourseModal" tabindex="-1" aria-labelledby="addCourseModalLabel" aria-hidden="true">
				<div class="modal-dialog">
					<div class="modal-content">
						<div class="modal-header">
							<h5 class="modal-title" id="addCourseModalLabel"><i class="fas fa-plus me-2"></i>Add New Course</h5>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
						</div>
						<div class="modal-body">
							<form action="" method="post" class="row g-3 needs-validation" novalidate>
								<div class="col-12">
									<label for="course_code_add" class="form-label">Course Code</label>
									<input type="text" class="form-control" id="course_code_add" name="course_code" required>
									<div class="invalid-feedback">Please enter course code</div>
								</div>
								<div class="col-12">
									<label for="course_name" class="form-label">Course Name</label>
									<input type="text" class="form-control" id="course_name" name="course_name" required>
									<div class="invalid-feedback">Please enter course name</div>
								</div>
								<div class="col-12">
									<label for="credits" class="form-label">Credits</label>
									<input type="number" class="form-control" id="credits" name="credits" required>
									<div class="invalid-feedback">Please enter credits</div>
								</div>
								<div class="col-12">
									<button class="btn btn-success" type="submit">Add Course</button>
								</div>
							</form>
						</div>
					</div>
				</div>
			</div>
		</div>

<!-- Ensure modal is appended to <body> and has higher z-index than sidebar -->
<style>
    :root {
        --logo-primary: #6f42c1; /* purple */
        --logo-secondary: #f9ad59; /* warm yellow/orange */
        --primary-color: var(--logo-primary);
        --secondary-color: var(--logo-secondary);
        --background-color: #ffffff; /* White */
        --card-bg: #ffffff;
        --card-border: #e9e6f7;
        --card-header-gradient: linear-gradient(90deg, var(--logo-primary) 0%, var(--logo-secondary) 100%);
        --transition-speed: 0.3s;
    }

    .action-buttons .btn { 
        margin: 0 2px;
        transition: transform var(--transition-speed);
    }
    .action-buttons .btn:hover {
        transform: translateY(-2px);
    }
    .table-responsive { 
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .nav-tabs .nav-link.active { 
        background-color: var(--primary-color);
        color: var(--background-color);
        border-color: var(--primary-color);
    }
    .nav-tabs .nav-link {
        transition: all var(--transition-speed);
    }
    .container-fluid { 
        padding: clamp(1rem, 2vw, 2rem);
        max-width: 1400px;
        margin: 0 auto;
    }
    .card {
        border-radius: 0.5rem;
        box-shadow: 0 6px 18px rgba(31, 41, 55, 0.06);
        background-color: var(--card-bg);
        border: 1px solid var(--card-border);
        color: #0f1724; /* Dark text for readability */
        overflow: hidden;
    }
    .card .card-header {
        background: var(--card-header-gradient);
        color: #ffffff;
        padding: 0.85rem 1rem;
        font-weight: 600;
        border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .card.admin-card .card-body,
    .card.admin-card > .card-body,
    .admin-card {
        background: var(--card-bg);
    }
    /* Buttons - ensure good contrast on white cards */
    .btn-primary {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        color: #ffffff;
    }
    .btn-primary:focus, .btn-primary:hover {
        background-color: #5b2fb0;
        border-color: #5b2fb0;
        color: #ffffff;
    }
    .btn-info {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
        color: #07203a; /* dark text for sky-blue */
    }
    .btn-info:focus, .btn-info:hover {
        background-color: #6f42c1; /* purple on hover */
        border-color: #6f42c1;
        color: #ffffff;
    }
    .btn-success {
        color: #ffffff;
    }
    .btn-secondary {
        background-color: #f3f4f6;
        border-color: #e5e7eb;
        color: #111827;
    }
    .table td {
        background-color: #ffffff;
        color: #1f2937;
    }
    .table th {
        background-color: #f1f5f9;
        color: #0f1724;
    }
    /* Style DataTable header to match logo colors */
    .table thead.table-dark {
        background: linear-gradient(90deg, var(--logo-primary), var(--logo-secondary));
        color: #ffffff;
    }
    .dashboard-title {
        color: var(--logo-primary);
        font-weight: 700;
        margin: 0 0 0.5rem 0;
    }
    /* Column sizing and content fit helpers */
    .col-name { width: 16%; }
    .col-contact { width: 22%; }
    .col-program { width: 28%; }
    .col-actions { width: 12%; }
    .name-cell {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 220px;
    }
    .contact-cell dd,
    .program-cell dd {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin: 0;
    }
    .contact-cell {
        max-width: 280px;
    }
    .program-cell {
        max-width: 360px;
    }
    .action-buttons {
        display: flex;
        gap: 0.35rem;
        flex-wrap: wrap;
        align-items: center;
    }
    .action-buttons .btn {
        padding: 0.25rem 0.45rem;
        min-width: 36px;
    }
    .admin-card {
        background: var(--background-color);
        color: #0f1724;
    }
    .breadcrumb { 
        background: transparent;
        padding: 0;
        margin-bottom: 1rem;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .table-responsive { 
            font-size: 0.875rem;
        }
        .action-buttons .btn { 
            padding: 0.25rem 0.5rem;
        }
        .card-body {
            padding: 1rem;
        }
    }

    /* Dark mode support */
    @media (prefers-color-scheme: dark) {
        body.has-unified-sidebar {
            background-color: #212529 !important;
            color: #f8f9fa;
        }
        .card {
            background-color: #2b3035;
            border-color: #373b3e;
        }
        .table {
            color: #f8f9fa;
        }
    }

/* Module-scoped override: keep modal above other UI */
.modal-backdrop { z-index: 1990 !important; }
.modal { z-index: 2000 !important; }
.modal .modal-content { pointer-events: auto !important; }
</style>

<script>
// Move modal to document.body to avoid stacking-context issues
document.addEventListener('DOMContentLoaded', function(){
	var modals = document.querySelectorAll('.modal');
	modals.forEach(function(modalEl) {
		if (modalEl && modalEl.parentNode !== document.body) {
			document.body.appendChild(modalEl);
			console.log('courses.php: moved modal to body');
		}
	});
});

document.addEventListener('DOMContentLoaded', function(){
	// Validation for forms
	var forms = document.querySelectorAll('.needs-validation');
	Array.prototype.slice.call(forms).forEach(function (form) {
		form.addEventListener('submit', function (event) {
			if (!form.checkValidity()) { 
				event.preventDefault(); 
				event.stopPropagation(); 
			}
			form.classList.add('was-validated');
		}, false);
	});
});
</script>
<?php require "includes/footer.php"; ?>

