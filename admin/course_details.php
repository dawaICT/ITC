<?php
// Course Credits & Syllabus Management
require_once "includes/admin.php";
require_once "includes/header.php";

// Ensure syllabus column exists on courses
function ensure_course_columns(mysqli $db): void {
    $hasCredits = false; $hasSyllabus = false;
    if ($res = $db->query("SHOW COLUMNS FROM courses LIKE 'credits'")) { $hasCredits = $res->num_rows > 0; $res->free(); }
    if ($res = $db->query("SHOW COLUMNS FROM courses LIKE 'syllabus'")) { $hasSyllabus = $res->num_rows > 0; $res->free(); }
    if (!$hasCredits) { $db->query("ALTER TABLE courses ADD COLUMN credits INT NULL AFTER course_name"); }
    if (!$hasSyllabus) { $db->query("ALTER TABLE courses ADD COLUMN syllabus TEXT NULL AFTER credits"); }
}

ensure_course_columns($db);

$message = null; $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course = trim($_POST['course_code'] ?? '');
    $credits = trim($_POST['credits'] ?? '');
    $syllabus = trim($_POST['syllabus'] ?? '');
    if ($course === '') { $error = 'Select a course.'; }
    else {
        $creditsVal = ($credits === '' ? null : (int)$credits);
        $stmt = $db->prepare("UPDATE courses SET credits = ?, syllabus = ? WHERE course_code = ?");
        $stmt->bind_param('iss', $creditsVal, $syllabus, $course);
        if ($stmt->execute()) { $message = 'Course details saved.'; }
        else { $error = 'Failed to save details.'; }
        $stmt->close();
        $_GET['course'] = $course;
    }
}

// Fetch all courses and the selected one
$courses = [];
if ($res = $db->query("SELECT course_code, course_name, credits FROM courses ORDER BY course_code")) {
    while ($row = $res->fetch_assoc()) { $courses[] = $row; }
    $res->free();
}

$selectedCourse = trim($_GET['course'] ?? '');
$selected = null;
if ($selectedCourse !== '') {
    $stmt = $db->prepare("SELECT course_code, course_name, credits, syllabus FROM courses WHERE course_code = ?");
    $stmt->bind_param('s', $selectedCourse);
    $stmt->execute();
    $selected = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>

<link rel="stylesheet" href="css/admin-dashboard.css">
<!-- Use unified default font sizing from global styles -->

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-4">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Course Credits & Syllabus</h1>
        <p class="text-muted">Manage credit hours and syllabus content</p>
      </div>
      <div class="col-auto">
        <a href="course_program_mgmt.php" class="btn btn-outline-primary"><i class="fas fa-sitemap me-2"></i>Back to Mgmt</a>
      </div>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="data-table-card mb-4">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Course Details</h5>
      </div>
    </div>
    <div class="card-body">
      <form method="get" class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label">Course</label>
          <select class="form-select" name="course" onchange="this.form.submit()" required>
            <option value="" disabled <?= $selectedCourse === '' ? 'selected' : '' ?>>Select course</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= htmlspecialchars($c['course_code']) ?>" <?= $selectedCourse === $c['course_code'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>

      <?php if ($selected): ?>
      <form method="post" class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Credits</label>
          <input type="number" class="form-control" name="credits" min="0" max="50" value="<?= htmlspecialchars((string)($selected['credits'] ?? '')) ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Syllabus</label>
          <textarea class="form-control" name="syllabus" rows="10" placeholder="Outline, topics, assessment plan..."><?= htmlspecialchars($selected['syllabus'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <input type="hidden" name="course_code" value="<?= htmlspecialchars($selected['course_code']) ?>">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="data-table-card">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Courses Overview</h5>
      </div>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Credits</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($courses as $c): ?>
              <tr>
                <td><?= htmlspecialchars($c['course_code']) ?></td>
                <td><?= htmlspecialchars($c['course_name']) ?></td>
                <td><?= htmlspecialchars((string)($c['credits'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once "includes/footer.php"; ?>



