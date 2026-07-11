<?php
require_once __DIR__ . '/includes/guard.php';

$records = [];
$selectedCourse = trim((string)($_GET['view'] ?? ''));
$assignmentMessage = '';
$assignmentMessageType = 'info';

function student_assignment_table_exists(mysqli $db, string $table): bool {
    $safeTable = $db->real_escape_string($table);
    if ($result = $db->query("SHOW TABLES LIKE '{$safeTable}'")) {
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
    return false;
}

if (isset($_POST['submit'])) {
    $Sid = trim((string)($_POST['Sid'] ?? ''));
    $course_code = trim((string)($_POST['course_code'] ?? ''));
    $due_dte = trim((string)($_POST['due_dte'] ?? ''));
    $dte = trim((string)($_POST['dte'] ?? ''));
    $originalName = (string)($_FILES['file_doc']['name'] ?? '');
    $tmpName = (string)($_FILES['file_doc']['tmp_name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'docx', 'doc', 'pdf'];

    if (!in_array($ext, $allowedExt, true)) {
        $assignmentMessage = 'Sorry, invalid file format. Upload files in pdf, docx, doc or jpg.';
        $assignmentMessageType = 'danger';
    } elseif (!student_assignment_table_exists($db, 'submitted_assess')) {
        $assignmentMessage = 'Assignment submission is not configured yet. Please check back later.';
        $assignmentMessageType = 'warning';
    } else {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;

        if (is_dir($uploadDir) && move_uploaded_file($tmpName, $uploadDir . $storedName)) {
            $stmt = $db->prepare(
                "INSERT INTO submitted_assess (Sid, course_code, due_dte, dte, file_doc) VALUES (?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                $stmt->bind_param('sssss', $Sid, $course_code, $due_dte, $dte, $storedName);
                if ($stmt->execute()) {
                    $assignmentMessage = 'Assignment submitted successfully.';
                    $assignmentMessageType = 'success';
                } else {
                    $assignmentMessage = 'Assignment could not be saved. Please try again.';
                    $assignmentMessageType = 'danger';
                }
                $stmt->close();
            }
        } else {
            $assignmentMessage = 'File was not uploaded. Limit file size is 2MB.';
            $assignmentMessageType = 'danger';
        }
    }
}

if ($selectedCourse !== '') {
    if (!student_assignment_table_exists($db, 'posted_assessments')) {
        $assignmentMessage = 'Assignments are not configured yet. Please check back later.';
        $assignmentMessageType = 'warning';
    } elseif ($stmt = $db->prepare("SELECT c.course_code, c.course_name, pa.dte, pa.due_dte, pa.image
        FROM courses c
        INNER JOIN posted_assessments pa ON c.course_code = pa.course_code
        WHERE c.course_code = ?
        ORDER BY pa.due_dte DESC")) {
        $stmt->bind_param('s', $selectedCourse);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_object()) {
            $records[] = $row;
        }
        $stmt->close();
        if (empty($records)) {
            $assignmentMessage = 'Course lecturer has not yet posted any assignment in this course.';
            $assignmentMessageType = 'info';
        }
    }
} else {
    $assignmentMessage = 'Select a course from My Courses to view posted assignments.';
    $assignmentMessageType = 'info';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Assignments - ITC</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="/wucportal/css/admin-style.css">
<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    <div class="content-wrapper">
    <div class="container-fluid">
		<div class="row">
			<div class="col-sm-2"></div>
			<div class="col-sm-8 card shadow-sm">
				<div class="card-body">
					<h5>Continous assessments | posted assignments</h5>
					<hr>
					<button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#upload_ca">
					<i class="fas fa-upload"></i> Upload</button>
					<a href="submittedAssign.php" class="btn btn-warning float-end"><i class="fas fa-folder-open"></i> View submitted</a><br><br>
					<?php if ($assignmentMessage !== ''): ?>
						<div class="alert alert-<?php echo htmlspecialchars($assignmentMessageType); ?>">
							<?php echo htmlspecialchars($assignmentMessage); ?>
						</div>
					<?php endif; ?>
					<?php if (!empty($records)): ?>
						<table class="table table-hover align-middle">
							<tr>
								<th>Date posted</th>
								<th>Due Date</th>
								<th>Course code</th>
								<th>Course name</th>
								<th>Download</th>
							</tr>

						<?php
			              foreach($records as $r) {
			              ?>
							<tr>
							  <td><?php echo htmlspecialchars((string)$r->dte); ?></td>
							  <td><?php echo htmlspecialchars((string)$r->due_dte); ?></td>
							  <td><?php echo htmlspecialchars((string)$r->course_code); ?></td>
							  <td><?php echo htmlspecialchars((string)$r->course_name); ?></td>
							  <td>
							  	<a download="<?php echo htmlspecialchars((string)$r->image); ?>" href="../lecturers/uploads/assessments/<?php echo htmlspecialchars((string)$r->image); ?>">
								<button class="btn btn-warning btn-sm rounded-circle"><i class="fas fa-cloud-download-alt"></i></button>
				                </a>
				            </td>
							</tr>

					<?php
                      }
                      ?>
					</table>
					<?php endif; ?>

					<div class="modal fade" id="upload_ca" tabindex="-1" aria-labelledby="uploadCaLabel" aria-hidden="true">
					  <div class="modal-dialog">
					    <div class="modal-content">
					      <div class="modal-header bg-purple text-white">
					        <h5 class="modal-title" id="uploadCaLabel">Upload Assignment</h5>
					        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					      </div>
					      <div class="modal-body">
					        <form action="assignments.php" method="post" enctype="multipart/form-data" name="myForm" onsubmit="return validateForm()">
					        	<div class="form-group">
					                  <label for="Sid">Student ID:</label><br>
					                  <input type="text" class="form-control" id="Sid" name="Sid" value="<?php echo htmlspecialchars((string)($_SESSION['Sid'] ?? '')); ?>" readonly>
					                </div>
					                <div class="form-group">
					                  <label for="course_code">Course code:</label><br>
					                  <input type="text" class="form-control" id="course_code" name="course_code" value="<?php echo htmlspecialchars($records[0]->course_code ?? $selectedCourse); ?>" readonly>
					                </div>
					                <div class="form-group">
					                  <label for="due_dte">Due date:</label><br>
					                  <input type="date" class="form-control" id="due_dte" name="due_dte" required>
					                </div>
					                <div class="form-group">
					                  <label for="dte">Date submitted:</label><br>
					                  <input type="text" class="form-control" id="dte" name="dte" value="<?php echo Date('d/m/Y');?>" readonly>
					                </div>
					                <div class="form-group">
					                  <label for="file_doc">Upload file:</label><br>
					                  <input type="file" class="form-control" id="file_doc" name="file_doc">
					                </div>
					                <div class="form-group">
					                  <button class="btn btn-warning w-100" type="submit" name="submit">SUBMIT</button>
					                </div>
					        	</form><!--registration form ends-->
					      </div>
					    </div>
					  </div>
					</div>
			</div>
		</div>
		<div class="col-sm-1"></div>
	</div>
    </div>
    </div>
<script>
/*function validateForm(){
	var x = document.forms["myForm"]["due_dte"].value;
	var z = document.forms["myForm"]["dte"].value;
	if (x < z) {
		alert("The assignment submission is out of due date.");
		return false;
	}
}*/
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

