<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/elearning_guard.php';
elearning_require_role(['systems_admin','lecturer','head_of_department']);

require_once __DIR__ . '/../../db/connect.php';

$err = null; $ok = null;

if (($_POST['action'] ?? '') === 'create_forum') {
    $scope = trim($_POST['scope'] ?? 'course');
    $course = trim($_POST['course_code'] ?? '');
    $moduleId = isset($_POST['module_id']) && trim($_POST['module_id']) !== '' ? (int)$_POST['module_id'] : null;
    $title = trim($_POST['title'] ?? '');
    if ($title === '' || $course === '') { $err = 'Course and title required.'; }
    else {
        $user = $_SESSION['staff_id'] ?? 'system';
        $stmt = $db->prepare("INSERT INTO lms_forums (scope, course_code, module_id, title, created_by) VALUES (?,?,?,?,?)");
        if ($stmt) {
            $stmt->bind_param('ssiss', $scope, $course, $moduleId, $title, $user);
            if ($stmt->execute()) { $ok = 'Forum created.'; } else { $err = 'Failed: ' . $stmt->error; }
            $stmt->close();
        } else {
            $err = 'Database error: ' . $db->error;
        }
    }
}

$forums = [];
if ($res = $db->query("SELECT * FROM lms_forums ORDER BY id DESC LIMIT 100")) { while ($r = $res->fetch_assoc()) { $forums[] = $r; } }

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../css/admin-dashboard.css" />
<div class="container-fluid px-4 portal-dashboard">
  <h2 class="mb-3"><i class="fas fa-comments me-2"></i>Discussion Forums</h2>
  <?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">Create Forum</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="action" value="create_forum" />
        <div class="row g-3">
          <div class="col-md-2">
            <label class="form-label">Scope</label>
            <select class="form-select" name="scope">
              <option value="course">Course</option>
              <option value="module">Module</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Course Code</label>
            <input class="form-control" name="course_code" required />
          </div>
          <div class="col-md-2">
            <label class="form-label">Module ID</label>
            <input class="form-control" name="module_id" placeholder="Optional" />
          </div>
          <div class="col-md-5">
            <label class="form-label">Title</label>
            <input class="form-control" name="title" required />
          </div>
          <div class="col-md-12 text-end">
            <button class="btn btn-primary" type="submit"><i class="fas fa-plus me-1"></i>Create</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Recent Forums</div>
    <div class="card-body table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light"><tr><th>ID</th><th>Scope</th><th>Course</th><th>Module</th><th>Title</th><th>Created</th></tr></thead>
        <tbody>
          <?php foreach ($forums as $f): ?>
            <tr>
              <td><?php echo (int)$f['id']; ?></td>
              <td>
                <span class="badge bg-<?php echo ($f['scope'] ?? '') === 'module' ? 'info' : 'secondary'; ?>">
                  <?php echo htmlspecialchars(ucfirst((string)($f['scope'] ?? 'course'))); ?>
                </span>
              </td>
              <td><?php echo htmlspecialchars($f['course_code']); ?></td>
              <td><?php echo ($f['module_id'] !== null && $f['module_id'] !== '') ? htmlspecialchars($f['module_id']) : '—'; ?></td>
              <td><?php echo htmlspecialchars($f['title']); ?></td>
              <td><?php echo !empty($f['created_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime((string)$f['created_at']))) : '—'; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (count($forums) === 0): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No forums yet. Create one above to get started.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
