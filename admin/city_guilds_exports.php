<?php
// City & Guilds — Walled Garden CSV exports.
require_once __DIR__ . '/includes/city_guilds_page.php';
require_once __DIR__ . '/includes/header.php';

$cgTitle = 'City & Guilds — Exports';
$cgSubtitle = 'Generate Walled Garden CSV files for learner registration, examination entry, and certificate claims.';
?>

<div class="container-fluid px-4 portal-dashboard">
  <?php require __DIR__ . '/includes/city_guilds_nav.php'; ?>

  <div class="data-table-card mb-4">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="fas fa-file-export me-2"></i>Walled Garden CSV Exports</h5>
      </div>
    </div>
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-4">
          <label class="form-label">Export type</label>
          <select name="export" class="form-select">
            <option value="registration">Learner registration</option>
            <option value="exam_entry">Examination entry</option>
            <option value="certificate_claim">Certificate claim</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Cohort</label>
          <select name="cohort" class="form-select">
            <option value="">All cohorts</option>
            <?php foreach ($cohorts as $row): ?>
              <option value="<?php echo cg_h($row['cohort']); ?>"><?php echo cg_h($row['cohort']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <button class="btn btn-outline-primary w-100" type="submit"><i class="fas fa-download me-2"></i>Download CSV</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
