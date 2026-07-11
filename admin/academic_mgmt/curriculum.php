<?php
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/auth_helpers.php';
require_once __DIR__ . '/../includes/header.php';
$csrfToken = wuc_csrf_token();

// Placeholder routes for: Programs (metadata), Syllabi uploads, Credits & prerequisites, Accreditation tracking
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <h1 class="dashboard-title"><i class="fas fa-sitemap me-2"></i>Course & Curriculum Management</h1>
    <p class="text-muted mb-0">Define programs, manage syllabi versions, allocate credits and prerequisites, and track accreditation.</p>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white">
          <strong>Programs</strong>
        </div>
        <div class="card-body">
          <a href="../programs.php" class="btn btn-primary"><i class="fas fa-graduation-cap me-2"></i>Manage Programs</a>
          <p class="text-muted mt-2 mb-0">Duration, qualification level, modes.</p>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white">
          <strong>Syllabi</strong>
        </div>
        <div class="card-body">
          <form action="upload_syllabus.php" method="post" enctype="multipart/form-data" class="mb-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Program</label>
                <select name="program_code" class="form-select" required>
                  <?php
                  if ($pr = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_name")) {
                    while ($p = $pr->fetch_assoc()) {
                      echo '<option value="'.htmlspecialchars($p['program_code']).'">'.htmlspecialchars($p['program_name']).'</option>';
                    }
                    $pr->free();
                  }
                  ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Syllabus File (PDF/DOCX)</label>
                <input type="file" name="syllabus_file" class="form-control" accept=".pdf,.doc,.docx" required>
              </div>
            </div>
            <div class="mt-3">
              <button class="btn btn-outline-primary" type="submit"><i class="fas fa-upload me-2"></i>Upload & Version</button>
            </div>
          </form>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Program</th>
                  <th>Filename</th>
                  <th>Version</th>
                  <th>Date</th>
                  <th>Download</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $i=1;
                if ($sy = $db->query("SELECT ps.*, p.program_name FROM program_syllabi ps JOIN programs p ON p.program_code=ps.program_code ORDER BY uploaded_at DESC")) {
                  while ($row = $sy->fetch_assoc()) {
                    echo '<tr>';
                    echo '<td>'.($i++).'</td>';
                    echo '<td>'.htmlspecialchars($row['program_name']).'</td>';
                    echo '<td>'.htmlspecialchars($row['filename']).'</td>';
                    echo '<td>'.(int)$row['version'].'</td>';
                    echo '<td>'.htmlspecialchars($row['uploaded_at']).'</td>';
                    echo '<td><a class="btn btn-sm btn-outline-secondary" href="download_syllabus.php?id='.(int)$row['id'].'" target="_blank"><i class="fas fa-download"></i></a></td>';
                    echo '</tr>';
                  }
                  $sy->free();
                }
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white"><strong>Credits & Prerequisites</strong></div>
        <div class="card-body">
          <a href="../course_prerequisites.php" class="btn btn-outline-primary"><i class="fas fa-link me-2"></i>Manage Prerequisites</a>
          <p class="text-muted mt-2 mb-0">Allocate credits per course and enforce semester credit limits.</p>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white"><strong>Accreditation Tracking</strong></div>
        <div class="card-body">
          <form action="save_accreditation.php" method="post" class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Program</label>
              <select name="program_code" class="form-select" required>
                <?php
                if ($pr2 = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_name")) {
                  while ($p = $pr2->fetch_assoc()) {
                    echo '<option value="'.htmlspecialchars($p['program_code']).'">'.htmlspecialchars($p['program_name']).'</option>';
                  }
                  $pr2->free();
                }
                ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="in-review">In Review</option>
                <option value="accredited">Accredited</option>
                <option value="suspended">Suspended</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Effective</label>
              <input type="date" name="effective_date" class="form-control">
            </div>
            <div class="col-12">
              <input type="text" name="notes" placeholder="Notes" class="form-control">
            </div>
            <div class="col-12">
              <button class="btn btn-primary" type="submit"><i class="fas fa-save me-2"></i>Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>



