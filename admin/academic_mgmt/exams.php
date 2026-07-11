<?php
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/role_helpers.php';

if (function_exists('canAccessExams') && !canAccessExams()) {
    $_SESSION['errorMssg'] = 'Access denied. You do not have permission to access exams.';
    header('Location: ../index.php');
    exit();
}

require_once __DIR__ . '/../includes/header.php';
$canEnterExamMarks = function_exists('canEnterExamMarks') && canEnterExamMarks();
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <h1 class="dashboard-title"><i class="fas fa-file-contract me-2"></i>Examination Management</h1>
    <p class="text-muted mb-0">Question bank, CA weighting, online proctoring, and workflows.</p>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white"><strong>Transcript Download</strong></div>
        <div class="card-body">
          <form method="POST" action="../transcript.php" target="_blank" class="row g-2">
            <div class="col-md-5">
              <label class="form-label">Student ID (SID)</label>
              <input type="text" class="form-control" name="Sid" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Year</label>
              <input type="number" class="form-control" name="Year" value="<?php echo date('Y'); ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">Sem</label>
              <select name="semester" class="form-select" required>
                <option value="1">1</option>
                <option value="2">2</option>
              </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <button class="btn btn-outline-primary w-100"><i class="fas fa-download me-2"></i>PDF</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white"><strong>Question Bank</strong></div>
        <div class="card-body">
          <a href="../upload_assessments.php" class="btn btn-outline-primary"><i class="fas fa-database me-2"></i>Manage Questions</a>
          <p class="text-muted mt-2 mb-0">Tag by course, topic, difficulty.</p>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white"><strong>CA Weighting</strong></div>
        <div class="card-body">
          <form action="save_ca_weighting.php" method="post" class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Program</label>
              <select class="form-select" name="program_code">
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
            <div class="col-md-4">
              <label class="form-label">Assignments %</label>
              <input type="number" class="form-control" name="assign_pct" value="40" min="0" max="100">
            </div>
            <div class="col-md-4">
              <label class="form-label">Exams %</label>
              <input type="number" class="form-control" name="exam_pct" value="60" min="0" max="100">
            </div>
            <div class="col-12">
              <button class="btn btn-primary" type="submit"><i class="fas fa-save me-2"></i>Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white"><strong>Online Proctoring</strong></div>
        <div class="card-body">
          <a href="../online_exams.php" class="btn btn-outline-primary"><i class="fas fa-video me-2"></i>Proctoring Console</a>
          <p class="text-muted mt-2 mb-0">Identity verification and activity logging.</p>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white"><strong>Marking & Results</strong></div>
        <div class="card-body">
          <?php if ($canEnterExamMarks): ?>
          <a href="../process_exam_results.php" class="btn btn-outline-primary"><i class="fas fa-clipboard-check me-2"></i>Process Results</a>
          <p class="text-muted mt-2 mb-0">Automation for objective items and workflows for subjective.</p>
          <?php else: ?>
          <p class="text-muted mb-0">You can view exam records, but exam mark entry requires exam-entry permission.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($canEnterExamMarks): ?>
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white"><strong>Final Results Upload</strong></div>
        <div class="card-body">
          <a href="../upload_finalExams.php" class="btn btn-primary"><i class="fas fa-upload me-2"></i>Bulk Upload (CSV)</a>
          <a href="../finalExams.php" class="btn btn-outline-primary ms-2"><i class="fas fa-list me-2"></i>Per Course Upload</a>
          <p class="text-muted mt-2 mb-0">Upload exam results in bulk or restrict to a single course.</p>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white"><strong>Continuous Assessment (CA)</strong></div>
        <div class="card-body">
          <a href="../upload_ca.php#bulk" class="btn btn-primary"><i class="fas fa-upload me-2"></i>Bulk Upload (CSV)</a>
          <a href="../upload_ca.php#per-course" class="btn btn-outline-primary ms-2"><i class="fas fa-list me-2"></i>Per Course Upload</a>
          <p class="text-muted mt-2 mb-0">Submit CA scores either in bulk or per course.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>



