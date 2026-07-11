<?php
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
error_reporting(0);

$records = [];
$existingExemption = null;
$view = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$sessionSid = (string)($_SESSION['Sid'] ?? '');

if ($view > 0 && $sessionSid !== '') {
    // course_registration's key column is `id` (CoRegID never existed here)
    $stmt = $db->prepare('SELECT * FROM course_registration WHERE id = ? AND Sid = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('is', $view, $sessionSid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_object();
        $stmt->close();
        if ($row) {
            $records[] = $row;
            $exStmt = $db->prepare('SELECT * FROM exemption WHERE Sid = ? AND course_code = ? LIMIT 1');
            if ($exStmt) {
                $exStmt->bind_param('ss', $sessionSid, $row->course_code);
                $exStmt->execute();
                $existingExemption = $exStmt->get_result()->fetch_object();
                $exStmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Apply for Exemption</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="/wucportal/css/admin-style.css">
<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    <div class="content-wrapper">
	<div class="container">
		<div class="row">
			<div class="col-sm-10 w3-card-4">
			<h5>Exemptions | apply</h5>
			<hr>
            <h4>Applying for course exemption</h4>
				<div class="w3-container"><br>
            <hr>
            <?php if ($view <= 0): ?>
                <div class="alert alert-warning">Select a registered course from My Courses to apply for exemption.</div>
            <?php elseif (empty($records)): ?>
                <div class="alert alert-danger">You have no courses registered for this application code.</div>
            <?php else: foreach ($records as $r): ?>
                <?php if ($existingExemption): ?>
                <div class="alert alert-info" role="status">
                    <strong>Exemption already on record</strong> for course
                    <?= htmlspecialchars((string)$r->course_code, ENT_QUOTES) ?>.
                    <?php if (!empty($existingExemption->support_doc)): ?>
                    Supporting document: <?= htmlspecialchars((string)$existingExemption->support_doc, ENT_QUOTES) ?>.
                    <?php endif; ?>
                    A new application cannot be submitted for the same course.
                </div>
                <?php else: ?>
            <form action="processExemption.php" method="POST" role="form" enctype="multipart/form-data">
            <div class="form-group">
              <label for="CoRegID">Application code:</label><br>
              <input type="text" class="form-control" name="CoRegID" id="CoRegID"
              value="<?php echo htmlspecialchars((string)$r->id, ENT_QUOTES); ?>" readonly>
            </div>
            <div class="form-group">
              <label for="Sid">Student ID:</label><br>
              <input type="text" class="form-control" name="Sid" id="Sid" 
              value="<?php echo htmlspecialchars((string)$r->Sid, ENT_QUOTES); ?>" autocomplete="off" readonly>
            </div>
            <div class="form-group">
              <label for="course_code">Course code:</label><br>
              <input type="text" class="form-control" name="course_code" id="course_code" 
              value="<?php echo htmlspecialchars((string)$r->course_code, ENT_QUOTES); ?>" readonly>
            </div>
                <div class="form-group">
                <label for="support_doc">Attach supporting document:</label><br>
                <input type="file" class="form-control" name="support_doc" id="support_doc" 
                accept="application/pdf,image/jpeg" required>
                </div>

                <div class="form-group">
                    <button class="btn w3-orange btn-block" type="submit" name="submit">Apply</button>
                </div>  
            </form>
                <?php endif; ?>
            <?php endforeach; endif; ?>

			</div>
			</div>
		</div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
