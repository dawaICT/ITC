<?php
require "includes/nav.php";

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

// Simple helper to set messages
$success_message = $error_message = null;

// DB operations: delete, update, insert
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// DELETE
	if (!empty($_POST['delete_program_code'])) {
		$del_code = trim($_POST['delete_program_code']);
		if ($del_code !== '') {
			if ($stmt = $db->prepare('DELETE FROM programs WHERE program_code = ?')) {
				$stmt->bind_param('s', $del_code);
				if ($stmt->execute()) {
					$success_message = 'Program deleted successfully.';
				} else {
					$error_message = 'Failed to delete program.';
				}
				$stmt->close();
			} else {
				$error_message = 'Database error (delete prepare).';
			}
		}
	}

	// UPDATE
	elseif (!empty($_POST['original_code']) && isset($_POST['program_code'], $_POST['program_name'])) {
		$original = trim($_POST['original_code']);
		$program_code = trim($_POST['program_code']);
		$program_name = trim($_POST['program_name']);
		if ($program_code === '' || $program_name === '') {
			$error_message = 'Program code and name required.';
		} else {
			if ($stmt = $db->prepare('UPDATE programs SET program_code = ?, program_name = ? WHERE program_code = ?')) {
				$stmt->bind_param('sss', $program_code, $program_name, $original);
				if ($stmt->execute()) {
					$success_message = 'Program updated successfully.';
				} else {
					$error_message = 'Failed to update program.';
				}
				$stmt->close();
			} else {
				$error_message = 'Database error (update prepare).';
			}
		}
	}

	// INSERT
	elseif (isset($_POST['program_code'], $_POST['program_name'])) {
		$program_code = trim($_POST['program_code']);
		$program_name = trim($_POST['program_name']);
		if ($program_code === '' || $program_name === '') {
			$error_message = 'Program code and name required.';
		} else {
			// uniqueness check
			if ($chk = $db->prepare('SELECT 1 FROM programs WHERE program_code = ? OR program_name = ? LIMIT 1')) {
				$chk->bind_param('ss', $program_code, $program_name);
				$chk->execute();
				$chk->store_result();
				if ($chk->num_rows > 0) {
					$error_message = 'Program code or name already exists.';
				}
				$chk->close();
			} else {
				$error_message = 'Database error (check prepare).';
			}

			if (empty($error_message)) {
				if ($ins = $db->prepare('INSERT INTO programs (program_code, program_name) VALUES (?,?)')) {
					$ins->bind_param('ss', $program_code, $program_name);
					if ($ins->execute()) {
						$success_message = 'New program added successfully.';
					} else {
						$error_message = 'Failed to insert program.';
					}
					$ins->close();
				} else {
					$error_message = 'Database error (insert prepare).';
				}
			}
		}
	}
}

// Fetch programs
$programs = [];
if ($res = $db->query('SELECT program_code, program_name FROM programs ORDER BY program_name')) {
	while ($r = $res->fetch_assoc()) { $programs[] = $r; }
	$res->free();
}
?>
<div class="container-fluid px-4 py-4 portal-dashboard">
	<div class="dashboard-header admin-section mb-3 d-flex align-items-center justify-content-between">
		<h3 class="dashboard-title mb-0"><i class="fas fa-graduation-cap me-2"></i>Programs</h3>
		<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addProgramModal"><i class="fas fa-plus me-1"></i> Add program</button>
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
				<h5 class="mb-0"><i class="fas fa-graduation-cap me-2"></i>Programs List</h5>
			</div>
		</div>
		<div class="card-body">
			<?php if (empty($programs)): ?>
				<p>No programs found.</p>
			<?php else: ?>
				<table class="table table-hover align-middle">
					<thead class="table-light">
						<tr>
							<th>Program Code</th>
							<th>Program Name</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($programs as $program): ?>
						<tr>
							<td><?= htmlspecialchars($program['program_code']) ?></td>
							<td><?= htmlspecialchars($program['program_name']) ?></td>
							<td>
								<button class="btn btn-sm btn-outline-primary edit-program-btn" data-code="<?= htmlspecialchars($program['program_code']) ?>" data-name="<?= htmlspecialchars($program['program_name']) ?>">Edit</button>
								<form method="POST" class="d-inline" onsubmit="return confirm('Delete this program?');">
									<input type="hidden" name="delete_program_code" value="<?= htmlspecialchars($program['program_code']) ?>">
									<button type="submit" class="btn btn-sm btn-danger">Delete</button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<!-- Add/Edit Modal -->
	<div class="modal fade" id="addProgramModal" tabindex="-1" aria-labelledby="addProgramModalLabel" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="addProgramModalLabel">Add New Program</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<form method="POST" id="programForm">
						<input type="hidden" name="original_code" id="original_code" value="">
						<div class="mb-3">
							<label for="program_code" class="form-label">Program Code</label>
							<input type="text" class="form-control" id="program_code" name="program_code" required>
						</div>
						<div class="mb-3">
							<label for="program_name" class="form-label">Program Name</label>
							<input type="text" class="form-control" id="program_name" name="program_name" required>
						</div>
						<button type="submit" class="btn btn-primary" id="programSubmit">Add Program</button>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- Modal z-index override to stay above sidebar -->
<style>
.modal-backdrop { z-index: 1990 !important; }
.modal { z-index: 2000 !important; }
.modal .modal-content { pointer-events: auto !important; }
</style>

<script>
// Move modal to document.body to avoid stacking-context issues
document.addEventListener('DOMContentLoaded', function(){
	var modalEl = document.getElementById('addProgramModal');
	if (modalEl && modalEl.parentNode !== document.body) {
		document.body.appendChild(modalEl);
		console.log('programs.php: moved modal to body');
	}
});

document.addEventListener('DOMContentLoaded', function(){
	// Edit button
	document.querySelectorAll('.edit-program-btn').forEach(function(btn){
		btn.addEventListener('click', function(){
			var code = this.getAttribute('data-code');
			var name = this.getAttribute('data-name');
			document.getElementById('program_code').value = code;
			document.getElementById('program_name').value = name;
			document.getElementById('original_code').value = code;
			document.getElementById('programSubmit').textContent = 'Update Program';
			var modalEl = document.getElementById('addProgramModal');
			if (window.bootstrap) new bootstrap.Modal(modalEl).show();
		});
	});

	// When opening the modal via Add button, reset form
	var addBtn = document.querySelector('button[data-bs-target="#addProgramModal"]');
	if (addBtn) addBtn.addEventListener('click', function(){
		var form = document.getElementById('programForm');
		form.reset();
		document.getElementById('original_code').value = '';
		document.getElementById('programSubmit').textContent = 'Add Program';
	});
});
</script>
<?php require "includes/footer.php"; ?>

