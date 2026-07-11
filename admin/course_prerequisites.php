<?php
// Course Prerequisites Management
require_once "includes/admin.php";
require_once "includes/header.php";

// Ensure required table exists. Some production DB users cannot CREATE tables,
// so this helper returns false and lets the page show a setup message instead
// of throwing a fatal mysqli exception.
function ensure_prereq_table(mysqli $db): bool {
    $hasTable = false;
    if ($res = $db->query("SHOW TABLES LIKE 'course_prerequisites'")) {
        $hasTable = $res->num_rows > 0; $res->free();
    }
    if (!$hasTable) {
        try {
            $db->query(
                "CREATE TABLE IF NOT EXISTS course_prerequisites (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    course_code VARCHAR(20) NOT NULL,
                    prereq_code VARCHAR(20) NOT NULL,
                    min_grade VARCHAR(5) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_course_prereq (course_code, prereq_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            if ($res = $db->query("SHOW TABLES LIKE 'course_prerequisites'")) {
                $hasTable = $res->num_rows > 0; $res->free();
            }
        } catch (Throwable $e) {
            error_log('course_prerequisites setup unavailable: ' . $e->getMessage());
            return false;
        }
    }
    return $hasTable;
}

$prereqTableReady = ensure_prereq_table($db);

// Handle add/delete
$message = null; $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$prereqTableReady) {
        $error = 'Prerequisite storage is not set up yet. Ask the database administrator to create the course_prerequisites table.';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'add') {
        $course = trim($_POST['course_code'] ?? '');
        $prereq = trim($_POST['prereq_code'] ?? '');
        $minGrade = trim($_POST['min_grade'] ?? '');
        if ($course === '' || $prereq === '' || $course === $prereq) {
            $error = 'Select a course and a different prerequisite course.';
        } else {
            $stmt = $db->prepare("INSERT INTO course_prerequisites (course_code, prereq_code, min_grade) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $course, $prereq, $minGrade);
            if ($stmt->execute()) { $message = 'Prerequisite added.'; } else { $error = 'Failed to add prerequisite (it may already exist).'; }
            $stmt->close();
            $_GET['course'] = $course; // keep selection
        }
    }
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $course = trim($_POST['course_keep'] ?? '');
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM course_prerequisites WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $message = 'Prerequisite removed.';
        }
        if ($course !== '') { $_GET['course'] = $course; }
    }
}

// Fetch all courses for dropdowns
$courses = [];
if ($res = $db->query("SELECT course_code, course_name FROM courses ORDER BY course_code")) {
    while ($row = $res->fetch_assoc()) { $courses[] = $row; }
    $res->free();
}

$selectedCourse = trim($_GET['course'] ?? '');
$prereqs = [];
if ($selectedCourse !== '' && $prereqTableReady) {
    $stmt = $db->prepare("SELECT cp.*, c.course_name AS prereq_name FROM course_prerequisites cp LEFT JOIN courses c ON c.course_code = cp.prereq_code WHERE cp.course_code = ? ORDER BY cp.prereq_code");
    $stmt->bind_param('s', $selectedCourse);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $prereqs[] = $row; }
    $stmt->close();
}
?>

<link rel="stylesheet" href="css/admin-dashboard.css">
<!-- Use unified default font sizing from global styles -->

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-4">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Course Prerequisites</h1>
        <p class="text-muted">Manage prerequisite relationships between courses</p>
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
  <?php if (!$prereqTableReady): ?>
    <div class="alert alert-warning py-2">
      Prerequisite storage is missing. Create the <code>course_prerequisites</code> table before adding prerequisite rules.
    </div>
  <?php endif; ?>

  <div class="data-table-card mb-4">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Add Prerequisite</h5>
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

      <?php if ($selectedCourse !== ''): ?>
      <?php if ($prereqTableReady): ?>
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="add">
        <div class="col-md-5">
          <label class="form-label">Prerequisite Course</label>
          <select class="form-select" name="prereq_code" required>
            <option value="" disabled selected>Select prerequisite</option>
            <?php foreach ($courses as $c): if ($c['course_code'] === $selectedCourse) continue; ?>
              <option value="<?= htmlspecialchars($c['course_code']) ?>"><?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Minimum Grade (optional)</label>
          <input type="text" class="form-control" name="min_grade" placeholder="e.g., C">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <input type="hidden" name="course_code" value="<?= htmlspecialchars($selectedCourse) ?>">
          <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-2"></i>Add</button>
        </div>
      </form>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="data-table-card">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Existing Prerequisites</h5>
      </div>
    </div>
    <div class="card-body">
      <?php if ($selectedCourse === ''): ?>
        <div class="alert alert-info">Select a course to view its prerequisites.</div>
      <?php elseif (empty($prereqs)): ?>
        <div class="alert alert-secondary">No prerequisites set for this course.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th>Prerequisite Code</th>
                <th>Prerequisite Name</th>
                <th>Minimum Grade</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($prereqs as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['prereq_code']) ?></td>
                <td><?= htmlspecialchars($p['prereq_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['min_grade'] ?? '') ?></td>
                <td class="text-center">
                  <form method="post" onsubmit="return confirm('Remove this prerequisite?')" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="course_keep" value="<?= htmlspecialchars($selectedCourse) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once "includes/footer.php"; ?>



