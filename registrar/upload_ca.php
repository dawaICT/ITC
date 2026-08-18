<?php
/**
 * Registrar – Upload Continuous Assessment (CA)
 *
 * Rewritten to use modern portal styling: container-fluid layout,
 * page-header, standard Bootstrap 5 card and inputs, and CSV format instructions.
 *
 * Unified nav (includes/nav.php) provides auth, session, role gate,
 * sidebar chrome, and all CDN assets.
 */

require dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
$page_title = 'Upload Continuous Assessment';
require __DIR__ . '/includes/nav.php';
$csrfToken = wuc_csrf_token();
?>

<style>
  .upload-ca-page {
    padding-top: 1.25rem;
    padding-bottom: 2rem;
  }

  .upload-ca-page .page-header,
  .upload-ca-page .data-table-card,
  .upload-ca-page .format-note {
    background: #fff !important;
    border: 1px solid #e5eaf2 !important;
    border-radius: 10px !important;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06) !important;
  }

  .upload-ca-page .page-header {
    padding: 1.1rem 1.25rem;
  }

  .upload-ca-page .page-title {
    color: #14213d;
    font-size: 1.05rem;
    font-weight: 700;
  }

  .upload-ca-page .page-subtitle {
    color: #64748b;
    font-size: 0.88rem;
  }

  .upload-ca-page .data-table-card .card-header {
    background: #fff !important;
    border-bottom: 1px solid #e8edf5 !important;
    padding: 0.95rem 1.1rem;
  }

  .upload-ca-page .data-table-card .card-header h5 {
    color: #164e86;
    font-size: 0.96rem;
    font-weight: 700;
  }

  .upload-ca-page .data-table-card .card-body {
    padding: 1.15rem;
  }

  .upload-ca-page .form-label {
    color: #334155;
    font-weight: 700;
    font-size: 0.84rem;
  }

  .upload-ca-page .form-control,
  .upload-ca-page .form-select {
    border-color: #d7e0ec;
    border-radius: 8px;
    min-height: 42px;
  }

  .upload-ca-page .form-control:focus,
  .upload-ca-page .form-select:focus {
    border-color: #2457a6;
    box-shadow: 0 0 0 0.18rem rgba(36, 87, 166, 0.14);
  }

  .upload-ca-page .btn-success {
    background: #087f5b;
    border-color: #087f5b;
    box-shadow: 0 6px 14px rgba(8, 127, 91, 0.16);
  }

  .upload-ca-page .format-note {
    padding: 1rem 1.1rem;
  }

  .upload-ca-page .format-note code {
    background: #eef2f7;
    color: #172033;
    border-radius: 5px;
    padding: 0.15rem 0.35rem;
    white-space: nowrap;
  }
</style>

<div class="container-fluid px-4 portal-dashboard upload-ca-page">
  <!-- Page Header -->
  <div class="page-header mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="page-title mb-0"><i class="fas fa-upload me-2 text-primary"></i>Upload Continuous Assessment (CA)</h5>
        <p class="page-subtitle mb-0">Upload CA results in CSV format</p>
      </div>
      <div>
        <a href="assessments.php" class="btn btn-secondary">
          <i class="fas fa-arrow-left me-2"></i>Back to CA List
        </a>
      </div>
    </div>
  </div>

  <?php if (!empty($_SESSION['errorMsg'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars((string)$_SESSION['errorMsg'], ENT_QUOTES, 'UTF-8') ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['errorMsg']); ?>
  <?php endif; ?>

  <!-- Upload Card -->
  <div class="data-table-card mb-4">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0 text-primary"><i class="fas fa-file-upload me-2"></i>Bulk Upload (CSV)</h5>
      </div>
    </div>
    <div class="card-body">
      <form action="uploaded_ca.php" method="post" enctype="multipart/form-data" class="row g-3 needs-validation" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="col-lg-4 col-md-6">
          <label class="form-label">CSV File</label>
          <input type="file" class="form-control" name="file" accept=".csv" required>
          <div class="invalid-feedback">Please choose a valid CSV file</div>
        </div>
        <div class="col-lg-3 col-md-6">
          <label class="form-label">Semester / Term</label>
          <select class="form-select" id="semester" name="semester" required>
            <option value="" disabled selected>Select semester or term</option>
            <option value="1">Semester 1 / Term 1</option>
            <option value="2">Semester 2 / Term 2</option>
            <option value="3">Term 3</option>
          </select>
          <div class="invalid-feedback">Please select a semester or term</div>
        </div>
        <div class="col-lg-2 col-md-6">
          <label class="form-label">Academic Year</label>
          <select class="form-select" id="Year" name="Year" required>
            <option value="" disabled selected>Select year</option>
          </select>
          <div class="invalid-feedback">Please select academic year</div>
        </div>
        <div class="col-lg-3 col-md-6 d-flex align-items-end">
          <button class="btn btn-success w-100 text-white" type="submit" name="import"><i class="fas fa-upload me-2"></i>Submit</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Format Note -->
  <div class="format-note">
    <div class="d-flex align-items-start gap-2">
      <i class="fas fa-info-circle text-primary mt-1"></i>
      <div>
        <strong class="d-block mb-1 text-primary">CSV File Format Instructions</strong>
        <div class="text-muted small mb-2">Use one row per student and course. Header row is optional and will be skipped when present. Short-course enrollments are detected automatically.</div>
        <div class="small text-secondary">Expected Columns: <code>Sid</code> <code>Course_Code</code> <code>A1</code> <code>A2</code> <code>A3</code> <code>T1</code> <code>T2</code></div>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  const sel = document.getElementById('Year');
  if (sel) {
    const endYear = new Date().getFullYear();
    for (let y = endYear; y >= 2000; y--) {
      const opt = document.createElement('option');
      opt.value = String(y);
      opt.textContent = String(y);
      sel.appendChild(opt);
    }
  }

  // Bootstrap validation trigger
  var forms = document.querySelectorAll('.needs-validation');
  Array.prototype.slice.call(forms).forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
