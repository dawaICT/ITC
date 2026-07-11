<?php
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';

$uploadMessage = '';
$uploadMessageType = 'info';

function submitted_assign_table_exists(mysqli $db, string $table): bool {
    $safeTable = $db->real_escape_string($table);
    if ($result = $db->query("SHOW TABLES LIKE '{$safeTable}'")) {
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
    return false;
}

if (isset($_POST['submit'])) {
    $Sid = (string)($_SESSION['Sid'] ?? '');
    $course_code = trim((string)($_POST['course_code'] ?? ''));
    $due_dte = trim((string)($_POST['due_dte'] ?? ''));
    $dte = trim((string)($_POST['dte'] ?? ''));
    $originalName = (string)($_FILES['file_doc']['name'] ?? '');
    $tmpName = (string)($_FILES['file_doc']['tmp_name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'docx', 'doc', 'pdf'];

    if (!in_array($ext, $allowedExt, true)) {
        $uploadMessage = 'Sorry, invalid file format. Upload files in pdf, docx, doc or jpg.';
        $uploadMessageType = 'danger';
    } elseif (!submitted_assign_table_exists($db, 'submitted_assess')) {
        $uploadMessage = 'Assignment submission is not configured yet. Please check back later.';
        $uploadMessageType = 'warning';
    } else {
        $term = wuc_resolve_latest_term($db, $Sid);
        $elig = is_student_allowed_ca($db, $Sid, $term['academic_year'], $term['semester']);
        if (!$elig['allowed']) {
            $uploadMessage = 'You are not eligible to submit assessments. Required payment not met.';
            $uploadMessageType = 'danger';
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
                        $uploadMessage = 'Assignment submitted successfully.';
                        $uploadMessageType = 'success';
                    } else {
                        $uploadMessage = 'Assignment could not be saved. Please try again.';
                        $uploadMessageType = 'danger';
                    }
                    $stmt->close();
                }
            } else {
                $uploadMessage = 'File was not uploaded. Limit file size is 2MB.';
                $uploadMessageType = 'danger';
            }
        }
    }
}

$records = [];
$listError = '';
if (!submitted_assign_table_exists($db, 'submitted_assess')) {
    $listError = 'Assignment submission is not configured yet. Please check back later.';
} else {
    $sid = (string)($_SESSION['Sid'] ?? '');
    if ($stmt = $db->prepare("SELECT * FROM submitted_assess WHERE Sid = ?")) {
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $results = $stmt->get_result();
        while ($row = $results->fetch_object()) {
            $records[] = $row;
        }
        $stmt->close();
    }
    if (empty($records)) {
        $listError = 'You have no submitted assignments currently.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submitted Assignments - ITC</title>
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
					<h5>Continous assessments | student assignments</h5>
					<hr>
                    <h3>Your submitted Assignments</h3>

					<?php if ($uploadMessage !== ''): ?>
						<div class="alert alert-<?php echo htmlspecialchars($uploadMessageType) ?>">
							<?php echo htmlspecialchars($uploadMessage) ?>
						</div>
					<?php endif; ?>

					<?php if (!empty($records)): ?>
						<table class="table table-hover align-middle">
							<tr>
								<th>#</th>
								<th>Date submitted</th>
								<th>Due Date</th>
								<th>Course code</th>
								<th>Download</th>
							</tr>

						<?php $number = 1; foreach ($records as $r): ?>
							<tr>
							  <td><?php echo $number++; ?></td>
							  <td><?php echo htmlspecialchars((string)$r->dte); ?></td>
							  <td><?php echo htmlspecialchars((string)$r->due_dte); ?></td>
							  <td><?php echo htmlspecialchars((string)$r->course_code); ?></td>
							  <td>
							  	<a download="<?php echo htmlspecialchars((string)$r->file_doc); ?>" href="uploads/<?php echo htmlspecialchars((string)$r->file_doc); ?>">
								<button class="btn btn-warning btn-sm rounded-circle"><i class="fas fa-cloud-download-alt"></i></button>
				                </a>
				            </td>
							</tr>
						<?php endforeach; ?>
						</table>
					<?php elseif ($listError !== ''): ?>
						<div class="alert alert-info"><?php echo htmlspecialchars($listError) ?></div>
					<?php endif; ?>

			</div>
		</div>
		<div class="col-sm-1"></div>
	</div>
    </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
