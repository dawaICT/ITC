<?php
/**
 * Lecturer — Course Library Resources
 *
 * Lets a lecturer publish digital resources (links / e-books / videos / notes)
 * to the courses they are assigned to teach. Each saved resource is stored in
 * library_digital_resources and linked to the course via library_resource_links,
 * so it appears immediately in the student "My Course Library" view.
 *
 * Security: a lecturer may only attach resources to courses returned by
 * lr_lecturer_scope() — i.e. their own active assignments in course_lecturer.
 */

$page_title = 'Course Library Resources';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/library_resource_helpers.php';
require_once __DIR__ . '/../includes/auth_helpers.php'; // wuc_csrf_token(), wuc_validate_csrf()

$staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$scope = lr_lecturer_scope($db, $staffId);
$myCourses = $scope['courses'];

// Friendly course names.
$courseNames = [];
if ($myCourses && wuc_table_exists($db, 'courses')) {
    $ph = implode(',', array_fill(0, count($myCourses), '?'));
    $rows = lr_fetch_rows($db, "SELECT course_code, course_name FROM courses WHERE course_code IN ($ph)",
        str_repeat('s', count($myCourses)), $myCourses);
    foreach ($rows as $r) { $courseNames[$r['course_code']] = $r['course_name']; }
}

$flash = null; $flashErr = false;
$csrf = wuc_csrf_token();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_resource') {
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $flash = 'Security token mismatch. Please refresh and try again.'; $flashErr = true;
    } else {
        $course = trim((string)($_POST['course_code'] ?? ''));
        $title  = trim((string)($_POST['title'] ?? ''));
        $url    = trim((string)($_POST['url'] ?? ''));
        $rtype  = (string)($_POST['resource_type'] ?? 'other');
        $allowedTypes = ['ebook','journal','video','audio','dataset','other'];
        if (!in_array($rtype, $allowedTypes, true)) { $rtype = 'other'; }

        if (!in_array($course, $myCourses, true)) {
            $flash = 'You can only add resources to courses you are assigned to.'; $flashErr = true;
        } elseif ($title === '' || $url === '') {
            $flash = 'A title and a resource URL are both required.'; $flashErr = true;
        } elseif (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $flash = 'Please enter a valid URL (including http:// or https://).'; $flashErr = true;
        } else {
            try {
                $stmt = $db->prepare(
                    "INSERT INTO library_digital_resources (title, resource_type, url, access_level, subject, description)
                     VALUES (?,?,?,'registered',?,?)"
                );
                $desc = 'Added by lecturer ' . $staffId . ' for ' . $course;
                $stmt->bind_param('sssss', $title, $rtype, $url, $course, $desc);
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();
                if ($newId > 0 && lr_link_resource($db, 'digital', $newId, 'course', $course, 'students', $staffId)) {
                    $flash = 'Resource published to ' . ($courseNames[$course] ?? $course) . '.';
                } else {
                    $flash = 'Resource saved but could not be linked to the course.'; $flashErr = true;
                }
            } catch (Throwable $e) {
                error_log('course_resources add failed: ' . $e->getMessage());
                $flash = 'Could not save the resource right now.'; $flashErr = true;
            }
        }
    }
}

require "includes/nav.php";
?>

<div class="container-fluid">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0"><i class="fas fa-book-open me-2 text-primary"></i>Course Library Resources</h3>
      <p class="text-muted mb-0">Publish reading materials and links to your assigned courses.</p>
    </div>
    <a href="/wucportal/lecturers/myCourses.php" class="btn btn-link">Back to My Courses</a>
  </div>

  <?php if ($flash !== null): ?>
    <div class="alert alert-<?= $flashErr ? 'danger' : 'success' ?> py-2"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <?php if (!$myCourses): ?>
    <div class="alert alert-info">
      <i class="fas fa-circle-info me-2"></i>You have no active course assignments, so there are no courses to add resources to.
    </div>
  <?php else: ?>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-bold text-primary"><i class="fas fa-plus me-2"></i>Add a Resource</div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="action" value="add_resource">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="mb-2">
              <label class="form-label small mb-0">Course</label>
              <select class="form-select" name="course_code" required>
                <?php foreach ($myCourses as $c): ?>
                  <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c . ' — ' . ($courseNames[$c] ?? $c)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small mb-0">Title</label>
              <input class="form-control" name="title" required maxlength="255" placeholder="e.g. Lecture 3 — Normalization (slides)">
            </div>
            <div class="mb-2">
              <label class="form-label small mb-0">Type</label>
              <select class="form-select" name="resource_type">
                <option value="other">Notes / Other</option>
                <option value="ebook">e-Book / PDF</option>
                <option value="video">Video</option>
                <option value="journal">Journal</option>
                <option value="audio">Audio</option>
                <option value="dataset">Dataset</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label small mb-0">Resource URL</label>
              <input class="form-control" name="url" type="url" required placeholder="https://…">
            </div>
            <button class="btn btn-primary w-100"><i class="fas fa-cloud-upload-alt me-1"></i>Publish to Course</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-bold text-primary"><i class="fas fa-list me-2"></i>Resources by Course</div>
        <div class="card-body">
          <div class="accordion" id="lecResAcc">
            <?php foreach ($myCourses as $idx => $c):
              $bundle = lr_course_resources($db, $c, 'staff');
              $total = count($bundle['items']) + count($bundle['digital']) + count($bundle['notes']);
            ?>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#lecRes<?= $idx ?>">
                  <span class="fw-semibold"><?= htmlspecialchars($courseNames[$c] ?? $c) ?></span>
                  <span class="badge bg-<?= $total ? 'primary' : 'secondary' ?> rounded-pill ms-2"><?= $total ?></span>
                </button>
              </h2>
              <div id="lecRes<?= $idx ?>" class="accordion-collapse collapse" data-bs-parent="#lecResAcc">
                <div class="accordion-body">
                  <?php if (!$total): ?>
                    <div class="text-muted small fst-italic">No resources linked yet.</div>
                  <?php else: ?>
                    <table class="table table-sm align-middle mb-0">
                      <?php foreach ($bundle['digital'] as $d): ?>
                        <tr>
                          <td><span class="badge bg-success"><?= htmlspecialchars($d['resource_type']) ?></span></td>
                          <td><a href="<?= htmlspecialchars($d['url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($d['title']) ?></a></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php foreach ($bundle['items'] as $it): ?>
                        <tr>
                          <td><span class="badge bg-primary">book</span></td>
                          <td><?= htmlspecialchars($it['title']) ?> <span class="text-muted small"><?= htmlspecialchars($it['authors'] ?? '') ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php foreach ($bundle['notes'] as $n): ?>
                        <tr>
                          <td><span class="badge bg-info text-dark">notes</span></td>
                          <td><?= $n['url'] ? '<a href="'.htmlspecialchars($n['url']).'" target="_blank" rel="noopener">'.htmlspecialchars($n['topic']).'</a>' : htmlspecialchars($n['topic']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </table>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
