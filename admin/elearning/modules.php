<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/elearning_guard.php';
elearning_require_role(['systems_admin','lecturer','head_of_department']);

require_once __DIR__ . '/../../db/connect.php';

$err = null; $ok = null;

// Best-effort guard: lms_modules is created by /migrations/20260622_lms_modules.sql.
// If the migration has not been applied this no-ops (the app user cannot run DDL)
// and the queries below degrade to a friendly notice instead of a fatal error.
wuc_ensure_tables($db, ["CREATE TABLE IF NOT EXISTS lms_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(64) NOT NULL,
    module_code VARCHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL,
    release_at DATETIME NULL,
    close_at DATETIME NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_by VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lms_module (course_code, module_code),
    KEY idx_lms_modules_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"]);

// Create or update module
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseCode = trim($_POST['course_code'] ?? '');
    $moduleCode = trim($_POST['module_code'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $releaseAt = trim($_POST['release_at'] ?? '') ?: null;
    $closeAt = trim($_POST['close_at'] ?? '') ?: null;
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    if ($courseCode === '' || $moduleCode === '' || $title === '') {
        $err = 'Course, Module code and Title are required.';
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO lms_modules (course_code, module_code, title, release_at, close_at, is_published, created_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title), release_at=VALUES(release_at), close_at=VALUES(close_at), is_published=VALUES(is_published)");
            $user = $_SESSION['staff_id'] ?? 'system';
            $stmt->bind_param('sssssis', $courseCode, $moduleCode, $title, $releaseAt, $closeAt, $isPublished, $user);
            if ($stmt->execute()) { $ok = 'Module saved.'; } else { $err = 'Failed to save module.'; }
        } catch (Throwable $e) {
            error_log('modules.php save failed: ' . $e->getMessage());
            $err = 'Modules are unavailable right now. The lms_modules table may be missing — apply the pending migration.';
        }
    }
}

// Fetch modules (limit for view)
$modules = [];
try {
    if ($res = $db->query("SELECT * FROM lms_modules ORDER BY updated_at DESC LIMIT 200")) {
        while ($row = $res->fetch_assoc()) { $modules[] = $row; }
        $res->free();
    }
} catch (Throwable $e) {
    error_log('modules.php list failed: ' . $e->getMessage());
    if ($err === null) {
        $err = 'Modules are unavailable right now. The lms_modules table may be missing — apply the pending migration.';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../css/admin-dashboard.css" />
<div class="container-fluid px-4 portal-dashboard">
  <h2 class="mb-3">Course Modules</h2>
  <?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">Create / Update Module</div>
    <div class="card-body">
      <form method="post">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Course Code</label>
            <input class="form-control" name="course_code" required />
          </div>
          <div class="col-md-3">
            <label class="form-label">Module Code</label>
            <input class="form-control" name="module_code" required />
          </div>
          <div class="col-md-6">
            <label class="form-label">Title</label>
            <input class="form-control" name="title" required />
          </div>
          <div class="col-md-3">
            <label class="form-label">Release At</label>
            <input type="datetime-local" class="form-control" name="release_at" />
          </div>
          <div class="col-md-3">
            <label class="form-label">Close At</label>
            <input type="datetime-local" class="form-control" name="close_at" />
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_published" id="pub" />
              <label class="form-check-label" for="pub">Published</label>
            </div>
          </div>
          <div class="col-md-3 d-flex align-items-end justify-content-end">
            <button class="btn btn-primary" type="submit">Save Module</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Recent Modules</div>
    <div class="card-body table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light"><tr><th>Course</th><th>Module</th><th>Title</th><th>Release</th><th>Close</th><th>Published</th></tr></thead>
        <tbody>
        <?php foreach ($modules as $m): ?>
          <tr>
            <td><?php echo htmlspecialchars($m['course_code']); ?></td>
            <td><?php echo htmlspecialchars($m['module_code']); ?></td>
            <td><?php echo htmlspecialchars($m['title']); ?></td>
            <td><?php echo htmlspecialchars($m['release_at']); ?></td>
            <td><?php echo htmlspecialchars($m['close_at']); ?></td>
            <td><?php echo $m['is_published'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>



